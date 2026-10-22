<?php
/**
 * Log Manager — the event logger's per-request firehose writer.
 *
 * A logged request opens the shared `_firehose:topic` Topic, hashes its request
 * id to a partition, and appends one TM_STRUCT Message per log line. Each line
 * lands on disk as a JSON array (Message::packed), keyed by the request id, and
 * `Request_Builder_Node`, `Flame_Builder_Node` and `Stats_Builder_Node`
 * reassemble those lines downstream into requests, flame graphs, and stats.
 *
 * This file is also the event logger's public API: Pyrobase and Nuclear
 * Gyrobase log through `Log_Manager::instance()`, while the substrate's job
 * worker reaches it through the `begin_job_context_filter()` /
 * `end_job_context()` pair hooked onto its before/after-job hooks.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Driver_Error;
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
 * begin_job_context() / end_job_context(), the pair bracketing every job the
 * substrate's worker runs.
 */
class Log_Manager {

	/**
	 * PHP error types finish() reports as a fatal crash, read out of
	 * error_get_last() and attached to the final `process (complete)` line.
	 */
	const FATAL_TYPES = [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR ];

	/**
	 * The outermost timer label — the request itself. This class opens the frame
	 * and closes it, so it owns the word; every consumer reads it from here, and
	 * `logEntryUtils.js` holds the one duplicate a separate deploy unit needs.
	 */
	public const REQUEST_LABEL = 'process';

	/** Opens the record, and carries the `rule` that admitted the request. */
	public const REQUEST_START = self::REQUEST_LABEL . ' (start)';

	/** Ends a record that ran to its end. */
	public const REQUEST_COMPLETE = self::REQUEST_LABEL . ' (complete)';

	/**
	 * Ends a record whose work stopped early, so a fragment of a duration
	 * never reads as a clean finish. With REQUEST_COMPLETE it makes up
	 * `Request_Builder_Node::TERMINAL_KEYWORDS`, the pair that closes a
	 * record — without one, it strands in flight until eviction.
	 */
	public const REQUEST_ABORTED = self::REQUEST_LABEL . ' (aborted)';

	/** The category whose `m` is the curated $_SERVER map `log_environment()` writes. */
	public const ENVIRONMENT = 'environment_v3';

	/** Category of the entry naming the worker a request runs in; its `m` is the type. */
	public const WORKER_TYPE = 'worker_type';

	/** Category of the entry naming the worker's partition; its `m` is the integer. */
	public const WORKER_PARTITION = 'worker_partition';

	/** Category of the line naming the request's method and URL. */
	public const REQUEST_LINE = 'request';

	/** Category of a job context's entry naming the record that caused it. */
	public const JOB_MESSAGE = 'message';

	/** Category of the getrusage() sample written at the open and at the close. */
	public const RESOURCES = 'resources';

	/** Category of the close's object-cache row; its `m` is `Object_Cache_Counts::row()`. */
	public const OBJECT_CACHE = 'objectcache';

	/** Category of the close's entry whose `m` is `App\Request_Context`'s map. */
	public const REQUEST_CONTEXT = 'request_context';

	/** The rows the close writes between the spans' drain and the terminal. */
	public const CLOSE_ROWS = [
		self::REQUEST_CONTEXT => true,
		self::OBJECT_CACHE    => true,
		self::RESOURCES       => true,
	];

	/** The `m` of a `(complete)` closing a span its caller never closed. */
	public const ORPHANED = '(orphaned)';

	/** @var int Bytes-to-megabytes divisor. */
	private const BYTES_PER_MB = 1024 * 1024;

	/**
	 * Curated $_SERVER keys logged as the single environment_v3 map. The
	 * consumer (Request_Builder_Node) reads REMOTE_ADDR / HTTP_X_FORWARDED_FOR /
	 * HTTP_USER_AGENT / GEOIP_COUNTRY_CODE / HTTP_FROM / HTTP_X_JA4_HASH; the
	 * rest are diagnostics, SERVER_NAME among them, which the `request` line's
	 * URL already carries as its host. Keys already carried by the
	 * `request` log line (REQUEST_METHOD / REQUEST_URI / QUERY_STRING) are
	 * intentionally omitted. Perl mirrors this list in Gyrobase::Log — keep the
	 * two IDENTICAL.
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
		'REMOTE_ADDR',
		'REMOTE_PORT',
		'REQUEST_SCHEME',
		'REQUEST_TIME',
		'REQUEST_TIME_FLOAT',
		'SERVER_NAME',
		'UNIQUE_ID',
	];

	/** Per-value byte cap for environment_v3 map values. */
	private const ENV_VALUE_MAX = 256;

	/** The bytes a logged `$_SERVER` value is stripped of: C0 controls and DEL. */
	private const CONTROL_CHARS = '/[\x00-\x1F\x7F]/';

	/**
	 * Bytes of REQUEST_METHOD the request line keeps: room for every
	 * registered method, the longest of which runs 17. Without a cap, a
	 * method of a few KB fills the entry, and the trim of `m` takes the
	 * URL with it. `Gyrobase::Log` carries the same value.
	 */
	private const REQUEST_METHOD_MAX = 32;

	private const MAX_DATA_SIZE = 3840;

	/** Raw bytes a fatal's message keeps on the terminal, cut on a character. */
	private const FATAL_MESSAGE_MAX = 1024;

	/** Raw bytes a fatal's file and plugin slug keep, cut on a character. */
	private const FATAL_PATH_MAX = 256;

	/**
	 * ENCODED bytes the terminal's fatal message may take.
	 *
	 * The terminal's other fields at their worst take 250 encoded bytes: `n`
	 * (17 with its key), `k` (25, `process (complete)`), `status_code` (18),
	 * `duration_ms` (38, an exponent float), `peak_mb` (20), `ts` (22),
	 * `error_status` (19), `fatal_line` (24), `fatal_type` (16) and the three
	 * fatal keys with their quotes (51). Reserving 512 for them leaves
	 * 3,840 − 512 = 3,328 for the three strings:
	 * 2,048 for the message and 640 each for the file and the plugin slug.
	 * Raw bytes are no bound — a 4-byte character encodes to 12 — so each is
	 * measured as `message()` encodes it.
	 */
	private const FATAL_MESSAGE_BUDGET = 2048;

	/** ENCODED bytes the fatal's file and plugin slug may each take; see above. */
	private const FATAL_PATH_BUDGET = 640;

	/**
	 * Last-error seam: replaces the `error_get_last()` call, which no test
	 * can make report a fatal without dying of one. Tests reassign it.
	 * Signature: `function (): ?array`, shaped as `error_get_last()` answers.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $last_error = null;

	/** The firehose's name; each partition is the directory `<name>.p<N>`. */
	private const FIREHOSE = 'firehose';

	/** @var int Maximum timer stack depth to prevent unbounded growth. */
	private const MAX_TIMER_DEPTH = 100;

	/** @var int Nanoseconds-to-milliseconds divisor. */
	private const NS_PER_MS = 1_000_000;

	/**
	 * A credential-shaped query parameter NAME, tested by `redact_url()`
	 * against the name as PHP reads it. `Gyrobase::Log` carries the identical
	 * copy, and `tools/check-firehose-parity.py` in dndocker diffs the two.
	 *
	 * Two tiers, because a short token collides with ordinary words: a name
	 * CONTAINING a credential token is redacted wherever the token sits, with
	 * `auth` sparing the core query var `author`; a short token counts only as a
	 * whole SEGMENT of the name, bounded by its ends, by `_`, `-`, `.` or `[`,
	 * at its start by `?` and at its end by `]`, so `consumer_key`, `api-key`,
	 * `x?key`, `key[]`, `api[key` and `data[key]` go while `keyword` and
	 * `postcode` stay.
	 *
	 * This is a DENYLIST: a credential under a name carrying none of these
	 * tokens reaches the log in cleartext. The segment rule over-reaches on
	 * `country_code` and `client_id`, redacted though neither is a secret; an
	 * allowlist is the wrong trade for a URL log, where `?p=`, `?s=` and the
	 * `utm_*` family are what an operator reads it for.
	 *
	 * @var string
	 */
	private const URL_REDACT_PATTERN = '/(?:secret|token|pass(?:w|phrase)|pwd|hmac|nonce|session|credential|bearer|signature|apikey|subscriptionkey|cache_cozy_warm|authorization|auth(?!or))|(?:\A|[_.\-?\[])(?:key|sig|code|pass|pin|otp|client)(?:[_.\-\[\]]|\z)/i';

	/**
	 * A query parameter's head: its run of separators, the name as sent, and
	 * the `=` that makes it a parameter, empty when the name ends at `&`, `#`
	 * or the end. A separator is `?` or `&`, taking with it the rest of an
	 * HTML character reference for `&` — `amp;`, `#38;` or `#x26;`, padded
	 * with zeros or not, in either case — so the name read behind
	 * `esc_url()`'s `&#038;token=` is `token`. A `?` takes it too, which only
	 * trims a prefix no credential token sits in. Keeping `[?&]` first lets
	 * Perl's start-class scan find a head.
	 *
	 * @var string
	 */
	private const URL_PARAMETER = '/(?:[?&](?:(?:amp|#0*38|#x0*26);)?)+([^=&#]*)(=?)/i';

	/** @var array<int,self> Stack of suspended parent Log_Manager instances. */
	private static $context_stack = [];

	/** @var self|null The active instance; instance() creates it on demand. */
	private static ?self $instance = null;

	/**
	 * LIFO frames of begin/end_job_context: the $_SERVER snapshot to restore,
	 * and the job message the context runs under, its enclosing job's when it
	 * brought none.
	 *
	 * @var list<array{0:array<string,mixed>,1:array<int,mixed>}>
	 */
	private static array $job_stack = [];

	/** @var array<string,mixed> Cached config (loaded once at construction). */
	private $config = [];
	/** @var bool finish() has run; nothing more will be written. */
	private $finished = false;

	/** @var bool This context ended without its job completing (cooperative stop). */
	private bool $aborted = false;

	/** @var int The next line's `n` field, 1-based. */
	private $line_number = 1;

	/** @var Rule|null The rule governing this request (null ⇒ no match ⇒ skip). */
	private ?Rule $matched_rule = null;

	/** @var Rule_Matcher|null Built once per request from the autoloaded rule list. */
	private ?Rule_Matcher $matcher = null;
	/** @var int Firehose partition this request's id hashes to. */
	private $partition_idx = 0;
	/** @var string This request's id — the KEY every Message carries. */
	private $request_id = '';
	/** @var int|float|null hrtime() reading the profiler drop-in took at request start. */
	private $request_time = null;
	/** @var float|null Wall-clock request start (microtime), from the profiler drop-in. */
	private $request_ts = null;
	/** @var string REQUEST_URI as sent, percent-encoding intact, less CONTROL_CHARS; '/unknown' when unset. */
	private $request_url = '';

	/**
	 * This context's `$_SERVER['UNIQUE_ID']`, captured by suspend() so resume()
	 * can put it back — a nested context overwrites the superglobal with its
	 * own per-job identity.
	 *
	 * @var string|null
	 */
	private ?string $saved_unique_id = null;
	/** @var bool|null True while logging, false after finish(), null when no rule started it. */
	private $started = null;
	/** @var array<int,array{label: string,ts: int|float,at?: float,m?: mixed,keep?: mixed,inner?: float}> Timer-frame stack; `at` is its wall-clock start, `inner` the ms its closed children took. */
	private $times = [];

	/** @var array<string,float> Self time of every closed span so far, in ms, by label. */
	private array $spent = [];

	/**
	 * The `Object_Cache_Counts::mark()` this context's `objectcache` row
	 * counts from; null when the cache is not one it knows, or when the
	 * governing rule does not say `log_cache`.
	 *
	 * @var array{kind: string, counters: array<string,int>, positions: array<array-key,int>}|null
	 */
	private ?array $cache_from = null;
	/** @var \Newspack_Nodes\Topic_Node|null The firehose Topic; null until init_firehose() runs. */
	private $topic = null;

	/**
	 * The platform's requests to itself, by path: the cron loopback and the
	 * substrate's own endpoints. Each is worker traffic whether or not the
	 * substrate set `NEWSPACK_NODES_WORKER_TYPE` in the serving process, and
	 * the value is what the `worker_type` entry carries, so a rule that logs
	 * one keeps it on the worker rows and off the global averages. Every REST
	 * endpoint here — the substrate's five and this plugin's MCP route —
	 * shares `restapi`: the path already names each, and the type rides the
	 * URL as its query, so a per-endpoint name would only repeat the path.
	 * `restapi` names a spawn request only until the substrate validates the
	 * worker it became; `identify_worker()` then names that worker, so each
	 * spawned worker keeps a row of its own. The paths are literals because two substrate controllers declare no
	 * route constant; a test registers every route and compares.
	 *
	 * @var array<string,string>
	 */
	private const PLATFORM_WORKERS = [
		'/wp-cron.php'                                => 'cron',
		'/wp-json/newspack-nodes/v1/auth'             => 'restapi',
		'/wp-json/newspack-nodes/v1/command'          => 'restapi',
		'/wp-json/newspack-nodes/v1/health/runtime'   => 'restapi',
		'/wp-json/newspack-nodes/v1/messages/stream'  => 'restapi',
		'/wp-json/newspack-nodes/v1/workers/spawn'    => 'restapi',
		'/wp-json/newspack-event-logger-nodes/v1/mcp' => 'restapi',
	];

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
		if ( \function_exists( 'posix_geteuid' ) && 0 === \posix_geteuid() ) {
			return;
		}
		$this->matcher = Rule_Set::load()->matcher();

		/** @var array{request_time?: int|float,request_ts?: float}|null $newspack_profiler */
		global $newspack_profiler;
		if ( null !== $newspack_profiler ) {
			$this->request_time = $newspack_profiler['request_time'] ?? null;
			$this->request_ts   = $newspack_profiler['request_ts']   ?? null;
			unset( $newspack_profiler['request_time'], $newspack_profiler['request_ts'] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- CONTROL_CHARS stripped; read before wp_magic_quotes() slashes $_SERVER.
		$this->request_url = isset( $_SERVER['REQUEST_URI'] ) ? \preg_replace( self::CONTROL_CHARS, '', Core::as_string( $_SERVER['REQUEST_URI'] ) ) ?? '' : '/unknown';
		if ( $this->matches_url_filter( $this->request_url ) ) {
			// Resolved first: a request with no host never starts.
			$origin        = self::origin();
			$this->started = true;
			\register_shutdown_function( [ $this, 'finish' ] );
			$this->init_firehose();
			$this->log_process( $origin );
		}
	}

	/**
	 * Close the request: drain the timer stack, then log the request context,
	 * the object cache's row and resources where the governing rule asks for
	 * them, and the final `process (complete)` line, which always carries the
	 * peak memory, before flushing the Topic.
	 *
	 * Registered as a shutdown function by the constructor, so it also runs
	 * after a fatal. When error_get_last() reports one of FATAL_TYPES, the
	 * completion line carries the message, file, line, type, offending plugin
	 * slug, and `error_status` = `F`.
	 *
	 * Idempotent: a second call after the first returns immediately.
	 *
	 * @throws \Throwable The drain's and the terminal's throwables, combined by `Worker_Should_Stop::raise()`.
	 */
	public function finish(): void {
		if ( $this->finished || ! $this->started ) {
			return;
		}
		$this->finished = true;

		// @longform The terminal outranks everything else here. A stop or a
		// failed write lands on a WRITE, and every line finish() emits is one,
		// so letting either propagate skips the terminal while `finished`
		// stays latched: nothing retries, and the record strands in flight
		// until eviction. So the terminal is written whatever the drain threw,
		// and both escape together after it. Terminal-LAST is a wire contract,
		// not a preference: `Reqgrep_Core` finalizes and evicts the rid on it.
		$caught = $this->drain_before_terminal();
		// A stop mid-finish means this request did not run to its end.
		$this->aborted = $this->aborted || [] !== \array_filter( $caught, static fn ( \Throwable $e ): bool => $e instanceof Worker_Should_Stop );
		$caught        = [ ...$caught, ...Worker_Should_Stop::attempt( $this->write_terminal( ... ) ) ];
		$this->started = false;
		// Per-request: a latched flag marks every later request.
		$this->aborted = false;
		Worker_Should_Stop::raise( $caught );
	}

	/**
	 * The line that ENDS the record. A fatal outranks a nominal finish, and an
	 * abort outranks both — the duration is a fragment either way, so it must
	 * never read as a clean completion.
	 */
	private function write_terminal(): void {
		$complete_extra = [];
		$last           = self::$last_error ?? static fn (): ?array => \error_get_last();
		/** @var array{type: int, message: string, file: string, line: int}|null $error As `error_get_last()` answers. */
		$error = $last();
		if ( $error && \in_array( $error['type'], self::FATAL_TYPES, true ) ) {
			$plugin                         = self::extract_plugin_slug( $error['file'] );
			$complete_extra['fatal_error']  = self::within_encoding( Driver_Error::fatal( $error['message'] ), self::FATAL_MESSAGE_MAX, self::FATAL_MESSAGE_BUDGET );
			$complete_extra['fatal_file']   = self::within_encoding( $error['file'], self::FATAL_PATH_MAX, self::FATAL_PATH_BUDGET );
			$complete_extra['fatal_line']   = $error['line'];
			$complete_extra['fatal_type']   = $error['type'];
			$complete_extra['fatal_plugin'] = null === $plugin ? null : self::within_encoding( $plugin, self::FATAL_PATH_MAX, self::FATAL_PATH_BUDGET );
			$complete_extra['error_status'] = 'F';
		}
		if ( $this->aborted ) {
			$complete_extra['error_status'] = 'A';
		}
		$this->complete(
			self::REQUEST_LABEL,
			\array_merge( [ 'status_code' => \http_response_code() ?: 0, 'peak_mb' => self::peak_mb() ], $complete_extra ),
			$this->aborted ? 'aborted' : 'complete'
		);
		$this->topic?->flush();
	}

	/**
	 * A prefix of `$text` no longer than `$bytes` raw and `$budget` encoded,
	 * as `message()` encodes it, its quotes counted, cut on a character
	 * boundary. JSON escapes each character on its own, so the encoding of
	 * the prefix is the sum of its characters'.
	 *
	 * @param string $text   The text.
	 * @param int    $bytes  Raw bytes it may keep.
	 * @param int    $budget Encoded bytes it may take.
	 */
	private static function within_encoding( string $text, int $bytes, int $budget ): string {
		$size = 2;
		$kept = '';
		foreach ( \mb_str_split( \mb_strcut( $text, 0, $bytes, 'UTF-8' ), 1, 'UTF-8' ) as $char ) {
			$size += \strlen( (string) \wp_json_encode( $char, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR ) ) - 2;
			if ( $size > $budget ) {
				break;
			}
			$kept .= $char;
		}
		return $kept;
	}

	/**
	 * Extract plugin slug from a file path.
	 *
	 * PHP names the file with every symlink resolved, so each root matches as
	 * written and as resolved. A plugin the deploy swaps in runs from
	 * `WP_CONTENT_DIR/plugin-releases/<slug>/<release>/<slug>/`, which its
	 * `plugins/<slug>` symlink points at; the slug there is the third segment.
	 *
	 * @param string $file File path from error_get_last().
	 * @return string|null Plugin slug or null if not in a plugin's tree.
	 */
	private static function extract_plugin_slug( string $file ): ?string {
		$roots = [];
		if ( \defined( 'WP_PLUGIN_DIR' ) ) {
			$roots[ WP_PLUGIN_DIR ] = 0;
		}
		if ( \defined( 'WP_CONTENT_DIR' ) ) {
			$roots[ WP_CONTENT_DIR . '/plugin-releases' ] = 2;
		}
		foreach ( $roots as $root => $segment ) {
			foreach ( [ $root, \realpath( $root ) ] as $dir ) {
				if ( false === $dir || 0 !== \strpos( $file, \trailingslashit( $dir ) ) ) {
					continue;
				}
				$segments = \explode( '/', \substr( $file, \strlen( \trailingslashit( $dir ) ) ) );
				if ( 0 === $segment ) {
					return '.php' === \substr( $segments[0], -4 ) ? \substr( $segments[0], 0, -4 ) : $segments[0];
				}
				// A release's own root holds no plugin file.
				return isset( $segments[ $segment + 1 ] ) ? $segments[ $segment ] : null;
			}
		}
		return null;
	}

	/**
	 * Everything the record carries before its terminal: the spans left open,
	 * then, as the governing rule's `log_context`, `log_cache` and
	 * `log_resources` ask, what WordPress made of a web request, the object
	 * cache's row and its resource usage. Each is attempted whatever an
	 * earlier one threw.
	 *
	 * @return list<\Throwable> What the producers threw, in order.
	 */
	private function drain_before_terminal(): array {
		$now = \hrtime( true );
		return Worker_Should_Stop::attempt(
			function () use ( $now ): void {
				while ( \count( $this->times ) > 1 ) {
					$this->emit_orphaned_complete( \array_pop( $this->times ), $now );
				}
			},
			function (): void {
				// A job context's record is not the request WordPress routed.
				$context = [] === self::$job_stack && $this->matched_rule?->log_context ? App\Request_Context::entry() : [];
				if ( [] !== $context ) {
					$this->message( self::REQUEST_CONTEXT, [ 'm' => $context ] );
				}
			},
			function (): void {
				$counts = Object_Cache_Counts::row( $this->cache_from );
				if ( null !== $counts ) {
					// The fold keeps it, as it keeps pyrobase's cache rows.
					$this->message( self::OBJECT_CACHE, [ 'm' => $counts, 'keep' => 1 ] );
				}
			},
			$this->log_resources( ... )
		);
	}

	/**
	 * Run `$run` inside a `$label` span. Every throwable propagates; the
	 * architecture guide states the rule.
	 *
	 * The caller asks `started_instance()` first and runs `$run` bare on null,
	 * so a process that logs nothing never builds the label.
	 *
	 * With `$describe`, the `(complete)` `m` is what it makes of the result,
	 * or `outcome()` of the throwable; without it the span carries no `m`.
	 * A `keep` in `$start` rides the `(complete)` too — see start().
	 *
	 * `$args` reach `$run` spread, so a listener hands over its callback and
	 * the hook's arguments as they are: a closure per firing would cost the
	 * hottest path an allocation.
	 *
	 * With `$write`, each of the span's two writes runs through it: a snapshot
	 * node hands its `guarded()`, so a stop either write raises waits for the
	 * node's bracket while the step still runs and still closes.
	 *
	 * @template T
	 * @param string                                $label    The span's name.
	 * @param callable(mixed...): T                 $run      The work the span times.
	 * @param (\Closure(T): (string|int))|null      $describe The `(complete)` `m` a returned result gets.
	 * @param array<string,mixed>                   $start    Extra keys for the `(start)` entry.
	 * @param list<mixed>                           $args     What `$run` is called with.
	 * @param (\Closure(\Closure): void)|null       $write    What the `(start)` and `(complete)` writes run through.
	 * @return T What `$run` returned.
	 */
	public function timed( string $label, callable $run, ?\Closure $describe = null, array $start = [], array $args = [], ?\Closure $write = null ): mixed {
		if ( null === $write ) {
			$this->start( $label, $start );
		} else {
			$write( fn () => $this->start( $label, $start ) );
		}
		$done = [];
		try {
			$result = $run( ...$args );
			if ( null !== $describe ) {
				$done['m'] = $describe( $result );
			}
			return $result;
		} catch ( \Throwable $e ) {
			if ( null !== $describe ) {
				$done['m'] = self::thrown( $e );
			}
			throw $e;
		} finally {
			if ( null === $write ) {
				$this->complete( $label, $done );
			} else {
				$write( fn () => $this->complete( $label, $done ) );
			}
		}
	}

	/**
	 * Close a labeled operation and log its duration.
	 *
	 * `Gyrobase::Log::_unwind_to()` mirrors this rule for the Perl engine, which
	 * writes into the same firehose — keep the two IDENTICAL, as the ENV_ALLOWLIST
	 * pair already is. Four readers depend on both producers agreeing about which
	 * spans a record leaves open: `computeIndentedEntries`, `pruneSeveredSpans`,
	 * and their two CLI counterparts.
	 *
	 * Search runs from the top of the timer stack down to the first frame with
	 * this label. Frames above it never got their own complete() — they drain as
	 * `(orphaned)` lines, innermost first, so an unbalanced caller costs its own
	 * frames and not the enclosing ones. An unknown label matches nothing and
	 * leaves the stack untouched.
	 *
	 * @param string              $label  Label that was passed to start().
	 * @param array<string,mixed> $data   Extra keys for the emitted entry.
	 * @param string              $suffix Parenthesised half of the keyword —
	 *                                    `complete`, or `aborted` when the
	 *                                    work stopped early.
	 * @param bool                $shaped Whether `m` is a query shape — see start().
	 */
	public function complete( string $label, array $data = [], string $suffix = 'complete', bool $shaped = false ): void {
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
			$data += \array_intersect_key( $match, [ 'keep' => true ] );
			$data['duration_ms'] = \max( 0, ( $now - $start ) / self::NS_PER_MS );
			$this->charge( $label, $data['duration_ms'], $match['inner'] ?? 0.0 );
			if ( $this->matched_rule?->log_memory ) {
				$data['peak_mb'] ??= self::peak_mb();
			}
			$this->message( "{$label} ({$suffix})", $data, $shaped );
		}
	}

	/** The process's memory high-water mark so far, in MB to two places. */
	private static function peak_mb(): float {
		return \round( \memory_get_peak_usage( true ) / self::BYTES_PER_MB, 2 );
	}

	/**
	 * Charge a closed span's self time to its label, and its whole time to
	 * the frames that held it, by `Request_Builder_Node::pop_stack()`'s rule:
	 * up through any listener frames to the first frame that is not one. A
	 * listener's own time is already its hook's, so it charges no frame. The
	 * request's root frame is the total, not a category, and is skipped.
	 *
	 * @param string $label    The span's label.
	 * @param float  $ms       Its duration.
	 * @param float  $inner_ms What its closed children took.
	 */
	private function charge( string $label, float $ms, float $inner_ms ): void {
		for ( $i = \count( $this->times ) - 1; $i >= 0 && ! Flame_Tree::is_overlay_span( $label ); $i-- ) {
			if ( self::REQUEST_LABEL === $this->times[ $i ]['label'] ) {
				break;
			}
			$this->times[ $i ]['inner'] = ( $this->times[ $i ]['inner'] ?? 0.0 ) + $ms;
			if ( ! Flame_Tree::is_listener_span( $this->times[ $i ]['label'] ) ) {
				break;
			}
		}
		if ( self::REQUEST_LABEL !== $label ) {
			$this->spent[ $label ] = ( $this->spent[ $label ] ?? 0.0 ) + $ms - $inner_ms;
		}
	}

	/**
	 * Emit a single orphaned `(complete)` line for an unclosed timer-stack
	 * frame. Shared by complete()'s mismatched-close drain and finish()'s
	 * end-of-request stack close.
	 *
	 * @param array{label: string,ts: int|float,m?: mixed,keep?: mixed} $entry Timer-stack frame.
	 * @param int|float $now Reference hrtime() reading.
	 */
	private function emit_orphaned_complete( array $entry, $now ): void {
		$duration_ms = ( $now - $entry['ts'] ) / self::NS_PER_MS;
		$data        = [ 'm' => self::ORPHANED, 'duration_ms' => $duration_ms ];
		$this->message( "{$entry['label']} (complete)", $data + \array_intersect_key( $entry, [ 'keep' => true ] ) );
	}

	/**
	 * A caught throwable as a line the firehose may carry: `stop` for a
	 * cooperative stop, else `Class: message` — the short class,
	 * `class@anonymous` for an anonymous one, whose name carries a file path
	 * and a NUL — its message through the substrate's `Driver_Error::of()`,
	 * which shapes a database driver's. message() clips a message too long
	 * for the line, so the class always survives. A thrown
	 * span's close, a Query Monitor log line and a builder's narration all
	 * spell a throwable this way.
	 *
	 * @param \Throwable $thrown What was thrown.
	 */
	public static function thrown( \Throwable $thrown ): string {
		if ( $thrown instanceof Worker_Should_Stop ) {
			return 'stop';
		}
		$class = \str_contains( $thrown::class, '@anonymous' )
			? 'class@anonymous'
			: \basename( \strtr( $thrown::class, '\\', '/' ) );
		$said  = Driver_Error::of( $thrown );
		return '' === $said ? $class : "{$class}: {$said}";
	}

	/**
	 * Start timing a labeled operation and push its frame on the timer stack.
	 *
	 * Two things drop a frame: a stack already MAX_TIMER_DEPTH deep, and a start
	 * line that could not be written. Pair every start() with a complete()
	 * carrying the same label — an unmatched frame drains as `(orphaned)`. A
	 * bare cooperative stop the write raises still pushes the frame before it
	 * propagates, because the Partition flushed the line before raising it, and
	 * the drain closes it. A stop carrying a failure says that flush threw, so
	 * the line never landed and no frame is pushed.
	 *
	 * `$shaped` says `m` is a query SHAPE rather than prose or a URL, and so
	 * must not be redacted. A shape needs no redaction: the producer's
	 * `sql_shape()` — `App\Core::without_literals()` here — has already replaced
	 * every literal and stripped every comment, which is where a credential in
	 * SQL can sit, and a URL carrying a query string reaches a statement only
	 * inside a literal. What is left is keywords, identifiers and placeholders.
	 *
	 * Redacting one is worse than wasted. `redact_url()` reads a placeholder
	 * as a parameter head, and its value runs to the next `&`
	 * — which SQL has none of — so a single column named like a credential
	 * truncates the statement from that `=` to the end. `Gyrobase::Log::start`
	 * carries the same flag.
	 *
	 * @param string              $label  Label for the timer (e.g. 'query', 'template').
	 * @param array<string,mixed> $data   Extra keys for the emitted start entry.
	 * @param bool                $shaped Whether `m` is a query shape.
	 */
	public function start( string $label, array $data = [], bool $shaped = false ): void {
		if ( \count( $this->times ) >= self::MAX_TIMER_DEPTH ) {
			return;
		}
		$written = false;
		try {
			$written = $this->message( "{$label} (start)", $data, $shaped );
		} catch ( Worker_Should_Stop $stop ) {
			// A bare stop follows a flushed line; a failure lost it.
			$written = null === $stop->getPrevious();
			throw $stop;
		} finally {
			if ( $written ) {
				// message() re-pinned Core::$now to the line's own `ts`.
				$entry = [ 'label' => $label, 'ts' => \hrtime( true ), 'at' => Core::$now ];
				if ( ! empty( $data['m'] ) ) {
					$entry['m'] = $data['m'];
				}
				// A kept start whose close folds away would read as severed.
				if ( isset( $data['keep'] ) ) {
					$entry['keep'] = $data['keep'];
				}
				$this->times[] = $entry;
			}
		}
	}

	/**
	 * Run `$run` as a `$label` span on the started record, or bare when no
	 * record has started. The `(complete)` `m` is what `$describe` makes of
	 * the result, `ok` without one, or `outcome()` of a throwable. A caller
	 * whose label costs work to build asks `started_instance()` itself and
	 * calls `timed()`.
	 *
	 * @template T
	 * @param string                           $label    The span's name.
	 * @param \Closure(): T                    $run      The work the span times.
	 * @param (\Closure(T): (string|int))|null $describe The `(complete)` `m` a returned result gets.
	 * @param array<string,mixed>              $start    Extra keys for the `(start)` entry.
	 * @return T What `$run` returned.
	 */
	public static function span( string $label, \Closure $run, ?\Closure $describe = null, array $start = [] ): mixed {
		$lm = self::started_instance();
		return null === $lm ? $run() : $lm->timed( $label, $run, $describe ?? static fn (): string => 'ok', $start );
	}

	/**
	 * Log an error.
	 *
	 * @api Used by external plugins.
	 * @param string $message Error text.
	 * @return bool True when the line was written.
	 */
	public function error( string $message ): bool {
		return $this->message( 'error', [ 'm' => $message ] );
	}

	/**
	 * Log a warning.
	 *
	 * @api Used by external plugins.
	 * @param string $message Warning text.
	 * @return bool True when the line was written.
	 */
	public function warning( string $message ): bool {
		return $this->message( 'warning', [ 'm' => $message ] );
	}

	/**
	 * Log an info message.
	 *
	 * @api Used by external plugins.
	 * @param string $message Informational text.
	 * @return bool True when the line was written.
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
	 * @param string $message Alert text.
	 * @return bool True when the line was written.
	 */
	public function alert( string $message ): bool {
		return $this->message( 'alert', [ 'm' => $message ] );
	}

	/**
	 * Firehose dirs indexed by partition, for readers (dashboard grep, reqgrep).
	 *
	 * ONE number answers this, for readers and writers alike: the config's
	 * `num_partitions`. `init_firehose()` hashes the rid over it on every
	 * request, so it is exactly the span that can hold data.
	 *
	 * A topology's `var num_partitions` is its WORKER count and says nothing
	 * about the firehose. Unioning the two lets a pinned topology — `hub-control`
	 * pins 1 — argue with the writer about a layout it does not own, and makes
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
	 * they hash different inputs — a rule id hashes a PATTERN (`/shop`), a URL
	 * row hashes a request URL's `Url_Parts::identity()` (`/shop/item/5`), and
	 * a page id hashes a lowercased host and path (`shop.test/shop/item/5`) —
	 * so the keys coincide only for an exact (`?`-terminated) rule pattern;
	 * don't join them.
	 *
	 * @param string $url URL to hash.
	 * @return string 12-character hex hash.
	 */
	public static function url_hash( string $url ): string {
		// Hash the whole string: its query keys the URL and marks a worker.
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
	public static function fnv1a32( string $str, int $seed = 2166136261 ): int {
		$hash = $seed;
		$len  = \strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$hash ^= \ord( $str[ $i ] );
			$hash  = ( $hash * 16777619 ) & 0xFFFFFFFF;
		}
		return $hash;
	}

	/**
	 * Leave a background-job request context: resume the parent Log_Manager and
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
		if ( [] === self::$job_stack ) {
			return;
		}
		$reported = \func_num_args() >= 3;
		if ( $reported && null === $outcome && null !== self::$instance ) {
			self::$instance->aborted = true;
		}
		try {
			self::resume();
		} finally {
			// The snapshot IS the pairing record; unpopped it strands jobs.
			if ( ! empty( self::$job_stack ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restoring saved value.
				$_SERVER = \array_pop( self::$job_stack )[0];
			}
			\do_action( 'newspack_event_logger_nodes_scope_changed' );
		}
	}

	/**
	 * Finish the current Log_Manager and restore the parent from the stack.
	 *
	 * finish() writes the terminal, then the parent becomes the active instance
	 * again and takes back its own UNIQUE_ID. The restore runs in a finally
	 * because finish() re-raises a cooperative stop: leaving the finished
	 * context installed would send every later line to an instance that writes
	 * nothing.
	 */
	public static function resume(): void {
		try {
			self::$instance?->finish();
		} finally {
			// A re-raised stop must not strand the finished context.
			self::$instance = ! empty( self::$context_stack )
				? \array_pop( self::$context_stack )
				: null;
			// Restore UNIQUE_ID from parent context.
			if ( null !== self::$instance && isset( self::$instance->saved_unique_id ) ) {
				$_SERVER['UNIQUE_ID'] = self::$instance->saved_unique_id;
			}
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
	 * Reached from begin_job_context_filter(), the listener this plugin hooks onto
	 * the substrate's `newspack_nodes/job_worker/before_job`. It suspends the parent
	 * Log_Manager, generates a fresh per-job UNIQUE_ID, and rewrites $_SERVER to a
	 * synthetic `/jobs/{handler}/{id}` request (plain `/jobs/{handler}` when the id
	 * is empty) so any Log_Manager the handler spawns picks up job-scoped context.
	 * The id is the substrate's first-class job identity, not a compound handler
	 * string or a smuggled parameter.
	 *
	 * Stack-based by design: the before/after-job hooks thread no state, so the
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
	 * fires, because the listener builds the Log_Manager that reads them.
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
	 *                                     overriding UNIQUE_ID would defeat the
	 *                                     fresh per-job request identity above.
	 */
	public static function begin_job_context( string $handler, string $id = '', array $message = [], array $server = [] ): void {
		// $_SERVER is string-keyed (superglobal snapshot for restore).
		/** @var array<string,mixed> $snapshot */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- snapshot for restore.
		$snapshot          = $_SERVER;
		self::$job_stack[] = [ $snapshot, [] === $message ? self::job_message() : $message ];

		// self::suspend() pushes the parent context onto the context stack.
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
		// A job has no edge id: the spawn request's is not its own.
		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
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
	 * log_process() supplies the real host from origin(), so only the path
	 * belongs here. A URL carrying no path names no template — the handler
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
	 * Suspend the current Log_Manager and push it onto the context stack.
	 *
	 * The suspended instance keeps its state — timers, request id, line number
	 * — so resume() picks it up where it left off, while the next instance()
	 * call builds a fresh one for the nested context.
	 *
	 * The Topic underneath is shared rather than per-instance, so this flushes
	 * the parent's buffered lines on the way out and the nested context's land
	 * after them rather than interleaved.
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
	 * `newspack_nodes/worker_identified` listener. A spawn request named
	 * itself at its start, before the spawn controller validated which worker
	 * it is; this names that worker, and the later pair of entries wins.
	 *
	 * Writes only into a request already logging, so an announcement never
	 * builds a logger of its own.
	 *
	 * @param string $type      The validated worker type.
	 * @param int    $partition The validated partition.
	 */
	public static function identify_worker( string $type, int $partition ): void {
		self::started_instance()?->write_worker( $type, $partition );
	}

	/**
	 * The active instance IFF it has already started logging — the seam for "is
	 * there somewhere to log this line?". Never creates or starts an instance
	 * (unlike instance()), so an unmatched / rule-gated / root context yields
	 * null and the caller drops the line, writes elsewhere, or opens a job
	 * context of its own.
	 *
	 * @api Used by the diagnostics bridge and by external plugins' cron managers.
	 */
	public static function started_instance(): ?self {
		return ( null !== self::$instance && self::$instance->is_started() ) ? self::$instance : null;
	}

	/**
	 * The CPU time, user plus system, a record's process spent between its
	 * first and last `resources` samples, read back from what
	 * `log_resources()` writes and from the Perl engine's plain-number
	 * spelling of the same pair. getrusage() counts this process alone, so
	 * a child it waited on adds nothing.
	 *
	 * @param array<array-key,mixed> $entries A record's entries.
	 * @return array{from:float,to:float,cpu_ms:float}|null The samples' stamps and the CPU between them; null with fewer than two.
	 * @throws \UnexpectedValueException When a sample's `m` carries no utime and stime.
	 */
	public static function cpu_between_samples( array $entries ): ?array {
		$samples = \array_values(
			\array_filter( $entries, static fn ( mixed $entry ): bool => \is_array( $entry ) && self::RESOURCES === ( $entry['k'] ?? null ) )
		);
		if ( \count( $samples ) < 2 ) {
			return null;
		}
		$first = $samples[0];
		$last  = $samples[ \count( $samples ) - 1 ];
		return [
			'from'   => Core::num_float( $first['ts'] ?? 0 ),
			'to'     => Core::num_float( $last['ts'] ?? 0 ),
			'cpu_ms' => ( self::cpu_seconds( $last ) - self::cpu_seconds( $first ) ) * 1000.0,
		];
	}

	/**
	 * User plus system seconds one `resources` sample carries.
	 *
	 * @param array<array-key,mixed> $sample One `resources` entry.
	 * @throws \UnexpectedValueException When its `m` carries no utime and stime.
	 */
	private static function cpu_seconds( array $sample ): float {
		$m = $sample['m'] ?? null;
		$number = '(\d+(?:\.\d*)?(?:[eE][-+]?\d+)?)';
		if ( ! \is_string( $m ) || 1 !== \preg_match( "/\\butime => {$number}, stime => {$number}(?=, \\w+ => |$)/", $m, $cpu ) ) {
			throw new \UnexpectedValueException( 'Log_Manager: a resources sample carries no utime and stime: ' . \substr( \is_string( $m ) ? $m : \gettype( $m ), 0, 120 ) );
		}
		return (float) $cpu[1] + (float) $cpu[2];
	}

	/**
	 * Write the profiler drop-in's plugin-load rows as span pairs, once.
	 *
	 * The deferred bootstrap calls this before it binds the rule, so the rows
	 * precede whatever App\Core writes for `plugins_loaded`. It empties
	 * `$newspack_profiler['plugins']` whatever the rule says, so the rows are
	 * written once. Only a started logger
	 * under a rule with `log_plugin_loads` writes them: two entries per
	 * site-activated plugin, eighty on a forty-plugin site before it does any
	 * work.
	 *
	 * Each entry carries its own `ts`, overriding the one `message()` would
	 * stamp, because the flush moment would collapse the whole plugin-loading
	 * phase onto one instant. `Flame_Tree` builds a span from the `(start)` /
	 * `(complete)` suffixes, `duration_ms` on the complete is its width, and
	 * the counts ride in `m`, the field the entry table renders.
	 */
	public function flush_plugin_loads(): void {
		/** @var array{plugins?: list<array{slug:string,start_ts:float,duration_ns:int|float,new_classes:int,new_files:int}>}|null $newspack_profiler */
		global $newspack_profiler;
		if ( null === $newspack_profiler ) {
			return;
		}
		$plugins                       = $newspack_profiler['plugins'] ?? [];
		$newspack_profiler['plugins'] = [];
		if ( ! $this->is_started() || ! $this->matched_rule?->log_plugin_loads ) {
			return;
		}
		foreach ( $plugins as $plugin ) {
			$duration_ms = \round( $plugin['duration_ns'] / 1000000, 3 );
			$this->message( "{$plugin['slug']} plugin (start)", [ 'ts' => $plugin['start_ts'] ] );
			$this->message(
				"{$plugin['slug']} plugin (complete)",
				[
					'ts'          => $plugin['start_ts'] + $plugin['duration_ns'] / 1000000000,
					'duration_ms' => $duration_ms,
					'm'           => "{$duration_ms}ms, {$plugin['new_classes']} classes, {$plugin['new_files']} files",
				]
			);
		}
	}

	/**
	 * The open record's time so far: milliseconds since the request's root
	 * frame opened — the profiler drop-in's start where it took one — and
	 * the self time of every span closed so far, by label. Null when no
	 * record is open. `App\Server_Timing` reads it as the headers leave.
	 *
	 * @return array{total_ms: float, spent: array<string,float>}|null
	 */
	public function timing_so_far(): ?array {
		if ( ! $this->is_started() || [] === $this->times ) {
			return null;
		}
		return [
			'total_ms' => \max( 0, ( \hrtime( true ) - $this->times[0]['ts'] ) / self::NS_PER_MS ),
			'spent'    => $this->spent,
		];
	}

	/**
	 * Where a span written now would nest: the innermost open frame's start,
	 * monotonic (`hr`, nanoseconds) and wall-clock (`ts`). Null when no record
	 * is open. `App\Query_Monitor_Actions` clamps a timer to it.
	 *
	 * @return array{hr: int|float, ts: float}|null
	 */
	public function open_since(): ?array {
		$frame = [] === $this->times || ! $this->is_started() ? null : $this->times[ \count( $this->times ) - 1 ];
		return null === $frame ? null : [ 'hr' => $frame['ts'], 'ts' => $frame['at'] ?? Core::$now ];
	}

	/**
	 * This context's logging window is open: the governing rule said `log` and
	 * finish() has not run yet. The gate every caller that instruments its own
	 * work — rather than just writing a line — should check first.
	 */
	public function is_started(): bool {
		return true === $this->started;
	}

	/**
	 * Whether a job context is open: a job's record, not a web request's,
	 * is the one being written.
	 */
	public static function in_job_context(): bool {
		return [] !== self::$job_stack;
	}

	/**
	 * Resolve the rule governing a URL and keep it as this request's.
	 *
	 * No match means skip: there is no log-all baseline, so a deployment that
	 * wants everything logged declares a `/` rule.
	 *
	 * @param string $url URL to check.
	 * @return bool True when a rule matched and it says `log`.
	 */
	public function matches_url_filter( string $url ): bool {
		$this->matched_rule = $this->matcher?->match( $url );
		return null !== $this->matched_rule && $this->matched_rule->is_log();
	}

	/**
	 * `Newspack_Nodes\Config::RESET_ACTION` listener: the reload a rule save
	 * asks every worker for. Each open context, the suspended ones included,
	 * re-resolves the rule governing its URL from the saved ruleset, so a
	 * running worker's own record follows the save from its next line. A
	 * context that never built a matcher — logging off, or root — stays inert.
	 */
	public static function reload_rules(): void {
		$contexts = \array_filter(
			[ ...self::$context_stack, self::$instance ],
			static fn ( ?self $lm ): bool => null !== $lm?->matcher
		);
		if ( [] === $contexts ) {
			return;
		}
		$matcher = Rule_Set::load()->matcher();
		foreach ( $contexts as $lm ) {
			$lm->matcher = $matcher;
			$lm->matches_url_filter( $lm->request_url );
		}
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
	 * @api Used by nuclear-gyrobase.
	 */
	public function flush(): void {
		$this->topic?->flush();
	}

	/**
	 * Finish the active instance and drop it, so the next instance() call
	 * resolves a fresh context. Call before changing REQUEST_URI to log a
	 * different request.
	 *
	 * @api Used by tests.
	 */
	public static function reset(): void {
		if ( null !== self::$instance ) {
			self::$instance->finish();
		}
		self::$instance = null;
		App\Request_Context::template( '' );
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
	 * Whether a logger already exists, WITHOUT creating one.
	 *
	 * `instance()` lazily constructs, and a span opened by a binding that
	 * outlived its request would build the logger at that moment — stamping
	 * `process (start)` with the callback's time rather than the mu-profiler's
	 * `request_ts`. Instrumentation asks `started_instance()`.
	 *
	 * @api Used by tests, which ask whether instrumentation constructed a logger.
	 */
	public static function has_instance(): bool {
		return null !== self::$instance;
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
	 * Split a `REQUEST_LINE` entry's `m` into the record's `request_method`,
	 * its `request_url` with the query, and its `url`, the key
	 * `Url_Parts::key()` files that URL's row by. The one parse of that line,
	 * for the readers that show a request and those that aggregate per URL
	 * alike.
	 *
	 * The method is any token: both producers write `REQUEST_METHOD` as the
	 * client sent it, less control characters, with each space, `&` and `?`
	 * read as `_` so it stays one token and opens no query head, cut to
	 * `REQUEST_METHOD_MAX` bytes, or `CLI` when nothing is left, so refusing
	 * an unlisted one would let a visitor keep a request out of the log by
	 * choosing its method. The URL must be absolute, `scheme://` and more, because both
	 * producers write it under its origin. A line `fit_data()` trimmed to fit
	 * the entry still parses, so a visitor cannot hide a request behind a
	 * long URL: its entry carries `truncated`, and its `request_url` and
	 * `url` are read from the trimmed prefix.
	 *
	 * @param array<array-key,mixed> $entry The `REQUEST_LINE` entry, whose `truncated` says `fit_data()` trimmed it.
	 * @return array{0:string,1:string,2:string}|null [ request_method, request_url, url ], or null when `m` is not a request line.
	 */
	public static function parse_request_line( array $entry ): ?array {
		$message = $entry['m'] ?? null;
		if ( ! \is_string( $message ) || 1 !== \preg_match( '~^(\S+) ([a-z][a-z\d+.-]*://.+)$~Di', $message, $m ) ) {
			return null;
		}
		return [ $m[1], $m[2], Url_Parts::key( $m[2], ! empty( $entry['truncated'] ) ) ];
	}

	/**
	 * Whether a rule pattern is written for the platform's own work: its
	 * path, lowercased as a rule matches, is one of `PLATFORM_WORKERS`', or
	 * is `/jobs` or below, where a job context runs. The flame builder lets a
	 * worker's record tune only such a rule; one under `/jobs` may also
	 * govern a site's own pages there.
	 *
	 * @param string $pattern A rule's URL pattern: a prefix, or an exact match with a `?` and any query.
	 */
	public static function serves_platform( string $pattern ): bool {
		$path = \strtolower( \explode( '?', $pattern, 2 )[0] );
		return isset( self::PLATFORM_WORKERS[ $path ] ) || '/jobs' === $path || \str_starts_with( $path, '/jobs/' );
	}

	/**
	 * Mint the request id, pick its partition, and attach the firehose Topic.
	 *
	 * The id comes from `UNIQUE_ID`, which Apache's mod_unique_id sets and a
	 * client cannot, else it is generated and published back into
	 * `$_SERVER['UNIQUE_ID']` so a subprocess inherits the same identity. No
	 * request header is ever a source: the id is every firehose line's Message
	 * KEY, the identity Request_Builder_Node groups by and the input to
	 * Partition_Node::hash_to_partition(), so a client-suppliable one would let
	 * a visitor file lines under another request's id and pick their partition.
	 * The edge's own id stays available for correlation as the allowlisted
	 * `HTTP_X_A8C_REQUEST_ID` value in the `environment_v3` entry, which is what
	 * `wp nodes reqgrep <edge id>` finds the request through.
	 *
	 * `_firehose:topic` is built once per process and adopted by every later
	 * context.
	 */
	private function init_firehose(): void {
		// request_id FIRST: Topic ctor re-enters message(), which needs a rid.
		if ( ! empty( $_SERVER['UNIQUE_ID'] ) && \is_string( $_SERVER['UNIQUE_ID'] ) ) {
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
			$this->topic->patron( $this->topic );
			// Per request: no :config here or on the partitions it builds.
			$this->topic->without_config();
			$this->topic->name( '_firehose:topic' );
			$this->topic->arguments( [ $dir_template, (string) $num_partitions, (string) $segment_size, (string) $min_segments, (string) $num_segments, (string) $max_segments, (string) $min_lifetime, (string) $lifetime ] );
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
	 *
	 * @param string $logs_dir Root the partition dirs hang from.
	 */
	public static function firehose_dir_template( string $logs_dir = '<config:logs_dir>' ): string {
		return \rtrim( $logs_dir, '/' ) . '/' . self::FIREHOSE . '.p{partition}';
	}

	/**
	 * Generate a new request ID: 32 base-36 characters over 20 random bytes.
	 *
	 * @return string
	 */
	public static function generate_request_id(): string {
		return self::encode_request_id( \random_bytes( 20 ) );
	}

	/**
	 * Spell bytes as a request id, eight base-36 digits per five bytes.
	 *
	 * @param string $bytes Raw bytes, a multiple of five long.
	 * @return string
	 */
	public static function encode_request_id( string $bytes ): string {
		$rid = '';
		foreach ( \str_split( $bytes, 5 ) as $chunk ) {
			$rid .= \str_pad( \base_convert( \bin2hex( $chunk ), 16, 36 ), 8, '0', STR_PAD_LEFT );
		}
		return $rid;
	}

	/**
	 * The `scheme://host` this request was served under: SERVER_NAME, with
	 * the scheme HTTPS names. A WP-CLI process that set no SERVER_NAME takes
	 * both from home_url(), the site's own origin. Every logged URL carries a
	 * host, so a request with none is refused rather than logged as a path.
	 *
	 * @throws \RuntimeException When SERVER_NAME is unset or empty outside WP-CLI, or under WP-CLI when home_url() names no host either.
	 */
	private static function origin(): string {
		$server_name = \is_string( $_SERVER['SERVER_NAME'] ?? null ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
		if ( '' !== $server_name ) {
			$scheme = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ? 'https' : 'http';
			return "{$scheme}://{$server_name}";
		}
		if ( ! ( \defined( 'WP_CLI' ) && \WP_CLI ) ) {
			throw new \RuntimeException( 'Log_Manager: SERVER_NAME is unset, so the request has no host to log its URL under' );
		}
		$home   = \home_url();
		$scheme = Core::as_string( \wp_parse_url( $home, \PHP_URL_SCHEME ) );
		$host   = Core::as_string( \wp_parse_url( $home, \PHP_URL_HOST ) );
		if ( '' === $scheme || '' === $host ) {
			throw new \RuntimeException( 'Log_Manager: SERVER_NAME is unset and home_url() names no host, so the WP-CLI request has no host to log its URL under' );
		}
		return "{$scheme}://{$host}";
	}

	/**
	 * Open the request: `process (start)`, `request`, environment, and the
	 * resources sample a rule's `log_resources` asks for.
	 *
	 * The `process` frame this pushes is the root of the timer stack — finish()
	 * closes it last, and every orphaned frame above it drains first.
	 *
	 * @param string $origin The request's `scheme://host`, from `origin()`.
	 */
	private function log_process( string $origin ): void {
		$process_hr   = $this->request_time ?? \hrtime( true );
		$process_data = [ 'm' => \getmypid() . ' on ' . \gethostname() . ', WordPress ' . \get_bloginfo( 'version' ), 'l' => '' ];

		if ( null !== $this->request_ts ) {
			$process_data['ts'] = $this->request_ts;
		}
		$process_data['rule'] = $this->governing_rule_id();

		$this->message( self::REQUEST_START, $process_data );
		$this->times[] = [ 'label' => self::REQUEST_LABEL, 'ts' => $process_hr, 'at' => $this->request_ts ?? Core::$now ];
		// A web request is its process; a job counts from its own start.
		$this->cache_from = $this->matched_rule?->log_cache ? Object_Cache_Counts::mark( [] === self::$job_stack ) : null;
		$this->log_worker();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- CONTROL_CHARS stripped, as REQUEST_URI is.
		$method = \is_string( $_SERVER['REQUEST_METHOD'] ?? null ) ? \preg_replace( self::CONTROL_CHARS, '', $_SERVER['REQUEST_METHOD'] ) ?? '' : '';
		$method = \strtr( $method, ' &?', '___' );
		$method = '' === $method ? 'CLI' : \substr( $method, 0, self::REQUEST_METHOD_MAX );
		$this->message( self::REQUEST_LINE, [ 'm' => "{$method} {$origin}{$this->request_url}" ] );

		// The record that caused this request; ID seeks onto the log.
		$job_message = self::job_message();
		if ( [] !== $job_message ) {
			$this->message(
				self::JOB_MESSAGE,
				[
					'm' => [
						'FROM' => Core::as_string( $job_message[ \Newspack_Nodes\Message::FROM ] ?? '', '' ),
						'ID'   => Core::as_string( $job_message[ \Newspack_Nodes\Message::ID ] ?? '', '' ),
						'KEY'  => Core::as_string( $job_message[ \Newspack_Nodes\Message::KEY ] ?? '', '' ),
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
	 * a reader can difference the two. Silent unless the governing rule says
	 * `log_resources`, and when getrusage() is unavailable.
	 */
	private function log_resources(): void {
		if ( ! $this->matched_rule?->log_resources ) {
			return;
		}
		$r = \getrusage();
		if ( ! $r ) {
			return;
		}
		$info = [
			\sprintf( 'utime => %F',  ( $r['ru_utime.tv_sec'] ?? 0 ) + ( $r['ru_utime.tv_usec'] ?? 0 ) / 1000000 ),
			\sprintf( 'stime => %F',  ( $r['ru_stime.tv_sec'] ?? 0 ) + ( $r['ru_stime.tv_usec'] ?? 0 ) / 1000000 ),
			\sprintf( 'maxrss => %d',   $r['ru_maxrss']   ?? 0 ),
			\sprintf( 'minflt => %d',   $r['ru_minflt']   ?? 0 ), \sprintf( 'majflt => %d',  $r['ru_majflt']  ?? 0 ),
			\sprintf( 'inblock => %d',  $r['ru_inblock']  ?? 0 ), \sprintf( 'oublock => %d', $r['ru_oublock'] ?? 0 ),
			\sprintf( 'nsignals => %d', $r['ru_nsignals'] ?? 0 ),
			\sprintf( 'nvcsw => %d',    $r['ru_nvcsw']    ?? 0 ), \sprintf( 'nivcsw => %d',  $r['ru_nivcsw']  ?? 0 ),
		];
		$this->message( self::RESOURCES, [ 'm' => \implode( ', ', $info ) ] );
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
			$sanitized = \preg_replace( self::CONTROL_CHARS, '', Core::as_string( $value ) ) ?? '';
			// Redact URL secrets in every value, not just HTTP_REFERER.
			$sanitized = self::redact_url( $sanitized );
			// Cap AFTER redaction so truncation can't hide a secret's boundary.
			if ( \strlen( $sanitized ) > self::ENV_VALUE_MAX ) {
				$sanitized = \substr( $sanitized, 0, self::ENV_VALUE_MAX ) . '…';
			}
			$env[ $key ] = $sanitized;
		}
		if ( ! empty( $env ) ) {
			$this->message( self::ENVIRONMENT, [ 'm' => $env ] );
		}
	}

	/**
	 * The job message the innermost job context runs under; none outside one.
	 *
	 * @return array<int,mixed>
	 */
	private static function job_message(): array {
		return [] === self::$job_stack ? [] : self::$job_stack[ \count( self::$job_stack ) - 1 ][1];
	}

	/**
	 * Name the worker this request runs in, as two entries of its own. The
	 * substrate's env var wins; a platform request without one is named by
	 * its path; a page writes neither.
	 *
	 * A job context names the type alone. The job is a run inside the worker,
	 * so its row is `/jobs/{handler}/{id}?<type>` whichever partition ran it,
	 * and the partition entry belongs to the worker's own record. `$_SERVER`
	 * keeps the partition, because a job handler reads it for its lease.
	 */
	private function log_worker(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately below.
		$worker_type = \sanitize_text_field( Core::as_string( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] ?? '' ) );
		if ( '' === $worker_type ) {
			$worker_type = self::platform_worker_type( $this->request_url );
		}
		if ( '' === $worker_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- is_numeric() validates; (int) sanitizes.
		$partition = [] === self::$job_stack ? $_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] ?? null : null;
		// A value that is not a number names no partition.
		$this->write_worker( $worker_type, \is_numeric( $partition ) ? (int) $partition : null );
	}

	/**
	 * The two worker entries: the type, then the partition where one exists.
	 *
	 * @param string   $type      Worker type.
	 * @param int|null $partition Partition, or null when the worker has none.
	 */
	private function write_worker( string $type, ?int $partition ): void {
		$this->message( self::WORKER_TYPE, [ 'm' => $type ] );
		if ( null !== $partition ) {
			$this->message( self::WORKER_PARTITION, [ 'm' => $partition ] );
		}
	}

	/**
	 * Write one firehose line: a TM_STRUCT Message keyed by the request id.
	 *
	 * The entry carries the line number as `n`, the category as `k`, the caller's
	 * data, and a `ts` timestamp. An entry whose encoding exceeds MAX_DATA_SIZE
	 * is NOT chunked: fit_data() shrinks it, trimming or dropping `m` first.
	 *
	 * @param string $category Event category/keyword.
	 * @param array<string,mixed>  $data     Additional data to include.
	 * @param bool   $shaped   Whether `m` is a query shape — see start().
	 * @return bool True when the line was written; false when logging never started or the Topic is missing.
	 */
	public function message( string $category, array $data = [], bool $shaped = false ): bool {
		if ( ! $this->started ) {
			return false;
		}
		if ( ! $shaped && isset( $data['m'] ) && \is_string( $data['m'] ) ) {
			$data['m'] = self::redact_url( $data['m'] );
		}
		if ( null === $this->topic ) {
			return false;
		}

		// @longform A FRESH read, deliberately, and the one on this path: the
		// cached tick is pinned at load in request scope, so every line of a
		// request would carry one `ts` and the timeline would be flat. It
		// re-pins `Core::$now` as a side effect, which is why a reply reads
		// its clock once and passes it down (decision 29).
		$now   = Core::right_now();
		$entry = [ 'n' => $this->line_number++, 'k' => $category ] + $data + [ 'ts' => $now ];
		// Fit the ENTRY, not $data: the cap must bound what the wire carries.
		$entry_json = \wp_json_encode( $entry, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( false !== $entry_json && \strlen( $entry_json ) > self::MAX_DATA_SIZE ) {
			$entry = self::fit_data( $entry, $entry_json );
		}
		$message                                       = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$message[ \Newspack_Nodes\Message::TIMESTAMP ] = $now;
		$message[ \Newspack_Nodes\Message::KEY ]       = $this->request_id;
		$message[ \Newspack_Nodes\Message::VALUE ]     = $entry;
		$this->topic->fill( $message );

		if ( $this->matched_rule?->flush_every_line ) {
			$this->topic->flush();
		}

		return true;
	}

	/**
	 * Shrink an oversized entry to fit MAX_DATA_SIZE.
	 *
	 * `m` is the only value that reaches this size: an array `m` is dropped
	 * whole and a string `m` is trimmed to whatever room the other keys leave.
	 * The category `k` is never renamed — that breaks `Flame_Tree::PATTERN_START`
	 * and a trimmed span would never open.
	 *
	 * @param array<string,mixed> $entry      The oversized entry.
	 * @param string              $entry_json Its encoding, already measured.
	 * @return array<string,mixed> An entry whose encoding fits.
	 */
	private static function fit_data( array $entry, string $entry_json ): array {
		$flags              = \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR;
		$m                  = $entry['m'] ?? null;
		$entry['truncated'] = true;
		if ( \is_string( $m ) ) {
			for ( $len = self::MAX_DATA_SIZE; $len > 0; $len = (int) ( $len * 0.9 ) ) {
				$entry['m'] = \substr( $m, 0, $len );
				$encoded    = \wp_json_encode( $entry, $flags );
				if ( false !== $encoded && \strlen( $encoded ) <= self::MAX_DATA_SIZE ) {
					return $entry;
				}
			}
		}
		unset( $entry['m'] );
		$encoded = \wp_json_encode( $entry, $flags );
		if ( false !== $encoded && \strlen( $encoded ) <= self::MAX_DATA_SIZE ) {
			return $entry;
		}
		// Floor: a truncated dump keeping n/k/ts, or no reader can place it.
		return [
			'n'         => $entry['n'] ?? 0,
			'k'         => $entry['k'] ?? '',
			'm'         => \substr( $entry_json, 0, 1000 ) . '...',
			'ts'        => $entry['ts'] ?? 0,
			'truncated' => true,
		];
	}

	/**
	 * Redact sensitive query parameters from a URL. Public because it is the
	 * ONE redaction path: anything that sends a URL somewhere it was not
	 * already written — the Ask brief, an agent surface — goes through here,
	 * not through a second pattern that would drift from this one.
	 *
	 * The one place either producer decides whether a string has a query, and
	 * the answer is every head in it: one linear walk over each separator
	 * `URL_PARAMETER` reads, followed by a name and `=`, wherever it sits, so
	 * a string with no head comes back as written, and one holding neither
	 * `?` nor `&` comes back before any PCRE call. A name is sensitive when
	 * `URL_REDACT_PATTERN` matches it as sent OR as `read_name()` reads it,
	 * a reading taken only for a name holding `%` or `+`, and either
	 * way it is written back as sent. A `?` inside a name stays part of it,
	 * and a short token may start a segment at that `?`, so `x?key` is
	 * sensitive, as is `xtoken?a`, whose token sits ahead of it; a `?` inside
	 * a value opens its own parameter. A sensitive name keeps its head and
	 * has its value, up to the next `&`, replaced by `[REDACTED]`, and a
	 * parameter whose name starts inside that value is skipped. A run of
	 * separators is one head, and each head is read in turn, so memory stays
	 * flat however many a hostile string packs.
	 * A PCRE failure keeps only the text ahead of the first `?` or `&` and
	 * appends `?[REDACTED]`, never the URL as sent.
	 * `Gyrobase::Log::_redact_url` is the Perl twin.
	 *
	 * @param string $url URL to redact.
	 * @return string Redacted URL.
	 */
	public static function redact_url( string $url ): string {
		if ( false === \strpbrk( $url, '?&' ) ) {
			return $url;
		}
		$redacted = '';
		$copied   = 0;
		$value    = 0;
		while ( 1 === \preg_match( self::URL_PARAMETER, $url, $parameter, \PREG_OFFSET_CAPTURE, $value ) ) {
			[ [ $head, $offset ], [ $name, $at ], [ $equals ] ] = $parameter;
			$value = $offset + \strlen( $head );
			if ( '' === $equals || $at <= $copied ) {
				continue;
			}
			if ( 0 === \preg_match( self::URL_REDACT_PATTERN, $name ) && ( false === \strpbrk( $name, '%+' ) || 0 === \preg_match( self::URL_REDACT_PATTERN, self::read_name( $name ) ) ) ) {
				continue;
			}
			$end       = \strpos( $url, '&', $value );
			$redacted .= \substr( $url, $copied, $value - $copied ) . '[REDACTED]';
			$copied    = false === $end ? \strlen( $url ) : $end;
		}
		if ( \PREG_NO_ERROR !== \preg_last_error() ) {
			return \substr( $url, 0, \strcspn( $url, '?&' ) ) . '?[REDACTED]';
		}
		return $redacted . \substr( $url, $copied );
	}

	/**
	 * A query parameter name as redaction reads it: `%XX` decoded, `+` and
	 * `%20` read as `_`, and cut at its first NUL, so `api%5Fkey`, `api+key`
	 * and `api%20key` all read `api_key`, and `key%00x` reads `key`. Unlike
	 * `$_GET`, `.` and `[` stay as sent, and a raw space stays a space: no
	 * request line carries one, so it is prose, and reading it as `_` would
	 * make `The client said` a `_client_` segment.
	 *
	 * @param string $name The name as sent.
	 */
	public static function read_name( string $name ): string {
		return \explode( "\0", \urldecode( \str_ireplace( [ '+', '%20' ], '_', $name ) ), 2 )[0];
	}

	/**
	 * The worker type a request URL names by its path, or '' for a page.
	 *
	 * @param string $url The request URI, query and all.
	 * @return string One of `PLATFORM_WORKERS`' values, or ''.
	 */
	private static function platform_worker_type( string $url ): string {
		$path = \wp_parse_url( $url, PHP_URL_PATH );
		return self::PLATFORM_WORKERS[ \is_string( $path ) ? $path : '' ] ?? '';
	}

	/**
	 * The governing rule's id, or '' when nothing matched. Rides the
	 * `process (start)` line as `rule`, which is how a reader attributes a
	 * request to the rule that admitted it.
	 */
	public function governing_rule_id(): string {
		return $this->matched_rule->id ?? '';
	}

}
