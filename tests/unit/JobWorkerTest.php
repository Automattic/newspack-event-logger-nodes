<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\JobWorker;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( JobWorker::class )]
class JobWorkerTest extends TestCase {
	private function job_message( string $handler, mixed $payload = null ): array {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'k' => 'job', 'handler' => $handler, 'payload' => $payload ] );
		return $msg;
	}

	public function test_executes_job_via_handler(): void {
		$jw = new JobWorker();
		$received = null;
		$jw->register_handler( 'a', function ( $payload ) use ( &$received ) {
			$received = $payload;
		} );

		$msg = $this->job_message( 'a', [ 'x' => 1 ] );
		$jw->fill( $msg );

		$this->assertSame( [ 'x' => 1 ], $received );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_between_jobs_callback_fires_after_each_job(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		$counters = [];
		$jw->set_between_jobs_callback( function ( int $count ) use ( &$counters ) {
			$counters[] = $count;
		} );

		for ( $i = 0; $i < 5; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}

		// Callback fires after every job, with the running counter.
		$this->assertSame( [ 1, 2, 3, 4, 5 ], $counters );
		$this->assertSame( 5, $jw->jobs_executed() );
	}

	public function test_callback_can_implement_every_n_cadence(): void {
		// The cadence (e.g., wp_cache_flush every 50) lives in the callback,
		// not in JobWorker. Verify a typical "every-50" pattern works.
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		$flush_count = 0;
		$jw->set_between_jobs_callback( function ( int $count ) use ( &$flush_count ) {
			if ( $count % 3 === 0 ) {
				++$flush_count;
			}
		} );

		for ( $i = 0; $i < 10; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}

		// 10 jobs / 3 = 3 cycles (after jobs 3, 6, 9).
		$this->assertSame( 3, $flush_count );
	}

	public function test_callback_fires_after_exception(): void {
		// Memory leaks accumulate fastest after a crashed handler — the
		// between-jobs hook MUST fire even when the handler threw.
		$jw = new JobWorker();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$cycles = 0;
		$jw->set_between_jobs_callback( function () use ( &$cycles ) { ++$cycles; } );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg );

		$this->assertSame( 1, $cycles );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_set_callback_to_null_clears_it(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		$cycles = 0;
		$jw->set_between_jobs_callback( function () use ( &$cycles ) { ++$cycles; } );

		$msg = $this->job_message( 'noop' );
		$jw->fill( $msg );
		$this->assertSame( 1, $cycles );

		$jw->set_between_jobs_callback( null );
		$msg = $this->job_message( 'noop' );
		$jw->fill( $msg );

		$this->assertSame( 1, $cycles ); // Unchanged after clear.
	}

	public function test_handler_exception_caught_and_logged(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg ); // Should not propagate.
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_no_callback_does_not_fire(): void {
		// No callback registered: jobs run, counter advances, no exceptions.
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		for ( $i = 0; $i < 3; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}
		$this->assertSame( 3, $jw->jobs_executed() );
	}
}
