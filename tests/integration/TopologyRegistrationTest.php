<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Topology_Registry;

/**
 * Verify the application's topology filter registration plumbs through
 * to runtime Bootstrap::expand_workers().
 *
 * Post-A3 the descriptor carries the topology NAME (a stock TSL file in
 * `topologies/`), not a PHP file path — Topology_Loader resolves the
 * name to a file at spawn time via Topology_Registry::resolve().
 */
class TopologyRegistrationTest extends TestCase {
	/**
	 * Re-register the plugin's topology filter — many other tests wipe
	 * $GLOBALS['_wp_actions'] in their setUp(), which kills the filter
	 * registered at plugin-file load time. We mirror the production
	 * filter shape here (name-keyed descriptors, num_partitions /
	 * stale_timeout numbers); the names map to real TSL files in
	 * `topologies/` so Topology_Registry::resolve() returns a path.
	 */
	protected function setUp(): void {
		parent::setUp();
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ): array {
				$topologies['firehose-workers-and-jobs'] = [
					'topology'       => 'firehose-workers-and-jobs',
					'num_partitions' => 4,
					'stale_timeout'  => 60,
				];
				$topologies['aggregator'] = [
					'topology'       => 'aggregator',
					'num_partitions' => 1,
					'stale_timeout'  => 60,
				];
				return $topologies;
			}
		);
	}

	public function test_topologies_filter_exposes_both_topologies(): void {
		$topologies = Bootstrap::get_topologies();
		$this->assertArrayHasKey( 'firehose-workers-and-jobs', $topologies );
		$this->assertArrayHasKey( 'aggregator', $topologies );
	}

	public function test_topology_names_resolve_to_tsl_files(): void {
		$topologies = Bootstrap::get_topologies();
		foreach ( [ 'firehose-workers-and-jobs', 'aggregator' ] as $name ) {
			$path = Topology_Registry::resolve( $topologies[ $name ]['topology'] );
			$this->assertNotNull( $path, "topology '{$name}' must resolve to a TSL file" );
			$this->assertFileExists( $path );
			$this->assertStringEndsWith( '.tsl', $path );
		}
	}

	public function test_expand_workers_returns_five_rows(): void {
		// 4 firehose-workers-and-jobs partitions + 1 aggregator partition = 5.
		$workers = Bootstrap::expand_workers();

		$firehose   = \array_filter( $workers, static fn ( $w ) => 'firehose-workers-and-jobs' === $w['type'] );
		$aggregator = \array_filter( $workers, static fn ( $w ) => 'aggregator' === $w['type'] );

		$this->assertCount( 4, $firehose );
		$this->assertCount( 1, $aggregator );
		$this->assertGreaterThanOrEqual( 5, \count( $workers ) );
	}

	public function test_each_worker_descriptor_carries_required_fields(): void {
		$workers = Bootstrap::expand_workers();
		foreach ( $workers as $w ) {
			if ( 'firehose-workers-and-jobs' !== $w['type'] && 'aggregator' !== $w['type'] ) {
				continue; // ignore other plugins' topologies
			}
			$this->assertArrayHasKey( 'topology', $w );
			$this->assertArrayHasKey( 'partition', $w );
			$this->assertArrayHasKey( 'stale_timeout', $w );
			$this->assertNotEmpty( $w['topology'] );
		}
	}
}
