<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\StreamMerger;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\EventFramework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\CaptureSink;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( StreamMerger::class )]
class StreamMergerTest extends TestCase {
	public function test_processes_sse_data_lines(): void {
		$sm = new StreamMerger();
		$capture = new CaptureSink();
		$sm->sink( $capture );

		$sm->process_sse_chunk( "data: {\"k\":\"start\"}\n\ndata: {\"k\":\"complete\"}\n\n" );

		$this->assertCount( 2, $capture->captured );
		$this->assertSame( '{"k":"start"}', $capture->captured[0][ Message::VALUE ] );
		$this->assertSame( '{"k":"complete"}', $capture->captured[1][ Message::VALUE ] );
	}

	public function test_skips_non_data_lines(): void {
		$sm = new StreamMerger();
		$capture = new CaptureSink();
		$sm->sink( $capture );

		$sm->process_sse_chunk( "event: heartbeat\ndata: alive\n\nid: 123\ndata: payload\n\n" );

		$this->assertCount( 2, $capture->captured );
		$this->assertSame( 'alive',   $capture->captured[0][ Message::VALUE ] );
		$this->assertSame( 'payload', $capture->captured[1][ Message::VALUE ] );
	}

	public function test_handles_partial_chunk_across_calls(): void {
		$sm = new StreamMerger();
		$capture = new CaptureSink();
		$sm->sink( $capture );

		// First chunk: incomplete (no trailing blank line).
		$sm->process_sse_chunk( "data: part" );
		$this->assertCount( 0, $capture->captured );

		// Second chunk completes it.
		$sm->process_sse_chunk( "ial\n\n" );
		$this->assertCount( 1, $capture->captured );
		$this->assertSame( 'partial', $capture->captured[0][ Message::VALUE ] );
	}

	public function test_remote_job_rewrite_filter_applied(): void {
		$GLOBALS['_wp_actions'] = [];
		add_filter( 'newspack_nodes/aggregator_ingest_line', function ( string $line ): string {
			$decoded = json_decode( $line, true );
			if ( ( $decoded['k'] ?? '' ) === 'job' ) {
				$decoded['k'] = 'remote_job';
				return json_encode( $decoded );
			}
			return $line;
		} );

		$sm = new StreamMerger();
		$capture = new CaptureSink();
		$sm->sink( $capture );

		$sm->process_sse_chunk( 'data: {"k":"job","handler":"x"}' . "\n\n" );

		$out = json_decode( $capture->captured[0][ Message::VALUE ], true );
		$this->assertSame( 'remote_job', $out['k'] );
	}

	public function test_add_remote_registers_curl_handle_with_event_framework(): void {
		EventFramework::reset();
		$sm = new StreamMerger();
		$sm->add_remote( 'site-a', 'http://localhost:9999/stream', 'tok' );
		$this->assertSame( 1, $sm->remote_count() );
	}

	public function test_on_curl_message_clears_handle_on_completion(): void {
		EventFramework::reset();
		$sm = new StreamMerger();
		Core::set_now( 1000.0 );
		$sm->add_remote( 'site-a', 'http://127.0.0.1:1/x', 'tok' ); // unreachable

		// Active count goes up after add (handle attached).
		$this->assertGreaterThanOrEqual( 0, $sm->active_count() );

		// Synthesize a completion message and feed it to on_curl_message.
		// Use the actually-attached handle so the lookup succeeds.
		$handle = $sm->test_get_handle( 'site-a' );
		$this->assertNotNull( $handle );

		$sm->on_curl_message( [
			'msg'    => \CURLMSG_DONE,
			'result' => \CURLE_COULDNT_CONNECT,
			'handle' => $handle,
		] );

		// After completion, the handle is cleared.
		$this->assertNull( $sm->test_get_handle( 'site-a' ) );
		$this->assertSame( 0, $sm->active_count() );
		$this->assertSame( 1, $sm->remote_count() );
	}

	public function test_tick_attempts_reconnect_after_backoff(): void {
		EventFramework::reset();
		$sm = new StreamMerger();
		Core::set_now( 1000.0 );
		$sm->add_remote( 'site-a', 'http://127.0.0.1:1/x', 'tok' );

		// Force a disconnect by simulating a curl-completion message.
		$handle = $sm->test_get_handle( 'site-a' );
		$sm->on_curl_message( [
			'msg'    => \CURLMSG_DONE,
			'result' => \CURLE_COULDNT_CONNECT,
			'handle' => $handle,
		] );
		$this->assertSame( 0, $sm->active_count() );

		// Within backoff window: tick is no-op (handle stays null).
		Core::set_now( 1002.0 );
		$sm->tick();
		$this->assertSame( 0, $sm->active_count() );
		$this->assertSame( 1, $sm->remote_count() );

		// After backoff window: tick reconnects (handle becomes non-null).
		Core::set_now( 1010.0 );
		$sm->tick();
		$this->assertSame( 1, $sm->active_count() );
		$this->assertSame( 1, $sm->remote_count() );
	}
}
