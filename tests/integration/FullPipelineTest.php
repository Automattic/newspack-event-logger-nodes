<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\JobRouter;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Consumer;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router;
use Newspack_Nodes\Tee;
use Newspack_Nodes\Tests\CaptureSink;
use Newspack_Nodes\Topic;

/**
 * Full pipeline integration test: real firehose JSONL lines flow through
 * Topic → Consumer → Tee → RequestBuilder → FlameBuilder, and a `k:"job"`
 * line in the same firehose stream gets dispatched to a JobRouter handler.
 */
class FullPipelineTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = $this->make_temp_dir();
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	public function test_full_pipeline_topic_consumer_tee_request_builder_flame_builder_job_router(): void {
		// Producer: write firehose lines mixing a request lifecycle and a job.
		$topic = new Topic( "{$this->tmp}/firehose.log", 1 );
		$topic->write( '/x', \json_encode( [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '99 on host', 'ts' => 1 ] ) . "\n" );
		$topic->write( '/x', \json_encode( [ 'n' => 2, 'rid' => 'r1', 'k' => 'request', 'm' => 'GET /x', 'ts' => 1 ] ) . "\n" );
		$topic->write( '/x', \json_encode( [ 'n' => 3, 'rid' => 'r1', 'k' => 'init (start)', 'l' => '', 'ts' => 1 ] ) . "\n" );
		$topic->write( '/x', \json_encode( [ 'n' => 4, 'rid' => 'r1', 'k' => 'init (complete)', 'duration_ms' => 5.0, 'ts' => 1 ] ) . "\n" );
		$topic->write( '/x', \json_encode( [
			'k'       => 'job',
			'handler' => 'echo_job',
			'payload' => [ 'val' => 42 ],
		] ) . "\n" );
		$topic->write( '/x', \json_encode( [ 'n' => 5, 'rid' => 'r1', 'k' => 'process (complete)', 'duration_ms' => 50.0, 'status_code' => 200, 'ts' => 1 ] ) . "\n" );

		// Worker side: scaffolding.
		$router = new Router();
		$router->name( '_router' );

		$tee = new Tee();
		$tee->name( 'firehose-fanout' );
		$tee->sink( $router );

		$rb = new RequestBuilder();
		$rb->name( 'request-builder' );

		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
		$fb->set_stats_store( $store );
		$rb->sink( $fb );

		$flame_capture = new CaptureSink();
		$fb->set_flames_sink( $flame_capture );

		$job_executions = [];
		$jr             = new JobRouter();
		$jr->name( 'job-router' );
		$jr->register_handler( 'echo_job', function ( $payload ) use ( &$job_executions ) {
			$job_executions[] = $payload;
		} );

		$tee->connect_node( 'request-builder' );
		$tee->connect_node( 'job-router' );

		$consumer = new Consumer( "{$this->tmp}/firehose.log", 0, "{$this->tmp}/offsets/r/p0" );
		$consumer->sink( $tee );
		$consumer->poll();

		// 1. JobRouter dispatched the 'echo_job' handler with payload.
		$this->assertCount( 1, $job_executions );
		$this->assertSame( [ 'val' => 42 ], $job_executions[0] );

		// 2. RequestBuilder assembled r1 → FlameBuilder wrote a flame.
		$this->assertCount( 1, $flame_capture->captured );
		$flame = \json_decode( $flame_capture->captured[0][ Message::VALUE ], true );
		$this->assertSame( 'r1', $flame['rid'] );
		// Flame tree: root('request') → process → init.
		$this->assertNotEmpty( $flame['children'] );
		$process = $flame['children'][0];
		$this->assertSame( 'process', $process['name'] );
		$this->assertNotEmpty( $process['children'] );
		$this->assertSame( 'init', $process['children'][0]['name'] );

		// 3. FlameBuilder.flush() writes URL aggregate to memcache.
		$fb->flush();
		$url_hash = RequestBuilder::url_hash( '/x' );
		$stats    = $store->get_url_stats( $url_hash );
		$this->assertNotNull( $stats, 'flush should write per-URL aggregate' );
		$this->assertSame( 1, $stats['flame_raw']['count'] );
	}
}
