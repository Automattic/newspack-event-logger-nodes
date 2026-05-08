<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\JobWorker;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( JobWorker::class )]
class JobWorkerTest extends TestCase {
	public function test_executes_job_via_handler(): void {
		$jw = new JobWorker();
		$received = null;
		$jw->register_handler( 'a', function ( $payload ) use ( &$received ) {
			$received = $payload;
		} );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'k' => 'job', 'handler' => 'a', 'payload' => [ 'x' => 1 ] ] );
		$jw->fill( $msg );

		$this->assertSame( [ 'x' => 1 ], $received );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_between_jobs_callback_fires_every_N_jobs(): void {
		$jw = new JobWorker( between_jobs_every: 3 );
		$jw->register_handler( 'noop', fn ( $p ) => null );

		$cycles = 0;
		$jw->set_between_jobs_callback( function () use ( &$cycles ) {
			++$cycles;
		} );

		for ( $i = 0; $i < 10; ++$i ) {
			$msg = Message::new_message();
			$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
			$msg[ Message::VALUE ] = json_encode( [ 'k' => 'job', 'handler' => 'noop', 'payload' => [] ] );
			$jw->fill( $msg );
		}

		// 10 jobs / 3 = 3 cycles fired (after jobs 3, 6, 9).
		$this->assertSame( 3, $cycles );
	}

	public function test_handler_exception_caught_and_logged(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'k' => 'job', 'handler' => 'boom', 'payload' => [] ] );
		$jw->fill( $msg ); // Should not propagate.
		$this->assertSame( 1, $jw->jobs_executed() );
	}
}
