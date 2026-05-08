<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\CaptureSink;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RequestBuilder::class )]
class RequestBuilderTest extends TestCase {
	public function test_constructor_initializes_empty_cache(): void {
		$rb = new RequestBuilder();
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_processing_start_event_adds_to_cache(): void {
		$rb = new RequestBuilder();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = \json_encode( [ 'rid' => 'abc', 'k' => 'start', 'url' => '/x' ] );
		$rb->fill( $msg );
		$this->assertSame( 1, $rb->cache_size() );
	}

	public function test_processing_complete_event_evicts_and_emits(): void {
		$rb = new RequestBuilder();
		$capture = new CaptureSink();
		$rb->sink( $capture );

		$start = Message::new_message();
		$start[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$start[ Message::VALUE ] = \json_encode( [ 'rid' => 'abc', 'k' => 'start', 'url' => '/x' ] );
		$rb->fill( $start );

		$end = Message::new_message();
		$end[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$end[ Message::VALUE ] = \json_encode( [ 'rid' => 'abc', 'k' => 'complete' ] );
		$rb->fill( $end );

		$this->assertCount( 1, $capture->captured );
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_invalid_json_logged_and_skipped(): void {
		$rb = new RequestBuilder();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'not-json';
		$rb->fill( $msg );
		$this->assertSame( 0, $rb->cache_size() );
	}
}
