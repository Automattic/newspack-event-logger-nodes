<?php
/**
 * Log Manager
 *
 * JSONL logging via Newspack_Nodes Topic + Partition. This is the public API
 * for Pyrobase and other plugins to log events.
 *
 * Ported from `Newspack_Performance_Logger\LogManager`. The on-disk format
 * (JSONL lines under `{base_dir}/firehose.log/p{N}/{seg}.log`) and atomic-write
 * contract (PIPE_BUF 4096 bytes) are unchanged. Storage is performed via
 * `Newspack_Nodes\Topic` constructed in request scope (lazy partition init —
 * no scandir during the constructor); writes go through `Topic::write()`,
 * which routes by URL CRC32 to the correct Partition.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Topic;
use Newspack_Nodes\Partition;

\defined( 'ABSPATH' ) || exit;

/**
 * Log manager class.
 *
 * Singleton that provides the request lifecycle logging API.
 * External plugins (Pyrobase, etc.) use this to log custom events.
 */
class LogManager {
	public bool $enabled         = false;
	private ?bool $started       = null;
	private bool $finished       = false;
	private ?Topic $topic        = null;
	/** @var int Partition index used for the current request's writes. */
	private int $partition_idx   = 0;
	private bool $line_limited   = false;
	private int $line_number     = 1;
	private array $times         = [];
	private ?float $request_time = null;
	private string $request_id   = '';
	private string $request_url  = '';
	private static ?self $instance = null;

	/** @var array Stack of suspended parent LogManager instances. */
	private static array $context_stack = [];

	/** @var array Cached config (loaded once at construction). */
	private array $config = [];

	/** @var bool Append peak_mb to every complete() entry for memory profiling. */
	private bool $log_memory = false;

	/** @var bool Flush write buffer after every log line (survives OOM/crash). */
	private bool $flush_every_line = false;

	/** @var string|null Saved UNIQUE_ID for suspend/resume. */
	private ?string $saved_unique_id = null;

	/** @var string|null Compiled regex for skip URL patterns. */
	private ?string $skip_regex = null;

	/** @var string|null Compiled regex for log URL patterns. */
	private ?string $log_regex = null;

	/** @var string Write buffer for batching (flush before exceeding 4KB atomic limit). */
	private string $write_buffer = '';

	/** @var int Current buffer size in bytes. */
	private int $buffer_size = 0;

	/** @var int Max buffer size - stay under 4KB for atomic writes. */
	private const MAX_BUFFER_SIZE = 4096;

	/** @var int Maximum timer stack depth to prevent unbounded growth. */
	private const MAX_TIMER_DEPTH = 100;

	/** @var int Maximum data size in bytes for log entry data arrays. */
	private const MAX_DATA_SIZE = 3840;

	/** @var int Mute start/complete after this many lines, leaving room for messages + finish. */
	private const MAX_LOG_LINES = 40000;

	/** @var float Nanoseconds-to-milliseconds divisor. */
	private const NS_PER_MS = 1_000_000;

	/**
	 * PHP error types that indicate a fatal crash.
	 */
	const FATAL_TYPES = [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR ];

	/** @var array Hash set for fast sensitive key lookup. */
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

	/** @var array Sensitive substrings to check in keys. */
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
		$this->config = Config::load_config();

		if ( empty( $this->config['enable_logging'] ) ) {
			return;
		}

		$this->log_memory       = ! empty( $this->config['log_memory'] );
		$this->flush_every_line = ! empty( $this->config['flush_every_line'] );

		// Refuse to run as root - creates permission problems for www-data workers.
		if ( \function_exists( 'posix_getuid' ) && 0 === \posix_getuid() ) {
			return;
		}

		// Compile URL filter patterns into single regex for O(1) matching.
		$skip_urls = $this->config['skip_urls'] ?? [];
		if ( \is_array( $skip_urls ) && ! empty( $skip_urls ) ) {
			$patterns = \array_filter( \array_map( 'trim', $skip_urls ) );
			if ( ! empty( $patterns ) ) {
				$this->skip_regex = '/' . \implode( '|', \array_map( fn( $p ) => \preg_quote( $p, '/' ), $patterns ) ) . '/i';
			}
		}
		$log_urls = $this->config['log_urls'] ?? [];
		if ( \is_array( $log_urls ) && ! empty( $log_urls ) ) {
			$patterns = \array_filter( \array_map( 'trim', $log_urls ) );
			if ( ! empty( $patterns ) ) {
				$this->log_regex = '/' . \implode( '|', \array_map( fn( $p ) => \preg_quote( $p, '/' ), $patterns ) ) . '/i';
			}
		}

		global $newspack_profiler;
		if ( null !== $newspack_profiler ) {
			$this->request_time = $newspack_profiler['request_time'] ?? null;
			unset( $newspack_profiler['request_time'] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->request_url = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/unknown';
		$this->matches_url_filter( $this->request_url );
	}

	/**
	 * Finish initialization. Construct the Topic and pre-compute the partition
	 * index so refresh_firehose() can map back to the active Partition.
	 *
	 * @param array $config Cached config (passed in to avoid reentry through Config::load_config).
	 */
	private function init_firehose( array $config ): void {
		// Set request ID FIRST — Topic constructor MAY trigger re-entrant
		// message() calls (via Config::load_config filters), and those need a valid rid.
		if ( ! empty( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) ) {
			$this->request_id = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_A8C_REQUEST_ID'] ) ), 0, 64 );
		} elseif ( ! empty( $_SERVER['UNIQUE_ID'] ) ) {
			$this->request_id = \substr( \sanitize_text_field( \wp_unslash( $_SERVER['UNIQUE_ID'] ) ), 0, 64 );
		} else {
			$this->request_id     = self::generate_request_id();
			$_SERVER['UNIQUE_ID'] = $this->request_id;
		}

		$base_dir            = Config::get_logs_directory() . '/firehose.log';
		$num_partitions      = (int) ( $config['num_partitions'] ?? 1 );
		$num_partitions      = $num_partitions > 0 ? $num_partitions : 1;
		$this->partition_idx = Partition::hash_to_partition( $this->request_url, $num_partitions );

		// Pass segment_size/num_segments/max_lifespan from core config to avoid
		// Topic calling load_config('full'), which fires option schema filters
		// and re-enters LogManager.
		$segment_size = (int) ( $config['segment_size'] ?? Partition::DEFAULT_SEGMENT_SIZE );
		$num_segments = (int) ( $config['num_segments'] ?? Partition::DEFAULT_NUM_SEGMENTS );
		$max_lifespan = (int) ( $config['max_lifespan'] ?? Partition::DEFAULT_MAX_LIFESPAN );

		$this->topic = new Topic( $base_dir, $num_partitions, $segment_size, $num_segments, $max_lifespan );
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
	 * Get the request ID for the current request.
	 *
	 * @return string
	 */
	public function get_request_id(): string {
		return $this->request_id;
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Reset the singleton instance.
	 *
	 * Call before changing REQUEST_URI to log a different request context.
	 * Only used by unit tests.
	 */
	public static function reset(): void {
		if ( null !== self::$instance ) {
			self::$instance->finish();
		}
		self::$instance = null;
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
			self::$instance->flush_buffer();
			// Save UNIQUE_ID so resume() can restore it (child may overwrite it).
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- saving our own generated ID for restore.
			self::$instance->saved_unique_id = $_SERVER['UNIQUE_ID'] ?? null;
			self::$context_stack[]           = self::$instance;
			self::$instance                  = null;
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
	 * Ensure that full logging has started.
	 *
	 * @return bool True if logging is started.
	 */
	private function ensure_started(): bool {
		if ( $this->started || ! $this->enabled || $this->finished ) {
			return $this->started ?? false;
		}

		// Guard against re-entry. init_firehose() calls Config::get_logs_directory()
		// which calls Config::load_config() which applies the
		// `newspack_event_logger_nodes_option_schema_core` filter. If an admin has
		// added that (or any other internal Event Logger filter) to log_events
		// — something the admin UI's "all known filters" selector can easily
		// do by accident — then App\Core::hook_start is registered on it, and
		// apply_filters re-enters ensure_started() → stack overflow. Marking
		// started true here short-circuits the reentry at the top of this
		// method; messages that arrive during init_firehose just accumulate in
		// write_buffer and flush normally once the firehose is set up.
		$this->started = true;

		\register_shutdown_function( [ $this, 'finish' ] );
		$this->init_firehose( $this->config );
		$this->log_process();

		return true;
	}

	/**
	 * Log process details.
	 *
	 * @return void
	 */
	private function log_process(): void {
		// First request in a process: use mu-profiler's start time (captured at
		// PHP startup, before plugins load). Subsequent requests after reset()
		// (job workers, Pyrobase templates) use current time.
		$process_hr   = $this->request_time ?? \hrtime( true );
		$process_data = [ 'm' => \getmypid() . ' on ' . \gethostname(), 'l' => '' ];

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized immediately below.
		$worker_type = \sanitize_text_field( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] ?? '' );
		if ( '' !== $worker_type ) {
			$process_data['worker_type'] = $worker_type;
		}

		$this->message( 'process (start)', $process_data );
		$this->times[] = [ 'label' => 'process', 'ts' => $process_hr ];

		$method       = isset( $_SERVER['REQUEST_METHOD'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'CLI';
		$server_name  = isset( $_SERVER['SERVER_NAME'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
		$scheme       = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ? 'https' : 'http';
		$redacted_url = self::redact_url( $this->request_url );
		$full_url     = $server_name ? "{$scheme}://{$server_name}{$redacted_url}" : $redacted_url;
		$this->message( 'request', [ 'm' => "{$method} {$full_url}" ] );

		$this->log_environment();
		$this->log_resources();
	}

	/**
	 * Log a message with the given category and data.
	 *
	 * @param string $category Event category/keyword.
	 * @param array  $data     Additional data to include.
	 * @return bool True on success.
	 */
	public function message( string $category, array $data = [] ): bool {
		// Make sure process (start) lands first. Without this, any code path that
		// calls message()/error()/warning()/info() before the first start() leaves
		// process (start) stranded at whatever line_number ensure_started later
		// happens to fire at — wp-admin requests in particular show it at line 28+
		// because admin hooks log via error()/warning() before start() is reached.
		// ensure_started is re-entry safe ($this->started is set first thing).
		$this->ensure_started();

		// Redact sensitive query parameters in message URLs.
		if ( isset( $data['m'] ) && \is_string( $data['m'] ) && false !== \strpos( $data['m'], '?' ) ) {
			$data['m'] = \preg_replace( self::URL_REDACT_PATTERN, '$1$2=[REDACTED]', $data['m'] );
		}
		// Validate data size to prevent breaking atomicity.
		$data_json = \wp_json_encode( $data );
		if ( false !== $data_json && \strlen( $data_json ) > self::MAX_DATA_SIZE ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( 'LogManager: data truncated for category "%s", size=%d (limit=%d). Use JobIntake::queue() for payloads >4KB.', $category, \strlen( $data_json ), self::MAX_DATA_SIZE ) );
			$data = [ 'truncated' => true ];
		}
		$entry = [ 'n' => $this->line_number, 'rid' => $this->request_id, 'k' => $category ] + $data + [ 'ts' => \microtime( true ) ];
		$json  = \wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
		if ( $json ) {
			$line_size = \strlen( $json ) + 1; // +1 for newline.

			// Flush if adding this line would exceed atomic write limit.
			if ( $this->buffer_size > 0 && $this->buffer_size + $line_size > self::MAX_BUFFER_SIZE ) {
				$this->flush_buffer();
			}

			$this->write_buffer .= $json . "\n";
			$this->buffer_size  += $line_size;
			++$this->line_number;

			if ( $this->flush_every_line ) {
				$this->flush_buffer();
			}

			// Stop detailed logging after MAX_LOG_LINES to bound downstream state.
			// Don't disable started — finish() needs it to close the stack cleanly.
			if ( $this->line_number > self::MAX_LOG_LINES && ! $this->line_limited ) {
				$this->flush_buffer();
				$this->line_limited = true;
			}
		}
		return true;
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Error message.
	 * @return bool True on success.
	 */
	public function error( string $message ): bool {
		return $this->message( 'error', [ 'm' => $message ] );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Warning message.
	 * @return bool True on success.
	 */
	public function warning( string $message ): bool {
		return $this->message( 'warning', [ 'm' => $message ] );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Info message.
	 * @return bool True on success.
	 */
	public function info( string $message ): bool {
		return $this->message( 'info', [ 'm' => $message ] );
	}

	/**
	 * Refresh firehose segment state from disk.
	 *
	 * Call after a subprocess that may have written to or rotated the firehose,
	 * so subsequent writes go to the current segment. Routes through the active
	 * Partition's init_current_segment via reflection — Topic exposes Partition
	 * instances lazily, and we need to force a re-scan of the segment list
	 * after an external writer has touched the partition directory.
	 */
	public function refresh_firehose(): void {
		if ( null === $this->topic ) {
			return;
		}
		// Reach into Topic's protected partition() method to obtain the
		// Partition that owns the current request's writes, then call
		// init_current_segment() on it. This is the cleanest port of the
		// legacy `Firehose::init_current_segment()` semantics.
		$ref_partition_method = new \ReflectionMethod( Topic::class, 'partition' );
		$ref_partition_method->setAccessible( true );
		$partition = $ref_partition_method->invoke( $this->topic, $this->partition_idx );

		$ref_init = new \ReflectionMethod( Partition::class, 'init_current_segment' );
		$ref_init->setAccessible( true );
		$ref_init->invoke( $partition );
	}

	/**
	 * Flush buffered writes to firehose (atomic <=4KB write).
	 */
	public function flush_buffer(): void {
		if ( 0 === $this->buffer_size || null === $this->topic ) {
			return;
		}
		// Topic::write( $key, $value ) hashes $key to a partition via
		// Partition::hash_to_partition; passing $this->request_url ensures
		// every line for this request lands in the same partition.
		$this->topic->write( $this->request_url, $this->write_buffer );
		$this->write_buffer = '';
		$this->buffer_size  = 0;
	}

	/**
	 * Start timing a labeled operation.
	 *
	 * @param string $label Label for the timer (e.g., 'query', 'template').
	 * @param array  $data  Additional data to include in the start event.
	 */
	public function start( string $label, array $data = [] ): void {
		if ( ! $this->ensure_started() ) {
			return;
		}
		// Prevent unbounded timer stack growth.
		if ( \count( $this->times ) >= self::MAX_TIMER_DEPTH ) {
			return;
		}
		$muted = $this->line_limited;
		if ( ! $muted ) {
			$this->message( "{$label} (start)", $data );
		}
		$entry = [ 'label' => $label, 'ts' => \hrtime( true ), 'muted' => $muted ];
		if ( ! empty( $data['m'] ) ) {
			$entry['m'] = $data['m'];
		}
		$this->times[] = $entry;
	}

	/**
	 * Complete a labeled operation and log the duration.
	 *
	 * @param string $label Label that was passed to start().
	 * @param array  $data  Additional data to include in the complete event.
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
				$start   = $entry['ts'] ?? $now;
				$removed = \array_splice( $this->times, $i );
				$match   = \array_shift( $removed );
				break;
			}
		}
		for ( $i = \count( $removed ) - 1; $i >= 0; $i-- ) {
			$entry = $removed[ $i ];
			if ( empty( $entry['muted'] ) ) {
				$duration_ms = ( $now - ( $entry['ts'] ?? $now ) ) / self::NS_PER_MS;
				$this->message( "{$entry['label']} (complete)", [ 'm' => '(orphaned)', 'duration_ms' => $duration_ms ] );
			}
		}
		if ( ! empty( $match ) ) {
			$data['duration_ms'] = \max( 0, ( $now - $start ) / self::NS_PER_MS );
			if ( $this->log_memory ) {
				$data['peak_mb'] = \round( \memory_get_peak_usage( true ) / 1048576, 2 );
			}
			if ( empty( $match['muted'] ) ) {
				$this->message( "{$label} (complete)", $data );
			}
		}
	}

	/**
	 * Redact sensitive query parameters from a URL.
	 *
	 * @param string $url URL to redact.
	 * @return string Redacted URL.
	 */
	private static function redact_url( string $url ): string {
		return \preg_replace( self::URL_REDACT_PATTERN, '$1$2=[REDACTED]', $url );
	}

	/**
	 * Check if a $_SERVER key should be redacted.
	 *
	 * @param string $key Server variable key.
	 * @return bool True if sensitive.
	 */
	private static function is_sensitive_key( string $key ): bool {
		// O(1) hash lookup for exact matches.
		if ( isset( self::$sensitive_keys[ $key ] ) ) {
			return true;
		}
		// Check for sensitive substrings.
		$key_upper = \strtoupper( $key );
		foreach ( self::$sensitive_substrings as $pattern ) {
			if ( false !== \strpos( $key_upper, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if URL matches the configured filter patterns.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL should be logged.
	 */
	public function matches_url_filter( string $url ): bool {
		// Check skip regex first (always skip these).
		if ( null !== $this->skip_regex && \preg_match( $this->skip_regex, $url ) ) {
			$this->enabled = false;
			return false;
		}

		// Check log regex (if set, only log matching URLs).
		if ( null === $this->log_regex ) {
			$this->enabled = true;
			return true;  // No filter = log all.
		}
		if ( \preg_match( $this->log_regex, $url ) ) {
			$this->enabled = true;
			return true;
		}
		$this->enabled = false;
		return false;
	}

	private function log_environment(): void {
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
			// Strip control characters to prevent log injection.
			$sanitized = \preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value );
			// Redact sensitive query parameters from URL-containing values.
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

	private function log_resources(): void {
		$r = \getrusage();
		if ( ! $r ) {
			return;
		}
		$info = [
			\sprintf( 'utime => %f', ( $r['ru_utime.tv_sec'] ?? 0 ) + ( $r['ru_utime.tv_usec'] ?? 0 ) / 1000000 ),
			\sprintf( 'stime => %f', ( $r['ru_stime.tv_sec'] ?? 0 ) + ( $r['ru_stime.tv_usec'] ?? 0 ) / 1000000 ),
			\sprintf( 'maxrss => %d', $r['ru_maxrss'] ?? 0 ),
			\sprintf( 'minflt => %d', $r['ru_minflt'] ?? 0 ), \sprintf( 'majflt => %d', $r['ru_majflt'] ?? 0 ),
			\sprintf( 'inblock => %d', $r['ru_inblock'] ?? 0 ), \sprintf( 'oublock => %d', $r['ru_oublock'] ?? 0 ),
			\sprintf( 'nsignals => %d', $r['ru_nsignals'] ?? 0 ),
			\sprintf( 'nvcsw => %d', $r['ru_nvcsw'] ?? 0 ), \sprintf( 'nivcsw => %d', $r['ru_nivcsw'] ?? 0 ),
		];
		$this->message( 'resources', [ 'm' => \implode( ', ', $info ) ] );
	}

	/**
	 * Log final summary including memory usage and resources.
	 */
	public function finish(): void {
		if ( $this->finished || ! $this->started ) {
			return;
		}
		$this->finished = true;

		// Close any open stack entries (orphaned hooks) before final summary.
		$now = \hrtime( true );
		while ( \count( $this->times ) > 1 ) {
			$entry = \array_pop( $this->times );
			if ( empty( $entry['muted'] ) ) {
				$duration_ms = ( $now - $entry['ts'] ) / self::NS_PER_MS;
				$this->message( "{$entry['label']} (complete)", [ 'm' => '(orphaned)', 'duration_ms' => $duration_ms ] );
			}
		}

		$this->message( 'memory', [
			'm' => [
				'peak' => \round( \memory_get_peak_usage( true ) / 1024 / 1024, 2 ) . 'MB',
				'end'  => \round( \memory_get_usage( true ) / 1024 / 1024, 2 ) . 'MB',
			],
		] );
		$this->log_resources();

		// Detect fatal error for tagging.
		$complete_extra = [];
		$error          = \error_get_last();
		if ( $error && \in_array( $error['type'], self::FATAL_TYPES, true ) ) {
			$complete_extra['fatal_error']  = \substr( $error['message'] ?? '', 0, 1024 );
			$complete_extra['fatal_file']   = $error['file'] ?? '';
			$complete_extra['fatal_line']   = $error['line'] ?? 0;
			$complete_extra['fatal_type']   = $error['type'];
			$complete_extra['fatal_plugin'] = self::extract_plugin_slug( $error['file'] ?? '' );
			$complete_extra['error_status'] = 'F';
		}

		$this->complete( 'process', \array_merge( [ 'status_code' => \http_response_code() ?: 0 ], $complete_extra ) );
		$this->flush_buffer();
		$this->started = false;
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
}
