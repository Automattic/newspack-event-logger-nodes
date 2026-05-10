<?php
/**
 * Job Worker
 *
 * Consumes job entries and dispatches to registered handlers.
 *
 * Handlers are registered via the newspack_nodes/job_handlers filter.
 * Each job specifies a handler name and parameters.
 *
 * SECURITY NOTES:
 * - Handler names must match HANDLER_NAME_PATTERN (validated here)
 * - Parameters are validated for type/size but handlers MUST validate content
 * - Only handlers registered via newspack_nodes/job_handlers filter are callable
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Worker class.
 */
class JobWorker extends Node {
	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';
	public const MAX_JOB_SIZE         = 10485760;

	/** Maximum JSON decode depth to prevent stack-exhaustion attacks. */
	public const MAX_JSON_DEPTH = 64;

	/** Default cache-flush interval in jobs. */
	public const CACHE_FLUSH_INTERVAL = 50;

	/** Default stale-timeout hint for long-running JobWorker pipelines. */
	public const DEFAULT_STALE_TIMEOUT = 600;

	/** Default max-runtime hint (matches DEFAULT_STALE_TIMEOUT for symmetry). */
	public const DEFAULT_MAX_RUNTIME = 600;

	/** Memory watermark — request restart when memory_get_usage crosses this fraction. */
	public const MEMORY_WATERMARK_PCT = 0.80;

	/** @var array<string,callable> */
	private array $handlers = [];
	private int $jobs_executed = 0;
	private int $jobs_since_cache_flush = 0;

	/** @var callable|null */
	private $between_jobs_cb = null;

	/** Latched true when a per-job memory check crossed the watermark. */
	private bool $memory_pressure = false;

	private int $cache_flush_interval;
	private int $stale_timeout;
	private int $max_runtime;

	/**
	 * Constructor.
	 *
	 * @param int $cache_flush_interval Run wp_cache_flush() every N jobs.
	 * @param int $stale_timeout        Stale-timeout hint (seconds) — exposed for topology config.
	 * @param int $max_runtime          Max-runtime hint (seconds) — exposed for topology config.
	 */
	public function __construct(
		int $cache_flush_interval = self::CACHE_FLUSH_INTERVAL,
		int $stale_timeout = self::DEFAULT_STALE_TIMEOUT,
		int $max_runtime = self::DEFAULT_MAX_RUNTIME
	) {
		$this->cache_flush_interval = \max( 1, $cache_flush_interval );
		$this->stale_timeout        = \max( 1, $stale_timeout );
		$this->max_runtime          = \max( 1, $max_runtime );
	}

	public function register_handler( string $name, callable $cb ): void {
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( "invalid handler name: $name" );
		}
		$this->handlers[ $name ] = $cb;
	}

	/**
	 * Register a between-jobs callback that fires after every job. Pass null to
	 * clear. The callback receives the jobs_executed counter as its single arg
	 * so it can decide its own cadence.
	 */
	public function set_between_jobs_callback( ?callable $cb ): void {
		$this->between_jobs_cb = $cb;
	}

	public function jobs_executed(): int {
		return $this->jobs_executed;
	}

	/** Stale-timeout hint exposed for topology config. */
	public function get_stale_timeout(): int {
		return $this->stale_timeout;
	}

	/** Max-runtime hint exposed for topology config. */
	public function get_max_runtime(): int {
		return $this->max_runtime;
	}

	/**
	 * Whether a previous job's memory check tripped the watermark. Topology
	 * code (or the worker's drain predicate) reads this to decide whether to
	 * exit cleanly so the supervisor can respawn into a fresh process.
	 */
	public function memory_pressure(): bool {
		return $this->memory_pressure;
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_STRUCT ) ) {
			return;
		}
		$entry = $message[ Message::VALUE ];
		if ( ! \is_array( $entry ) ) {
			return;
		}
		$encoded = \wp_json_encode( $entry );
		if ( false !== $encoded && \strlen( $encoded ) > self::MAX_JOB_SIZE ) {
			Core::print_less_often( 'JobWorker: oversized entry, skipping' );
			return;
		}
		$kind = $entry['k'] ?? '';
		if ( 'job' !== $kind && 'remote_job' !== $kind ) {
			return;
		}
		$handler = $entry['handler'] ?? '';
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) || ! isset( $this->handlers[ $handler ] ) ) {
			Core::print_less_often( "JobWorker: missing or invalid handler: $handler" );
			return;
		}

		// Field-name compat: prefer 'parameters' (upstream/JobIntake), fall back
		// to 'payload' (legacy producers). One field path; no double-execution.
		$parameters = $entry['parameters'] ?? $entry['payload'] ?? null;

		// Per-job discipline. The cleanup block runs even if the handler throws,
		// because gc/cache cycles need to happen MOST when handlers misbehave —
		// a crashed handler is exactly when leaks accumulate fastest.
		// Capture $_SERVER outside begin_job_context so a suspend()/_SERVER edit
		// failure mid-begin still has a snapshot we can restore.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- preserving for restore.
		$orig_server = $_SERVER;
		try {
			$this->begin_job_context( $handler );
			( $this->handlers[ $handler ] )( $parameters );
		} catch ( \Throwable $e ) {
			Core::print_less_often( "JobWorker: handler $handler threw: " . $e->getMessage() );
		} finally {
			$this->end_job_context( $orig_server );
		}
		++$this->jobs_executed;
		++$this->jobs_since_cache_flush;

		// Force a GC cycle every job. Reference-counted GC can't break cycles
		// immediately; explicit collection delays the watermark trip.
		\gc_collect_cycles();

		// Periodic object-cache flush extends per-process runtime by orders of
		// magnitude on workloads that fan out wp_query under handler control.
		if ( $this->jobs_since_cache_flush >= $this->cache_flush_interval ) {
			if ( \function_exists( 'wp_cache_flush' ) ) {
				\wp_cache_flush();
			}
			$this->jobs_since_cache_flush = 0;
		}

		// Memory watermark check. If we cross 80% of memory_limit, latch the
		// pressure flag — topology code reads memory_pressure() in its drain
		// predicate and exits cleanly so the supervisor respawns.
		if ( $this->is_memory_high() ) {
			$this->memory_pressure = true;
		}

		if ( null !== $this->between_jobs_cb ) {
			// Pass the counter so the callback owns cadence decisions.
			( $this->between_jobs_cb )( $this->jobs_executed );
		}
	}

	/**
	 * Suspend the parent LogManager (if loaded), generate a fresh per-job
	 * UNIQUE_ID, and rewrite $_SERVER paths to a /jobs/{handler} synthetic URL
	 * so any LogManager spawned by the handler picks up job-scoped context.
	 *
	 * Caller MUST capture $_SERVER snapshot before invoking, and pass it to
	 * end_job_context() in a finally block — even if begin_job_context throws.
	 */
	private function begin_job_context( string $handler ): void {
		// LogManager::suspend() pushes the parent context onto its stack. If the
		// class isn't loaded (test bootstrap, parent plugin not active), no-op.
		if ( \class_exists( '\Newspack_Event_Logger_Nodes\LogManager' ) ) {
			\Newspack_Event_Logger_Nodes\LogManager::suspend();
		}

		$path_info = '/' . \ltrim( $handler, '/' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- internal-only context.
		$server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';

		$_SERVER['UNIQUE_ID']       = self::generate_request_id();
		$_SERVER['REQUEST_URI']     = '/jobs/' . \ltrim( $handler, '/' );
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['PATH_INFO']       = $path_info;
		$_SERVER['SCRIPT_NAME']     = $path_info;
		$_SERVER['SCRIPT_URL']      = $path_info;
		$_SERVER['SCRIPT_URI']      = 'https://' . $server_name . $path_info;
		$_SERVER['SCRIPT_FILENAME'] = ( \defined( 'NEWSPACK_FOUNDATION_BASE' ) ? \NEWSPACK_FOUNDATION_BASE : '' ) . '/template';
		$_SERVER['QUERY_STRING']    = '';
		unset(
			$_SERVER['CONTENT_TYPE'],
			$_SERVER['CONTENT_LENGTH'],
			$_SERVER['HTTP_X_A8C_REQUEST_ID']
		);
	}

	/**
	 * Resume the parent LogManager (if loaded) and restore the original $_SERVER.
	 *
	 * @param array<string,mixed> $orig_server $_SERVER snapshot from begin_job_context().
	 */
	private function end_job_context( array $orig_server ): void {
		if ( \class_exists( '\Newspack_Event_Logger_Nodes\LogManager' ) ) {
			\Newspack_Event_Logger_Nodes\LogManager::resume();
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restoring saved value.
		$_SERVER = $orig_server;
	}

	/**
	 * Generate a 32-char base36 request ID. Matches LogManager::generate_request_id
	 * exactly so any per-job LogManager session has IDs indistinguishable from
	 * the request-scope IDs LogManager produces directly.
	 */
	public static function generate_request_id(): string {
		$rid = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$rid .= \base_convert( \bin2hex( \random_bytes( 5 ) ), 16, 36 );
		}
		return \substr( $rid, 0, 32 );
	}

	/**
	 * Whether memory_get_usage(true) has crossed MEMORY_WATERMARK_PCT of
	 * memory_limit. Returns false if memory_limit is unlimited (-1).
	 */
	public function is_memory_high(): bool {
		$limit = $this->memory_limit_bytes();
		if ( $limit <= 0 ) {
			return false;
		}
		return \memory_get_usage( true ) >= ( $limit * self::MEMORY_WATERMARK_PCT );
	}

	private function memory_limit_bytes(): int {
		$ini = \ini_get( 'memory_limit' );
		if ( '-1' === $ini || false === $ini ) {
			return -1;
		}
		$num = (int) $ini;
		switch ( \strtolower( \substr( $ini, -1 ) ) ) {
			case 'g':
				$num *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$num *= 1024 * 1024;
				break;
			case 'k':
				$num *= 1024;
				break;
		}
		return $num;
	}
}
