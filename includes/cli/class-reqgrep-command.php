<?php
/**
 * Reqgrep_Command: the `wp nodes reqgrep` subcommand — filter the firehose by
 * request id, URL, or any text, and print each matching request as an indented
 * lifecycle tree.
 *
 * This command owns reading and rendering; `Reqgrep_Core` owns grouping. Every
 * read path funnels lines into `Reqgrep_Core::push()`, which decides which lines
 * belong to which request and when one is complete, so the command and the
 * `performance` CI's `request_grep` verb agree byte-for-byte. The caps on an
 * in-progress request — bytes, lines, retained history lines — live there too.
 *
 * Three read paths share one graph shape: a source node whose sink is a
 * `Callback_Node` feeding `process_message()`.
 *
 *  - cat (the default) and `--recent`: one `Consumer_Node` per firehose
 *    partition, driven to EOF by the Consumer's synchronous `drain()`.
 *    `--recent` seeds the cursor at the second-to-last segment; the default
 *    reads from the start.
 *  - `--follow`: the same Consumers seeded at the tail, run under the
 *    `Event_Framework` drain loop, where each Consumer's `fire_cb` polls for new
 *    bytes until SIGINT.
 *  - stdin: a `Stdin_Node` over a pipe or a redirected file, detected by fstat.
 *
 * Every firehose line is a 7-field positional `Message` envelope
 * (`Message::packed`): the entry hash sits at `Message::VALUE`, the request id
 * at `Message::KEY`. No legacy entry-hash format is read.
 *
 * The partition dirs come from `Log_Manager::firehose_dirs()`, which owns the
 * layout; `--firehose` overrides the base and must resolve inside
 * `Config::get_logs_directory()`.
 *
 * Output flows through `$stdout` — a `Stdout_Node` that fwrites straight to
 * STDOUT, bypassing PHP's output buffers. Tests swap in a capturing node.
 *
 * @package Newspack_Event_Logger_Nodes
 * @phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not web
 */

namespace Newspack_Event_Logger_Nodes\CLI;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Reqgrep_Core;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\LRU_Cache;
use Newspack_Nodes\Message;
use Newspack_Nodes\Stdin_Node;
use Newspack_Nodes\Stdout_Node;

/**
 * `wp nodes reqgrep` — read the firehose and print the requests that match.
 *
 * One instance per invocation. `__invoke()` parses the flags, builds the
 * in-flight cache and the `Reqgrep_Core` engine, then dispatches to stdin,
 * follow, or cat mode. The `fmt_*` fields are per-request rendering state that
 * `output_request()` resets before each tree.
 */
class Reqgrep_Command {

	/**
	 * In-flight LRU geometry: 100 items × 3 buckets = 300 slots, well above the
	 * typical PHP-FPM concurrency ceiling. Anything that falls out of the oldest
	 * bucket is printed as `[incomplete]`.
	 */
	private const INFLIGHT_BUCKET_SIZE = 100;

	/** Live buckets in the in-flight cache; the oldest is evicted on rotation. */
	private const INFLIGHT_NUM_BUCKETS = 3;

	/** Seconds between in-flight cache rotations (60 × 3 = 180s idle ceiling). */
	private const INFLIGHT_ROTATE_INTERVAL = 60.0;

	/**
	 * Output sink — lazily a Stdout_Node in production (see emit()); tests swap in
	 * a capturing Callback_Node. Public so the test harness can substitute the
	 * terminal sink without short-circuiting the rest of the emit path.
	 */
	public ?\Newspack_Nodes\Node $stdout = null;

	/** Firehose base path — the `--firehose` override, else the configured logs dir. Reported by follow mode. */
	private string $base_dir = '';

	/** History bucket size (lines per bucket). */
	private int $bucket_size = 250;

	/**
	 * Cat-mode starting offset: 'start' (default; full grep semantics) or
	 * 'recent' (only the second-to-last segment and newer — fast lookup).
	 */
	private string $cat_offset = 'start';

	/** The outermost pair: the request itself, which spans any break in the record. */
	private const OUTERMOST_PAIR = 'process';

	/** Keys announcing dropped or merged entries — nothing before one can contain anything after. */
	private const SEQUENCE_BREAK_KEYS = [ 'entries (lost)', 'entries (aggregated)' ];

	/** True once a request has been printed, so the rule falls BETWEEN requests only. */
	private bool $fmt_printed_request = false;

	/** Formatting state — current indent column. */
	private int $fmt_indent = 0;

	/**
	 * Open `(start)` spans, innermost last, as base names — the LIFO a `(complete)` matches
	 * against BY NAME. Depth is what indents, so this is the indent, not a counter.
	 *
	 * @var list<string>
	 */
	private array $fmt_pairs = [];

	/** Formatting state — last seen timestamp. */
	private float $fmt_last_timestamp = 0;

	/** Grouping/matching engine (shared with the request_grep verb); built by init_core(). */
	private ?Reqgrep_Core $core = null;

	/** True if --incomplete was passed. */
	private bool $incomplete = false;

	/** In-flight matched requests, shared with Reqgrep_Core. Each value is stdClass {lines:array, bytes:int, clipped?:bool}. */
	private ?LRU_Cache $inflight = null;

	/** History bucket count. */
	private int $num_buckets = 10;

	/** @var array<int,string> Firehose partition dirs to walk, indexed by partition. */
	private array $partition_dirs = [];

	/** Search pattern (positional arg, default '.'). */
	private string $pattern = '.';

	/** True if --raw was passed. */
	private bool $raw = false;

	/**
	 * Filter firehose JSONL logs by request id or pattern.
	 *
	 * Collects every entry sharing a request id once any line for that rid
	 * matches the pattern, then prints them in chronological order with
	 * indentation reflecting the (start)/(complete) tree.
	 *
	 * Piped or redirected stdin wins over every other source: with data on
	 * stdin the command reads that and ignores --follow and --recent.
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
	 * : Lines per bucket for the history buffer. Clamped to 1-10000.
	 * ---
	 * default: 250
	 * ---
	 *
	 * [--num-buckets=<count>]
	 * : Number of history buckets retained. Clamped to 1-100.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--firehose=<path>]
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
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->pattern       = $args[0] ?? '.';
		$this->incomplete    = isset( $assoc_args['incomplete'] );
		$this->raw           = isset( $assoc_args['raw'] );
		$bucket_size_arg     = $assoc_args['bucket-size'] ?? 250;
		$num_buckets_arg     = $assoc_args['num-buckets'] ?? 10;
		$this->bucket_size   = \max( 1, \min( 10000, Core::num_int( $bucket_size_arg ) ) );
		$this->num_buckets   = \max( 1, \min( 100, Core::num_int( $num_buckets_arg ) ) );
		$follow              = isset( $assoc_args['follow'] );
		$this->cat_offset    = isset( $assoc_args['recent'] ) ? 'recent' : 'start';

		// Validate --firehose FIRST: dirs opened must be the canonical path.
		$override = '';
		if ( isset( $assoc_args['firehose'] ) ) {
			$path_value = Core::str( $assoc_args['firehose'] );
			$real_path  = \realpath( $path_value );
			if ( false === $real_path ) {
				\WP_CLI::error( 'Invalid path: ' . $path_value );
				return;
			}
			if ( 0 !== \strpos( $real_path, Config::get_logs_directory() ) ) {
				\WP_CLI::error( 'Path must be within the logs directory.' );
				return;
			}
			$override = $real_path;
		}
		$this->partition_dirs = Log_Manager::firehose_dirs( $override );
		$this->base_dir       = '' !== $override ? $override : $this->partition_dirs[0];

		// LRU_Cache: 300 slots, 60s rotation, on-evict prints [incomplete].
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
		$this->init_core();

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
	 * Cat mode: one Consumer per partition, drained synchronously to EOF. `--recent`
	 * seeds the Consumer at the second-to-last segment; the default reads from the
	 * start. Flush incomplete requests once every partition is exhausted.
	 */
	private function cat_mode(): void {
		foreach ( $this->partition_dirs as $dir ) {
			$consumer = $this->build_consumer( $dir );
			if ( 'recent' === $this->cat_offset ) {
				$consumer->next_offset( 'recent' );
			}
			$consumer->drain();
		}
		$this->output_remaining();
	}

	/**
	 * Follow mode: one Consumer per partition seeded at the tail (no history
	 * replay), then run under the Event_Framework drain loop — each Consumer's
	 * fire_cb polls its source for new bytes and forwards them to
	 * process_message.
	 *
	 * Signal handlers are installed first, so Ctrl+C ends the drain loop instead
	 * of killing the process mid-write.
	 *
	 * @param int $max_iterations Drain-loop iteration budget. PHP_INT_MAX in
	 *                            production (tail until SIGINT); tests pass a
	 *                            small number to bound the loop.
	 */
	private function follow_mode( int $max_iterations = \PHP_INT_MAX ): void {
		foreach ( $this->partition_dirs as $dir ) {
			$consumer = $this->build_consumer( $dir );
			$consumer->next_offset( 'end' ); // Tail — don't replay history on attach.
		}

		\WP_CLI::log( 'Base dir: ' . $this->base_dir );
		\WP_CLI::log( 'Following ' . \count( $this->partition_dirs ) . ' partition(s)... (Ctrl+C to stop)' );

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
	 * Build an ephemeral Consumer over one firehose partition dir (resolved by
	 * Log_Manager::firehose_dirs, which owns the layout). The sink is a
	 * Callback_Node that routes each unpacked Message to process_message. No
	 * offsetlog — a reqgrep run keeps no durable cursor.
	 *
	 * The sink must be attached before `arguments()`, which is where the Consumer
	 * builds its source Partition and hands the sink down to it.
	 *
	 * @param string $source_dir Concrete partition dir.
	 * @return Consumer_Node The wired Consumer, ready for next_offset() or drain().
	 */
	private function build_consumer( string $source_dir ): Consumer_Node {
		$sink = new Callback_Node( $this->process_message( ... ) );
		$consumer = new Consumer_Node();
		$consumer->sink( $sink );
		$consumer->arguments( [ $source_dir ] );
		return $consumer;
	}

	/**
	 * Process one unpacked firehose Message: the entry hash sits at
	 * Message::VALUE, the request id at Message::KEY. An entry that is not an
	 * array, or one with no rid, is dropped.
	 *
	 * The envelope is re-packed before it reaches the engine, because the packed
	 * line is what the pattern matches and what the engine hands back — raw mode
	 * echoes it verbatim, formatted mode unpacks it again.
	 *
	 * @param array<int,mixed> $message The 7-field positional Message array.
	 */
	private function process_message( array $message ): void {
		$entry = $message[ Message::VALUE ];
		$rid   = Core::as_string( $message[ Message::KEY ] ?? '' );
		if ( ! \is_array( $entry ) || '' === $rid ) {
			return;
		}
		$this->require_core()->push( $entry, $rid, Message::packed( $message ) );
	}

	/**
	 * Narrow the setup-assigned engine to non-null; null means a read ran before
	 * init_core().
	 *
	 * @throws \RuntimeException If init_core() has not run yet.
	 */
	private function require_core(): Reqgrep_Core {
		if ( null === $this->core ) {
			throw new \RuntimeException( 'reqgrep core not initialized' );
		}
		return $this->core;
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
			$line = \trim( Core::as_string( $message[ Message::VALUE ] ) );
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
	 * Print every still-in-flight request as `[incomplete]`. Cat and stdin modes
	 * call this once their sources are exhausted, so a request whose
	 * `process (complete)` never arrived is still shown rather than dropped.
	 */
	private function output_remaining(): void {
		foreach ( $this->require_inflight()->iterate() as $rid => $state ) {
			if ( ! $state instanceof \stdClass ) {
				continue;
			}
			$this->output_request( self::to_lines( $state->lines ), Core::as_string( $rid ) );
			$this->emit( '[incomplete]' );
			$this->emit( '' );
		}
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
	 * @return bool True when the stream is a pipe or a regular file.
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
	 * Build the shared grouping/matching engine from the parsed run config. Its
	 * on_complete emits the assembled request (unless --incomplete suppresses
	 * completed output); on_history_miss surfaces the tune-your-buckets warning.
	 * The engine shares the LRU_Cache the on-evict callback drives, so output_remaining
	 * still walks $this->inflight for the [incomplete] tail.
	 */
	private function init_core(): void {
		$on_complete = function ( array $lines, string $rid ): void {
			if ( ! $this->incomplete ) {
				$this->output_request( self::to_lines( $lines ), $rid );
			}
		};
		$on_miss = static function (): void {
			\WP_CLI::warning( "Couldn't find request start in history - try increasing --bucket-size or --num-buckets" );
		};
		$this->core = new Reqgrep_Core(
			$this->pattern,
			$this->require_inflight(),
			$this->bucket_size,
			$this->num_buckets,
			$on_complete,
			$on_miss
		);
	}

	/**
	 * Narrow the run-setup-assigned `$inflight` cache to non-null. The cache is
	 * built in the command's setup before any line processing; a null here means
	 * a caller invoked a processing method before setup, which is a bug.
	 *
	 * @throws \RuntimeException If the cache has not been built yet.
	 */
	private function require_inflight(): LRU_Cache {
		if ( null === $this->inflight ) {
			throw new \RuntimeException( 'in-flight cache not initialized' );
		}
		return $this->inflight;
	}

	/**
	 * Narrow a state object's `->lines` value to a list of strings for
	 * output_request. `Reqgrep_Core::append_to_state()` only ever appends
	 * strings, so this is a type narrowing, not a filter that drops real data.
	 *
	 * @param mixed $value Decoded value.
	 * @return array<int,string>
	 */
	private static function to_lines( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		return \array_values( \array_filter( $value, 'is_string' ) );
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
		$this->fmt_pairs          = [];
		$this->fmt_last_timestamp = 0;

		// The rule marks the real boundary: one request ends, another begins.
		if ( $this->fmt_printed_request ) {
			$this->emit( '    ' . \str_repeat( '#', 60 ) );
			$this->emit( '' );
		}
		$this->fmt_printed_request = true;

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
	 * Format one log entry as a display line and advance the rendering state.
	 *
	 * The rules, in the order the code applies them:
	 *
	 *  - Indent: indent_for() places the line against the open-span stack.
	 *  - Clock: the timestamp column prints only when it advances at 0.1-second
	 *    resolution, keeping repeated same-tick entries readable.
	 *  - Gaps: elapsed whole seconds render as dot rows at escalating intervals
	 *    (1s, then 10s, then 100s, …) so a multi-day gap costs O(log gap) rows.
	 *  - Body: an array `m` pretty-prints as JSON, and every continuation line of
	 *    a multi-line body is padded to the message column.
	 *  - Suffix: `duration_ms` and `peak_mb` trail the line when present.
	 *
	 * @param array<int|string,mixed> $entry Decoded JSON entry.
	 * @return string Formatted output line.
	 */
	private function format_entry( array $entry ): string {
		$number = Core::num_int( $entry['n'] ?? 0 );
		$ts     = Core::num_float( $entry['ts'] ?? 0 );
		$key    = Core::as_string( $entry['k'] ?? '' );

		$this->fmt_indent = $this->indent_for( $key );

		$output = '';

		// Timestamp column — only print when it advances at 0.1s resolution.
		$time_str = '';
		if ( (int) ( $ts * 10 ) > (int) ( $this->fmt_last_timestamp * 10 ) ) {
			$tenth    = (int) ( ( $ts - \floor( $ts ) ) * 10 );
			$time_str = \gmdate( 'Y-m-d H:i:s', (int) $ts ) . ".{$tenth}";

			// Dot rows at escalating intervals so long gaps stay O(log gap).
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

		// Build message body. Arrays pretty-print as JSON; strings verbatim.
		$message = '';
		if ( isset( $entry['m'] ) ) {
			if ( \is_array( $entry['m'] ) ) {
				$message = \wp_json_encode( $entry['m'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '';
			} else {
				$message = Core::as_string( $entry['m'] );
			}
		}

		$suffix = '';
		if ( isset( $entry['duration_ms'] ) ) {
			$suffix .= ' (' . \number_format( Core::num_float( $entry['duration_ms'] ), 2 ) . 'ms)';
		}
		if ( isset( $entry['peak_mb'] ) ) {
			$suffix .= ' [' . Core::as_string( $entry['peak_mb'] ) . 'MB]';
		}

		$prefix = \sprintf(
			"%4d: %22s %s%s:",
			$number,
			$time_str,
			\str_repeat( ' ', $this->fmt_indent ),
			$key
		);

		// Multi-line 'm': pad continuation lines to the message column.
		if ( false !== \strpos( $message, "\n" ) ) {
			$pad   = \str_repeat( ' ', \strlen( $prefix ) );
			$lines = \explode( "\n", $message );
			$message   = $lines[0];
			for ( $i = 1, $c = \count( $lines ); $i < $c; $i++ ) {
				$message .= "\n" . $pad . $lines[ $i ];
			}
		}

		$output .= $prefix . $message . $suffix;

		$this->fmt_last_timestamp = $ts;

		return \rtrim( $output, "\n" );
	}

	/**
	 * The indent column for one entry, and the pair-stack move that goes with it — the CLI half
	 * of the dashboard's `computeIndentedEntries()` (`src/overview/utils/logEntryUtils.js`), which
	 * is the canonical reading of this log. Both must agree, or the same request reads two ways.
	 *
	 * A `(complete)` matches the nearest open `(start)` OF THE SAME NAME and closes only that one,
	 * leaving children that outlived their parent where they are; with no match it is orphaned and
	 * prints at the top column. Names, not arithmetic, are what let a request survive an embedded
	 * engine trace whose spans are numbered from 1 and share names with the request's own.
	 *
	 * A break keyword closes every span but the request itself, which does span the break.
	 */
	private function indent_for( string $key ): int {
		if ( \in_array( $key, self::SEQUENCE_BREAK_KEYS, true ) ) {
			$outermost       = ( [] !== $this->fmt_pairs && self::OUTERMOST_PAIR === $this->fmt_pairs[0] ) ? 1 : 0;
			$this->fmt_pairs = \array_slice( $this->fmt_pairs, 0, $outermost );
		}
		if ( \preg_match( '/^(.+?) \(complete\)$/', $key, $matches ) ) {
			$found = \array_keys( $this->fmt_pairs, $matches[1], true );
			if ( [] === $found ) {
				return 0; // Orphaned: its (start) is not in this request.
			}
			$at = \end( $found );
			\array_splice( $this->fmt_pairs, $at, 1 );
			return $at * 4;
		}
		$indent = \count( $this->fmt_pairs ) * 4;
		if ( \preg_match( '/^(.+?) \(start\)$/', $key, $matches ) ) {
			$this->fmt_pairs[] = $matches[1];
		}
		return $indent;
	}

	/**
	 * Emit one line to the output node — `$stdout`, lazily a `Stdout_Node` unless
	 * a test swapped it. `Stdout_Node::fill()` fwrites the VALUE verbatim and
	 * appends nothing, so `$text` carries whatever layout the caller wants.
	 *
	 * @param string $text Text to write.
	 */
	private function emit( string $text ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		// Stdout_Node writes the VALUE verbatim; nothing else terminates.
		$message[ Message::VALUE ] = \rtrim( $text, "\n" ) . "\n";
		( $this->stdout ??= new Stdout_Node() )->fill( $message );
	}
}
