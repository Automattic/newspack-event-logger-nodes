<?php
/**
 * Log Manager — the event logger's per-request firehose writer.
 *
 * A logged request opens the shared `_firehose:topic` Topic, hashes its request
 * id to a partition, and appends one TM_STRUCT Message per log line. Each line
 * lands on disk as a JSON array (Message::packed), keyed by the request id, and
 * `Request_Builder_Node` / `Flame_Builder_Node` reassemble those lines
 * downstream into requests, flame graphs, and stats.
 *
 * This file is also the event logger's public API: Pyrobase, Nuclear Gyrobase,
 * and the substrate's job worker all log through `Log_Manager::instance()`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log manager class.
 *
 * One instance governs one request context. Construction resolves the rule
 * matching REQUEST_URI and starts logging when that rule says `log`; a
 * `skip` rule, no rule at all, `enable_logging` off, or a root process leaves
 * the instance inert and every write returns false.
 *
 * Nested contexts — background jobs, cron, template subprocesses — suspend the
 * active instance onto a LIFO stack and install a fresh one. See
 * begin_job_context() / end_job_context(), the pair the substrate's job worker
 * hooks around every handler.
 */
class Log_Manager {

	/**
	 * PHP error types finish() reports as a fatal crash, read out of
	 * error_get_last() and attached to the final `process (complete)` line.
	 */
	const FATAL_TYPES = [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR ];

	/** @var int Bytes-to-megabytes divisor. */
	private const BYTES_PER_MB = 1024 * 1024;

	/**
	 * Curated $_SERVER keys logged as the single environment_v3 map. The
	 * consumer (Request_Builder_Node) reads REMOTE_ADDR / HTTP_X_FORWARDED_FOR /
	 * HTTP_USER_AGENT / SERVER_NAME / GEOIP_COUNTRY_CODE / HTTP_FROM /
	 * HTTP_X_JA4_HASH; the rest are diagnostics. Keys already carried by the
	 * `request` log line (REQUEST_METHOD / REQUEST_URI / QUERY_STRING) are
	 * intentionally omitted. Perl mirrors this list in Gyrobase::Log — keep the
	 * two IDENTICAL and in sync.
	 *
	 * @var array<int,string>
	 */
	private const ENV_ALLOWLIST = [
		'A8C_PROXIED_REQUEST',
		'ATOMIC_SITE_OPCACHE_MEMORY_MB',
		'CONTENT_LENGTH',
		'CONTENT_TYPE',
		'GEOIP_COUNTRY_CODE',
		'HTTP_FROM',
		'HTTP_HOST',
		'HTTP_REFERER',
		'HTTP_USER_AGENT',
		'HTTP_X_A8C_EDGE_DC',
		'HTTP_X_A8C_REQUEST_ID',
		'HTTP_X_EDGE_BLACKBOX_SCORE',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_IP_PROXY_TYPE',
		'HTTP_X_JA3_HASH',
		'HTTP_X_JA4T_HASH',
		'HTTP_X_JA4T_LITE_HASH',
		'HTTP_X_JA4_HASH',
		'HTTP_X_OPENAI_HOST_HASH',
		'HTTP_X_REQUESTED_WITH',
		'HTTP_X_SUPPORTLOGIN',
		'HTTP_X_TCP_RTT_AVG',
		'HTTP_X_TCP_RTT_MIN',
		'HTTP_X_VALID_CERTIFICATE',
		'HTTP_X_WPLOGIN',
		'NEWSPACK_NODES_WORKER_PARTITION',
		'NEWSPACK_NODES_WORKER_TYPE',
		'REMOTE_ADDR',
		'REMOTE_PORT',
		'REQUEST_SCHEME',
		'REQUEST_TIME',
		'REQUEST_TIME_FLOAT',
		'SERVER_NAME',
		'UNIQUE_ID',
	];

	/** @var int Per-value byte cap for environment_v3 map values. Keeps one long
	 * (client-controllable) value from pushing the encoded map over MAX_DATA_SIZE
	 * and dropping the whole map. 256 keeps the full curated allowlist — even with
	 * several oversized values — comfortably under MAX_DATA_SIZE. */
	private const ENV_VALUE_MAX = 256;

	/** @var int Encoded-data cap in bytes. Headroom under PIPE_BUF (4096), which
	 * is what keeps a lock-free append atomic against every other writer on this
	 * multi-writer log. Payloads that can exceed it belong in
	 * \Newspack_Nodes\Job_Intake::queue(), not here — message() truncates. */
	private const MAX_DATA_SIZE = 3840;

	/** @var int Maximum timer stack depth to prevent unbounded growth. */
	private const MAX_TIMER_DEPTH = 100;

	/** @var int Nanoseconds-to-milliseconds divisor. */
	private const NS_PER_MS = 1_000_000;

	/** @var string Regex for sensitive URL query parameters. */
	private const URL_REDACT_PATTERN = '/([?&])(key|api_key|apikey|token|access_token|auth_token|refresh_token|password|passwd|pwd|secret|api_secret|client_secret|private_key|subscription[_-]?key|bearer|authorization|auth|session|sessionid|credentials)=[^&]*/i';

	/** @var array<int,self> Stack of suspended parent LogManager instances. */
	private static $context_stack = [];

	/** @var self|null The active instance; instance() creates it on demand. */
	private static ?self $instance = null;

	/** @var array<int,array<string,mixed>> LIFO $_SERVER snapshots for begin/end_job_context. */
	private static array $job_server_stack = [];

	/**
	 * The job message the innermost job context was entered with.
	 *
	 * @longform Deliberately NOT stacked. A nested context — evTemplate
	 * rendering inside a job — passes none and inherits this one, which is what
	 * makes the causing record reachable from the innermost trace. The next job
	 * overwrites it rather than exit clearing it, and every job in a worker
	 * enters through before_job, so there is nothing for it to go stale against.
	 *
	 * @var array<int,mixed>
	 */
	private static array $job_message = [];

	/**
	 * Deprecated mirror of is_started(), set with it at construction. The
	 * profiler drop-in on Atomic is served from a read-only /wordpress/mu-plugins
	 * with no override — a regular plugin can be shadowed from
	 * /srv/htdocs/wp-content/plugins, an mu-plugin cannot — so the copy running
	 * there gates on this. Left false it never contributes; mirroring restores
	 * it without instrumenting requests the ruleset declined. Removable once
	 * their deploy replaces the drop-in (2026-08-10).
	 *
	 * @api Read by pre-0.46.0 copies of 00-newspack-profiler.php.
	 * @var bool
	 */
	public $enabled = false;

	/** @var array<string,mixed> Cached config (loaded once at construction). */
	private $config = [];
	/** @var bool finish() has run; nothing more will be written. */
	private $finished = false;

	/** @var bool This context ended without its job completing (cooperative stop). */
	private bool $aborted = false;

	/** @var bool Flush write buffer after every log line (survives OOM/crash). */
	private $flush_every_line = false;
	/** @var int The next line's `n` field, 1-based. */
	private $line_number = 1;

	/** @var bool Append peak_mb to every complete() entry for memory profiling. */
	private $log_memory = false;

	/** @var Rule|null The rule governing this request (null ⇒ no match ⇒ skip). */
	private ?Rule $matched_rule = null;

	/** @var Rule_Matcher|null Built once per request from the autoloaded rule list. */
	private ?Rule_Matcher $matcher = null;
	/** @var int Firehose partition this request's id hashes to. */
	private $partition_idx = 0;
	/** @var string This request's id — the KEY every Message carries. */
	private $request_id = '';
	/** @var float|null hrtime() reading the profiler drop-in took at request start. */
	private $request_time = null;
	/** @var int|float|null Wall-clock request start, from the profiler drop-in. */
	private $request_ts = null;
	/** @var string Sanitized REQUEST_URI, or '/unknown' when there is none. */
	private $request_url = '';

	/** Saved UNIQUE_ID for suspend/resume. */
	private ?string $saved_unique_id = null;
	/** @var bool|null True while logging, false after finish(), null when no rule started it. */
	private $started = null;
	/** @var array<int,array{label: string,ts: int|float,m?: mixed}> Timer-frame stack. */
	private $times = [];
	/** @var \Newspack_Nodes\Topic_Node|null The firehose Topic; null until init_firehose() runs. */
	private $topic = null;

	/**
	 * Resolve this request's context and start logging when a rule allows it.
	 *
	 * Bails inert — never starting — when `enable_logging` is off or the process
	 * runs as root, whose writes would leave root-owned segment files the web
	 * user could never append to. Otherwise it builds the rule matcher, adopts
	 * the profiler drop-in's request-start readings (consuming them from the
	 * `$newspack_profiler` global so a nested context cannot claim them twice),
	 * and starts eagerly when the governing rule says `log`.
	 */
	public function __construct() {
		// Assign self FIRST: load_config() re-enters instance(); null recurses.
		self::$instance = $this;
		$this->config = Config::load_config();
		if ( empty( $this->config['enable_logging'] ) ) {
			return;
		}
		$this->log_memory       = ! empty( $this->config['log_memory'] );
		$this->flush_every_line = ! empty( $this->config['flush_every_line'] );
		if ( \function_exists( 'posix_getuid' ) && 0 === \posix_getuid() ) {
			return;
		}
		$this->matcher = Rule_Set::load()->matcher();

		/** @var array{request_time?: float, request_ts?: int|float}|null $newspack_profiler */
		global $newspack_profiler;
		if ( null !== $newspack_profiler ) {
			$this->request_time = $newspack_profiler['request_time'] ?? null;
			$this->request_ts   = $newspack_profiler['request_ts']   ?? null;
			unset( $newspack_profiler['request_time'], $newspack_profiler['request_ts'] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->request_url = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( Core::as_string( $_SERVER['REQUEST_URI'] ) ) ) : '/unknown';
		if ( $this->matches_url_filter( $this->request_url ) ) {
			$this->started = true;
			$this->enabled = true; // deprecated mirror; see the property.
			\register_shutdown_function( [ $this, 'finish' ] );
			$this->init_firehose();
			$this->log_process();
		}
	}

	/**
	 * Close the request: drain the timer stack, then log memory, resources, and
	 * the final `process (complete)` line before flushing the Topic.
	 *
	 * Registered as a shutdown function by the constructor, so it also runs
	 * after a fatal. When error_get_last() reports one of FATAL_TYPES, the
	 * completion line carries the message, file, line, type, offending plugin
	 * slug, and `error_status` = `F`.
	 *
	 * Idempotent: a second call after the first returns immediately.
	 */
	public function finish(): void {
		if ( $this->finished || ! $this->started ) {
			return;
		}
		$this->finished = true;
		$now = \hrtime( true );
		while ( \count( $this->times ) > 1 ) {
			$this->emit_orphaned_complete( \array_pop( $this->times ), $now );
		}

		$this->message( 'memory', [
			'm' => [
				'peak' => \round( \memory_get_peak_usage( true ) / self::BYTES_PER_MB, 2 ) . 'MB',
				'end'  => \round( \memory_get_usage( true ) / self::BYTES_PER_MB, 2 ) . 'MB',
			],
		] );
		$this->log_resources();
		$complete_extra = [];
		$error          = \error_get_last();
		if ( $error && \in_array( $error['type'], self::FATAL_TYPES, true ) ) {
			$complete_extra['fatal_error']  = \substr( $error['message'], 0, 1024 );
			$complete_extra['fatal_file']   = $error['file'];
			$complete_extra['fatal_line']   = $error['line'];
			$complete_extra['fatal_type']   = $error['type'];
			$complete_extra['fatal_plugin'] = self::extract_plugin_slug( $error['file'] );
			$complete_extra['error_status'] = 'F';
		}

		// A killed job's duration is a fragment: say aborted, never complete.
		if ( $this->aborted ) {
			$complete_extra['error_status'] = 'A';
		}
		$this->complete(
			'process',
			\array_merge( [ 'status_code' => \http_response_code() ?: 0 ], $complete_extra ),
			$this->aborted ? 'aborted' : 'complete'
		);
		$this->topic?->flush();
		$this->started = false;
		// Per-request: a latched flag marks every later request in the process.
		$this->aborted = false;
	}

	/**
	 * Close a labeled operation and log its duration.
	 *
	 * Search runs from the top of the timer stack down to the first frame with
	 * this label. Frames above it never got their own complete() — they drain as
	 * `(orphaned)` lines, innermost first, so an unbalanced caller costs its own
	 * frames and not the enclosing ones. An unknown label matches nothing and
	 * leaves the stack untouched.
	 *
	 * @param string $label Label that was passed to start().
	 * @param array<string,mixed>  $data  Additional data to include in the complete event.
	 */
	public function complete( string $label, array $data = [], string $suffix = 'complete' ): void {
		if ( \count( $this->times ) < 1 ) {
			return;
		}
		$now     = \hrtime( true );
		$start   = $now;
		$removed = [];
		$match   = [];
		for ( $i = \count( $this->times ) - 1; $i >= 0; $i-- ) {
			$entry = $this->times[ $i ];
			if ( $label === $entry['label'] ) {
				$start   = $entry['ts'];
				$removed = \array_splice( $this->times, $i );
				$match   = \array_shift( $removed );
				break;
			}
		}
		for ( $i = \count( $removed ) - 1; $i >= 0; $i-- ) {
			$this->emit_orphaned_complete( $removed[ $i ], $now );
		}
		if ( ! empty( $match ) ) {
			$data['duration_ms'] = \max( 0, ( $now - $start ) / self::NS_PER_MS );
			if ( $this->log_memory ) {
				$data['peak_mb'] = \round( \memory_get_peak_usage( true ) / self::BYTES_PER_MB, 2 );
			}
			$this->message( "{$label} ({$suffix})", $data );
		}
	}

	/**
	 * Emit a single orphaned `(complete)` line for an unclosed timer-stack
	 * frame. Shared by complete()'s mismatched-close drain and finish()'s
	 * end-of-request stack close.
	 *
	 * @param array{label: string, ts: int|float, m?: mixed} $entry Timer-stack frame.
	 * @param int|float $now Reference hrtime() reading.
	 */
	private function emit_orphaned_complete( array $entry, $now ): void {
		$duration_ms = ( $now - $entry['ts'] ) / self::NS_PER_MS;
		$this->message( "{$entry['label']} (complete)", [ 'm' => '(orphaned)', 'duration_ms' => $duration_ms ] );
	}

	/**
	 * Extract plugin slug from a file path.
	 *
	 * @param string $file File path from error_get_last().
	 * @return string|null Plugin slug or null if not in plugins dir.
	 */
	private static function extract_plugin_slug( string $file ): ?string {
		if ( ! \defined( 'WP_PLUGIN_DIR' ) ) {
			return null;
		}
		$plugins_dir = \trailingslashit( WP_PLUGIN_DIR );
		if ( 0 !== \strpos( $file, $plugins_dir ) ) {
			return null;
		}
		$relative = \substr( $file, \strlen( $plugins_dir ) );
		$slug     = \explode( '/', $relative )[0];
		if ( '.php' === \substr( $slug, -4 ) ) {
			$slug = \substr( $slug, 0, -4 );
		}
		return $slug;
	}

	/**
	 * Log an error message.
	 *
	 * @api Used by external plugins.
	 * @param string $message Error message.
	 * @return bool True on success.
	 */
	public function error( string $message ): bool {
		return $this->message( 'error', [ 'm' => $message ] );
	}

	/**
	 * Log a warning message.
	 *
	 * @api Used by external plugins.
	 * @param string $message Warning message.
	 * @return bool True on success.
	 */
	public function warning( string $message ): bool {
		return $this->message( 'warning', [ 'm' => $message ] );
	}

	/**
	 * Log an info message.
	 *
	 * @api Used by external plugins.
	 * @param string $message Info message.
	 * @return bool True on success.
	 */
	public function info( string $message ): bool {
		return $this->message( 'info', [ 'm' => $message ] );
	}

	/**
	 * Log a fleet-alert message to the current request's firehose stream.
	 * `Request_Builder_Node` routes `alert` entries to its `alerts_target` — the
	 * fleet journal — and to nothing else; `error` / `warning` are the keywords
	 * that reach the Error Log.
	 *
	 * @api Used by external plugins.
	 * @param string $message Alert message.
	 * @return bool True on success.
	 */
	public function alert( string $message ): bool {
		return $this->message( 'alert', [ 'm' => $message ] );
	}

	/**
	 * Start timing a labeled operation and push its frame on the timer stack.
	 *
	 * Two budgets guard the stack. Past MAX_TIMER_DEPTH the call is dropped
	 * entirely. A frame is also dropped when the start line itself could not
	 * be written — pair every start() with a complete() carrying the same label.
	 *
	 * @param string $label Label for the timer (e.g., 'query', 'template').
	 * @param array<string,mixed>  $data  Additional data to include in the start event.
	 */
	public function start( string $label, array $data = [] ): void {
		if ( \count( $this->times ) >= self::MAX_TIMER_DEPTH ) {
			return;
		}
		if ( false === $this->message( "{$label} (start)", $data ) ) {
			return;
		}
		$entry = [ 'label' => $label, 'ts' => \hrtime( true ) ];
		if ( ! empty( $data['m'] ) ) {
			$entry['m'] = $data['m'];
		}
		$this->times[] = $entry;
	}

	/**
	 * Firehose dirs indexed by partition, for readers (dashboard grep, reqgrep).
	 *
	 * ONE number answers this, for readers and writers alike: the config's
	 * `num_partitions`. `init_firehose()` hashes the rid over it on every
	 * request, so it is exactly the span that can hold data.
	 *
	 * A topology's `var num_partitions` is its WORKER count and says nothing
	 * about the firehose. Unioning the two let a pinned topology — `hub-control`
	 * pins 1 — argue with the writer about a layout it does not own, and made
	 * every reader parse TSL to ask.
	 *
	 * `$log_path` is `reqgrep --firehose`'s override. A path that already names
	 * a partition is an instruction: it answers for that partition alone, keyed
	 * by the index it names. A bare base is a hint about where the logs live,
	 * so the `.p{N}` span is derived from it here — the one place that knows
	 * the layout.
	 *
	 * @param string $log_path Partition dir or log base overriding the configured layout; '' uses the config.
	 * @return array<int,string> Partition index => directory.
	 */
	public static function firehose_dirs( string $log_path = '' ): array {
		if ( '' !== $log_path && \preg_match( '/\.p(\d+)$/', $log_path, $named ) ) {
			return [ (int) $named[1] => $log_path ];
		}
		$template = '' === $log_path
			? self::firehose_dir_template( Config::get_logs_directory() )
			: $log_path . '.p{partition}';
		$count = Bootstrap::global_num_partitions();
		$dirs  = [];
		for ( $p = 0; $p < $count; $p++ ) {
			$dirs[ $p ] = Core::resolve_partition_template( $template, $p );
		}
		return $dirs;
	}

	/**
	 * URL hash - 12-char FNV-1a hash. The shared URL identity primitive: the
	 * firehose per-URL key (Request_Builder_Node / Flame_Builder_Node) and the
	 * per-URL rule id (Rule_Set::id_for) both derive from this one function. Note
	 * they hash different inputs — a rule id hashes a PATTERN (`/shop`), a stats
	 * bucket hashes a concrete request URL (`/shop/item/5`) — so the two keys
	 * coincide only for an exact (`?`-terminated) rule pattern; don't join them.
	 *
	 * @param string $url URL to hash.
	 * @return string 12-character hex hash.
	 */
	public static function url_hash( string $url ): string {
		// Hash full string: surviving '?' is ?worker_type mark — don't strip.
		$hash1 = self::fnv1a32( $url );
		$hash2 = self::fnv1a32( $url, $hash1 ^ 0x811c9dc5 );
		return \sprintf( '%08x%04x', $hash1, $hash2 & 0xFFFF );
	}

	/**
	 * FNV-1a 32-bit hash.
	 *
	 * @param string $str  Input string.
	 * @param int    $seed Offset basis.
	 * @return int 32-bit hash.
	 */
	private static function fnv1a32( string $str, int $seed = 2166136261 ): int {
		$hash = $seed;
		$len  = \strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash ^= \ord( $str[ $i ] );
			$hash  = ( $hash * 16777619 ) & 0xFFFFFFFF;
		}
		return $hash;
	}

	/**
	 * Leave a background-job request context: resume the parent LogManager and
	 * restore the $_SERVER snapshot pushed by begin_job_context(). The symmetric
	 * pair to begin_job_context() — safe to call on an empty stack (no-op restore)
	 * so a throwing/unpaired begin can't fatal here.
	 *
	 * Fires `newspack_event_logger_nodes_scope_changed`, which `App\Core` uses to
	 * rebind its hook instrumentation to the restored scope's rule.
	 *
	 * A null $outcome means the job did not finish — but ONLY when the caller
	 * actually supplied one. A bare `end_job_context()` is a context restore
	 * (the reconcile bridge does exactly that around every WP-Cron pass) and
	 * must not read as an abort, so the arity is the discriminator: the
	 * `after_job` hook is registered with accepted_args 3 and always passes it.
	 *
	 * @param string                    $handler Handler name; unused, but the action passes it first.
	 * @param string                    $id      Job identity; unused, but follows $handler everywhere.
	 * @param array<string,mixed>|null $outcome Job_Worker_Node's classified outcome; null when the job did not finish.
	 */
	public static function end_job_context( string $handler = '', string $id = '', ?array $outcome = null ): void {
		unset( $handler, $id );
		// @longform Unpaired: a before_job listener declined the job, so no
		// context was opened. resume() would finish the ENCLOSING context — the
		// worker's own request — and pop an empty stack. begin_job_context()
		// pushes its $_SERVER snapshot first: the stack IS the pairing record.
		if ( [] === self::$job_server_stack ) {
			return;
		}
		$reported = \func_num_args() >= 3;
		if ( $reported && null === $outcome && null !== self::$instance ) {
			self::$instance->aborted = true;
		}
		self::resume();
		if ( ! empty( self::$job_server_stack ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restoring saved value.
			$_SERVER = \array_pop( self::$job_server_stack );
		}
		\do_action( 'newspack_event_logger_nodes_scope_changed' );
	}

	/**
	 * Finish the current LogManager and restore the parent from the stack.
	 *
	 * The current context gets finish() called (logging process complete),
	 * then the parent context is restored as the active instance.
	 */
	public static function resume(): void {
		if ( null !== self::$instance ) {
			self::$instance->finish();
		}
		self::$instance = ! empty( self::$context_stack )
			? \array_pop( self::$context_stack )
			: null;
		// Restore UNIQUE_ID from parent context.
		if ( null !== self::$instance && isset( self::$instance->saved_unique_id ) ) {
			$_SERVER['UNIQUE_ID'] = self::$instance->saved_unique_id;
		}
	}

	/**
	 * `newspack_nodes/job_worker/before_job` listener — the substrate runs it as
	 * a FILTER so a listener can decline a job outright.
	 *
	 * Opens the job's context unless an earlier listener already declined, in
	 * which case opening one would write the record the decline exists to
	 * prevent. The decision passes through untouched: whether a job belongs to
	 * this host is the owning plugin's question, and this plugin never answers
	 * it — it has no notion of a publication, only of a request.
	 *
	 * @param mixed                $run     False once any listener has declined.
	 * @param string               $handler Job handler name.
	 * @param string               $id      First-class job identity.
	 * @param array<int,mixed>     $message The job message.
	 * @return mixed The decision, unchanged.
	 */
	public static function begin_job_context_filter( mixed $run, string $handler, string $id = '', array $message = [] ): mixed {
		if ( false !== $run ) {
			self::begin_job_context( $handler, $id, $message );
		}
		return $run;
	}

	/**
	 * Enter a background-job request context.
	 *
	 * The substrate's Job_Worker_Node fires `newspack_nodes/job_worker/before_job`
	 * with ( $handler, $id ) around each handler; this is the event-logger's hooked
	 * listener. It suspends the parent LogManager, generates a fresh per-job
	 * UNIQUE_ID, and rewrites $_SERVER to a synthetic `/jobs/{handler}/{id}` request
	 * (plain `/jobs/{handler}` when the id is empty) so any LogManager the handler
	 * spawns picks up job-scoped context. The id is the substrate's first-class job
	 * identity, not a compound handler string or a smuggled parameter.
	 *
	 * Stack-based by design: the before/after-job actions thread no state, so the
	 * original $_SERVER is pushed onto an internal LIFO restored by
	 * end_job_context(). The snapshot is taken FIRST so a partial $_SERVER edit
	 * mid-method still leaves a complete snapshot to restore from, and so an
	 * unpaired/throwing begin still leaves end_job_context a snapshot to pop.
	 * Public static so handlers (and direct callers like cron) can nest their own
	 * sub-scopes — pair with end_job_context() in a finally block. Both ends fire
	 * `newspack_event_logger_nodes_scope_changed` so `App\Core` rebinds its hook
	 * instrumentation to whichever rule now governs.
	 *
	 * A handler running something other than a POST against /jobs/{handler} —
	 * a template rendered as GET with its own URI and query string — passes
	 * $server. Those keys are applied over the defaults BEFORE the action
	 * fires, because the listener builds the LogManager that reads them.
	 *
	 * @param string               $handler Job handler name.
	 * @param string               $id      First-class job identity ('' ⇒ no id segment).
	 * @param array<int,mixed>     $message The job message, as the substrate's
	 *                                     before_job passes it. Empty KEEPS the
	 *                                     enclosing job's, so a nested context
	 *                                     (evTemplate rendering inside a job)
	 *                                     still names the causing record.
	 * @param array<string,string> $server $_SERVER keys overriding the synthetic
	 *                                     defaults. Describes the request only —
	 *                                     overriding UNIQUE_ID or
	 *                                     HTTP_X_A8C_REQUEST_ID would defeat the
	 *                                     fresh per-job request identity above.
	 */
	public static function begin_job_context( string $handler, string $id = '', array $message = [], array $server = [] ): void {
		// Before the scope action, which builds the LogManager that reads it.
		if ( [] !== $message ) {
			self::$job_message = $message;
		}
		// $_SERVER is string-keyed (superglobal snapshot for restore).
		/** @var array<string,mixed> $snapshot */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- snapshot for restore.
		$snapshot                 = $_SERVER;
		self::$job_server_stack[] = $snapshot;

		// LogManager::suspend() pushes the parent context onto its stack.
		self::suspend();

		$request_uri = '/jobs/' . \ltrim( $handler, '/' );
		$id_path     = self::job_id_path( $id );
		if ( '' !== $id_path ) {
			$request_uri .= '/' . \ltrim( $id_path, '/' );
		}
		$_SERVER['UNIQUE_ID']      = self::generate_request_id();
		$_SERVER['REQUEST_URI']    = $request_uri;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['PATH_INFO']      = '';
		$_SERVER['QUERY_STRING']   = '';
		unset(
			$_SERVER['CONTENT_TYPE'],
			$_SERVER['CONTENT_LENGTH'],
			$_SERVER['HTTP_X_A8C_REQUEST_ID']
		);
		foreach ( $server as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
		\do_action( 'newspack_event_logger_nodes_scope_changed' );
	}

	/**
	 * The path portion of a job id, for the synthetic request URI.
	 *
	 * A job id addressed to another host is an absolute URL, so that the worker
	 * can route on it. Spliced into the URI whole, it nests a second scheme and
	 * host inside the path and reads as though the executing host served it —
	 * `https://www.spoke.com/jobs/evtemplate/https://hub/Tools/UpdateSite.html`.
	 * log_request_details() supplies the real host from SERVER_NAME, so only the
	 * path belongs here. A URL carrying no path names no template — the handler
	 * returns early on exactly that input — so it yields '' and the caller drops
	 * the segment, giving the bare `/jobs/{handler}` an id-less job already has.
	 *
	 * @param string $id Job id, absolute URL or bare path.
	 * @return string Path naming the job without scheme or host, '' if it names none.
	 */
	private static function job_id_path( string $id ): string {
		$host = \wp_parse_url( $id, PHP_URL_HOST );
		if ( ! \is_string( $host ) || '' === $host ) {
			return $id;
		}
		$path = \wp_parse_url( $id, PHP_URL_PATH );
		return \is_string( $path ) && '' !== \trim( $path, '/' ) ? $path : '';
	}

	/**
	 * Suspend the current LogManager and push it onto the context stack.
	 *
	 * The suspended instance keeps its state (timers, request ID, buffer)
	 * intact. A new instance will be created on the next instance() call.
	 * Call resume() to restore the parent context.
	 *
	 * The parent's buffered lines are flushed on the way out, so the nested
	 * context's lines land after them rather than interleaved.
	 */
	public static function suspend(): void {
		if ( null !== self::$instance ) {
			self::$instance->topic?->flush();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- saving our own generated ID for restore.
			$saved                           = $_SERVER['UNIQUE_ID'] ?? null;
			self::$instance->saved_unique_id = \is_string( $saved ) ? $saved : null;
			self::$context_stack[]           = self::$instance;
			self::$instance                  = null;
		}
	}

	/**
	 * Resolve the governing rule for a URL, storing it accordingly.
	 * No rule matched means skip — there is no log-all baseline.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL should be logged.
	 */
	public function matches_url_filter( string $url ): bool {
		$this->matched_rule = $this->matcher?->match( $url );
		return null !== $this->matched_rule && $this->matched_rule->is_log();
	}

	/**
	 * This context's logging window is open: the governing rule said `log` and
	 * finish() has not run yet. The gate every caller that instruments its own
	 * work — rather than just writing a line — should check first.
	 *
	 * @api Used by App\Core and the profiler drop-in.
	 */
	public function is_started(): bool {
		return true === $this->started;
	}

	/**
	 * The active instance IFF it has already started logging — the bridge's seam
	 * for "is there somewhere to log this line?". Never creates or starts an
	 * instance (unlike instance()), so an unmatched / rule-gated / root context
	 * yields null and the caller drops or writes elsewhere.
	 *
	 * @api Used by the substrate-diagnostics bridge.
	 */
	public static function started_instance(): ?self {
		return ( null !== self::$instance && self::$instance->is_started() ) ? self::$instance : null;
	}

	/**
	 * The rule governing this request (null ⇒ no match ⇒ skip).
	 *
	 * @api Used by external plugins.
	 */
	public function governing_rule(): ?Rule {
		return $this->matched_rule;
	}

	/**
	 * Lazy Topic→interpreter relay (a Callback_Node sink): when the Topic was
	 * wired before the command_interpreter node existed (load order), rewire it
	 * to the now-built interpreter on the first message and forward — so an
	 * early-wired Topic never fills into a missing sink.
	 *
	 * @param array<int,mixed> $message The positional Message array.
	 */
	public function relay_topic_to_ci( array $message ): void {
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null !== $ci ) {
			$this->topic?->sink( $ci );
			$ci->fill( $message );
		}
	}

	/**
	 * Drain every materialized Partition's in-memory batch to disk.
	 *
	 * Two callers need this. A caller handing off to a subprocess that writes the
	 * same firehose (nuclear-gyrobase's run_gyrobase.sh) flushes BEFORE
	 * `proc_open`, or the child appends between the parent's accumulated Messages
	 * and the next size-threshold / timer flush and the segment ends up out of
	 * logical order. A caller that has just written a `job` entry flushes so the
	 * Job Router sees the work now rather than whenever the batch happens to fill.
	 *
	 * @api Used by external plugins (nuclear-gyrobase, pyrobase).
	 */
	public function flush(): void {
		$this->topic?->flush();
	}

	/**
	 * Reset the singleton instance.
	 *
	 * Call before changing REQUEST_URI to log a different request context.
	 * Only used by unit tests.
	 *
	 * @api Used by tests.
	 */
	public static function reset(): void {
		if ( null !== self::$instance ) {
			self::$instance->finish();
		}
		self::$instance = null;
	}

	/**
	 * The active instance, constructed on first call. Construction is what
	 * resolves the governing rule and may start logging — a caller that only
	 * wants to write to an already-started context wants started_instance().
	 *
	 * @return self
	 */
	public static function instance() {
		return self::$instance ??= new self();
	}

	/**
	 * Get the request ID for the current request. Empty until init_firehose()
	 * runs, which is how callers detect an unlogged request.
	 *
	 * @api Used by external plugins.
	 * @return string
	 */
	public function get_request_id(): string {
		return $this->request_id;
	}

	/**
	 * Get the partition the current request's firehose lines hash to — the
	 * `requests.p{N}` a reader needs to locate this request.
	 *
	 * @return int
	 */
	public function get_partition(): int {
		return $this->partition_idx;
	}

	/**
	 * Refresh firehose segment state from disk.
	 *
	 * Call after a subprocess that may have written to or rotated the firehose,
	 * so subsequent writes go to the current segment. Reflection reaches the
	 * substrate's protected `Topic_Node::partition()` and
	 * `Partition_Node::init_current_segment()` — renaming either breaks this
	 * silently, since neither call can fail at compile time.
	 *
	 * @api Used by external plugins.
	 */
	public function refresh_firehose(): void {
		if ( null === $this->topic ) {
			return;
		}
		$ref_partition_method = new \ReflectionMethod( Topic_Node::class, 'partition' );
		$partition = $ref_partition_method->invoke( $this->topic, $this->partition_idx );
		if ( ! $partition instanceof Partition_Node ) {
			return;
		}

		$ref_init = new \ReflectionMethod( Partition_Node::class, 'init_current_segment' );
		$ref_init->invoke( $partition );
	}

	/**
	 * Mint the request id, pick its partition, and attach the firehose Topic.
	 *
	 * The id comes from the edge (`HTTP_X_A8C_REQUEST_ID`), else from `UNIQUE_ID`,
	 * else it is generated and published back into `$_SERVER['UNIQUE_ID']` so a
	 * subprocess inherits the same identity. `_firehose:topic` is built once per
	 * process and adopted by every later context.
	 */
	private function init_firehose(): void {
		// request_id FIRST: Topic ctor re-enters message(), which needs a rid.
		if ( ! empty( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) && \is_string( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) ) {
			$this->request_id = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) ), 0, 64 );
		} elseif ( ! empty( $_SERVER['UNIQUE_ID'] ) && \is_string( $_SERVER['UNIQUE_ID'] ) ) {
			$this->request_id = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['UNIQUE_ID'] ) ), 0, 64 );
		} else {
			$this->request_id     = self::generate_request_id();
			$_SERVER['UNIQUE_ID'] = $this->request_id;
		}

		$dir_template        = self::firehose_dir_template( Config::get_logs_directory() );
		// THE accessor: past the cap no worker consumes, and the GC sweeps it.
		$num_partitions      = Bootstrap::global_num_partitions();
		$this->partition_idx = Partition_Node::hash_to_partition( $this->request_id, $num_partitions );
		$segment_size = Core::as_int( Config::value( 'segment_size' ) );
		$min_segments = Core::as_int( Config::value( 'min_segments' ) );
		$num_segments = Core::as_int( Config::value( 'num_segments' ) );
		$min_lifetime = Core::as_int( Config::value( 'min_lifetime' ) );
		$lifetime     = Core::as_int( Config::value( 'lifetime' ) );
		$max_segments = Core::as_int( Config::value( 'max_segments' ) );
		$existing = Core::node( '_firehose:topic' );
		if ( $existing instanceof Topic_Node ) {
			$this->topic = $existing;
		} else {
			$this->topic = new Topic_Node();
			$this->topic->name( '_firehose:topic' );
			$this->topic->arguments( [ $dir_template, (string) $num_partitions, (string) $segment_size, (string) $min_segments, (string) $num_segments, (string) $max_segments, (string) $min_lifetime, (string) $lifetime ] );
			$this->topic->patron( $this->topic );
			$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
			if ( null !== $ci ) {
				$this->topic->sink( $ci );
			} else {
				// CI not built yet (load order): relay hooks Topic to CI later.
				$this->topic->sink( new Callback_Node( [ $this, 'relay_topic_to_ci' ] ) );
			}
		}
	}

	/**
	 * Dir template for the firehose Topic — the one place its layout is written.
	 * The Topic writes through it and the plugin registers it with the substrate's
	 * log GC, so the dirs written and the dirs declared are one statement. The
	 * default root is the `<config:logs_dir>` token: registration runs inside the
	 * GC sweep and must not touch the filesystem.
	 *
	 * The `{partition}` spelling is load-bearing — `Topic_Node` substitutes only
	 * that one, while the GC accepts either.
	 */
	public static function firehose_dir_template( string $logs_dir = '<config:logs_dir>' ): string {
		return \rtrim( $logs_dir, '/' ) . '/firehose.p{partition}';
	}

	/**
	 * Generate a new request ID: 32 base-36 characters over 25 random bytes.
	 *
	 * @return string
	 */
	public static function generate_request_id(): string {
		$rid = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$rid .= \base_convert( \bin2hex( \random_bytes( 5 ) ), 16, 36 );
		}
		return \substr( $rid, 0, 32 );
	}

	/**
	 * Open the request: `process (start)`, `request`, environment, resources.
	 *
	 * The `process` frame this pushes is the root of the timer stack — finish()
	 * closes it last, and every orphaned frame above it drains first.
	 *
	 * @return void
	 */
	private function log_process(): void {
		$process_hr   = $this->request_time ?? \hrtime( true );
		$process_data = [ 'm' => \getmypid() . ' on ' . \gethostname(), 'l' => '' ];

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately below.
		$worker_type = \sanitize_text_field( Core::as_string( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] ?? '' ) );
		if ( '' !== $worker_type ) {
			$process_data['worker_type'] = $worker_type;
		}
		if ( null !== $this->request_ts ) {
			$process_data['ts'] = $this->request_ts;
		}
		$process_data['rule'] = $this->governing_rule_id();

		$this->message( 'process (start)', $process_data );
		$this->times[] = [ 'label' => 'process', 'ts' => $process_hr ];

		$method       = \is_string( $_SERVER['REQUEST_METHOD'] ?? null ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'CLI';
		$server_name  = \is_string( $_SERVER['SERVER_NAME'] ?? null ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
		$scheme       = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ? 'https' : 'http';
		$redacted_url = self::redact_url( $this->request_url );
		$full_url     = $server_name ? "{$scheme}://{$server_name}{$redacted_url}" : $redacted_url;
		$this->message( 'request', [ 'm' => "{$method} {$full_url}" ] );

		// The record that caused this request; ID seeks onto the log.
		if ( [] !== self::$job_message ) {
			$this->message(
				'message',
				[
					'm' => [
						'FROM' => Core::as_string( self::$job_message[ \Newspack_Nodes\Message::FROM ] ?? '', '' ),
						'ID'   => Core::as_string( self::$job_message[ \Newspack_Nodes\Message::ID ] ?? '', '' ),
						'KEY'  => Core::as_string( self::$job_message[ \Newspack_Nodes\Message::KEY ] ?? '', '' ),
					],
				]
			);
		}

		$this->log_environment();
		$this->log_resources();
	}

	/**
	 * Emit a `resources` line from getrusage(): CPU time, faults, I/O blocks,
	 * signals, context switches. Called at request open and again at finish, so
	 * a reader can difference the two. Silent when getrusage() is unavailable.
	 */
	private function log_resources(): void {
		$r = \getrusage();
		if ( ! $r ) {
			return;
		}
		$info = [
			\sprintf( 'utime => %f',  ( $r['ru_utime.tv_sec'] ?? 0 ) + ( $r['ru_utime.tv_usec'] ?? 0 ) / 1000000 ),
			\sprintf( 'stime => %f',  ( $r['ru_stime.tv_sec'] ?? 0 ) + ( $r['ru_stime.tv_usec'] ?? 0 ) / 1000000 ),
			\sprintf( 'maxrss => %d',   $r['ru_maxrss']   ?? 0 ),
			\sprintf( 'minflt => %d',   $r['ru_minflt']   ?? 0 ), \sprintf( 'majflt => %d',  $r['ru_majflt']  ?? 0 ),
			\sprintf( 'inblock => %d',  $r['ru_inblock']  ?? 0 ), \sprintf( 'oublock => %d', $r['ru_oublock'] ?? 0 ),
			\sprintf( 'nsignals => %d', $r['ru_nsignals'] ?? 0 ),
			\sprintf( 'nvcsw => %d',    $r['ru_nvcsw']    ?? 0 ), \sprintf( 'nivcsw => %d',  $r['ru_nivcsw']  ?? 0 ),
		];
		$this->message( 'resources', [ 'm' => \implode( ', ', $info ) ] );
	}

	/**
	 * Emit the curated `environment_v3` map for this request.
	 *
	 * Every value is stripped of control characters, redacted where it carries a
	 * query string, and capped at ENV_VALUE_MAX bytes — in that order, so a cut
	 * can never expose the tail of a secret the redaction would have covered.
	 * Array-valued keys are dropped outright.
	 *
	 * ENV_ALLOWLIST is the only key source, and no entry in it may read as a
	 * secret — `LogManagerTest::test_no_allowlisted_environment_key_looks_like_a_secret`
	 * holds that, so a bad addition fails the build rather than this request.
	 */
	private function log_environment(): void {
		$env = [];
		foreach ( self::ENV_ALLOWLIST as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with preg_replace.
			$value = $_SERVER[ $key ];
			if ( \is_array( $value ) ) {
				continue;
			}
			$sanitized = \preg_replace( '/[\x00-\x1F\x7F]/', '', Core::as_string( $value ) ) ?? '';
			// Redact URL secrets in values with a query, not just HTTP_REFERER.
			if ( false !== \strpos( $sanitized, '?' ) ) {
				$sanitized = self::redact_url( $sanitized );
			}
			// Cap AFTER redaction so truncation can't hide a secret's boundary.
			if ( \strlen( $sanitized ) > self::ENV_VALUE_MAX ) {
				$sanitized = \substr( $sanitized, 0, self::ENV_VALUE_MAX ) . '…';
			}
			$env[ $key ] = $sanitized;
		}
		if ( ! empty( $env ) ) {
			$this->message( 'environment_v3', [ 'm' => $env ] );
		}
	}

	/**
	 * Write one firehose line: a TM_STRUCT Message keyed by the request id.
	 *
	 * The entry carries the line number as `n`, the category as `k`, the caller's
	 * data, and a `ts` timestamp. Data encoding over MAX_DATA_SIZE is NOT chunked
	 * — the category gains `" (truncated)"` and the data collapses to a
	 * 1000-character excerpt, so anything larger belongs in
	 * `\Newspack_Nodes\Job_Intake::queue()` instead. A caller-supplied `rid` is
	 * dropped: the real one is the Message KEY, and honoring the caller's would
	 * let it forge another request's identity.
	 *
	 * @param string $category Event category/keyword.
	 * @param array<string,mixed>  $data     Additional data to include.
	 * @return bool True when the line was written; false when logging never started or the Topic is missing.
	 */
	public function message( string $category, array $data = [] ): bool {
		if ( ! $this->started ) {
			return false;
		}
		if ( isset( $data['m'] ) && \is_string( $data['m'] ) && false !== \strpos( $data['m'], '?' ) ) {
			$data['m'] = self::redact_url( $data['m'] );
		}
		// @longform Substitute-on-error: invalid-UTF8 data still yields a
		// string sized like Message::packed()'s output (same flag) — else
		// the guard skips truncating and the Partition drops the record.
		$data_json = \wp_json_encode( $data, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( false !== $data_json && \strlen( $data_json ) > self::MAX_DATA_SIZE ) {
			Core::print_less_often( "LogManager: data truncated for category \"{$category}\", size=", (string) \strlen( $data_json ), \sprintf( ' (limit=%d).', self::MAX_DATA_SIZE ) );
			$category .= ' (truncated)';
			$data = [ 'm' => \substr( $data_json, 0, 1000 ) . '...' ];
		}
		if ( null === $this->topic ) {
			return false;
		}
		// Strip caller rid (real one is Message::KEY); blocks a forged rid.
		unset( $data['rid'] );

		// Request-scope hot: cache frozen; one fresh read, threaded to both.
		$now                                           = Core::right_now();
		$entry = [ 'n' => $this->line_number, 'k' => $category ] + $data + [ 'ts' => $now ];
		$message                                       = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$message[ \Newspack_Nodes\Message::TIMESTAMP ] = $now;
		$message[ \Newspack_Nodes\Message::KEY ]       = $this->request_id;
		$message[ \Newspack_Nodes\Message::VALUE ]     = $entry;
		$this->topic->fill( $message );
		++$this->line_number;

		if ( $this->flush_every_line ) {
			$this->topic->flush();
		}

		return true;
	}

	/**
	 * Redact sensitive query parameters from a URL. Public because it is the
	 * ONE redaction path: anything that sends a URL somewhere it was not
	 * already written — the Ask brief, an agent surface — goes through here,
	 * not through a second pattern that would drift from this one.
	 *
	 * @param string $url URL to redact.
	 * @return string Redacted URL.
	 */
	public static function redact_url( string $url ): string {
		return \preg_replace( self::URL_REDACT_PATTERN, '$1$2=[REDACTED]', $url ) ?? $url;
	}

	/**
	 * The governing rule's id, or '' when nothing matched. Rides the
	 * `process (start)` line as `rule`, which is how a reader attributes a
	 * request to the rule that admitted it.
	 *
	 * @api Public accessor.
	 */
	public function governing_rule_id(): string {
		return $this->matched_rule->id ?? '';
	}

}
