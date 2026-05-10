<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\WorkersController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( WorkersController::class )]
class WorkersControllerTest extends TestCase {
	private FakeMemcached $cache;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_current_user_id']  = 7;
		$GLOBALS['_wp_options']       = [];
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
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
		parent::tearDown();
	}

	public function test_register_routes_registers_workers_endpoints(): void {
		( new WorkersController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/workers', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/workers/restart', $GLOBALS['_rest_routes'] );
	}

	public function test_get_workers_returns_documented_shape(): void {
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
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
		$host = \gethostname() ?: 'host';
		$this->cache->set(
			"evlog:pos:{$host}:firehose-workers:p0",
			[ 'firehose.log' => [ 'seg' => 5, 'off' => 1234 ] ],
			60
		);
		$ctrl = new WorkersController();
		$resp = $ctrl->get_workers( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
	}

	public function test_restart_workers_requires_nonce(): void {
		$ctrl = new WorkersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'type', 'firehose-workers' );
		$req->set_param( 'partition', 0 );
		$req->set_param( 'nonce', 'wrong-nonce' );
		$result = $ctrl->restart_permissions_check( $req );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new WorkersController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Each topology worker type should report the source log it actually tails:
	 *   firehose-workers → firehose.log (primary; jobintake.log secondary)
	 *   request-workers   → requests.log
	 *   job-workers      → jobs.log
	 *   aggregator       → no local input (StreamMerger pulls remote feeds via SSE)
	 *
	 * Pre-fix, every worker reported `firehose.log` because the static map
	 * fallback didn't exist and the `log_readers` filter is never populated by
	 * the new plugin. Regression guard for the topology-split rendering.
	 */
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
			'request-workers'   => 'requests.log',
			'job-workers'      => 'jobs.log',
			'aggregator'       => '',
		];
		foreach ( $expected_inputs as $type => $expected ) {
			$this->assertArrayHasKey( $type, $by_type, "Missing topology type: {$type}" );
			foreach ( $by_type[ $type ] as $worker ) {
				$this->assertSame(
					$expected,
					$worker['input_log'] ?? null,
					"Worker {$type} reported wrong input_log: {$worker['input_log']}"
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

		// inputs_status / outputs_status mirror the inputs[]/outputs[] lists.
		$this->assertCount( 2, $firehose['inputs_status'] ?? [] );
		$this->assertCount( 3, $firehose['outputs_status'] ?? [] );
		// Primary input has cursor; secondary input does not (no offsetlog
		// surfaced here, so LogSection treats it as output-only).
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
}
