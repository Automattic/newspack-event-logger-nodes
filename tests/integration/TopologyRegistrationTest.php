<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Bootstrap;

/**
 * Verify the application's topology filter registration plumbs through
 * to runtime Bootstrap::expand_workers().
 */
class TopologyRegistrationTest extends TestCase {
	/**
	 * Re-register the plugin's topology filter — many other tests wipe
	 * $GLOBALS['_wp_actions'] in their setUp(), which kills the filter
	 * registered at plugin-file load time.
	 */
	protected function setUp(): void {
		parent::setUp();
		$dir = \dirname( __DIR__, 2 );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ) use ( $dir ): array {
				$topologies['firehose-workers'] = [
					'topology'       => $dir . '/topologies/firehose-workers.php',
					'num_partitions' => 4,
					'stale_timeout'  => 60,
				];
				$topologies['aggregator'] = [
					'topology'       => $dir . '/topologies/aggregator.php',
					'num_partitions' => 1,
					'stale_timeout'  => 60,
				];
				return $topologies;
			}
		);
	}

	public function test_topologies_filter_exposes_both_topologies(): void {
		$topologies = Bootstrap::get_topologies();
		$this->assertArrayHasKey( 'firehose-workers', $topologies, 'firehose-workers topology must be registered' );
		$this->assertArrayHasKey( 'aggregator', $topologies, 'aggregator topology must be registered' );
	}

	public function test_topology_files_exist_and_return_callables(): void {
		$topologies = Bootstrap::get_topologies();
		foreach ( [ 'firehose-workers', 'aggregator' ] as $name ) {
			$path = $topologies[ $name ]['topology'];
			$this->assertFileExists( $path, "{$name} topology file missing: {$path}" );
			$loaded = require $path;
			$this->assertIsCallable( $loaded, "{$name} topology must return a callable" );
		}
	}

	public function test_expand_workers_returns_five_rows(): void {
		// 4 firehose-workers partitions + 1 aggregator partition = 5.
		$workers = Bootstrap::expand_workers();

		$firehose = \array_filter( $workers, static fn ( $w ) => 'firehose-workers' === $w['type'] );
		$aggregator = \array_filter( $workers, static fn ( $w ) => 'aggregator' === $w['type'] );

		$this->assertCount( 4, $firehose, 'firehose-workers must expand to 4 partitions' );
		$this->assertCount( 1, $aggregator, 'aggregator must expand to 1 partition' );
		$this->assertGreaterThanOrEqual( 5, \count( $workers ) );
	}

	public function test_each_worker_descriptor_has_topology_path(): void {
		$workers = Bootstrap::expand_workers();
		foreach ( $workers as $w ) {
			if ( 'firehose-workers' !== $w['type'] && 'aggregator' !== $w['type'] ) {
				continue; // ignore other plugins' topologies
			}
			$this->assertArrayHasKey( 'topology', $w );
			$this->assertArrayHasKey( 'partition', $w );
			$this->assertArrayHasKey( 'stale_timeout', $w );
			$this->assertNotEmpty( $w['topology'] );
		}
	}
}
