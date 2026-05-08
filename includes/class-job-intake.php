<?php
/**
 * JobIntake: locked write path for large jobs into jobintake.log.
 *
 * Lift-adapt from upstream newspack-event-jobs/class-job-intake.php. Adaptations:
 *  - Uses Newspack_Nodes\Lock + Newspack_Nodes\Partition (not the legacy Firehose).
 *  - 10MB write cap (Partition::MAX_LARGE_LINE_SIZE = 10485760).
 *  - 5-min retry-with-backoff on lock contention (100ms sleep), in the static
 *    queue() helper. Instance API does not retry — callers that hold the lock
 *    via init() can call write_job() rapid-fire under their own retry policy.
 *  - Handler-name validation at intake (regex matches upstream pattern, NOT the
 *    new tighter JobRouter pattern — keeps producers permissive at intake).
 *  - Round-robin across partitions OR keyed-mode via Partition::hash_to_partition().
 *  - Instance API: queue_many([]) for batch writes within a single lock acquisition.
 *  - Single shared lock at {base}/locks/jobintake.lock.d for true single-writer
 *    ordering — vs Partition::allow_large_writes() which uses per-partition locks.
 *    JobIntake's stricter ordering matters when callers logically need write
 *    ordering across partitions (e.g., batched ingest where a downstream consumer
 *    expects records in submission order).
 *
 * Why the shared lock:
 *  - Multiple producers writing to different partitions could otherwise interleave
 *    log lines in non-deterministic order. JobIntake serializes the global write
 *    stream so consumers see a single ordered sequence per producer batch.
 *  - The per-partition Partition lock is still in play (allow_large_writes
 *    activates it) — JobIntake just adds the outer global lock as belt-and-
 *    suspenders. Inner lock is never contended because only JobIntake holds it.
 *
 * Concurrency model:
 *  - Static `queue()` — acquires lock per call, writes, releases. Retries up
 *    to 5 minutes on contention with 100ms backoff.
 *  - Instance API — acquires lock at init() (lazy on first write), holds across
 *    the lifetime of the JobIntake instance, releases at close() / destruct.
 *    Use this for batch ingest (WP-CLI imports, admin actions sweeping options).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Lock;
use Newspack_Nodes\Partition;

class JobIntake {

	/**
	 * Valid handler name pattern. Permissive at intake (defense-in-depth: JobRouter
	 * and JobWorker re-validate with the same pattern). Matches upstream's pattern
	 * exactly so producer code can be lift-shifted without rewrite.
	 */
	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	/**
	 * Maximum job size in bytes (10MB). Aligned with Partition::MAX_LARGE_LINE_SIZE
	 * so a job that passes intake validation is guaranteed to fit a partition write.
	 */
	public const MAX_JOB_SIZE = 10485760;

	/**
	 * Maximum time to wait for lock in the static queue() helper, in seconds.
	 */
	public const QUEUE_TIMEOUT_S = 300;

	/**
	 * Sleep interval between lock retries in the static queue() helper, in microseconds.
	 */
	public const QUEUE_RETRY_INTERVAL_US = 100000;

	/**
	 * Round-robin counter for partition distribution. Process-wide so successive
	 * write_job() calls within a single PHP process distribute across partitions.
	 *
	 * @var int
	 */
	private static int $round_robin = 0;

	/**
	 * Base directory for this intake's log + lock dirs.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Number of partitions. Configured at construction.
	 *
	 * @var int
	 */
	private int $num_partitions;

	/**
	 * Whether to acquire the shared lock. Default true — producers that already
	 * hold the lock (or know they're the sole writer) can opt out for performance.
	 *
	 * @var bool
	 */
	private bool $use_lock;

	/**
	 * Partition instances per index. Materialized lazily on first write.
	 *
	 * @var array<int, Partition>
	 */
	private array $partitions = [];

	/**
	 * Lock instance, held for the lifetime of this JobIntake when use_lock=true.
	 *
	 * @var Lock|null
	 */
	private ?Lock $lock = null;

	/**
	 * Pinned partition index, or null for round-robin / keyed mode.
	 *
	 * @var int|null
	 */
	private ?int $pinned_partition = null;

	/**
	 * Last time we touched the heartbeat. Throttled to once-per-second so long
	 * batch writes don't burn syscalls on every line.
	 *
	 * @var int
	 */
	private int $last_touch = 0;

	/**
	 * Whether init() has run (lock acquired, partitions opened).
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor. Lock is acquired lazily on first write.
	 *
	 * @param string $base_dir       Base directory containing logs/ and locks/.
	 * @param int    $num_partitions Number of partitions for round-robin / keyed mode.
	 * @param bool   $use_lock       Whether to acquire shared lock (default: true).
	 */
	public function __construct( string $base_dir, int $num_partitions = 1, bool $use_lock = true ) {
		$this->base_dir       = \rtrim( $base_dir, '/' );
		$this->num_partitions = \max( 1, $num_partitions );
		$this->use_lock       = $use_lock;
	}

	/**
	 * Destructor — release lock if held. Safe to call even when not initialized.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Pin all writes to a specific partition. Returns $this for chaining.
	 *
	 * @param int $partition Partition index.
	 * @return self
	 */
	public function partition( int $partition ): self {
		$this->pinned_partition = \max( 0, \min( $partition, $this->num_partitions - 1 ) );
		return $this;
	}

	/**
	 * Initialize: acquire the shared lock (if enabled) and lazy-open Partitions.
	 *
	 * @return bool True if lock acquired (or locking disabled); false if contended.
	 */
	private function init(): bool {
		if ( $this->initialized ) {
			return true;
		}

		// Acquire the shared lock first. If it's contended, return immediately so
		// the static queue() helper can decide to retry. We do NOT block here.
		if ( $this->use_lock ) {
			$lock_dir = $this->base_dir . '/locks/jobintake.lock.d';
			if ( ! \is_dir( $this->base_dir . '/locks' ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
				@\mkdir( $this->base_dir . '/locks', 0755, true );
			}
			$this->lock = new Lock( $lock_dir );
			if ( ! $this->lock->acquire() ) {
				$this->lock = null;
				return false;
			}
		}

		// Lazy-open one Partition per index. allow_large_writes() flips the
		// per-partition inner lock on so the 10MB cap applies; the inner lock
		// is never contended in practice (we hold the outer global lock).
		$log_base = $this->base_dir . '/logs/jobintake.log';
		for ( $p = 0; $p < $this->num_partitions; $p++ ) {
			$this->partitions[ $p ] = ( new Partition( $log_base, $p ) )
				->allow_large_writes();
		}

		$this->initialized = true;
		return true;
	}

	/**
	 * Write a single job to jobintake.log.
	 *
	 * Partition selection (in priority order):
	 *   1. Pinned via partition() — always uses that index.
	 *   2. Key provided — Partition::hash_to_partition($key, $num_partitions).
	 *   3. Otherwise — round-robin across partitions.
	 *
	 * @param string      $handler    Handler name (validated against HANDLER_NAME_PATTERN).
	 * @param array       $parameters Job parameters (anything JSON-encodable; can be large).
	 * @param string|null $key        Optional partition key for consistent routing.
	 * @return bool True on success; false on validation failure, lock contention, or write error.
	 */
	public function write_job( string $handler, array $parameters, ?string $key = null ): bool {
		// Fail-early: validate handler name before doing any work.
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return false;
		}

		if ( ! $this->init() ) {
			return false;
		}

		// Select partition.
		if ( null !== $this->pinned_partition ) {
			$partition = $this->pinned_partition;
		} elseif ( null !== $key && '' !== $key ) {
			$partition = Partition::hash_to_partition( $key, $this->num_partitions );
		} else {
			$partition         = self::$round_robin % $this->num_partitions;
			self::$round_robin = ( self::$round_robin + 1 ) % \PHP_INT_MAX;
		}

		// Clamp to a valid partition index in case num_partitions changed mid-process.
		$partition = \max( 0, \min( $partition, $this->num_partitions - 1 ) );

		// Build the JSON envelope. Schema matches upstream:
		//   { type: 'job', handler, parameters, ts }
		// JobRouter parses this and dispatches via parameters (or payload — see compat note).
		$job = [
			'type'       => 'job',
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => \microtime( true ),
		];

		// Prefer wp_json_encode (sanitizes UTF-8 and respects WordPress defaults);
		// fall back to json_encode for the test bootstrap where the function is absent.
		$job_json = \function_exists( 'wp_json_encode' )
			? \wp_json_encode( $job )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			: \json_encode( $job );
		if ( false === $job_json ) {
			return false;
		}
		if ( \strlen( $job_json ) > self::MAX_JOB_SIZE ) {
			// Rate-limited stderr — high-volume bad producers won't flood the log.
			\Newspack_Nodes\Core::print_less_often( 'JobIntake: job exceeds size limit for handler: ' . $handler );
			return false;
		}

		// Partition expects a newline-terminated line for log readers; add it
		// explicitly so the line boundary is unambiguous to Tail consumers.
		$line   = $job_json . "\n";
		$result = $this->partitions[ $partition ]->write( $line );

		if ( $result ) {
			$this->touch();
		}

		return $result;
	}

	/**
	 * Write multiple jobs in one shot, all under the same lock acquisition.
	 *
	 * @param array<int, array{handler:string, parameters:array}> $jobs Jobs to write.
	 * @param string|null                                          $key  Optional key applied to all jobs.
	 * @return int Number of jobs successfully written (0 if lock contended).
	 */
	public function queue_many( array $jobs, ?string $key = null ): int {
		if ( ! $this->init() ) {
			return 0;
		}

		$written = 0;
		foreach ( $jobs as $job ) {
			$handler    = $job['handler'] ?? '';
			$parameters = $job['parameters'] ?? [];

			if ( ! \is_string( $handler ) || ! \is_array( $parameters ) ) {
				continue;
			}
			if ( $this->write_job( $handler, $parameters, $key ) ) {
				++$written;
			}
		}
		return $written;
	}

	/**
	 * Close the intake: release the lock and drop Partition references.
	 * Idempotent — safe to call multiple times. Called automatically by __destruct.
	 */
	public function close(): void {
		$this->partitions  = [];
		$this->initialized = false;
		if ( null !== $this->lock ) {
			$this->lock->release();
			$this->lock = null;
		}
	}

	/**
	 * Currently pinned partition, or null if using round-robin / keyed mode.
	 */
	public function get_partition(): ?int {
		return $this->pinned_partition;
	}

	/**
	 * Whether init() has run (lock acquired and Partitions opened).
	 */
	public function is_open(): bool {
		return $this->initialized;
	}

	/**
	 * Probe whether the shared lock is available without acquiring it. Useful for
	 * a long import workflow that wants to fail-fast before doing expensive work.
	 *
	 * Note: lock state can change between this check and the actual write attempt.
	 */
	public function is_lock_available(): bool {
		if ( ! $this->use_lock ) {
			return true;
		}
		if ( $this->is_open() ) {
			return true; // Already holding it.
		}

		$lock_dir       = $this->base_dir . '/locks/jobintake.lock.d';
		$heartbeat_file = $lock_dir . '/' . Lock::HEARTBEAT_FILE;

		if ( ! \is_dir( $lock_dir ) ) {
			return true; // No lock held.
		}

		$mtime = @\filemtime( $heartbeat_file );
		if ( false === $mtime ) {
			return true; // No heartbeat present — orphan or stale.
		}
		return ( \time() - $mtime ) >= Lock::STALE_TIMEOUT;
	}

	/**
	 * Refresh heartbeat for long-running batch imports. Throttled to once per
	 * second so a tight write_job() loop doesn't burn syscalls.
	 */
	public function touch(): void {
		if ( null === $this->lock ) {
			return;
		}
		$now = \time();
		if ( $now > $this->last_touch ) {
			$this->lock->heartbeat();
			$this->last_touch = $now;
		}
	}

	/**
	 * Static helper: queue a single job with retry-on-contention.
	 *
	 * Up to QUEUE_TIMEOUT_S seconds of retry with QUEUE_RETRY_INTERVAL_US backoff.
	 * Validates handler name before entering the retry loop (fail-fast on bad input).
	 *
	 * @param string      $base_dir       Base directory.
	 * @param string      $handler        Handler name.
	 * @param array       $parameters     Job parameters.
	 * @param string|null $key            Optional partition key.
	 * @param int         $num_partitions Number of partitions (default: 1).
	 * @return bool True on success; false on validation failure or timeout.
	 */
	public static function queue(
		string $base_dir,
		string $handler,
		array $parameters,
		?string $key = null,
		int $num_partitions = 1
	): bool {
		// Fail-early: validate handler name BEFORE entering the retry loop.
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return false;
		}

		$deadline = \microtime( true ) + self::QUEUE_TIMEOUT_S;

		while ( true ) {
			$intake   = new self( $base_dir, $num_partitions );
			$result   = $intake->write_job( $handler, $parameters, $key );
			$was_open = $intake->is_open();
			$intake->close();

			if ( $result ) {
				return true;
			}

			// If we got the lock (was_open=true) but the write still failed, that's
			// a permanent error (oversize, disk full). Don't retry — the second
			// attempt will hit the same wall.
			if ( $was_open ) {
				return false;
			}

			if ( \microtime( true ) >= $deadline ) {
				return false;
			}

			// Lock contention — back off and retry.
			\usleep( self::QUEUE_RETRY_INTERVAL_US );
		}
	}
}
