<?php
/**
 * Log Manager
 *
 * JSONL logging via Newspack_Nodes Topic + Partition.
 * This is the public API for Pyrobase and other plugins to log events.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log manager class.
 *
 * Singleton that provides the request lifecycle logging API.
 * External plugins (Pyrobase, etc.) use this to log custom events.
 */
class Log_Manager {

	/** @var bool */
	public $enabled          = false;
	/** @var bool|null */
	private $started         = null;
	/** @var bool */
	private $finished        = false;
	/** @var \Newspack_Nodes\Topic_Node|null */
	private $topic           = null;
	/** @var int */
	private $partition_idx   = 0;
	/** @var bool */
	private $line_limited    = false;
	/** @var int */
	private $line_number     = 1;
	/** @var array<int, array{label: string, ts: int|float, muted?: bool, m?: mixed}> Timer-frame stack. */
	private $times           = [];
	/** @var float|null */
	private $request_time    = null;
	/** @var int|float|null */
	private $request_ts      = null;
	/** @var string */
	private $request_id      = '';
	/** @var string */
	private $request_url     = '';
	private static ?self $instance = null;

	/** @var array<int, self> Stack of suspended parent LogManager instances. */
	private static $context_stack = [];

	/** @var array<int, array<string, mixed>> LIFO $_SERVER snapshots for begin/end_job_context. */
	private static array $job_server_stack = [];

	/** @var array<string, mixed> Cached config (loaded once at construction). */
	private $config = [];

	/** @var bool Append peak_mb to every complete() entry for memory profiling. */
	private $log_memory = false;

	/** @var bool Flush write buffer after every log line (survives OOM/crash). */
	private $flush_every_line = false;

	/** Saved UNIQUE_ID for suspend/resume. */
	private ?string $saved_unique_id = null;

	/** @var string|null Compiled regex for skip URL patterns. */
	private $skip_regex = null;

	/** @var string|null Compiled regex for log URL patterns. */
	private $log_regex = null;

	/** @var int Maximum timer stack depth to prevent unbounded growth. */
	private const MAX_TIMER_DEPTH = 100;

	/** @var int Maximum data size in bytes for log entry data arrays. */
	private const MAX_DATA_SIZE = 3840;

	/** @var int Mute start/complete after this many lines, leaving room for messages + finish. */
	private const MAX_LOG_LINES = 40000;

	/** @var int Nanoseconds-to-milliseconds divisor. */
	private const NS_PER_MS = 1_000_000;

	/** @var int Bytes-to-megabytes divisor. */
	private const BYTES_PER_MB = 1024 * 1024;

	/**
	 * PHP error types that indicate a fatal crash.
	 */
	const FATAL_TYPES = [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR ];

	/** @var array<string, bool> Hash set for fast sensitive key lookup. */
	private static array $sensitive_keys = [
		'AUTH_KEY'                 => true,
		'AUTH_SALT'                => true,
		'DB_PASSWORD'              => true,
		'DB_USER'                  => true,
		'HTTP_AUTHORIZATION'       => true,
		'HTTP_COOKIE'              => true,
		'HTTP_PROXY_AUTHORIZATION' => true,
		'HTTP_X_API_KEY'           => true,
		'HTTP_X_AUTH_TOKEN'        => true,
		'HTTP_X_CSRF_TOKEN'        => true,
		'HTTP_X_XSRF_TOKEN'        => true,
		'LOGGED_IN_KEY'            => true,
		'LOGGED_IN_SALT'           => true,
		'NONCE_KEY'                => true,
		'NONCE_SALT'               => true,
		'SECURE_AUTH_KEY'          => true,
		'SECURE_AUTH_SALT'         => true,
		'TERMCAP'                  => true,
	];

	/** @var array<int, string> Sensitive substrings to check in keys. */
	private static array $sensitive_substrings = [
		'AUTH',
		'BEARER',
		'CREDENTIAL',
		'DSN',
		'KEY',
		'NONCE',
		'PASS',
		'PASSWD',
		'PASSWORD',
		'PRIVATE',
		'SALT',
		'SECRET',
		'TOKEN',
		'_URL',
	];

	/** @var string Regex for sensitive URL query parameters. */
	private const URL_REDACT_PATTERN = '/([?&])(key|api_key|apikey|token|access_token|auth_token|refresh_token|password|passwd|pwd|secret|api_secret|client_secret|private_key|subscription[_-]?key|bearer|authorization|auth|session|sessionid|credentials)=[^&]*/i';

	public function __construct() {
		// Assign self FIRST: load_config() can re-enter instance(); a null $instance would spawn a second LM and recurse.
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
		$this->skip_regex = self::compile_url_filter( $this->config['skip_urls'] ?? [] );
		$this->log_regex  = self::compile_url_filter( $this->config['log_urls'] ?? [] );

		/** @var array{request_time?: float, request_ts?: int|float}|null $newspack_profiler */
		global $newspack_profiler;
		if ( null !== $newspack_profiler ) {
			$this->request_time = $newspack_profiler['request_time'] ?? null;
			$this->request_ts   = $newspack_profiler['request_ts']   ?? null;
			unset( $newspack_profiler['request_time'], $newspack_profiler['request_ts'] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->request_url = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( self::to_string( $_SERVER['REQUEST_URI'] ) ) ) : '/unknown';
		$this->matches_url_filter( $this->request_url );
	}

	/**
	 * Compile a URL-filter pattern list into a start-anchored regex, or null.
	 *
	 * Patterns prefix-match the request path with a '?' terminator appended
	 * (see matches_url_filter), so a pattern ending in '?' matches exactly.
	 *
	 * @param mixed $urls Config value, expected to be an array of pattern strings.
	 * @return string|null Compiled regex, or null when there are no patterns.
	 */
	private static function compile_url_filter( $urls ): ?string {
		if ( ! \is_array( $urls ) || empty( $urls ) ) {
			return null;
		}
		$patterns = \array_filter( \array_map( static fn( $u ) => \trim( self::to_string( $u ) ), $urls ), static fn ( $v ) => (bool) $v );
		if ( empty( $patterns ) ) {
			return null;
		}
		// `/^(?:a|b)/i` — start-anchored; the (?:) group anchors EVERY alternative.
		return '/^(?:' . \implode( '|', \array_map( fn( $p ) => \preg_quote( $p, '/' ), $patterns ) ) . ')/i';
	}

	/**
	 * Narrow a mixed $_SERVER / config value to a string, reproducing the
	 * `(string)` coercion the surrounding code already applies to scalars
	 * (these values are always scalar strings in practice).
	 *
	 * @param mixed $value Value to coerce.
	 */
	private static function to_string( $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Check if URL matches the configured filter patterns.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL should be logged.
	 */
	public function matches_url_filter( string $url ): bool {
		$target = \explode( '?', $url, 2 )[0] . '?';
		if ( null !== $this->skip_regex && \preg_match( $this->skip_regex, $target ) ) {
			$this->enabled = false;
			return false;
		}
		if ( null === $this->log_regex ) {
			$this->enabled = true;
			return true;  // No filter = log all.
		}
		if ( \preg_match( $this->log_regex, $target ) ) {
			$this->enabled = true;
			return true;
		}
		$this->enabled = false;
		return false;
	}

	/**
	 * Log final summary including memory usage and resources.
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

		$this->complete( 'process', \array_merge( [ 'status_code' => \http_response_code() ?: 0 ], $complete_extra ) );
		$this->topic?->flush();
		$this->started = false;
	}

	/**
	 * Emit a single orphaned `(complete)` line for an unclosed timer-stack
	 * frame. Shared by complete()'s mismatched-close drain and finish()'s
	 * end-of-request stack close; muted frames are skipped.
	 *
	 * @param array{label: string, ts: int|float, muted?: bool, m?: mixed} $entry Timer-stack frame.
	 * @param int|float $now Reference hrtime() reading.
	 */
	private function emit_orphaned_complete( array $entry, $now ): void {
		if ( ! empty( $entry['muted'] ) ) {
			return;
		}
		$duration_ms = ( $now - $entry['ts'] ) / self::NS_PER_MS;
		$this->message( "{$entry['label']} (complete)", [ 'm' => '(orphaned)', 'duration_ms' => $duration_ms ] );
	}

	/**
	 * Log a message with the given category and data.
	 *
	 * @param string $category Event category/keyword.
	 * @param array<string, mixed>  $data     Additional data to include.
	 * @return bool True on success.
	 */
	public function message( string $category, array $data = [] ): bool {
		if ( ! $this->ensure_started() ) {
			return false;
		}
		if ( isset( $data['m'] ) && \is_string( $data['m'] ) && false !== \strpos( $data['m'], '?' ) ) {
			$data['m'] = self::redact_url( $data['m'] );
		}
		$data_json = \wp_json_encode( $data );
		if ( false !== $data_json && \strlen( $data_json ) > self::MAX_DATA_SIZE ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( 'LogManager: data truncated for category "%s", size=%d (limit=%d).', $category, \strlen( $data_json ), self::MAX_DATA_SIZE ) );
			$category .= ' (truncated)';
			$data = [ 'm' => \substr( $data_json, 0, 1000 ) . '...' ];
		}
		if ( null === $this->topic ) {
			return false;
		}
		// Strip caller-supplied rid: the real one lives in Message::KEY; this stops a forged rid being smuggled in.
		unset( $data['rid'] );

		$entry = [ 'n' => $this->line_number, 'k' => $category ] + $data + [ 'ts' => \microtime( true ) ];
		$message                                       = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$message[ \Newspack_Nodes\Message::TIMESTAMP ] = \Newspack_Nodes\Core::$now;
		$message[ \Newspack_Nodes\Message::KEY ]       = $this->request_id;
		$message[ \Newspack_Nodes\Message::VALUE ]     = $entry;
		$this->topic->fill( $message );
		++$this->line_number;

		if ( $this->flush_every_line ) {
			$this->topic->flush();
		}

		if ( $this->line_number > self::MAX_LOG_LINES && ! $this->line_limited ) {
			$this->line_limited = true;
		}
		return true;
	}

	/**
	 * Ensure that full logging has started.
	 *
	 * @return bool True if logging is started.
	 */
	private function ensure_started(): bool {
		if ( $this->started || ! $this->enabled || $this->finished ) {
			return $this->started ?? false;
		}
		// Set started=true BEFORE init_firehose: it can re-enter ensure_started(); this short-circuits the recursion.
		$this->started = true;
		\register_shutdown_function( [ $this, 'finish' ] );
		$this->init_firehose( $this->config );
		$this->log_process();
		return true;
	}

	/**
	 * Finish initialization
	 *
	 * @param array<string, mixed> $config
	 */
	private function init_firehose( array $config ): void {
		// Set request_id FIRST: the Topic ctor can re-enter message(), which needs a valid rid.
		if ( ! empty( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) && \is_string( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) ) {
			$this->request_id = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) ), 0, 64 );
		} elseif ( ! empty( $_SERVER['UNIQUE_ID'] ) && \is_string( $_SERVER['UNIQUE_ID'] ) ) {
			$this->request_id = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['UNIQUE_ID'] ) ), 0, 64 );
		} else {
			$this->request_id     = self::generate_request_id();
			$_SERVER['UNIQUE_ID'] = $this->request_id;
		}

		$dir_template        = Config::get_logs_directory() . '/firehose.p{partition}';
		$num_partitions      = self::to_int( $config['num_partitions'] ?? 1 );
		$num_partitions      = $num_partitions > 0 ? $num_partitions : 1;
		$this->partition_idx = Partition_Node::hash_to_partition( $this->request_id, $num_partitions );
		$segment_size = self::to_int( $config['segment_size'] ?? Partition_Node::DEFAULT_SEGMENT_SIZE );
		$num_segments = self::to_int( $config['num_segments'] ?? Partition_Node::DEFAULT_NUM_SEGMENTS );
		$max_lifespan = self::to_int( $config['max_lifespan'] ?? Partition_Node::DEFAULT_MAX_LIFESPAN );
		$existing = Core::node( 'firehose:topic' );
		if ( $existing instanceof Topic_Node ) {
			$this->topic = $existing;
		} else {
			$this->topic = new Topic_Node();
			$this->topic->name( 'firehose:topic' );
			$this->topic->arguments( "{$dir_template} {$num_partitions} {$segment_size} {$num_segments} {$max_lifespan}" );
			$this->topic->patron( $this->topic );
			$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
			if ( null !== $ci ) {
				$this->topic->sink( $ci );
			}
		}
	}

	/**
	 * Generate a new request ID for the current request.
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
	 * Narrow a mixed config value to an int, reproducing the `(int)`
	 * coercion the surrounding code already applies to scalars (these
	 * values are always scalar in practice).
	 *
	 * @param mixed $value Value to coerce.
	 */
	private static function to_int( $value ): int {
		return \is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Log process details
	 *
	 * @return void
	 */
	private function log_process(): void {
		$process_hr   = $this->request_time ?? \hrtime( true );
		$process_data = [ 'm' => \getmypid() . ' on ' . \gethostname(), 'l' => '' ];

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately below.
		$worker_type = \sanitize_text_field( self::to_string( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] ?? '' ) );
		if ( '' !== $worker_type ) {
			$process_data['worker_type'] = $worker_type;
		}
		if ( null !== $this->request_ts ) {
			$process_data['ts'] = $this->request_ts;
		}

		$this->message( 'process (start)', $process_data );
		$this->times[] = [ 'label' => 'process', 'ts' => $process_hr ];

		$method       = \is_string( $_SERVER['REQUEST_METHOD'] ?? null ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'CLI';
		$server_name  = \is_string( $_SERVER['SERVER_NAME'] ?? null ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
		$scheme       = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ? 'https' : 'http';
		$redacted_url = self::redact_url( $this->request_url );
		$full_url     = $server_name ? "{$scheme}://{$server_name}{$redacted_url}" : $redacted_url;
		$this->message( 'request', [ 'm' => "{$method} {$full_url}" ] );

		$this->log_environment();
		$this->log_resources();
	}

	/**
	 * Redact sensitive query parameters from a URL.
	 *
	 * @param string $url URL to redact.
	 * @return string Redacted URL.
	 */
	private static function redact_url( string $url ): string {
		return \preg_replace( self::URL_REDACT_PATTERN, '$1$2=[REDACTED]', $url ) ?? $url;
	}

	private function log_environment(): void {
		/** @var array<string, true> $url_value_keys */
		static $url_value_keys = [
			'HTTP_REFERER'          => true,
			'QUERY_STRING'          => true,
			'REDIRECT_QUERY_STRING' => true,
			'REDIRECT_URL'          => true,
			'REQUEST_URI'           => true,
		];
		$keys = \array_keys( $_SERVER );
		\sort( $keys );
		foreach ( $keys as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with preg_replace.
			$value = $_SERVER[ $key ];
			if ( \is_array( $value ) || self::is_sensitive_key( $key ) ) {
				continue;
			}
			$sanitized = \preg_replace( '/[\x00-\x1F\x7F]/', '', self::to_string( $value ) ) ?? '';
			if ( isset( $url_value_keys[ $key ] ) ) {
				$sanitized = self::redact_url( $sanitized );
			}
			$this->message( 'environment_v2', [
				'm' => \sprintf( '%s => "%s"',
					$key,
					\str_replace( '"', '\\"', $sanitized )
				)
			] );
		}
	}

	/**
	 * Check if a $_SERVER key should be redacted.
	 *
	 * @param string $key Server variable key.
	 * @return bool True if sensitive.
	 */
	private static function is_sensitive_key( string $key ): bool {
		if ( isset( self::$sensitive_keys[ $key ] ) ) {
			return true;
		}
		$key_upper = \strtoupper( $key );
		foreach ( self::$sensitive_substrings as $pattern ) {
			if ( false !== \strpos( $key_upper, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

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
	 * Complete a labeled operation and log the duration.
	 *
	 * @param string $label Label that was passed to start().
	 * @param array<string, mixed>  $data  Additional data to include in the complete event.
	 */
	public function complete( string $label, array $data = [] ): void {
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
			if ( empty( $match['muted'] ) ) {
				$this->message( "{$label} (complete)", $data );
			}
		}
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
	 * Start timing a labeled operation.
	 *
	 * @param string $label Label for the timer (e.g., 'query', 'template').
	 * @param array<string, mixed>  $data  Additional data to include in the start event.
	 */
	public function start( string $label, array $data = [] ): void {
		if ( \count( $this->times ) >= self::MAX_TIMER_DEPTH ) {
			return;
		}
		$muted = $this->line_limited;
		if ( ! $muted ) {
			if ( false === $this->message( "{$label} (start)", $data ) ) {
				return;
			}
		}
		$entry = [ 'label' => $label, 'ts' => \hrtime( true ), 'muted' => $muted ];
		if ( ! empty( $data['m'] ) ) {
			$entry['m'] = $data['m'];
		}
		$this->times[] = $entry;
	}

	/**
	 * Leave a background-job request context: resume the parent LogManager and
	 * restore the $_SERVER snapshot pushed by begin_job_context(). The symmetric
	 * pair to begin_job_context() — safe to call on an empty stack (no-op restore)
	 * so a throwing/unpaired begin can't fatal here.
	 */
	public static function end_job_context(): void {
		self::resume();
		if ( ! empty( self::$job_server_stack ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restoring saved value.
			$_SERVER = \array_pop( self::$job_server_stack );
		}
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
	 * Enter a background-job request context.
	 *
	 * The substrate's Job_Worker_Node fires `newspack_nodes/job_worker/before_job`
	 * around each handler; this is the event-logger's hooked listener. It suspends
	 * the parent LogManager, generates a fresh per-job UNIQUE_ID, and rewrites
	 * $_SERVER to a synthetic `/jobs/{handler}` request so any LogManager the
	 * handler spawns picks up job-scoped context.
	 *
	 * Stack-based by design: the before/after-job actions thread no state, so the
	 * original $_SERVER is pushed onto an internal LIFO restored by
	 * end_job_context(). The snapshot is taken FIRST so a partial $_SERVER edit
	 * mid-method still leaves a complete snapshot to restore from, and so an
	 * unpaired/throwing begin still leaves end_job_context a snapshot to pop.
	 * Public static so handlers (and direct callers like cron) can nest their own
	 * sub-scopes — pair with end_job_context() in a finally block.
	 */
	public static function begin_job_context( string $handler ): void {
		// $_SERVER is string-keyed by design (superglobal snapshot for restore).
		/** @var array<string, mixed> $snapshot */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- snapshot for restore.
		$snapshot                 = $_SERVER;
		self::$job_server_stack[] = $snapshot;

		// LogManager::suspend() pushes the parent context onto its stack.
		self::suspend();

		$path_info = '/' . \ltrim( $handler, '/' );
		/** @var int|float|string|bool|null $raw_server_name */
		$raw_server_name = $_SERVER['SERVER_NAME'] ?? 'localhost'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- internal-only context.
		$server_name     = (string) $raw_server_name;

		$_SERVER['UNIQUE_ID']       = self::generate_request_id();
		$_SERVER['REQUEST_URI']     = '/jobs/' . \ltrim( $handler, '/' );
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['PATH_INFO']       = $path_info;
		$_SERVER['SCRIPT_NAME']     = $path_info;
		$_SERVER['SCRIPT_URL']      = $path_info;
		$_SERVER['SCRIPT_URI']      = 'https://' . $server_name . $path_info;
		$foundation_base = \defined( 'NEWSPACK_FOUNDATION_BASE' ) ? \NEWSPACK_FOUNDATION_BASE : '';
		$_SERVER['SCRIPT_FILENAME'] = ( \is_string( $foundation_base ) ? $foundation_base : '' ) . '/template';
		$_SERVER['QUERY_STRING']    = '';
		unset(
			$_SERVER['CONTENT_TYPE'],
			$_SERVER['CONTENT_LENGTH'],
			$_SERVER['HTTP_X_A8C_REQUEST_ID']
		);
	}

	/**
	 * Suspend the current LogManager and push it onto the context stack.
	 *
	 * The suspended instance keeps its state (timers, request ID, buffer)
	 * intact. A new instance will be created on the next instance() call.
	 * Call resume() to restore the parent context.
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
	 * Drain every materialized Partition's in-memory batch to disk.
	 *
	 * Equivalent of the legacy `flush_buffer()`. Callers that hand off to a
	 * subprocess writing to the same firehose (nuclear-gyrobase's run_gyrobase.sh,
	 * pyrobase's template execution) call this BEFORE `proc_open` so the
	 * parent's buffered Messages land in segment order before the child starts
	 * appending. Without it, the subprocess can write between the parent's
	 * accumulated Messages and the next size-threshold / timer flush, leaving
	 * entries on disk out of logical order.
	 *
	 * @api Used by external plugins (nuclear-gyrobase + pyrobase pre-proc_open flush).
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
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		return self::$instance ??= new self();
	}

	/**
	 * Get the request ID for the current request.
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
	 * so subsequent writes go to the current segment.
	 *
	 * @api Used by external plugins.
	 */
	public function refresh_firehose(): void {
		if ( null === $this->topic ) {
			return;
		}
		$ref_partition_method = new \ReflectionMethod( Topic_Node::class, 'partition' );
		$ref_partition_method->setAccessible( true );
		$partition = $ref_partition_method->invoke( $this->topic, $this->partition_idx );
		if ( ! $partition instanceof Partition_Node ) {
			return;
		}

		$ref_init = new \ReflectionMethod( Partition_Node::class, 'init_current_segment' );
		$ref_init->setAccessible( true );
		$ref_init->invoke( $partition );
	}

}
