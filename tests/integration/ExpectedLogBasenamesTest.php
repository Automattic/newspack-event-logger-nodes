<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * Verify the application's `newspack_nodes/expected_log_basenames` filter
 * publishes the union of log basenames owned by the currently-active
 * topologies. Read by `Newspack_Nodes\Log_Cleaner` to decide which
 * `{base}/logs/*.log/` directories are safe to GC after a topology
 * shrinks.
 */
class ExpectedLogBasenamesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_actions'] = [];
		$this->tmp              = $this->make_temp_dir();
		// setUp() wipes wp_actions, so the plugin-file `add_filter` is
		// gone — re-attach the same named callback the plugin file uses.
		\add_filter(
			'newspack_nodes/expected_log_basenames',
			'newspack_event_logger_nodes_expected_log_basenames'
		);
	}

	private string $tmp;

	private function activate_topologies( array $names ): void {
		$this->use_base_dir( $this->tmp, [ 'topologies' => $names ] );
	}

	private function basenames(): array {
		$set = \apply_filters( 'newspack_nodes/expected_log_basenames', [] );
		\sort( $set );
		return $set;
	}

	public function test_firehose_workers_only_publishes_firehose_errors_requests(): void {
		$this->activate_topologies( [ 'firehose-workers-only' ] );
		$this->assertSame(
			[ 'errors', 'firehose', 'requests' ],
			$this->basenames()
		);
	}

	public function test_request_workers_alone_publishes_flames_and_requests(): void {
		$this->activate_topologies( [ 'request-workers' ] );
		$this->assertSame(
			[ 'flames', 'requests' ],
			$this->basenames()
		);
	}

	public function test_job_workers_alone_publishes_jobs(): void {
		$this->activate_topologies( [ 'job-workers' ] );
		$this->assertSame( [ 'jobs' ], $this->basenames() );
	}

	public function test_firehose_jobs_only_publishes_firehose_jobintake_jobs(): void {
		$this->activate_topologies( [ 'firehose-jobs-only' ] );
		$this->assertSame(
			[ 'firehose', 'jobintake', 'jobs' ],
			$this->basenames()
		);
	}

	public function test_aggregator_alone_publishes_nothing(): void {
		// Aggregator only consumes REMOTE firehoses (not a local log
		// basename), so it contributes nothing to the local-log set.
		$this->activate_topologies( [ 'aggregator' ] );
		$this->assertSame( [], $this->basenames() );
	}

	public function test_combined_topologies_publish_union(): void {
		$this->activate_topologies( [
			'firehose-workers-only',
			'request-workers',
		] );
		$this->assertSame(
			[ 'errors', 'firehose', 'flames', 'requests' ],
			$this->basenames()
		);
	}

	public function test_disabling_request_workers_drops_flames(): void {
		$this->activate_topologies( [
			'firehose-workers-and-jobs',
			'request-workers',
		] );
		$with_flames = $this->basenames();
		$this->assertContains( 'flames', $with_flames );

		// Re-activate without request-workers.
		\Newspack_Event_Logger_Nodes\Config::reset();
		$this->activate_topologies( [ 'firehose-workers-and-jobs' ] );
		$without_flames = $this->basenames();
		$this->assertNotContains( 'flames', $without_flames );
	}

	public function test_topology_with_live_lock_dir_keeps_its_basenames(): void {
		// Operator just removed request-workers from config, but a
		// pre-shrink request-workers worker is still running out its
		// lifetime — its lock dir is still on disk. The filter must
		// keep 'flames' in the set until the worker exits and releases
		// its lock, otherwise Log_Cleaner would delete flames.log out
		// from under it.
		$this->activate_topologies( [ 'firehose-workers-and-jobs' ] );
		\mkdir( "{$this->tmp}/locks/request-workers.p0.lock.d", 0755, true );
		\file_put_contents(
			"{$this->tmp}/locks/request-workers.p0.lock.d/heartbeat",
			(string) \getmypid()
		);

		$this->assertContains( 'flames', $this->basenames() );
	}

	public function test_lock_dir_cleared_drops_basenames_on_next_tick(): void {
		// After the worker exits and Lock::release() rmdirs the lock
		// dir, the filter no longer keeps the disabled topology's
		// basenames in the set — Log_Cleaner can now act.
		$this->activate_topologies( [ 'firehose-workers-and-jobs' ] );
		// (No lock dir seeded; this is the "after settling" state.)
		$this->assertNotContains( 'flames', $this->basenames() );
	}
}
