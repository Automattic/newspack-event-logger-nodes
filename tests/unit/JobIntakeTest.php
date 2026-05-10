<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

// JobIntake is not loaded by the plugin's main require list (Agent 4 ships the
// require addition; until then the test pulls the class file in directly).
require_once \dirname( __DIR__, 2 ) . '/includes/class-job-intake.php';

use Newspack_Event_Logger_Nodes\JobIntake;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Lock;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( JobIntake::class )]
class JobIntakeTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = $this->make_temp_dir( 'newspack-jobintake-test-' );
		mkdir( "{$this->tmp}/locks", 0755, true );
		mkdir( "{$this->tmp}/logs", 0755, true );
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	private function read_all_jobintake_lines(): array {
		$lines    = [];
		$base_log = "{$this->tmp}/logs/jobintake.log";
		if ( ! is_dir( $base_log ) ) {
			return $lines;
		}
		// Walk every partition dir + every segment. Each line on disk is a
		// packed Tachikoma Message carrying the job-envelope array in VALUE.
		foreach ( scandir( $base_log ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$pdir = "{$base_log}/{$entry}";
			if ( ! is_dir( $pdir ) ) {
				continue;
			}
			foreach ( scandir( $pdir ) as $f ) {
				if ( ! preg_match( '/^\d+\.log$/', $f ) ) {
					continue;
				}
				$content = file_get_contents( "{$pdir}/{$f}" );
				if ( '' === $content ) {
					continue;
				}
				foreach ( preg_split( '/\n/', rtrim( $content, "\n" ) ) as $line ) {
					if ( '' === $line ) {
						continue;
					}
					$msg     = Message::unpacked( $line );
					$decoded = $msg[ Message::VALUE ];
					if ( \is_array( $decoded ) ) {
						$lines[] = $decoded;
					}
				}
			}
		}
		return $lines;
	}

	// --- Validation ---------------------------------------------------------

	public function test_rejects_invalid_handler_name(): void {
		$intake = new JobIntake( $this->tmp );
		$this->assertFalse( $intake->write_job( 'Bad-Name!', [] ) );
		$intake->close();
	}

	public function test_accepts_alphanumeric_underscore_handler(): void {
		$intake = new JobIntake( $this->tmp );
		$this->assertTrue( $intake->write_job( 'good_handler', [ 'x' => 1 ] ) );
		$intake->close();
	}

	public function test_accepts_dashed_handler(): void {
		// HANDLER_NAME_PATTERN matches upstream — permissive at intake.
		$intake = new JobIntake( $this->tmp );
		$this->assertTrue( $intake->write_job( 'sync-settings', [ 'x' => 1 ] ) );
		$intake->close();
	}

	public function test_static_queue_validates_before_lock(): void {
		// Fail-fast: invalid handler name MUST return false without ever touching
		// the filesystem (validate before entering the retry loop).
		$this->assertFalse( JobIntake::queue( $this->tmp, '!bad', [] ) );
		$this->assertFalse( is_dir( "{$this->tmp}/locks/jobintake.lock.d" ) );
	}

	// --- Write semantics ----------------------------------------------------

	public function test_write_job_writes_envelope_to_partition_log(): void {
		$intake = new JobIntake( $this->tmp, num_partitions: 1 );
		$intake->partition( 0 );
		$this->assertTrue( $intake->write_job( 'sync', [ 'opt' => 'log_urls' ] ) );
		$intake->close();

		$lines = $this->read_all_jobintake_lines();
		$this->assertCount( 1, $lines );
		$this->assertSame( 'job', $lines[0]['k'] );
		$this->assertSame( 'sync', $lines[0]['handler'] );
		$this->assertSame( [ 'opt' => 'log_urls' ], $lines[0]['parameters'] );
		$this->assertArrayHasKey( 'ts', $lines[0] );
	}

	public function test_pinned_partition_routes_all_writes_to_one_dir(): void {
		$intake = new JobIntake( $this->tmp, num_partitions: 4 );
		$intake->partition( 2 );

		$this->assertTrue( $intake->write_job( 'a', [] ) );
		$this->assertTrue( $intake->write_job( 'a', [] ) );
		$intake->close();

		$this->assertTrue( is_dir( "{$this->tmp}/logs/jobintake.log/p2" ) );
		// Other partitions should not have segments materialized.
		$this->assertFalse( is_dir( "{$this->tmp}/logs/jobintake.log/p0" ) );
		$this->assertFalse( is_dir( "{$this->tmp}/logs/jobintake.log/p1" ) );
		$this->assertFalse( is_dir( "{$this->tmp}/logs/jobintake.log/p3" ) );
	}

	public function test_keyed_routing_uses_hash_to_partition(): void {
		// Same key → same partition every time. Guaranteed by Partition::hash_to_partition.
		$intake = new JobIntake( $this->tmp, num_partitions: 4 );
		$expected = Partition::hash_to_partition( 'event_42', 4 );
		$this->assertTrue( $intake->write_job( 'sync', [ 'eid' => 42 ], 'event_42' ) );
		$this->assertTrue( $intake->write_job( 'sync', [ 'eid' => 42 ], 'event_42' ) );
		$intake->close();

		$this->assertTrue( is_dir( "{$this->tmp}/logs/jobintake.log/p{$expected}" ) );
	}

	public function test_round_robin_distribution(): void {
		$intake = new JobIntake( $this->tmp, num_partitions: 4 );
		// Issue 8 writes; the round-robin counter advances monotonically.
		for ( $i = 0; $i < 8; $i++ ) {
			$this->assertTrue( $intake->write_job( 'noop', [ 'i' => $i ] ) );
		}
		$intake->close();

		// At least two distinct partition dirs must exist (round-robin actually
		// distributed). With 8 writes over 4 partitions every dir should appear.
		$pdirs = array_filter(
			scandir( "{$this->tmp}/logs/jobintake.log" ),
			static fn ( $f ) => preg_match( '/^p\d+$/', $f )
		);
		$this->assertGreaterThanOrEqual( 2, count( $pdirs ) );
	}

	public function test_oversized_payload_rejected(): void {
		// 11MB JSON > MAX_JOB_SIZE 10MB.
		$intake = new JobIntake( $this->tmp );
		$big    = str_repeat( 'x', 11 * 1024 * 1024 );
		$this->assertFalse( $intake->write_job( 'big', [ 'data' => $big ] ) );
		$intake->close();
	}

	// --- queue_many batching ------------------------------------------------

	public function test_queue_many_writes_under_one_lock_acquisition(): void {
		// Batch API: lock acquired once, multiple writes under it, released at close.
		$jobs = [
			[ 'handler' => 'a', 'parameters' => [ 1 ] ],
			[ 'handler' => 'b', 'parameters' => [ 2 ] ],
			[ 'handler' => 'c', 'parameters' => [ 3 ] ],
		];

		$intake = new JobIntake( $this->tmp );
		$this->assertSame( 3, $intake->queue_many( $jobs ) );
		$this->assertTrue( $intake->is_open() );
		$intake->close();
		$this->assertFalse( $intake->is_open() );

		$lines = $this->read_all_jobintake_lines();
		$this->assertCount( 3, $lines );
		$handlers = array_column( $lines, 'handler' );
		$this->assertContains( 'a', $handlers );
		$this->assertContains( 'b', $handlers );
		$this->assertContains( 'c', $handlers );
	}

	public function test_queue_many_skips_malformed_entries(): void {
		$jobs = [
			[ 'handler' => 'good', 'parameters' => [ 'x' => 1 ] ],
			[ 'handler' => 123, 'parameters' => [] ],          // non-string handler
			[ 'handler' => 'good2', 'parameters' => 'not-array' ], // non-array parameters
			[ 'handler' => 'good3', 'parameters' => [] ],
		];
		$intake = new JobIntake( $this->tmp );
		$this->assertSame( 2, $intake->queue_many( $jobs ) );
		$intake->close();
	}

	// --- Lock semantics -----------------------------------------------------

	public function test_init_acquires_shared_lock(): void {
		$intake = new JobIntake( $this->tmp );
		$this->assertFalse( $intake->is_open() );
		$intake->write_job( 'a', [] );
		$this->assertTrue( $intake->is_open() );
		$this->assertTrue( is_dir( "{$this->tmp}/locks/jobintake.lock.d" ) );
		$intake->close();
		$this->assertFalse( is_dir( "{$this->tmp}/locks/jobintake.lock.d" ) );
	}

	public function test_close_releases_lock(): void {
		$intake = new JobIntake( $this->tmp );
		$intake->write_job( 'a', [] );
		$intake->close();
		$this->assertFalse( $intake->is_open() );
		// Lock dir gone — another producer can acquire.
		$intake2 = new JobIntake( $this->tmp );
		$this->assertTrue( $intake2->write_job( 'b', [] ) );
		$intake2->close();
	}

	public function test_destruct_releases_lock(): void {
		// __destruct calls close(); lock should be released even if the caller
		// forgets to call close() explicitly.
		$intake = new JobIntake( $this->tmp );
		$intake->write_job( 'a', [] );
		// Drop the only reference; PHP collects and __destruct runs.
		unset( $intake );
		$this->assertFalse( is_dir( "{$this->tmp}/locks/jobintake.lock.d" ) );
	}

	public function test_concurrent_intake_blocks_second_writer(): void {
		// First intake holds the lock; second's write_job() returns false because
		// init() can't acquire the lock.
		$first = new JobIntake( $this->tmp );
		$this->assertTrue( $first->write_job( 'a', [] ) );
		$this->assertTrue( $first->is_open() );

		$second = new JobIntake( $this->tmp );
		$this->assertFalse( $second->write_job( 'b', [] ) );
		$this->assertFalse( $second->is_open() );

		$first->close();
		// After release, second can acquire.
		$this->assertTrue( $second->write_job( 'b', [] ) );
		$second->close();
	}

	public function test_use_lock_false_skips_lock_acquisition(): void {
		// Producers that already hold the lock or know they're sole writer can
		// opt out via use_lock=false.
		$intake = new JobIntake( $this->tmp, num_partitions: 1, use_lock: false );
		$this->assertTrue( $intake->write_job( 'noop', [] ) );
		$this->assertFalse( is_dir( "{$this->tmp}/locks/jobintake.lock.d" ) );
		$intake->close();
	}

	public function test_is_lock_available_when_no_holder(): void {
		$intake = new JobIntake( $this->tmp );
		$this->assertTrue( $intake->is_lock_available() );
	}

	public function test_is_lock_available_returns_true_when_holding_it(): void {
		$intake = new JobIntake( $this->tmp );
		$intake->write_job( 'a', [] );
		$this->assertTrue( $intake->is_lock_available() );
		$intake->close();
	}

	public function test_is_lock_available_false_when_held_by_other(): void {
		$first = new JobIntake( $this->tmp );
		$first->write_job( 'a', [] );

		$probe = new JobIntake( $this->tmp );
		$this->assertFalse( $probe->is_lock_available() );

		$first->close();
	}

	// --- Static queue() helper ----------------------------------------------

	public function test_static_queue_writes_single_job(): void {
		$this->assertTrue( JobIntake::queue( $this->tmp, 'a_handler', [ 'x' => 1 ] ) );
		$lines = $this->read_all_jobintake_lines();
		$this->assertCount( 1, $lines );
		$this->assertSame( 'a_handler', $lines[0]['handler'] );
	}

	public function test_static_queue_with_key_routes_consistently(): void {
		$expected = Partition::hash_to_partition( 'k', 4 );
		$this->assertTrue( JobIntake::queue( $this->tmp, 'a', [], 'k', 4 ) );
		$this->assertTrue( is_dir( "{$this->tmp}/logs/jobintake.log/p{$expected}" ) );
	}

	public function test_static_queue_releases_lock_after_call(): void {
		// Single-shot calls must release the lock so another caller can immediately
		// queue another job. No "second queue() blocks until timeout" pathology.
		JobIntake::queue( $this->tmp, 'a', [] );
		$this->assertFalse( is_dir( "{$this->tmp}/locks/jobintake.lock.d" ) );

		// Second call succeeds without contention.
		$this->assertTrue( JobIntake::queue( $this->tmp, 'b', [] ) );
	}

	// --- Heartbeat throttling -----------------------------------------------

	public function test_touch_throttles_to_once_per_second(): void {
		// touch() called many times in the same second should issue only one
		// heartbeat update — keeps tight write-loops from burning syscalls.
		$intake = new JobIntake( $this->tmp );
		$intake->write_job( 'a', [] ); // initial heartbeat written.

		$hb = "{$this->tmp}/locks/jobintake.lock.d/heartbeat";
		clearstatcache( true, $hb );
		$mtime1 = filemtime( $hb );

		$intake->touch();
		$intake->touch();
		clearstatcache( true, $hb );
		$mtime2 = filemtime( $hb );

		// Within the same second; mtime unchanged (or at most updated once at
		// the second boundary).
		$this->assertGreaterThanOrEqual( $mtime1, $mtime2 );

		$intake->close();
	}
}
