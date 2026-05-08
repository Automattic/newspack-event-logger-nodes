<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Consumer;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition;
use Newspack_Nodes\Tests\CaptureSink;
use Newspack_Nodes\Topic;

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

	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) return;
		foreach ( scandir( $dir ) as $f ) {
			if ( $f === '.' || $f === '..' ) continue;
			$path = "$dir/$f";
			is_dir( $path ) ? $this->rmdir_recursive( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	public function test_topic_to_consumer_to_request_builder_assembles_request(): void {
		$topic = new Topic( "{$this->tmp}/firehose.log", 1 );
		$topic->write( '/x', json_encode( [ 'rid' => 'r1', 'k' => 'start', 'url' => '/x' ] ) . "\n" );
		$topic->write( '/x', json_encode( [ 'rid' => 'r1', 'k' => 'complete' ] ) . "\n" );

		$capture = new CaptureSink();
		$rb = new RequestBuilder();
		$rb->sink( $capture );

		$consumer = new Consumer( "{$this->tmp}/firehose.log", 0, "{$this->tmp}/offsets/rb/p0" );
		$consumer->sink( $rb );
		$consumer->poll();

		$this->assertCount( 1, $capture->captured );
		$assembled = json_decode( $capture->captured[0][ Message::VALUE ], true );
		$this->assertSame( 'r1', $assembled['rid'] );
		$this->assertSame( '/x', $assembled['url'] );
	}
}
