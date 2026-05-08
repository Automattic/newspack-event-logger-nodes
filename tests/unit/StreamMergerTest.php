<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\StreamMerger;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
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
}
