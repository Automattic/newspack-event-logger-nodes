<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Job_Router_Node;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Job_Worker_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tee_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use Newspack_Nodes\Topic_Node;

/**
 * Full pipeline integration test: real firehose JSONL lines flow through
 * Topic → Consumer → Tee → RequestBuilder → FlameBuilder, and a `k:"job"`
 * line in the same firehose stream gets dispatched to a JobRouter handler.
 */
class FullPipelineTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// Job handlers now register through the WP filter; clear filter state so
		// nothing leaks in from another test class sharing this process.
		$GLOBALS['_wp_actions'] = [];
		$this->tmp = $this->make_temp_dir();
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Write an entry through the canonical Topic::fill path. Constructs a
	 * TM_STRUCT Message keyed by rid (Topic hashes KEY → partition, and the
	 * v0.2.17+ producer convention is KEY = rid so every entry for a single
	 * request co-locates in one partition). Consumer auto-unpacks on the read
	 * side; VALUE is the entry array directly (no JSON wrapper). The `$url`
	 * arg is retained for call-site readability but isn't used as the routing
	 * key anymore.
	 */
	private function topic_write( Topic_Node $topic, string $url, array $entry ): void {
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::KEY ]       = (string) ( $entry['rid'] ?? $url );
		$message[ Message::VALUE ]     = $entry;
		$topic->fill( $message );
		$topic->flush();
	}

	public function test_full_pipeline_topic_consumer_tee_request_builder_flame_builder_job_router(): void {
		// Producer: write firehose lines mixing a request lifecycle and a job.
		$topic = new Topic_Node();
		$topic->arguments( "{$this->tmp}/firehose.p{partition} {1}" );
		$this->topic_write( $topic, '/x', [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '99 on host', 'ts' => 1 ] );
		$this->topic_write( $topic, '/x', [ 'n' => 2, 'rid' => 'r1', 'k' => 'request', 'm' => 'GET /x', 'ts' => 1 ] );
		$this->topic_write( $topic, '/x', [ 'n' => 3, 'rid' => 'r1', 'k' => 'init (start)', 'l' => '', 'ts' => 1 ] );
		$this->topic_write( $topic, '/x', [ 'n' => 4, 'rid' => 'r1', 'k' => 'init (complete)', 'duration_ms' => 5.0, 'ts' => 1 ] );
		// Job entry from the firehose path: LogManager wraps the job body under
		// `m` (the standard message-shape).
		$this->topic_write( $topic, '/x', [
			'n'   => 5,
			'rid' => 'r1',
			'k'   => 'job',
			'm'   => [
				'type'       => 'job',
				'handler'    => 'echo_job',
				'parameters' => [ 'val' => 42 ],
			],
			'ts'  => 1,
		] );
		$this->topic_write( $topic, '/x', [ 'n' => 6, 'rid' => 'r1', 'k' => 'process (complete)', 'duration_ms' => 50.0, 'status_code' => 200, 'ts' => 1 ] );

		// Worker side: scaffolding.
		$router = new Router_Node();
		$router->name( '_router' );

		$tee = new Tee_Node();
		$tee->name( 'firehose-fanout' );
		$tee->sink( $router );

		$rb = new Request_Builder_Node();
		$rb->name( 'request-builder' );

		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->name( 'flame-builder' );
		$fb->set_stats_store( $store );
		$rb->sink( $fb );

		$flame_capture = new Capture_Sink_Node();
		$fb->sink( $flame_capture );
		$fb->connect_node( 'flames:partition' );

		// Job pipeline: JobRouter (pure forwarder) → JobWorker (executor).
		// Production has a jobs.log Partition between them; the test wires
		// them in-process to assert routing without the disk roundtrip.
		$job_executions = [];
		$jw             = new Job_Worker_Node();
		$jw->name( 'job-worker' );
		$this->register_job_handler( $jw, 'echo_job', function ( $params ) use ( &$job_executions ) {
			$job_executions[] = $params;
		} );

		$jr = new Job_Router_Node();
		$jr->name( 'job-router' );
		$jr->sink( $router );
		$jr->connect_node( 'job-worker' );

		$tee->connect_node( 'request-builder' );
		$tee->connect_node( 'job-router' );

		// Consumer must be named so JobRouter recognizes the source via FROM.
		$consumer = new Consumer_Node();
		$consumer->arguments( "{$this->tmp}/firehose.p0 {$this->tmp}/offsets/r/p0" );
		$consumer->name( 'firehose:consumer' );
		$consumer->sink( $tee );
		$this->pump_consumer( $consumer );

		// 1. JobRouter forwarded → JobWorker dispatched 'echo_job' with parameters.
		$this->assertCount( 1, $job_executions );
		$this->assertSame( [ 'val' => 42 ], $job_executions[0] );

		// 2. RequestBuilder assembled r1 → FlameBuilder wrote a flame.
		$this->assertCount( 1, $flame_capture->captured );
		$flame = $flame_capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'r1', $flame['rid'] );
		// Flame tree: root('request') → process → init.
		$this->assertNotEmpty( $flame['children'] );
		$process = $flame['children'][0];
		$this->assertSame( 'process', $process['name'] );
		$this->assertNotEmpty( $process['children'] );
		$this->assertSame( 'init', $process['children'][0]['name'] );

		// 3. FlameBuilder.flush() writes URL aggregate to memcache.
		$fb->flush();
		$url_hash = Request_Builder_Node::url_hash( '/x' );
		$stats    = $store->get_url_stats( $url_hash );
		$this->assertNotNull( $stats, 'flush should write per-URL aggregate' );
		$this->assertSame( 1, $stats['flame_raw']['count'] );
	}
}
