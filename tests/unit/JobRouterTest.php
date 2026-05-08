<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\JobRouter;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( JobRouter::class )]
class JobRouterTest extends TestCase {
	public function test_register_handler_stores_callback(): void {
		$jr = new JobRouter();
		$jr->register_handler( 'my_handler', fn ( $payload ) => null );
		$this->assertTrue( $jr->has_handler( 'my_handler' ) );
	}

	public function test_register_handler_rejects_invalid_name(): void {
		$jr = new JobRouter();
		$this->expectException( \InvalidArgumentException::class );
		$jr->register_handler( 'BadName-WithDash', fn ( $payload ) => null );
	}

	public function test_processing_job_invokes_handler(): void {
		$jr = new JobRouter();
		$received = null;
		$jr->register_handler( 'echo_job', function ( $payload ) use ( &$received ) {
			$received = $payload;
		} );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'k' => 'job', 'handler' => 'echo_job', 'payload' => [ 'x' => 1 ] ] );
		$jr->fill( $msg );

		$this->assertSame( [ 'x' => 1 ], $received );
	}

	public function test_processing_skips_non_job_lines(): void {
		$jr = new JobRouter();
		$called = false;
		$jr->register_handler( 'echo_job', function () use ( &$called ) { $called = true; } );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'k' => 'start', 'rid' => 'r1' ] );
		$jr->fill( $msg );

		$this->assertFalse( $called );
	}

	public function test_processing_skips_unknown_handler(): void {
		$jr = new JobRouter();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'k' => 'job', 'handler' => 'unknown', 'payload' => [] ] );
		$jr->fill( $msg ); // Should not throw.
		$this->assertTrue( true );
	}

	public function test_oversized_payload_rejected(): void {
		$jr = new JobRouter();
		$called = false;
		$jr->register_handler( 'big', function () use ( &$called ) { $called = true; } );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [
			'k' => 'job',
			'handler' => 'big',
			'payload' => [ 'data' => str_repeat( 'x', 11 * 1024 * 1024 ) ],
		] );
		$jr->fill( $msg );
		$this->assertFalse( $called );
	}
}
