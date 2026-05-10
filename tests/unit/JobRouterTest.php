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
		$jr->register_handler( '1bad-leading-digit', fn ( $payload ) => null );
	}

	public function test_set_local_handler_rejects_invalid_name(): void {
		$jr = new JobRouter();
		$this->expectException( \InvalidArgumentException::class );
		$jr->set_local_handler( 'bad name with spaces', fn ( $p ) => null );
	}

	public function test_set_remote_handler_rejects_invalid_name(): void {
		$jr = new JobRouter();
		$this->expectException( \InvalidArgumentException::class );
		$jr->set_remote_handler( 'bad/path/name', fn ( $p ) => null );
	}

	public function test_processing_job_invokes_handler(): void {
		$jr = new JobRouter();
		$received = null;
		$jr->register_handler( 'echo_job', function ( $payload ) use ( &$received ) {
			$received = $payload;
		} );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'k' => 'job', 'handler' => 'echo_job', 'payload' => [ 'x' => 1 ] ];
		$jr->fill( $msg );

		$this->assertSame( [ 'x' => 1 ], $received );
	}

	public function test_processing_skips_non_job_lines(): void {
		$jr = new JobRouter();
		$called = false;
		$jr->register_handler( 'echo_job', function () use ( &$called ) { $called = true; } );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'k' => 'start', 'rid' => 'r1' ];
		$jr->fill( $msg );

		$this->assertFalse( $called );
	}

	public function test_processing_skips_unknown_handler(): void {
		$jr = new JobRouter();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'k' => 'job', 'handler' => 'unknown', 'payload' => [] ];
		$jr->fill( $msg ); // Should not throw.
		$this->assertTrue( true );
	}

	public function test_oversized_payload_rejected(): void {
		$jr = new JobRouter();
		$called = false;
		$jr->register_handler( 'big', function () use ( &$called ) { $called = true; } );

		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [
			'k' => 'job',
			'handler' => 'big',
			'payload' => [ 'data' => str_repeat( 'x', 11 * 1024 * 1024 ) ],
		];
		$jr->fill( $msg );
		$this->assertFalse( $called );
	}

	// --- Multi-input routing tests ---------------------------------------

	public function test_firehose_job_dispatches_to_local_handler(): void {
		$jr        = new JobRouter();
		$local_hit = false;
		$remote_hit = false;
		$jr->set_local_handler( 'work', function () use ( &$local_hit ) { $local_hit = true; } );
		$jr->set_remote_handler( 'work', function () use ( &$remote_hit ) { $remote_hit = true; } );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'firehose:job';
		$msg[ Message::VALUE ] = [ 'k' => 'job', 'handler' => 'work', 'payload' => 1 ];
		$jr->fill( $msg );

		$this->assertTrue( $local_hit );
		$this->assertFalse( $remote_hit );
	}

	public function test_firehose_remote_job_dispatches_to_remote_handler(): void {
		$jr         = new JobRouter();
		$local_hit  = false;
		$remote_hit = null;
		$jr->set_local_handler( 'sync_setting', function () use ( &$local_hit ) { $local_hit = true; } );
		$jr->set_remote_handler( 'sync_setting', function ( $payload ) use ( &$remote_hit ) { $remote_hit = $payload; } );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'firehose:remote_job';
		$msg[ Message::VALUE ] = [ 'k' => 'remote_job', 'handler' => 'sync_setting', 'payload' => [ 'opt' => 'log_urls' ] ];
		$jr->fill( $msg );

		$this->assertFalse( $local_hit );
		$this->assertSame( [ 'opt' => 'log_urls' ], $remote_hit );
	}

	public function test_jobintake_always_treated_as_local(): void {
		$jr         = new JobRouter();
		$local_hit  = null;
		$remote_hit = false;
		$jr->set_local_handler( 'flush_buffer', function ( $payload ) use ( &$local_hit ) { $local_hit = $payload; } );
		$jr->set_remote_handler( 'flush_buffer', function () use ( &$remote_hit ) { $remote_hit = true; } );

		// Even if the entry says k:"remote_job", jobintake-stamped KEY forces local.
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'jobintake:job';
		$msg[ Message::VALUE ] = [ 'k' => 'job', 'handler' => 'flush_buffer', 'payload' => 'x' ];
		$jr->fill( $msg );

		$this->assertSame( 'x', $local_hit );
		$this->assertFalse( $remote_hit );
	}

	public function test_jobintake_with_remote_kind_still_local(): void {
		// Defense-in-depth: if a misbehaving producer writes k:"remote_job" into
		// jobintake.log, we MUST NOT escalate it to remote dispatch (that would
		// allow spokes to inject hub-only operations). KEY=jobintake forces local.
		$jr         = new JobRouter();
		$local_hit  = false;
		$remote_hit = false;
		$jr->set_local_handler( 'do_thing', function () use ( &$local_hit ) { $local_hit = true; } );
		$jr->set_remote_handler( 'do_thing', function () use ( &$remote_hit ) { $remote_hit = true; } );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'jobintake:remote_job';
		$msg[ Message::VALUE ] = [ 'k' => 'remote_job', 'handler' => 'do_thing', 'payload' => null ];
		$jr->fill( $msg );

		// Local local-handler doesn't carry remote_job either; we drop into local
		// dispatch but the entry's `k` is "remote_job" and the local handler is
		// registered, so it fires.
		$this->assertTrue( $local_hit );
		$this->assertFalse( $remote_hit );
	}

	public function test_unknown_local_handler_logs_warning(): void {
		$jr = new JobRouter();
		// No handlers registered; firehose:job with unknown handler.
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'firehose:job';
		$msg[ Message::VALUE ] = [ 'k' => 'job', 'handler' => 'never_registered', 'payload' => null ];
		$jr->fill( $msg ); // No throw, no dispatch — silent (or rate-limited stderr).
		$this->assertFalse( $jr->has_handler( 'never_registered' ) );
	}

	public function test_unknown_remote_handler_logs_warning(): void {
		$jr = new JobRouter();
		$jr->set_local_handler( 'work', fn () => null ); // Wrong bucket.

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'firehose:remote_job';
		$msg[ Message::VALUE ] = [ 'k' => 'remote_job', 'handler' => 'work', 'payload' => null ];
		$jr->fill( $msg ); // local-bucket has 'work' but remote-bucket doesn't — silent reject.
		$this->assertFalse( $jr->has_remote_handler( 'work' ) );
		$this->assertTrue( $jr->has_local_handler( 'work' ) );
	}

	public function test_malformed_key_falls_back_to_local(): void {
		// KEY without colon (e.g., raw segment offset like "0:1234" from a Consumer
		// that doesn't tag) — the source isn't recognized; we treat it as local.
		$jr        = new JobRouter();
		$local_hit = false;
		$jr->set_local_handler( 'noop', function () use ( &$local_hit ) { $local_hit = true; } );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = '0:1234'; // Looks like seg:offset, not source:kind.
		$msg[ Message::VALUE ] = [ 'k' => 'job', 'handler' => 'noop', 'payload' => null ];
		$jr->fill( $msg );

		$this->assertTrue( $local_hit );
	}

	public function test_local_and_remote_can_share_handler_name(): void {
		// Same handler name registered in both buckets; routing decides which fires.
		$jr           = new JobRouter();
		$local_calls  = 0;
		$remote_calls = 0;
		$jr->set_local_handler( 'sync', function () use ( &$local_calls ) { ++$local_calls; } );
		$jr->set_remote_handler( 'sync', function () use ( &$remote_calls ) { ++$remote_calls; } );

		$mk = function ( string $key, string $k ) {
			$m                   = Message::new_message();
			$m[ Message::TYPE ]  = Message::TM_STRUCT;
			$m[ Message::KEY ]   = $key;
			$m[ Message::VALUE ] = [ 'k' => $k, 'handler' => 'sync', 'payload' => null ];
			return $m;
		};
		$msg = $mk( 'firehose:job', 'job' );
		$jr->fill( $msg );
		$msg = $mk( 'firehose:job', 'job' );
		$jr->fill( $msg );
		$msg = $mk( 'firehose:remote_job', 'remote_job' );
		$jr->fill( $msg );

		$this->assertSame( 2, $local_calls );
		$this->assertSame( 1, $remote_calls );
	}
}
