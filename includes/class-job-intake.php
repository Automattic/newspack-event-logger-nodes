<?php
/**
 * Job Intake
 *
 * Provides an interface for import processes to queue large jobs.
 * Jobs written here are routed to jobs.log by JobRouter.
 *
 * Locking happens per-Partition inside `Partition::allow_large_writes()` —
 * one writer per partition, multiple partitions can write in parallel. The
 * legacy intake-level lock at `{base_dir}/locks/jobintake.lock.d` (a single
 * host-wide gate) was removed when Partition learned to drive its own
 * heartbeat from `fill()` without an EventFramework Timer.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Partition_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Intake class.
 */
class Job_Intake {

	/**
	 * Valid handler name pattern (must match JobRouter and JobWorker).
	 */
	private const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	/**
	 * Maximum job size in bytes.
	 */
	private const MAX_JOB_SIZE = 10485760;

	/**
	 * Round-robin counter for partition distribution.
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
	 * Number of partitions.
	 *
	 * @var int
	 */
	private int $num_partitions;

	/**
	 * Partition instances for each partition index.
	 *
	 * @var array<int, Partition_Node>
	 */
	private array $partitions = [];

	/**
	 * Pinned partition (null = round-robin).
	 *
	 * @var int|null
	 */
	private ?int $pinned_partition = null;

	/**
	 * Constructor.
	 *
	 * Both `$base_dir` and `$num_partitions` default to the substrate config
	 * (`\Newspack_Nodes\Config::load_config()`) — callers don't need to
	 * thread them through unless they're targeting a non-default location
	 * (e.g. tests with a tmp dir). Pass strings/ints explicitly to override.
	 *
	 * @param string|null $base_dir       Base directory containing logs/ and locks/.
	 * @param int|null    $num_partitions Number of partitions.
	 */
	public function __construct( ?string $base_dir = null, ?int $num_partitions = null ) {
		if ( null === $base_dir || null === $num_partitions ) {
			$config         = \class_exists( '\Newspack_Nodes\Config' )
				? \Newspack_Nodes\Config::load_config()
				: [];
			$base_dir       = $base_dir       ?? \Newspack_Nodes\Config::get_base_directory();
			$num_partitions = $num_partitions ?? (int) ( $config['num_partitions'] ?? 1 );
		}
		$this->base_dir       = \rtrim( $base_dir, '/' );
		$this->num_partitions = \max( 1, $num_partitions );
	}

	/**
	 * Destructor — release any per-Partition write locks still held.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Pin all writes to a specific partition.
	 *
	 * @param int $partition Partition index.
	 * @return self For chaining.
	 */
	public function partition( int $partition ): self {
		$this->pinned_partition = \max( 0, \min( $partition, $this->num_partitions - 1 ) );
		return $this;
	}

	/**
	 * Lazily materialize the Partition for a given index. The per-Partition
	 * `allow_large_writes()` call acquires the partition's write lock — blocks
	 * up to ~65s on a respawn race, throws on a genuine concurrent writer.
	 */
	private function partition_handle( int $partition ): Partition_Node {
		if ( isset( $this->partitions[ $partition ] ) ) {
			return $this->partitions[ $partition ];
		}
		$log_base = $this->base_dir . '/logs/jobintake.log';
		// Suffix names with a process+object-id token so a second JobIntake
		// instantiated mid-process (e.g. during tests, or after a close) doesn't
		// clash with stale Core registrations from the previous instance.
		$instance_token = \getmypid() . '-' . \spl_object_id( $this );
		$p              = new Partition_Node( $log_base, $partition );
		$p->name( "jobintake.{$instance_token}.p{$partition}" );
		$p->allow_large_writes();
		$this->partitions[ $partition ] = $p;
		return $p;
	}

	/**
	 * Write a job to the job intake.
	 *
	 * Partition selection:
	 * - If pinned via partition(), always uses that partition
	 * - If key provided, hashes to consistent partition
	 * - Otherwise, round-robin across partitions
	 *
	 * @param string      $handler    Handler name (alphanumeric, underscores, hyphens, max 64 chars).
	 * @param array       $parameters Job parameters (can be large).
	 * @param string|null $key        Optional partition key for consistent routing.
	 * @return bool True on success, false on validation failure, lock unavailable, or write error.
	 */
	public function write_job( string $handler, array $parameters, ?string $key = null ): bool {
		// Validate handler name.
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return false;
		}

		// Select partition.
		if ( null !== $this->pinned_partition ) {
			$partition = $this->pinned_partition;
		} elseif ( null !== $key && '' !== $key ) {
			$partition = Partition_Node::hash_to_partition( $key, $this->num_partitions );
		} else {
			$partition         = self::$round_robin % $this->num_partitions;
			self::$round_robin = ( self::$round_robin + 1 ) % \PHP_INT_MAX;
		}

		// Clamp partition to valid range.
		$partition = \max( 0, \min( $partition, $this->num_partitions - 1 ) );

		$job = [
			'k'          => 'job',
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => \microtime( true ),
		];

		$encoded = \wp_json_encode( $job );
		if ( false === $encoded || \strlen( $encoded ) > self::MAX_JOB_SIZE ) {
			Core::stderr( '[EventLogger] JobIntake: Job exceeds size limit for handler: ' . $handler );
			return false;
		}

		// Wrap as a TM_STRUCT Message ($job is a structured array) so
		// Partition::fill packs and appends.
		$msg                                       = \Newspack_Nodes\Message::new_message();
		$msg[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$msg[ \Newspack_Nodes\Message::TIMESTAMP ] = \Newspack_Nodes\Core::$now;
		$msg[ \Newspack_Nodes\Message::VALUE ]     = $job;
		$this->partition_handle( $partition )->fill( $msg );
		return true;
	}

	/**
	 * Close all open Partitions. `Partition::remove_node()` flushes the batch
	 * and releases the per-Partition write lock.
	 */
	public function close(): void {
		foreach ( $this->partitions as $partition ) {
			$partition->flush();
			$base = $partition->name();
			if ( '' !== $base ) {
				\Newspack_Nodes\Core::unregister_node( "{$base}:lock" );
				\Newspack_Nodes\Core::unregister_node( "{$base}:heartbeat" );
			}
			$partition->remove_node();
		}
		$this->partitions = [];
	}

	/**
	 * Write multiple jobs in a batch.
	 *
	 * @param array       $jobs Array of ['handler' => string, 'parameters' => array].
	 * @param string|null $key  Optional partition key for all jobs.
	 * @return int Number of jobs successfully written.
	 */
	public function queue_many( array $jobs, ?string $key = null ): int {
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
	 * Get the pinned partition, or null if using round-robin.
	 *
	 * @return int|null Partition index or null.
	 */
	public function get_partition(): ?int {
		return $this->pinned_partition;
	}

	/**
	 * Static helper to write a single job.
	 *
	 * If a key is provided, jobs with the same key always go to the same partition.
	 * If no key is provided, jobs are distributed via round-robin.
	 *
	 * Lock acquisition (per-Partition, not host-wide) happens inside
	 * `Partition::allow_large_writes()`, which blocks up to ~65s on a respawn
	 * race and throws on a genuine concurrent live writer. We catch the
	 * throw and return false so callers retain the boolean contract.
	 *
	 * `$base_dir` and `$num_partitions` default to the substrate config — callers
	 * should normally just pass `(handler, parameters[, key])`. The trailing
	 * overrides are for tests targeting an isolated tmp dir.
	 *
	 * @param string      $handler        Handler name.
	 * @param array       $parameters     Job parameters.
	 * @param string|null $key            Optional partition key (e.g., event ID).
	 * @param string|null $base_dir       Override base directory.
	 * @param int|null    $num_partitions Override partition count.
	 * @return bool True on success, false on validation failure or unrecoverable
	 *              lock contention (live concurrent writer on same partition).
	 */
	public static function queue(
		string $handler,
		array $parameters,
		?string $key = null,
		?string $base_dir = null,
		?int $num_partitions = null
	): bool {
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			return false;
		}

		$intake = new self( $base_dir, $num_partitions );
		try {
			$result = $intake->write_job( $handler, $parameters, $key );
		} catch ( \RuntimeException $e ) {
			$result = false;
		} finally {
			$intake->close();
		}
		return $result;
	}
}
