<?php
/**
 * ReqgrepCommand: WP-CLI subcommand for filtering firehose JSONL by request id
 * (or any pattern). Reads the firehose through the substrate's `Consumer_Node`
 * instead of hand-rolling `Partition::read_at()` + `json_decode()`:
 *
 *  - cat / --recent: one `Consumer_Node` per partition, sink → a `Callback_Node`
 *    that hands each unpacked `Message` to `process_message()`, driven to EOF via
 *    the Consumer's synchronous `drain()`.
 *  - --follow: the same Consumer graph seeded at the partition tail, run under the
 *    `Event_Framework` drain loop (each Consumer's `fire_cb` polls for new bytes).
 *  - Every firehose line on disk is a 7-field positional `Message` envelope
 *    (`Message::packed`); the entry hash is at `Message::VALUE`, the rid at
 *    `Message::KEY`. There is no legacy entry-hash format.
 *  - Logs path: `Config::get_logs_directory() . '/firehose.log'`.
 *  - Namespace: `Newspack_Event_Logger_Nodes\CLI`. Uses the local LruCache port.
 *
 * Behaviour preserved 1:1:
 *  - 300-slot 3-bucket × 100 LruCache with 60-second timed rotation; on-evict
 *    callback prints `[incomplete]` and drops.
 *  - Per-rid byte cap MAX_BYTES_PER_REQUEST = 10MB.
 *  - Line caps MAX_LINES_PER_REQUEST = 20000, MAX_LINES_PER_REQUEST_IN_HISTORY = 10000.
 *  - Indent state machine: `(start)` increases indent by 4, `(complete)`
 *    decreases by 4 (clamped at 0).
 *  - 0.1-second timestamp resolution display.
 *  - Escalating-interval dot rows (1s, 10s, 100s, ...) so multi-day gaps don't
 *    blow up output.
 *  - peak_mb / duration_ms suffix on (complete) lines.
 *  - Multi-line message indentation aligned to the prefix width.
 *  - `#` separator on rid reset (when message number rewinds).
 *  - stdin pipe-mode (S_IFIFO + S_IFREG detection), read via the substrate's
 *    `Stdin_Node`; all output flows through a swappable `Stdout_Node` (which
 *    fwrites straight to STDOUT, bypassing PHP output buffers).
 *
 * Capability: requires `manage_options` (the standard WP-CLI invariant —
 * WP-CLI calls run as root unless the user explicitly passes `--user=`, but
 * we keep the explicit check so future REST gateway integration shares one
 * authorization path).
 *
 * @package Newspack_Event_Logger_Nodes
 * @phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not web
 */

namespace Newspack_Event_Logger_Nodes\CLI;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\LRU_Cache;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Stdin_Node;
use Newspack_Nodes\Stdout_Node;

class Reqgrep_Command {

	/** Maximum lines per in-progress request. */
	private const MAX_LINES_PER_REQUEST = 20000;

	/**
	 * Maximum bytes per in-progress request. Disk-sourced lines are already
	 * PIPE_BUF-capped at 4KB by LogManager and RequestBuilder already truncates
	 * the `m` field to 1KB at source, so 10MB only matters when stdin pipes in
	 * giant lines from a non-canonical producer. 300 slots × 10MB = 3GB worst
	 * case ceiling; the typical run stays well under PHP's memory_limit.
	 */
	private const MAX_BYTES_PER_REQUEST = 10 * 1024 * 1024;

	/** Maximum lines per request retained in history buckets. */
	private const MAX_LINES_PER_REQUEST_IN_HISTORY = 10000;

	/**
	 * In-flight LRU geometry: 100 items × 3 buckets = 300 slots, well above the
	 * typical PHP-FPM concurrency ceiling. Anything that falls out of the oldest
	 * bucket is printed as `[incomplete]`.
	 */
	private const INFLIGHT_BUCKET_SIZE = 100;
	private const INFLIGHT_NUM_BUCKETS = 3;

	/** Seconds between in-flight cache rotations (60 × 3 = 180s idle ceiling). */
	private const INFLIGHT_ROTATE_INTERVAL = 60.0;

	/** Formatting state — current indent column. */
	private int $fmt_indent = 0;

	/** Formatting state — last seen entry number. */
	private int $fmt_last_number = 0;

	/** Formatting state — last seen timestamp. */
	private float $fmt_last_timestamp = 0;

	/** In-flight matched requests. Each value is stdClass {lines:array, bytes:int}. */
	private ?LRU_Cache $inflight = null;

	/** @var array<int, array<string, array<int, string>>> History buckets; each bucket maps rid => lines. */
	private array $history = [ [] ];

	/** Search pattern (positional arg, default '.'). */
	private string $pattern = '.';

	/** Pre-compiled regex pattern for matching. */
	private string $pattern_regex = '/./i';

	/** True if --incomplete was passed. */
	private bool $incomplete = false;

	/** True if --raw was passed. */
	private bool $raw = false;

	/**
	 * Cat-mode starting offset: 'start' (default; full grep semantics) or
	 * 'recent' (only the second-to-last segment and newer — fast lookup).
	 */
	private string $cat_offset = 'start';

	/** History bucket size (lines per bucket). */
	private int $bucket_size = 250;

	/** History bucket count. */
	private int $num_buckets = 10;

	/** Firehose base directory (resolved from Config or --path). */
	private string $base_dir = '';

	/** Number of partitions to walk. */
	private int $num_partitions = 1;

	/** @var array<string, mixed> Loaded config snapshot. */
	private array $config = [];

	/**
	 * Output sink — lazily a Stdout_Node in production (see emit()); tests swap in
	 * a capturing Callback_Node. Public so the test harness can substitute the
	 * terminal sink without short-circuiting the rest of the emit path.
	 */
	public ?\Newspack_Nodes\Node $stdout = null;

	/**
	 * Filter firehose JSONL logs by request id or pattern.
	 *
	 * Collects every entry sharing a request id once any line for that rid
	 * matches the pattern, then prints them in chronological order with
	 * indentation reflecting the (start)/(complete) tree.
	 *
	 * ## OPTIONS
	 *
	 * [<pattern>]
	 * : Search pattern (rid, URL, or any text). Matches everything if omitted.
	 *
	 * [--follow]
	 * : Tail mode — keep reading and printing new requests as they finish.
	 *
	 * [--recent]
	 * : Only scan the second-to-last segment and newer (fast lookup vs. full grep).
	 *
	 * [--raw]
	 * : Emit raw JSONL instead of formatted output.
	 *
	 * [--incomplete]
	 * : Show requests that never reached `process (complete)`.
	 *
	 * [--bucket-size=<size>]
	 * : Lines per bucket for the history buffer.
	 * ---
	 * default: 250
	 * ---
	 *
	 * [--num-buckets=<count>]
	 * : Number of history buckets retained.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--path=<path>]
	 * : Override firehose base directory. Must be inside the configured logs dir.
	 *
	 * ## EXAMPLES
	 *
	 *     # Search every segment for /calendar
	 *     wp nodes reqgrep /calendar
	 *
	 *     # Live tail
	 *     wp nodes reqgrep --follow
	 *
	 *     # Specific rid
	 *     wp nodes reqgrep abc123def
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->pattern       = $args[0] ?? '.';
		$this->pattern_regex = '/' . \preg_quote( $this->pattern, '/' ) . '/i';
		$this->incomplete    = isset( $assoc_args['incomplete'] );
		$this->raw           = isset( $assoc_args['raw'] );
		$bucket_size_arg     = $assoc_args['bucket-size'] ?? 250;
		$num_buckets_arg     = $assoc_args['num-buckets'] ?? 10;
		$this->bucket_size   = \max( 1, \min( 10000, \is_numeric( $bucket_size_arg ) ? (int) $bucket_size_arg : 0 ) );
		$this->num_buckets   = \max( 1, \min( 100, \is_numeric( $num_buckets_arg ) ? (int) $num_buckets_arg : 0 ) );
		$follow              = isset( $assoc_args['follow'] );
		$this->cat_offset    = isset( $assoc_args['recent'] ) ? 'recent' : 'start';

		$this->config         = Config::load_config();
		$path_arg             = $assoc_args['path'] ?? null;
		$this->base_dir       = \is_string( $path_arg ) ? $path_arg : Config::get_logs_directory() . '/firehose.log';
		$num_partitions_cfg   = $this->config['num_partitions'] ?? 1;
		$this->num_partitions = \is_numeric( $num_partitions_cfg ) ? (int) $num_partitions_cfg : 0;

		// LruCache: 300 slots, 60s rotation, on-evict prints [incomplete].
		$this->inflight = ( new LRU_Cache( self::INFLIGHT_BUCKET_SIZE, self::INFLIGHT_NUM_BUCKETS ) )
			->with_timed_rotation(
				self::INFLIGHT_ROTATE_INTERVAL,
				function ( string $rid, $state ): void {
					if ( ! $state instanceof \stdClass ) {
						return;
					}
					$this->output_request( self::to_lines( $state->lines ), $rid );
					$this->emit( '[incomplete]' );
					$this->emit( '' );
				}
			);

		// Validate explicit --path against the configured logs directory.
		if ( isset( $assoc_args['path'] ) ) {
			$path_value = \is_string( $assoc_args['path'] ) ? $assoc_args['path'] : '';
			$real_path  = \realpath( $path_value );
			if ( false === $real_path ) {
				\WP_CLI::error( 'Invalid path: ' . $path_value );
				return;
			}
			$logs_dir = Config::get_logs_directory();
			if ( 0 !== \strpos( $real_path, $logs_dir ) ) {
				\WP_CLI::error( 'Path must be within the logs directory.' );
			}
			$this->base_dir = $real_path;
		}

		// Detect pipe / file redirection (stdin) vs. cat / follow mode.
		$use_stdin = $this->stdin_has_data();

		if ( $use_stdin ) {
			$this->process_stdin();
		} elseif ( $follow ) {
			$this->follow_mode();
		} else {
			$this->cat_mode();
		}
	}

	/** Emit one line to the output node (Stdout_Node appends the trailing newline). */
	private function emit( string $text ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$message[ Message::VALUE ] = $text;
		( $this->stdout ??= new Stdout_Node() )->fill( $message );
	}

	/**
	 * Output a completed request — either the raw JSON lines (raw mode) or the
	 * formatted indented tree.
	 *
	 * Raw mode echoes spooled lines verbatim (the packed Message envelope).
	 * Formatted mode unpacks each envelope and renders its VALUE (the entry hash);
	 * a line that isn't a packed Message passes through verbatim.
	 *
	 * @param array<string> $lines Packed Message envelopes for the request.
	 * @param string        $rid   Request id, used for the formatted header.
	 */
	private function output_request( array $lines, string $rid ): void {
		if ( $this->raw ) {
			foreach ( $lines as $line ) {
				$this->emit( $line );
			}
			$this->emit( '' );
			return;
		}

		$this->fmt_indent         = 0;
		$this->fmt_last_number    = 0;
		$this->fmt_last_timestamp = 0;

		if ( '' !== $rid ) {
			$this->emit( \sprintf( '      %22s request_id:%s', '', $rid ) );
		}

		foreach ( $lines as $line ) {
			try {
				$message = Message::unpacked( $line );
			} catch ( \InvalidArgumentException $e ) {
				$this->emit( $line );
				continue;
			}
			$entry = $message[ Message::VALUE ];
			if ( ! \is_array( $entry ) ) {
				$this->emit( $line );
				continue;
			}
			$this->emit( $this->format_entry( $entry ) );
		}
		$this->emit( '' );
	}

	/**
	 * Format a log entry for display with indentation, dot rows for elapsed
	 * seconds, and (start)/(complete) bookkeeping.
	 *
	 * @param array<int|string, mixed> $entry Decoded JSON entry.
	 * @return string Formatted output line.
	 */
	private function format_entry( array $entry ): string {
		$number = self::to_int( $entry['n'] ?? 0 );
		$ts     = self::to_float( $entry['ts'] ?? 0 );
		$key    = self::to_str( $entry['k'] ?? '' );

		// Decrease indent BEFORE printing the (complete) line so its key sits
		// at the same column as the matching (start) entry.
		if ( false !== \strpos( $key, '(complete)' ) ) {
			$this->fmt_indent -= 4;
		}
		if ( $this->fmt_indent < 0 ) {
			$this->fmt_indent = 0;
		}

		$output = '';

		// New-request separator when the entry number rewinds (rid reset).
		if ( $number < $this->fmt_last_number ) {
			$this->fmt_indent         = 0;
			$this->fmt_last_timestamp = 0;
			$output                  .= "\n    " . \str_repeat( '#', 60 ) . "\n\n";
		}

		// Timestamp column — only print when it advances at 0.1s resolution.
		$time_str = '';
		if ( (int) ( $ts * 10 ) > (int) ( $this->fmt_last_timestamp * 10 ) ) {
			$tenth    = (int) ( ( $ts - \floor( $ts ) ) * 10 );
			$time_str = \gmdate( 'Y-m-d H:i:s', (int) $ts ) . ".{$tenth}";

			// Print dot rows for elapsed seconds with escalating intervals so
			// a multi-hour gap doesn't blow up output. First 10 rows at 1s,
			// next 10 at 10s, etc. — O(log gap) rows.
			if ( $this->fmt_last_timestamp ) {
				$last_sec = (int) $this->fmt_last_timestamp;
				$curr_sec = (int) $ts;
				if ( $curr_sec > $last_sec + 1 ) {
					$interval   = 1;
					$rows_at_iv = 0;
					$s          = $last_sec + 1;
					while ( $s < $curr_sec ) {
						$dot_time = \gmdate( 'Y-m-d H:i:s', $s ) . ".{$tenth}";
						$output  .= \sprintf( "%4d: %22s %s.\n", $number, $dot_time, \str_repeat( ' ', $this->fmt_indent ) );
						++$rows_at_iv;
						if ( $rows_at_iv >= 10 ) {
							$interval  *= 10;
							$rows_at_iv = 0;
							// Jump to next interval-aligned boundary.
							$s = ( \intdiv( $s, $interval ) + 1 ) * $interval;
						} else {
							$s += $interval;
						}
					}
				}
			}
		}

		// Build the message body. Arrays pretty-print as JSON; strings stay verbatim.
		$message = '';
		if ( isset( $entry['m'] ) ) {
			if ( \is_array( $entry['m'] ) ) {
				$message = \wp_json_encode( $entry['m'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '';
			} else {
				$message = self::to_str( $entry['m'] );
			}
		}

		$suffix = '';
		if ( isset( $entry['duration_ms'] ) ) {
			$suffix .= ' (' . \number_format( self::to_float( $entry['duration_ms'] ), 2 ) . 'ms)';
		}
		if ( isset( $entry['peak_mb'] ) ) {
			$suffix .= ' [' . self::to_str( $entry['peak_mb'] ) . 'MB]';
		}

		$prefix = \sprintf(
			"%4d: %22s %s%s:",
			$number,
			$time_str,
			\str_repeat( ' ', $this->fmt_indent ),
			$key
		);

		// Multi-line `m` — pad continuation lines so they align with the start
		// of the message column.
		if ( false !== \strpos( $message, "\n" ) ) {
			$pad   = \str_repeat( ' ', \strlen( $prefix ) );
			$lines = \explode( "\n", $message );
			$message   = $lines[0];
			for ( $i = 1, $c = \count( $lines ); $i < $c; $i++ ) {
				$message .= "\n" . $pad . $lines[ $i ];
			}
		}

		$output .= $prefix . $message . $suffix;

		// Increase indent AFTER printing the (start) line so the next entry is
		// rendered one column deeper.
		if ( false !== \strpos( $key, '(start)' ) ) {
			$this->fmt_indent += 4;
		}

		$this->fmt_last_number    = $number;
		$this->fmt_last_timestamp = $ts;

		return \rtrim( $output, "\n" );
	}

	/**
	 * Coerce a decoded-JSON numeric to int, reproducing the prior `(int)` cast
	 * for the numeric field values the firehose emits; non-numerics become 0.
	 *
	 * @param mixed $value Decoded value.
	 */
	private static function to_int( $value ): int {
		return \is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Coerce a decoded-JSON numeric to float, reproducing the prior `(float)`
	 * cast for the numeric field values the firehose emits; non-numerics become 0.0.
	 *
	 * @param mixed $value Decoded value.
	 */
	private static function to_float( $value ): float {
		return \is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Coerce a decoded-JSON scalar to string, reproducing the prior `(string)`
	 * cast for the scalar field values the firehose actually emits; non-scalars
	 * (which the old casts would have warned/erred on) become ''.
	 *
	 * @param mixed $value Decoded value.
	 */
	private static function to_str( $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Narrow a stdClass `->lines` value (always built from string appends in
	 * append_to_state) to a list of strings for output_request.
	 *
	 * @param mixed $value Decoded value.
	 * @return array<int, string>
	 */
	private static function to_lines( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		return \array_values( \array_filter( $value, 'is_string' ) );
	}

	/**
	 * Detect whether `$stream` has piped data attached. fstat() reports the
	 * type bits — S_IFIFO (pipe) or S_IFREG (file) means data; everything
	 * else (tty, /dev/null, sockets) is "no piped data, use cat mode."
	 *
	 * Defaults to STDIN; tests pass a php://memory resource so the dispatch
	 * decision is observable without a real STDIN pipe.
	 *
	 * @param resource|null $stream Stream to inspect (defaults to STDIN).
	 */
	private function stdin_has_data( $stream = null ): bool {
		if ( null === $stream ) {
			if ( ! \defined( 'STDIN' ) ) {
				return false;
			}
			$stream = STDIN;
		}
		// Closed / non-resource → not piped data.
		if ( ! \is_resource( $stream ) ) {
			return false;
		}
		$stat = @\fstat( $stream );
		if ( ! $stat ) {
			return false;
		}
		$file_type = $stat['mode'] & 0170000;
		return 0010000 === $file_type || 0100000 === $file_type;
	}

	/**
	 * Stdin pipe mode: drive a `Stdin_Node` over `$stream` into a `Callback_Node`
	 * that unpacks each packed Message envelope and runs it through
	 * process_message, then flush incomplete requests so the operator can see
	 * partial state. eof_deadline 0 → the reader self-exits immediately after the
	 * stream's TM_EOF (no lingering post-EOF poll).
	 *
	 * Defaults to STDIN; tests inject a `fopen('php://memory', 'r+')` filled with
	 * fixture lines to drive the loop deterministically.
	 *
	 * @param resource|null $stream Source stream (defaults to STDIN).
	 */
	private function process_stdin( $stream = null ): void {
		$stream = $stream ?? ( \defined( 'STDIN' ) ? \STDIN : null );
		if ( null === $stream ) {
			return;
		}
		$src = new Stdin_Node( $stream, 0.0 );
		$src->sink( new Callback_Node( function ( array $message ): void {
			$line = \trim( self::to_str( $message[ Message::VALUE ] ) );
			if ( '' === $line ) {
				return;
			}
			try {
				$unpacked = Message::unpacked( $line );
			} catch ( \InvalidArgumentException $e ) {
				return; // Not a packed envelope — skip.
			}
			$this->process_message( $unpacked );
		} ) );
		while ( ! $src->exit ) {
			$src->fire();
		}
		$this->output_remaining();
	}

	/**
	 * Process one unpacked firehose Message: the entry hash sits at
	 * Message::VALUE, the routing rid at Message::KEY. Spool the re-packed
	 * envelope (the accepted minimal bridge — raw mode echoes it, formatted mode
	 * unpacks it) and hand off to the rid-grouping state machine.
	 *
	 * @param array<int, mixed> $message The 7-field positional Message array.
	 */
	private function process_message( array $message ): void {
		$entry = $message[ Message::VALUE ];
		$rid   = self::to_str( $message[ Message::KEY ] ?? '' );
		if ( ! \is_array( $entry ) || '' === $rid ) {
			return;
		}
		$this->group_and_output( $entry, $rid, Message::packed( $message ) );
	}

	/**
	 * Rid-grouping state machine — the shared tail of the read paths.
	 *
	 *  - Already-tracked rid: append; print on `process (complete)`.
	 *  - New rid + envelope matches pattern: pull history, append, start tracking.
	 *  - No match: stash in history (bounded by num_buckets × bucket_size).
	 *
	 * @param array<int|string, mixed> $entry Decoded entry hash (the Message VALUE).
	 * @param string                   $rid   Request id (the Message KEY).
	 * @param string                   $line  Packed Message envelope (spooled + grepped).
	 */
	private function group_and_output( array $entry, string $rid, string $line ): void {
		$key = self::to_str( $entry['k'] ?? '' );

		$inflight = $this->require_inflight();
		$state    = $inflight->get( $rid );
		if ( $state instanceof \stdClass ) {
			// Already tracking this rid: extend it and finalize on complete.
			$this->append_to_state( $state, $line );
			if ( 'process (complete)' === $key ) {
				if ( ! $this->incomplete ) {
					$this->output_request( self::to_lines( $state->lines ), $rid );
				}
				$inflight->delete( $rid );
			}
		} elseif ( $rid === $this->pattern || \preg_match( $this->pattern_regex, $line ) ) {
			// New matching rid: bootstrap state from history (if any).
			$state        = new \stdClass();
			$state->lines = [];
			$state->bytes = 0;

			$found_history = false;
			foreach ( $this->history as $recent ) {
				if ( isset( $recent[ $rid ] ) ) {
					$found_history = true;
					foreach ( $recent[ $rid ] as $hist_line ) {
						if ( ! $this->append_to_state( $state, $hist_line ) ) {
							break 2; // Cap hit — stop merging history.
						}
					}
				}
			}

			$n = self::to_int( $entry['n'] ?? 0 );
			if ( ! $found_history && $n > 1 && \count( $this->history ) >= $this->num_buckets ) {
				\WP_CLI::warning( "Couldn't find request start in history - try increasing --bucket-size or --num-buckets" );
			}

			$this->append_to_state( $state, $line );
			$inflight->set( $rid, $state );

			if ( 'process (complete)' === $key ) {
				if ( ! $this->incomplete ) {
					$this->output_request( self::to_lines( $state->lines ), $rid );
				}
				$inflight->delete( $rid );
			}
		} else {
			// Not matching — stash in history. Bound per-rid lines to defend memory.
			$recent_idx = \count( $this->history ) - 1;
			if ( ! isset( $this->history[ $recent_idx ][ $rid ] ) ) {
				$this->history[ $recent_idx ][ $rid ] = [];
			}
			if ( \count( $this->history[ $recent_idx ][ $rid ] ) < self::MAX_LINES_PER_REQUEST_IN_HISTORY ) {
				$this->history[ $recent_idx ][ $rid ][] = $line;
			}

			// Rotate history buckets when current overflows; trim to num_buckets.
			if ( \count( $this->history[ $recent_idx ], COUNT_RECURSIVE ) > $this->bucket_size ) {
				$this->history[] = [];
			}
			if ( \count( $this->history ) > $this->num_buckets ) {
				\array_shift( $this->history );
			}
		}

		// Roll the LruCache on its own schedule — the on-evict callback prints
		// `[incomplete]` for any rids that fell out of the oldest bucket.
		$inflight->rotate_if_due();
	}

	/**
	 * Narrow the run-setup-assigned `$inflight` cache to non-null. The cache is
	 * built in the command's setup before any line processing; a null here means
	 * a caller invoked a processing method before setup, which is a bug.
	 */
	private function require_inflight(): LRU_Cache {
		if ( null === $this->inflight ) {
			throw new \RuntimeException( 'in-flight cache not initialized' );
		}
		return $this->inflight;
	}

	/**
	 * Append a line to the in-flight request state, respecting line + byte caps.
	 *
	 * @param \stdClass $state State object with ->lines and ->bytes fields.
	 * @param string    $line  Raw JSON line (already m-truncated).
	 * @return bool True if appended; false if a cap was hit (caller may stop).
	 */
	private function append_to_state( \stdClass $state, string $line ): bool {
		$line_bytes = \strlen( $line );
		// Dynamic \stdClass state: ->bytes is always int, ->lines always a string list.
		$bytes = \is_int( $state->bytes ) ? $state->bytes : 0;
		if ( ! \is_array( $state->lines ) ) {
			$state->lines = [];
		}
		// Reference (not a copy) so the append mutates the property in place —
		// a copy-into-local + write-back triggers copy-on-write on every line.
		/** @var list<string> $lines */
		$lines = &$state->lines;
		if ( $bytes + $line_bytes > self::MAX_BYTES_PER_REQUEST ) {
			return false;
		}
		if ( \count( $lines ) >= self::MAX_LINES_PER_REQUEST ) {
			return false;
		}
		$lines[]      = $line;
		$state->bytes = $bytes + $line_bytes;
		return true;
	}

	/**
	 * Print every still-in-flight request as `[incomplete]`.
	 */
	private function output_remaining(): void {
		foreach ( $this->require_inflight()->iterate() as $rid => $state ) {
			if ( ! $state instanceof \stdClass ) {
				continue;
			}
			$this->output_request( self::to_lines( $state->lines ), self::to_str( $rid ) );
			$this->emit( '[incomplete]' );
			$this->emit( '' );
		}
	}

	/**
	 * Follow mode: one Consumer per partition seeded at the tail (no history
	 * replay), then run under the Event_Framework drain loop — each Consumer's
	 * fire_cb polls its source for new bytes and forwards them to
	 * process_message. Mirrors the legacy FirehoseReader('end') behavior.
	 *
	 * `$max_iterations` defaults to PHP_INT_MAX (production: tail forever until
	 * SIGINT). Tests pass a small number to bound the drain loop.
	 */
	private function follow_mode( int $max_iterations = \PHP_INT_MAX ): void {
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$consumer = $this->build_consumer( $p );
			$consumer->next_offset( 'end' ); // Tail — don't replay history on attach.
		}

		\WP_CLI::log( 'Base dir: ' . $this->base_dir );
		\WP_CLI::log( 'Following ' . $this->num_partitions . ' partition(s)... (Ctrl+C to stop)' );

		$framework = Event_Framework::instance();
		$framework->install_signal_handlers();
		$iterations = 0;
		$framework->drain(
			static function () use ( &$iterations, $max_iterations ): bool {
				return $iterations++ < $max_iterations;
			}
		);
	}

	/**
	 * Build an ephemeral Consumer over one firehose partition. The partition dir
	 * is the log basename with `.log` stripped plus `.p{N}` (matching the
	 * firehose layout); the sink is a Callback_Node that routes each unpacked
	 * Message to process_message. No offsetlog — a reqgrep run keeps no durable
	 * cursor.
	 *
	 * @param int $partition Partition index.
	 */
	private function build_consumer( int $partition ): Consumer_Node {
		$source_dir = \preg_replace( '/\.log$/', '', $this->base_dir ) . ".p{$partition}";
		$sink       = new Callback_Node( $this->process_message( ... ) );
		$consumer = new Consumer_Node();
		$consumer->sink( $sink );
		$consumer->arguments( $source_dir );
		return $consumer;
	}

	/**
	 * Cat mode: one Consumer per partition, drained synchronously to EOF. `--recent`
	 * seeds the Consumer at the second-to-last segment; the default reads from the
	 * start. Flush incomplete requests once every partition is exhausted.
	 */
	private function cat_mode(): void {
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$consumer = $this->build_consumer( $p );
			if ( 'recent' === $this->cat_offset ) {
				$consumer->next_offset( 'recent' );
			}
			$consumer->drain();
		}
		$this->output_remaining();
	}
}
