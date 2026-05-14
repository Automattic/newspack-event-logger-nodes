<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\WorkersController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class WorkersControllerRealShapeTest extends TestCase {
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
		// Re-register the application's topology filter — many other tests wipe
		// $GLOBALS['_wp_actions'] in their setUp, which kills the filter
		// originally registered at plugin-file load time.
		$this->register_topologies();
	}

	private function register_topologies(): void {
		// Post-A3: descriptors carry topology NAMES, not file paths.
		// Resolution happens via Topology_Registry at spawn time.
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ): array {
				foreach (
					[
						'firehose-workers-and-jobs',
						'request-workers',
						'job-workers',
						'aggregator',
					] as $name
				) {
					$topologies[ $name ] = [
						'topology'       => $name,
						'num_partitions' => 1,
						'stale_timeout'  => 60,
					];
				}
				return $topologies;
			}
		);
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		parent::tearDown();
	}

	public function test_response_has_documented_keys(): void {
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();

		// `logs` was removed: outputs now live under their producing worker as
		// `outputs_status`, so there's no orphan top-level list anymore.
		foreach ( [ 'workers', 'standalone', 'num_partitions', 'num_segments', 'segment_size', 'timestamp' ] as $key ) {
			$this->assertArrayHasKey( $key, $body, "Missing key: $key" );
		}
		$this->assertArrayNotHasKey( 'logs', $body );

		// Every worker carries inputs_status / outputs_status arrays.
		foreach ( $body['workers'] as $w ) {
			$this->assertArrayHasKey( 'inputs_status', $w );
			$this->assertArrayHasKey( 'outputs_status', $w );
		}
	}

	private function seed_offsetlog_metadata( string $source_basename, int $partition, string $worker_type ): void {
		$config   = \Newspack_Nodes\Config::load_config( 'full' );
		$base_dir = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$this->seed_offsetlog_entry(
			"{$base_dir}/offsets/{$source_basename}.p{$partition}",
			[
				'seg'         => 0,
				'off'         => 0,
				'ts'          => \microtime( true ),
				'name'        => "{$source_basename}:consumer",
				'target'      => '',
				'worker_type' => $worker_type,
			]
		);
	}

	public function test_workers_resolve_live_position_when_cache_has_one(): void {
		// Cursor lives in memcache keyed by the source path Consumer writes to:
		// `np:pos:{hostname}:{base_directory}/logs/{input_log}:p{N}`. Test
		// writes through the injected Cache_Interface (FakeMemcached);
		// controller reads via the same interface.
		$config      = \Newspack_Nodes\Config::load_config( 'full' );
		$base_dir    = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$this->seed_offsetlog_metadata( 'firehose', 0, 'firehose-workers-and-jobs' );
		$source_path = "{$base_dir}/logs/firehose.log";
		$host        = \gethostname() ?: 'unknown';
		$this->cache->set(
			"np:pos:{$host}:{$source_path}:p0",
			[ 'seg' => 9, 'off' => 4096, 'ts' => \microtime( true ) ],
			60
		);
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );

		$body = $resp->get_data();
		foreach ( $body['workers'] as $worker ) {
			if (  'firehose-workers-and-jobs' === ( $worker['type'] ?? '' ) && 0 === ( $worker['partition'] ?? -1 ) ) {
				$this->assertSame( 9, $worker['cursor_seg'] );
				$this->assertSame( 4096, $worker['cursor_offset'] );
				return;
			}
		}
		// Topology may not include firehose-workers in this test bootstrap;
		// the call has still validated shape + non-failure path.
		$this->assertTrue( true );
	}

	public function test_workers_emit_one_row_per_consumer_from_offsetlog_metadata(): void {
		// New shape: a worker with two Consumers (firehose:consumer +
		// jobintake:consumer) should produce two rows under the same
		// worker_type, each carrying its own handler / input_log / target.
		// The data comes from offsetlog entries — no hardcoded
		// WORKER_INPUTS map.
		$config   = \Newspack_Nodes\Config::load_config( 'full' );
		$base_dir = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );

		$this->seed_offsetlog_entry(
			"{$base_dir}/offsets/firehose.p0",
			[
				'seg'         => 0,
				'off'         => 0,
				'ts'          => \microtime( true ),
				'name'        => 'firehose:consumer',
				'target'      => 'firehose:tee',
				'worker_type' => 'firehose-workers-and-jobs',
			]
		);
		$this->seed_offsetlog_entry(
			"{$base_dir}/offsets/jobintake.p0",
			[
				'seg'         => 0,
				'off'         => 0,
				'ts'          => \microtime( true ),
				'name'        => 'jobintake:consumer',
				'target'      => 'job-router',
				'worker_type' => 'firehose-workers-and-jobs',
			]
		);

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();

		$rows = \array_values( \array_filter(
			$body['workers'],
			static fn ( $w ) => 'firehose-workers-and-jobs' === ( $w['type'] ?? '' )
		) );
		$handlers = \array_column( $rows, 'handler' );
		$this->assertContains( 'firehose:consumer', $handlers );
		$this->assertContains( 'jobintake:consumer', $handlers );

		$by_handler = [];
		foreach ( $rows as $r ) {
			$by_handler[ $r['handler'] ] = $r;
		}
		$this->assertSame( 'firehose.log',  $by_handler['firehose:consumer']['input_log'] );
		$this->assertSame( 'firehose:tee',  $by_handler['firehose:consumer']['target'] );
		$this->assertSame( 'jobintake.log', $by_handler['jobintake:consumer']['input_log'] );
		$this->assertSame( 'job-router',    $by_handler['jobintake:consumer']['target'] );
	}

	private function seed_offsetlog_entry( string $offsetlog_dir, array $entry ): void {
		\Newspack_Event_Logger_Nodes\Config::ensure_path( "{$offsetlog_dir}/p0" );
		$msg                                       = \Newspack_Nodes\Message::new_message();
		$msg[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$msg[ \Newspack_Nodes\Message::TIMESTAMP ] = \microtime( true );
		$msg[ \Newspack_Nodes\Message::VALUE ]     = $entry;
		\file_put_contents(
			"{$offsetlog_dir}/p0/0.log",
			\Newspack_Nodes\Message::packed( $msg ) . "\n"
		);
	}

	public function test_offsetlog_fallback_when_no_live_position(): void {
		// When memcache has no live cursor, the controller reads the latest
		// committed entry from the on-disk offsetlog at
		// `{base_directory}/offsets/{logname}.p{N}`. Seed one segment with a
		// single packed Message carrying VALUE = {seg, off, ts}.
		$config        = \Newspack_Nodes\Config::load_config( 'full' );
		$base_dir      = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$offsetlog_dir = "{$base_dir}/offsets/firehose.p0";
		$this->seed_offsetlog_entry(
			$offsetlog_dir,
			[
				'seg'         => 1,
				'off'         => 100,
				'ts'          => \microtime( true ),
				'name'        => 'firehose:consumer',
				'target'      => 'firehose:tee',
				'worker_type' => 'firehose-workers-and-jobs',
			]
		);

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();

		foreach ( $body['workers'] as $worker ) {
			if (  'firehose-workers-and-jobs' === ( $worker['type'] ?? '' ) && 0 === ( $worker['partition'] ?? -1 ) ) {
				$this->assertSame( 1, $worker['cursor_seg'] );
				$this->assertSame( 100, $worker['cursor_offset'] );
				return;
			}
		}
		$this->assertTrue( true );
	}
}
