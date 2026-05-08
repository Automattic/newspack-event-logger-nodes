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

	public function test_rotation_evicts_oldest_bucket_after_200s(): void {
		$rb = new RequestBuilder();
		$capture = new \Newspack_Nodes\Tests\CaptureSink();
		$rb->sink( $capture );

		// Fixed wall clock so rotation is deterministic.
		\Newspack_Nodes\Core::set_now( 1000.0 );

		// Open a request in bucket 0.
		$msg = \Newspack_Nodes\Message::new_message();
		$msg[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_BYTESTREAM;
		$msg[ \Newspack_Nodes\Message::VALUE ] = json_encode( [ 'rid' => 'orphan', 'k' => 'start', 'url' => '/x' ] );
		$rb->fill( $msg );

		$this->assertSame( 1, $rb->cache_size() );

		// Advance >600s — past the 3-bucket retention. Force a maintenance pass.
		\Newspack_Nodes\Core::set_now( 1700.0 );
		$rb->maintenance();

		// Orphan should be emitted as timeout.
		$this->assertCount( 1, $capture->captured );
		$timed_out = json_decode( $capture->captured[0][ \Newspack_Nodes\Message::VALUE ], true );
		$this->assertSame( 'orphan', $timed_out['rid'] );
		$this->assertTrue( $timed_out['timeout'] ?? false );

		// Cache cleared.
		$this->assertSame( 0, $rb->cache_size() );
	}

	public function test_request_completed_within_window_does_not_time_out(): void {
		$rb = new RequestBuilder();
		$capture = new \Newspack_Nodes\Tests\CaptureSink();
		$rb->sink( $capture );

		\Newspack_Nodes\Core::set_now( 1000.0 );
		$start = \Newspack_Nodes\Message::new_message();
		$start[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_BYTESTREAM;
		$start[ \Newspack_Nodes\Message::VALUE ] = json_encode( [ 'rid' => 'r1', 'k' => 'start', 'url' => '/x' ] );
		$rb->fill( $start );

		\Newspack_Nodes\Core::set_now( 1100.0 ); // within first bucket
		$end = \Newspack_Nodes\Message::new_message();
		$end[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_BYTESTREAM;
		$end[ \Newspack_Nodes\Message::VALUE ] = json_encode( [ 'rid' => 'r1', 'k' => 'complete' ] );
		$rb->fill( $end );

		$this->assertCount( 1, $capture->captured );
		$out = json_decode( $capture->captured[0][ \Newspack_Nodes\Message::VALUE ], true );
		$this->assertArrayNotHasKey( 'timeout', $out );

		// Advancing past timeout no longer matters — already evicted.
		\Newspack_Nodes\Core::set_now( 2000.0 );
		$rb->maintenance();
		$this->assertCount( 1, $capture->captured ); // still just the one
	}

	public function test_overflow_evicts_oldest_with_timeout_flag(): void {
		$rb = new RequestBuilder( max_per_bucket: 3 ); // small for test
		$capture = new \Newspack_Nodes\Tests\CaptureSink();
		$rb->sink( $capture );

		\Newspack_Nodes\Core::set_now( 1000.0 );
		for ( $i = 0; $i < 5; ++$i ) {
			$msg = \Newspack_Nodes\Message::new_message();
			$msg[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_BYTESTREAM;
			$msg[ \Newspack_Nodes\Message::VALUE ] = json_encode( [ 'rid' => "r$i", 'k' => 'start', 'url' => "/u$i" ] );
			$rb->fill( $msg );
		}

		// Exceeding 3 in current bucket pushes oldest out as timeouts.
		$this->assertGreaterThanOrEqual( 2, count( $capture->captured ) );
		foreach ( $capture->captured as $c ) {
			$out = json_decode( $c[ \Newspack_Nodes\Message::VALUE ], true );
			$this->assertTrue( $out['timeout'] ?? false );
		}
	}
}
