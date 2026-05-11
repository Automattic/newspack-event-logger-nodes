<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\WorkersController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( WorkersController::class )]
class WorkersControllerTest extends TestCase {
	private FakeMemcached $cache;
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$GLOBALS['_wp_actions']       = [];
		$GLOBALS['_wp_options']       = [];
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );

		$this->tmp = '/tmp/workers-controller-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		\add_filter( 'newspack_nodes/base_dir', fn () => $this->tmp );
		Config::reset();

		// Re-register the application's topology filter — many other tests wipe
		// $GLOBALS['_wp_actions'] in their setUp, which kills the filter
		// originally registered at plugin-file load time.
		$this->register_topologies();
	}

	private function register_topologies(): void {
		$dir = \dirname( __DIR__, 3 );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ) use ( $dir ): array {
				$topologies['firehose-workers'] = [
					'topology'       => $dir . '/topologies/firehose-workers.php',
					'num_partitions' => 1,
					'stale_timeout'  => 60,
				];
				$topologies['request-workers'] = [
					'topology'       => $dir . '/topologies/request-workers.php',
					'num_partitions' => 1,
					'stale_timeout'  => 60,
				];
				$topologies['job-workers'] = [
					'topology'       => $dir . '/topologies/job-workers.php',
					'num_partitions' => 1,
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

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		Config::reset();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Build a heartbeat file for a topology worker so its status flips to running.
	 */
	private function set_heartbeat( string $type, int $partition, int $age_seconds = 0 ): string {
		$lock_dir = "{$this->tmp}/locks/{$type}.p{$partition}.lock.d";
		\mkdir( $lock_dir, 0755, true );
		$hb = "{$lock_dir}/heartbeat";
		\touch( $hb, \time() - $age_seconds );
		return $lock_dir;
	}

	private function set_standalone_heartbeat( string $name, int $age_seconds = 0 ): string {
		$lock_dir = "{$this->tmp}/locks/{$name}.lock.d";
		\mkdir( $lock_dir, 0755, true );
		$hb = "{$lock_dir}/heartbeat";
		\touch( $hb, \time() - $age_seconds );
		return $lock_dir;
	}

	public function test_register_routes_registers_workers_endpoints(): void {
		( new WorkersController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/workers', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/workers/restart', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_workers_uses_get_method(): void {
		( new WorkersController() )->register_routes();
		$workers = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/workers'];
		$this->assertSame( 'GET', $workers['methods'] );

		$restart = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/workers/restart'];
		$this->assertSame( 'POST', $restart['methods'] );
		$this->assertArrayHasKey( 'type', $restart['args'] );
		$this->assertArrayHasKey( 'partition', $restart['args'] );
		$this->assertArrayHasKey( 'all_partitions', $restart['args'] );
		$this->assertArrayHasKey( 'nonce', $restart['args'] );
	}

	public function test_get_workers_returns_documented_shape(): void {
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'workers', $body );
		$this->assertArrayHasKey( 'standalone', $body );
		// `logs` was removed: outputs now live under their producing worker as
		// `outputs_status`, so there's no orphan top-level list anymore.
		$this->assertArrayNotHasKey( 'logs', $body );
		$this->assertArrayHasKey( 'num_partitions', $body );
		$this->assertArrayHasKey( 'segment_size', $body );
		$this->assertArrayHasKey( 'timestamp', $body );
		// Supervisor is always in standalone.
		$names = \array_column( $body['standalone'], 'type' );
		$this->assertContains( 'supervisor', $names );
		// Every worker carries inputs_status/outputs_status arrays.
		foreach ( $body['workers'] as $w ) {
			$this->assertArrayHasKey( 'inputs_status', $w );
			$this->assertArrayHasKey( 'outputs_status', $w );
			$this->assertIsArray( $w['inputs_status'] );
			$this->assertIsArray( $w['outputs_status'] );
		}
	}

	public function test_get_workers_uses_live_position_from_cache(): void {
		// Seed a live cursor for an arbitrary worker type — controller will pick
		// it up if the topology happens to include that type. The point of this
		// test is: the resolver path (cache → fallback) at least doesn't crash
		// and respects the FakeMemcached injection.
		$source_path = "{$this->tmp}/logs/firehose.log";
		$host        = \gethostname() ?: 'unknown';
		$this->cache->set(
			"np:pos:{$host}:{$source_path}:p0",
			[ 'seg' => 5, 'off' => 1234 ],
			60
		);
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		// Find firehose-workers row and verify cursor was picked up.
		$firehose = null;
		foreach ( $body['workers'] as $w ) {
			if ( 'firehose-workers' === $w['type'] ) {
				$firehose = $w;
				break;
			}
		}
		$this->assertNotNull( $firehose );
		$this->assertSame( 5, $firehose['cursor_seg'] );
		$this->assertSame( 1234, $firehose['cursor_offset'] );
	}

	public function test_get_workers_falls_back_to_saved_positions_filter(): void {
		\add_filter(
			'newspack_event_logger_nodes/log_reader_positions',
			static function () {
				return [
					'firehose-workers' => [
						0 => [ 'firehose.log' => [ 'seg' => 9, 'off' => 5555 ] ],
					],
				];
			}
		);
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();
		$firehose = null;
		foreach ( $body['workers'] as $w ) {
			if ( 'firehose-workers' === $w['type'] ) {
				$firehose = $w;
				break;
			}
		}
		$this->assertNotNull( $firehose );
		$this->assertSame( 9, $firehose['cursor_seg'] );
		$this->assertSame( 5555, $firehose['cursor_offset'] );
	}

	public function test_get_workers_marks_worker_running_when_heartbeat_fresh(): void {
		// Fresh heartbeat (0 seconds ago).
		$this->set_heartbeat( 'firehose-workers', 0, 0 );

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();
		$firehose = null;
		foreach ( $body['workers'] as $w ) {
			if ( 'firehose-workers' === $w['type'] ) {
				$firehose = $w;
				break;
			}
		}
		$this->assertNotNull( $firehose );
		$this->assertSame( 'running', $firehose['status'] );
		$this->assertNotNull( $firehose['heartbeat_age'] );
	}

	public function test_get_workers_marks_worker_dead_when_heartbeat_stale(): void {
		// Heartbeat stale by 200s (> 60s default).
		$this->set_heartbeat( 'firehose-workers', 0, 200 );

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();
		$firehose = null;
		foreach ( $body['workers'] as $w ) {
			if ( 'firehose-workers' === $w['type'] ) {
				$firehose = $w;
				break;
			}
		}
		$this->assertNotNull( $firehose );
		$this->assertSame( 'dead', $firehose['status'] );
	}

	public function test_get_workers_marks_supervisor_running_with_fresh_heartbeat(): void {
		$this->set_standalone_heartbeat( 'supervisor', 0 );

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();
		$supervisor = null;
		foreach ( $body['standalone'] as $s ) {
			if ( 'supervisor' === $s['type'] ) {
				$supervisor = $s;
				break;
			}
		}
		$this->assertNotNull( $supervisor );
		$this->assertSame( 'running', $supervisor['status'] );
		$this->assertNotNull( $supervisor['heartbeat_age'] );
	}

	public function test_get_workers_includes_standalone_workers_via_filter(): void {
		\add_filter(
			'newspack_event_logger_nodes/standalone_workers',
			static function () {
				return [
					'health-check' => [ 'partitions' => false ],
					'partitioned-thing' => [ 'partitions' => true ],
				];
			}
		);
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();

		$names = \array_column( $body['standalone'], 'type' );
		$this->assertContains( 'supervisor', $names );
		$this->assertContains( 'health-check', $names );
		$this->assertContains( 'partitioned-thing', $names );
	}

	public function test_get_workers_reports_correct_input_log_per_type(): void {
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();

		$by_type = [];
		foreach ( $body['workers'] as $w ) {
			$by_type[ $w['type'] ?? '' ][] = $w;
		}

		$expected_inputs = [
			'firehose-workers' => 'firehose.log',
			'request-workers'  => 'requests.log',
			'job-workers'      => 'jobs.log',
			'aggregator'       => '',
		];
		foreach ( $expected_inputs as $type => $expected ) {
			$this->assertArrayHasKey( $type, $by_type, "Missing topology type: {$type}" );
			foreach ( $by_type[ $type ] as $worker ) {
				$this->assertSame(
					$expected,
					$worker['input_log'] ?? null,
					"Worker {$type} reported wrong input_log"
				);
			}
		}

		// firehose-workers also tails jobintake.log as a secondary input.
		$firehose = $by_type['firehose-workers'][0] ?? null;
		$this->assertNotNull( $firehose );
		$this->assertContains( 'jobintake.log', $firehose['inputs'] ?? [] );
		$this->assertContains( 'firehose.log', $firehose['inputs'] ?? [] );

		// firehose-workers writes requests.log + errors.log + jobs.log.
		$this->assertContains( 'requests.log', $firehose['outputs'] ?? [] );
		$this->assertContains( 'errors.log', $firehose['outputs'] ?? [] );
		$this->assertContains( 'jobs.log', $firehose['outputs'] ?? [] );

		// Primary input has cursor; secondary input does not.
		$primary = $firehose['inputs_status'][0] ?? [];
		$this->assertSame( 'firehose.log', $primary['name'] ?? null );
		$this->assertArrayHasKey( 'cursor_seg', $primary );
		$this->assertArrayHasKey( 'cursor_offset', $primary );
		// Outputs never carry cursor data.
		foreach ( $firehose['outputs_status'] as $out ) {
			$this->assertArrayNotHasKey( 'cursor_seg', $out );
			$this->assertArrayNotHasKey( 'cursor_offset', $out );
		}

		$flame = $by_type['request-workers'][0] ?? null;
		$this->assertNotNull( $flame );
		$this->assertCount( 1, $flame['inputs_status'] ?? [] );
		$this->assertCount( 1, $flame['outputs_status'] ?? [] );

		$jobw = $by_type['job-workers'][0] ?? null;
		$this->assertNotNull( $jobw );
		$this->assertCount( 1, $jobw['inputs_status'] ?? [] );
		$this->assertCount( 0, $jobw['outputs_status'] ?? [] );

		$agg = $by_type['aggregator'][0] ?? null;
		$this->assertNotNull( $agg );
		$this->assertCount( 0, $agg['inputs_status'] ?? [] );
		$this->assertCount( 1, $agg['outputs_status'] ?? [] );
	}

	public function test_get_workers_reports_segment_data(): void {
		// Drop a fake segment file for firehose.log/p0.
		$dir = "{$this->tmp}/logs/firehose.log/p0";
		\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", \str_repeat( 'X', 500 ) );
		\file_put_contents( "{$dir}/1.log", \str_repeat( 'Y', 200 ) );

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();
		$firehose = null;
		foreach ( $body['workers'] as $w ) {
			if ( 'firehose-workers' === $w['type'] ) {
				$firehose = $w;
				break;
			}
		}
		$this->assertNotNull( $firehose );
		// Segment listing reflects the files we wrote.
		$this->assertCount( 2, $firehose['segments'] );
		$this->assertSame( 700, $firehose['total_size'] );

		// inputs_status[0] shows the same segments and total.
		$primary = $firehose['inputs_status'][0];
		$this->assertCount( 2, $primary['segments'] );
		$this->assertSame( 700, $primary['total_size'] );
	}

	public function test_get_workers_skips_symlink_segments(): void {
		// Create a real segment + a symlink — symlink must be ignored.
		$dir = "{$this->tmp}/logs/firehose.log/p0";
		\mkdir( $dir, 0755, true );
		\file_put_contents( "{$dir}/0.log", 'real' );
		// Symlink in the segment dir — shouldn't be counted by build_log_status_entry.
		// Skip the test if symlinks aren't supported (e.g., FAT/NTFS via Docker overlay).
		if ( false === @\symlink( "{$dir}/0.log", "{$dir}/9.log" ) ) {
			$this->markTestSkipped( 'symlink() not supported in this filesystem' );
		}

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$body = $resp->get_data();
		$firehose = null;
		foreach ( $body['workers'] as $w ) {
			if ( 'firehose-workers' === $w['type'] ) {
				$firehose = $w;
				break;
			}
		}
		$this->assertNotNull( $firehose );
		// The 'inputs_status' segments tracker excludes symlinks. Only the real
		// 0.log should be listed.
		$primary = $firehose['inputs_status'][0];
		$ids     = \array_column( $primary['segments'], 'id' );
		// Real 0.log is included; symlinked 9.log is excluded.
		$this->assertContains( 0, $ids );
		$this->assertNotContains( 9, $ids );
	}

	public function test_get_workers_returns_wp_error_when_rate_limited(): void {
		$cache = new FakeMemcached();
		PerformanceControllerBase::set_cache( $cache );
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$cache->set( 'newspack_nodes_rate:user_7:' . $window_start, 1000, 70 );

		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 429, $resp->data['status'] ?? 0 );
	}

	public function test_restart_workers_requires_nonce(): void {
		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'nonce', 'wrong-nonce' );
		$result = $ctrl->restart_permissions_check( $req );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] ?? 0 );
	}

	public function test_restart_workers_requires_present_nonce(): void {
		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', 0 );
		// nonce missing entirely
		$result = $ctrl->restart_permissions_check( $req );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_restart_workers_passes_with_valid_nonce(): void {
		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'nonce', \wp_create_nonce( 'newspack_nodes_restart_worker' ) );
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', 0 );
		$result = $ctrl->restart_permissions_check( $req );
		$this->assertTrue( $result );
	}

	public function test_restart_workers_rejects_invalid_partition(): void {
		// num_partitions defaults to 1, so partition 5 is out of range.
		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', 5 );
		$req->set_param( 'all_partitions', false );
		$resp = $ctrl->restart_workers( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_partition', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? 0 );
	}

	public function test_restart_workers_negative_partition_rejected(): void {
		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', -1 );
		$req->set_param( 'all_partitions', false );
		$resp = $ctrl->restart_workers( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_restart_workers_supervisor_targets_supervisor_lock(): void {
		// Pre-create the supervisor lock dir so request_restart_at can succeed.
		\mkdir( "{$this->tmp}/locks/supervisor.lock.d", 0755, true );

		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'supervisor' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'all_partitions', false );
		$resp = $ctrl->restart_workers( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertCount( 1, $body['results'] );
		$this->assertSame( 'supervisor', $body['results'][0]['type'] );
		$this->assertNull( $body['results'][0]['partition'] );
	}

	public function test_restart_workers_topology_targets_lock_per_partition(): void {
		\mkdir( "{$this->tmp}/locks/firehose-workers.p0.lock.d", 0755, true );

		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'all_partitions', false );
		$resp = $ctrl->restart_workers( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		// Just one topology type matched.
		$types = \array_column( $body['results'], 'type' );
		$this->assertContains( 'firehose-workers', $types );
	}

	public function test_restart_workers_all_targets_every_topology(): void {
		// Pre-create lock dirs for every topology.
		foreach ( [ 'firehose-workers', 'request-workers', 'job-workers', 'aggregator' ] as $type ) {
			\mkdir( "{$this->tmp}/locks/{$type}.p0.lock.d", 0755, true );
		}

		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'all' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'all_partitions', false );
		$resp = $ctrl->restart_workers( $req );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$types = \array_column( $body['results'], 'type' );
		$this->assertContains( 'firehose-workers', $types );
		$this->assertContains( 'request-workers', $types );
		$this->assertContains( 'job-workers', $types );
		$this->assertContains( 'aggregator', $types );
	}

	public function test_restart_workers_partitioned_standalone(): void {
		\add_filter(
			'newspack_event_logger_nodes/standalone_workers',
			static function () {
				return [
					'special' => [ 'partitions' => true ],
				];
			}
		);
		\mkdir( "{$this->tmp}/locks/special.p0.lock.d", 0755, true );

		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'special' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'all_partitions', false );
		$resp = $ctrl->restart_workers( $req );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 'special', $body['results'][0]['type'] );
		$this->assertSame( 0, $body['results'][0]['partition'] );
	}

	public function test_restart_workers_non_partitioned_standalone(): void {
		\add_filter(
			'newspack_event_logger_nodes/standalone_workers',
			static function () {
				return [
					'cron' => [ 'partitions' => false ],
				];
			}
		);
		\mkdir( "{$this->tmp}/locks/cron.lock.d", 0755, true );

		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'cron' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'all_partitions', false );
		$resp = $ctrl->restart_workers( $req );
		$body = $resp->get_data();
		$this->assertTrue( $body['success'] );
		$this->assertSame( 'cron', $body['results'][0]['type'] );
		$this->assertNull( $body['results'][0]['partition'] );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new WorkersController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
