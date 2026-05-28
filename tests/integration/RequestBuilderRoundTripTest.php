<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Topic_Node;

/**
 * Verify Topic → Consumer → RequestBuilder assembles a complete request from
 * real-shape firehose JSONL lines.
 */
class RequestBuilderRoundTripTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
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
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::KEY ]       = (string) ( $entry['rid'] ?? $url );
		$msg[ Message::VALUE ]     = $entry;
		$topic->fill( $msg );
		$topic->flush();
	}

	public function test_topic_to_consumer_to_request_builder_assembles_request(): void {
		$topic = new Topic_Node();
		$topic->arguments( "{$this->tmp}/firehose.log {1}" );
		$this->topic_write( $topic, '/x', [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '99 on host', 'l' => '', 'ts' => 1 ] );
		$this->topic_write( $topic, '/x', [ 'n' => 2, 'rid' => 'r1', 'k' => 'request', 'm' => 'GET /x', 'ts' => 1 ] );
		$this->topic_write( $topic, '/x', [ 'n' => 3, 'rid' => 'r1', 'k' => 'process (complete)', 'duration_ms' => 50.0, 'status_code' => 200, 'ts' => 1 ] );

		$capture = new Capture_Sink_Node();
		$rb      = new Request_Builder_Node();
		$rb->sink( $capture );

		$consumer = new Consumer_Node();
		$consumer->arguments( "{$this->tmp}/firehose.log 0 {$this->tmp}/offsets/rb/p0" );
		$consumer->sink( $rb );
		$consumer->poll();

		$this->assertCount( 1, $capture->captured );
		$assembled = $capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'r1', $assembled['rid'] );
		$this->assertSame( '/x', $assembled['url'] );
		$this->assertSame( 'GET', $assembled['request_method'] );
		$this->assertEqualsWithDelta( 50.0, $assembled['duration_ms'], 1e-9 );
		$this->assertSame( 200, $assembled['status_code'] );
	}
}
