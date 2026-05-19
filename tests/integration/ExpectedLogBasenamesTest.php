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
		// Two layers: app's file-default `topologies` (its known catalog)
		// and the substrate's operator-overlay option. The filter under test
		// now reads the substrate's resolved active set via
		// `Bootstrap::get_topologies()`, so the overlay is what actually
		// drives expected_basenames.
		$this->use_base_dir( $this->tmp, [ 'topologies' => $names ] );
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = $names;
	}

	private function basenames(): array {
		// Drive the full pipeline as Log_Cleaner does: substrate computes
		// the topology-derived set, then the app's filter callback appends
		// runtime basenames. Direct `apply_filters` with an empty array
		// would skip the substrate's computation.
		$set = \Newspack_Nodes\Log_Cleaner::expected_basenames( $this->tmp );
		\sort( $set );
		return $set;
	}

	public function test_runtime_basenames_always_in_set(): void {
		// LogManager (firehose) + JobIntake (jobintake) write directly from
		// request code without any topology Partition node. They MUST stay
		// expected whenever the plugin is loaded — otherwise Log_Cleaner
		// would orphan the very logs the plugin always writes to.
		$this->activate_topologies( [] );
		$set = $this->basenames();
		$this->assertContains( 'firehose', $set );
		$this->assertContains( 'jobintake', $set );
	}

	public function test_firehose_workers_only_adds_topology_outputs(): void {
		// firehose-workers-only TSL declares: completed, errors, gyroscope, requests.
		// Plus the always-on runtime basenames: firehose + jobintake.
		$this->activate_topologies( [ 'firehose-workers-only' ] );
		$this->assertSame(
			[ 'completed', 'errors', 'firehose', 'gyroscope', 'jobintake', 'requests' ],
			$this->basenames()
		);
	}

	public function test_request_workers_alone_adds_flames(): void {
		// request-workers TSL declares: flames. (Requests partition is owned
		// by firehose-workers-only; not in this topology's TSL.)
		$this->activate_topologies( [ 'request-workers' ] );
		$this->assertSame(
			[ 'firehose', 'flames', 'jobintake' ],
			$this->basenames()
		);
	}

	public function test_job_workers_topology_declares_no_partitions(): void {
		// job-workers TSL has no `make_node Partition` lines — jobs.log is
		// produced by firehose-workers-and-jobs (or firehose-jobs-only),
		// not by the job-workers topology that consumes it.
		$this->activate_topologies( [ 'job-workers' ] );
		$this->assertSame(
			[ 'firehose', 'jobintake' ],
			$this->basenames()
		);
	}

	public function test_firehose_jobs_only_adds_jobs(): void {
		// firehose-jobs-only TSL declares: jobs.
		$this->activate_topologies( [ 'firehose-jobs-only' ] );
		$this->assertSame(
			[ 'firehose', 'jobintake', 'jobs' ],
			$this->basenames()
		);
	}

	public function test_aggregator_topology_declares_no_partitions(): void {
		// Aggregator only consumes REMOTE firehoses; no local Partition.
		$this->activate_topologies( [ 'aggregator' ] );
		$this->assertSame(
			[ 'firehose', 'jobintake' ],
			$this->basenames()
		);
	}

	public function test_combined_topologies_publish_union(): void {
		$this->activate_topologies( [
			'firehose-workers-only',
			'request-workers',
		] );
		$this->assertSame(
			[ 'completed', 'errors', 'firehose', 'flames', 'gyroscope', 'jobintake', 'requests' ],
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

	public function test_substrate_operator_overlay_overrides_app_file_default_topologies(): void {
		// The app config file ships a default `topologies` list — those are
		// the topologies the plugin KNOWS about. The operator's actual
		// active subset lives in the substrate option `newspack_nodes_topologies`
		// (admin-UI checkboxes). The filter MUST honor the substrate overlay,
		// not the app's full known list — otherwise inactive topologies'
		// basenames stay "expected" forever and Log_Cleaner never sees an
		// orphan to delete.
		//
		// Datapoke staging hit this: app file defaults are firehose-workers-only
		// + request-workers; operator selected firehose-jobs-only + job-workers.
		// The filter unioned both and called every existing on-disk log
		// expected, hiding the orphans the lifecycle-arm cleanup was supposed
		// to delete.
		$this->activate_topologies( [ 'firehose-workers-only', 'request-workers' ] );
		// Substrate operator overlay names a DIFFERENT subset — only this set
		// should drive expected basenames.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'firehose-jobs-only' ];

		$this->assertSame(
			[ 'firehose', 'jobintake', 'jobs' ],
			$this->basenames(),
			'app file-default topologies must not bleed into expected basenames when operator overlay is set'
		);
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
