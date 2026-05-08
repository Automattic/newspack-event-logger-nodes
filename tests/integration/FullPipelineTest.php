<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\JobRouter;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Consumer;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router;
use Newspack_Nodes\Tee;
use Newspack_Nodes\Tests\CaptureSink;
use Newspack_Nodes\Topic;

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
		// Producer: write firehose lines.
		$topic = new Topic( "{$this->tmp}/firehose.log", 1 );
		$topic->write( '/x', json_encode( [ 'rid' => 'r1', 'k' => 'start', 'url' => '/x' ] ) . "\n" );
		$topic->write( '/x', json_encode( [
			'rid' => 'r1',
			'k' => 'hook',
			'name' => 'init',
			'time' => 0.5,
		] ) . "\n" );
		$topic->write( '/x', json_encode( [
			'k' => 'job',
			'handler' => 'echo_job',
			'payload' => [ 'val' => 42 ],
		] ) . "\n" );
		$topic->write( '/x', json_encode( [ 'rid' => 'r1', 'k' => 'complete' ] ) . "\n" );

		// Worker side: scaffolding.
		$router = new Router();
		$router->name( '_router' );

		// Tee fans firehose to RequestBuilder + JobRouter.
		$tee = new Tee();
		$tee->name( 'firehose-fanout' );
		$tee->sink( $router );

		$rb = new RequestBuilder();
		$rb->name( 'request-builder' );
		$fb = new FlameBuilder();
		$rb->sink( $fb ); // RequestBuilder's completed-request output -> FlameBuilder

		$job_executions = [];
		$jr = new JobRouter();
		$jr->name( 'job-router' );
		$jr->register_handler( 'echo_job', function ( $payload ) use ( &$job_executions ) {
			$job_executions[] = $payload;
		} );

		$tee->connect_node( 'request-builder' );
		$tee->connect_node( 'job-router' );

		// Consumer feeds the Tee.
		$consumer = new Consumer( "{$this->tmp}/firehose.log", 0, "{$this->tmp}/offsets/r/p0" );
		$consumer->sink( $tee );
		$consumer->poll();

		// Assertions:
		// 1. JobRouter dispatched the 'echo_job' handler with payload.
		$this->assertCount( 1, $job_executions );
		$this->assertSame( [ 'val' => 42 ], $job_executions[0] );

		// 2. RequestBuilder assembled r1 and forwarded to FlameBuilder.
		// FlameBuilder aggregated the 'init' hook event.
		$flame_stats = $fb->flush();
		$this->assertArrayHasKey( 'init', $flame_stats );
		$this->assertSame( 1, $flame_stats['init']['count'] );
		$this->assertSame( 0.5, $flame_stats['init']['sum_time'] );
	}
}
