<?php
/**
 * ReqgrepCommand: WP-CLI subcommand for filtering firehose JSONL by request id
 * (or any pattern). Verbatim port of Performance Logger's ReqgrepCommand
 * (`newspack-performance-logger/includes/cli/class-reqgrep-command.php`),
 * adapted to the Newspack Nodes runtime:
 *
 *  - Walks `Partition` segments directly (`get_segments()` + `read_at()`).
 *  - Live follow uses `Tail` semantics — but since reqgrep is a one-shot CLI
 *    tool we drive segment iteration synchronously rather than registering
 *    Tail with the EventFramework. Each poll iteration scans the partition's
 *    segments and emits new bytes since the last scan.
 *  - Logs path: `Config::get_logs_directory() . '/firehose.log'` (relocated
 *    from event-logger plugin to event-logger-nodes plugin).
 *  - Namespace: `Newspack_Event_Logger_Nodes\CLI`. Uses the local LruCache
 *    port (no dependency on the legacy plugin).
 *
 * Behaviour preserved 1:1:
 *  - 300-slot 3-bucket × 100 LruCache with 60-second timed rotation; on-evict
 *    callback prints `[incomplete]` and drops.
 *  - Per-rid byte cap MAX_BYTES_PER_REQUEST = 10MB.
 *  - Line caps MAX_LINES_PER_REQUEST = 20000, MAX_LINES_PER_REQUEST_IN_HISTORY = 10000.
 *  - Output buffer drain (`ob_get_level` loop, capped at 16 iterations).
 *  - Indent state machine: `(start)` increases indent by 4, `(complete)`
 *    decreases by 4 (clamped at 0).
 *  - 0.1-second timestamp resolution display.
 *  - Escalating-interval dot rows (1s, 10s, 100s, ...) so multi-day gaps don't
 *    blow up output.
 *  - peak_mb / duration_ms suffix on (complete) lines.
 *  - Multi-line message indentation aligned to the prefix width.
 *  - `#` separator on rid reset (when message number rewinds).
 *  - stdin pipe-mode (S_IFIFO + S_IFREG detection).
 *
 * Capability: requires `manage_options` (the standard WP-CLI invariant —
 * WP-CLI calls run as root unless the user explicitly passes `--user=`, but
 * we keep the explicit check so future REST gateway integration shares one
 * authorization path).
 *
 * @package Newspack_Event_Logger_Nodes
 * @phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not web
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Intentional log file access
 */

namespace Newspack_Event_Logger_Nodes\CLI;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\LRU_Cache;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;

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

	/** Output-buffer drain cap (defends against non-erasable userland buffers). */
	private const OB_DRAIN_CAP = 16;

	/** Per-read chunk for the cat/follow reader — bounds CLI memory on multi-GB segments. */
	private const READ_CHUNK_BYTES = 10 * 1024 * 1024;

	/** Live-follow sleep when no segments produced new bytes (microseconds). */
	private const FOLLOW_IDLE_USLEEP = 100_000;

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

	/** @var array<int, \Newspack_Nodes\Partition_Node> Cached Partition instances per index. */
	private array $partition_cache = [];

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
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	/**
	 * When true (production default), __invoke drains all plugin-installed
	 * output buffers before streaming begins. Tests set this to false to
	 * preserve PHPUnit's own ob layer.
	 */
	private bool $drain_buffers_on_invoke = true;

	/**
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Drain any plugin-installed output buffers so streamed echoes don't get
		// captured into a userspace buffer that grows until OOM.
		if ( $this->drain_buffers_on_invoke ) {
			$this->drain_output_buffers();
		}

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
					echo "[incomplete]\n\n";
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

	/**
	 * Drain plugin-installed output buffers up to OB_DRAIN_CAP iterations so
	 * echo lines stream straight to the terminal instead of accumulating in a
	 * buffer.
	 */
	private function drain_output_buffers(): void {
		$safety = self::OB_DRAIN_CAP;
		while ( \ob_get_level() > 0 && $safety-- > 0 ) {
			if ( ! @\ob_end_clean() ) {
				break; // Non-erasable buffer — give up.
			}
		}
	}

	/**
	 * Output a completed request — either the raw JSON lines (raw mode) or the
	 * formatted indented tree.
	 *
	 * Raw mode echoes spooled lines verbatim: whatever shape came in is what
	 * goes out (wire-format envelope for disk reads, entry-shape JSON for
	 * stdin pipes). Formatted mode decodes each line and unwraps envelopes.
	 *
	 * @param array<string> $lines JSON lines for the request.
	 * @param string        $rid   Request id, used for the formatted header.
	 */
	private function output_request( array $lines, string $rid ): void {
		if ( $this->raw ) {
			foreach ( $lines as $line ) {
				echo $line . "\n";
			}
			echo "\n";
			return;
		}

		$this->fmt_indent         = 0;
		$this->fmt_last_number    = 0;
		$this->fmt_last_timestamp = 0;

		if ( '' !== $rid ) {
			echo \sprintf( "      %22s request_id:%s\n", '', $rid );
		}

		foreach ( $lines as $line ) {
			$decoded = \json_decode( $line, true, 64 );
			if ( ! \is_array( $decoded ) ) {
				echo $line . "\n";
				continue;
			}
			// Unwrap envelope (positional list with VALUE at index 6) vs entry
			// (hash). Mirrors the detection in process_line().
			if ( \array_is_list( $decoded ) && isset( $decoded[ \Newspack_Nodes\Message::VALUE ] ) ) {
				$entry = $decoded[ \Newspack_Nodes\Message::VALUE ];
			} else {
				$entry = $decoded;
			}
			if ( ! \is_array( $entry ) ) {
				echo $line . "\n";
				continue;
			}
			echo $this->format_entry( $entry ) . "\n";
		}
		echo "\n";
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
	 * Stdin pipe mode: read line-by-line from `$stream`, run each through
	 * process_line, then flush incomplete requests so the operator can see
	 * partial state.
	 *
	 * Defaults to STDIN; tests inject a `fopen('php://memory', 'r+')` filled
	 * with fixture lines to drive the loop deterministically.
	 *
	 * @param resource|null $stream Source stream (defaults to STDIN).
	 */
	private function process_stdin( $stream = null ): void {
		if ( null === $stream ) {
			$stream = \defined( 'STDIN' ) ? STDIN : null;
			if ( null === $stream ) {
				return;
			}
		}
		while ( ( $line = \fgets( $stream ) ) !== false ) {
			$this->process_line( $line );
		}
		$this->output_remaining();
	}

	/**
	 * Process a single firehose JSONL line.
	 *
	 * State machine:
	 *  - Already-tracked rid: append; print on `process (complete)`.
	 *  - New rid + line matches pattern: pull history, append, start tracking.
	 *  - No match: stash in history (bounded by num_buckets × bucket_size).
	 *
	 * @param string $line Raw log line (with or without trailing newline).
	 */
	private function process_line( string $line ): void {
		$line = \trim( $line );
		if ( '' === $line ) {
			return;
		}

		// Lines on disk are 7-element positional Message envelopes (the firehose
		// writes packed Messages); stdin pipes and legacy callers may pass
		// entry-shape JSON directly. Detect either: a list-shaped decode is the
		// envelope (entry payload at Message::VALUE; rid at Message::KEY); a
		// hash carries rid inside. We decode once for control flow but spool
		// $line verbatim — raw mode echoes whatever came in, and formatted
		// mode decodes again at output time.
		$decoded = \json_decode( $line, true, 64 );
		if ( ! \is_array( $decoded ) ) {
			return;
		}
		if ( \array_is_list( $decoded ) && isset( $decoded[ \Newspack_Nodes\Message::VALUE ] ) ) {
			$entry = $decoded[ \Newspack_Nodes\Message::VALUE ];
			$rid   = self::to_str( $decoded[ \Newspack_Nodes\Message::KEY ] ?? '' );
		} else {
			$entry = $decoded;
			$rid   = self::to_str( $entry['rid'] ?? '' );
		}
		if ( ! \is_array( $entry ) || '' === $rid ) {
			return;
		}

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
			echo "[incomplete]\n\n";
		}
	}

	/**
	 * Follow mode: open a cursor per partition pointing at the tail of the
	 * newest segment, then loop polling each partition for new bytes. Mirrors
	 * the legacy FirehoseReader('end') behavior.
	 *
	 * `$max_iterations` defaults to PHP_INT_MAX (production: tail forever
	 * until SIGINT). Tests pass a small number to drive a bounded number of
	 * polls and assert on output without an infinite loop.
	 */
	private function follow_mode( int $max_iterations = \PHP_INT_MAX ): void {
		$cursors = $this->seed_follow_cursors();

		\WP_CLI::log( 'Base dir: ' . $this->base_dir );
		\WP_CLI::log( 'Following ' . $this->num_partitions . ' partition(s)... (Ctrl+C to stop)' );

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$had_data = $this->follow_tick( $cursors );
			if ( ! $had_data ) {
				\usleep( self::FOLLOW_IDLE_USLEEP );
			}
		}
	}

	/**
	 * Build the initial follow-mode cursor map: each partition starts at the
	 * tail of its newest segment so we don't replay history on attach.
	 *
	 * @return array<int,array{seg:int,off:int}>
	 */
	private function seed_follow_cursors(): array {
		$cursors = [];
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$partition = $this->get_partition( $p );
			$segments  = $partition->get_segments( true );
			if ( empty( $segments ) ) {
				$cursors[ $p ] = [ 'seg' => 0, 'off' => 0 ];
				continue;
			}
			$newest        = \end( $segments );
			$cursors[ $p ] = [
				'seg' => $newest['id'],
				'off' => $newest['size'],
			];
		}
		return $cursors;
	}

	/**
	 * Lazily resolve a Partition for a given partition index.
	 *
	 * @param int $partition Partition index.
	 * @return Partition_Node
	 */
	private function get_partition( int $partition ): Partition_Node {
		if ( ! isset( $this->partition_cache[ $partition ] ) ) {
			// Name the sibling after the firehose log basename; suffix with a
			// process+object-id token so a second command run doesn't clash with
			// stale Core registrations.
			$role           = \pathinfo( $this->base_dir, PATHINFO_FILENAME ) ?: 'firehose';
			$instance_token = \getmypid() . '-' . \spl_object_id( $this );
			$p              = new Partition_Node();
			$p->name( "{$role}.{$instance_token}.p{$partition}" );
			// Sibling plumbing: patron-link so dump_metadata hides it from the canvas.
			$p->patron( $p );
			// Rule 4: sink into the interpreter only when one is in scope.
			$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
			if ( null === $p->sink() && null !== $ci ) {
				$p->sink( $ci );
			}
			$flat_dir = \preg_replace( '/\.log$/', '', $this->base_dir );
			$p->arguments( "{$flat_dir}.p{$partition}" );
			$this->partition_cache[ $partition ] = $p;
		}
		return $this->partition_cache[ $partition ];
	}

	/**
	 * One iteration of the follow-mode poll. Mutates `$cursors` in place,
	 * returns true iff any partition yielded new bytes this tick. Extracted
	 * so tests can drive a known number of ticks without a while(true).
	 *
	 * @param array<int,array{seg:int,off:int}> $cursors Per-partition cursor state.
	 */
	private function follow_tick( array &$cursors ): bool {
		$had_data = false;
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$partition = $this->get_partition( $p );
			$segments  = $partition->get_segments( true );
			if ( empty( $segments ) ) {
				continue;
			}

			$cursor = $cursors[ $p ];

			// Walk every segment ≥ cursor.seg; advance cursor as bytes consumed.
			foreach ( $segments as $s ) {
				if ( $s['id'] < $cursor['seg'] ) {
					continue;
				}
				$start = ( $s['id'] === $cursor['seg'] ) ? $cursor['off'] : 0;
				$len   = $s['size'] - $start;
				if ( $len <= 0 ) {
					// Move the cursor forward to the start of the next segment so
					// we don't restart at this seg's tail next iteration.
					if ( $s['id'] > $cursor['seg'] ) {
						$cursor['seg'] = $s['id'];
						$cursor['off'] = 0;
					}
					continue;
				}
				$consumed      = $this->stream_segment_lines( $partition, $s['id'], $start, $len );
				$cursor['seg'] = $s['id'];
				$cursor['off'] = $start + $consumed;
				if ( $consumed > 0 ) {
					$had_data = true;
				}
			}

			$cursors[ $p ] = $cursor;
		}
		return $had_data;
	}

	/**
	 * Read a contiguous byte range from a segment, split into lines, and feed
	 * each to process_line. Returns the number of complete-line bytes consumed
	 * (so callers can advance their cursor).
	 *
	 * Trailing partial lines are NOT consumed — the caller's next poll will
	 * pick them up once the writer flushes a newline.
	 *
	 * @param Partition_Node $partition Partition instance.
	 * @param int       $seg       Segment id.
	 * @param int       $offset    Start offset within segment.
	 * @param int       $length    Bytes to read.
	 * @return int Bytes consumed (offset advance).
	 */
	private function stream_segment_lines( Partition_Node $partition, int $seg, int $offset, int $length ): int {
		if ( $length <= 0 ) {
			return 0;
		}
		// Chunk large ranges to keep memory peak bounded — `read_at` itself
		// is uncapped, but a single fread of an entire multi-GB segment
		// would balloon the CLI process.
		$consumed = 0;
		$pending  = '';
		$max      = self::READ_CHUNK_BYTES;
		while ( $consumed < $length ) {
			$want  = \min( $max, $length - $consumed );
			$bytes = $partition->read_at( $seg, $offset + $consumed, $want );
			if ( '' === $bytes ) {
				break;
			}
			$buffer    = $pending . $bytes;
			$lines     = \explode( "\n", $buffer );
			$pending   = \array_pop( $lines );
			foreach ( $lines as $line ) {
				$this->process_line( $line . "\n" );
			}
			$consumed += \strlen( $bytes );
		}
		// Tell the caller how many bytes lived inside complete lines.
		return $consumed - \strlen( $pending );
	}

	/**
	 * Cat mode: walk each partition's segments oldest-first, stream every line
	 * through process_line, then flush incomplete requests.
	 */
	private function cat_mode(): void {
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$partition = $this->get_partition( $p );
			$segments  = $partition->get_segments( true );
			if ( empty( $segments ) ) {
				continue;
			}

			// Resolve cat-offset starting segment.
			$start_seg = ( 'recent' === $this->cat_offset && \count( $segments ) >= 2 )
				? $segments[ \count( $segments ) - 2 ]['id']
				: $segments[0]['id'];

			foreach ( $segments as $s ) {
				if ( $s['id'] < $start_seg ) {
					continue;
				}
				$this->stream_segment_lines( $partition, $s['id'], 0, $s['size'] );
			}
		}
		$this->output_remaining();
	}
}
