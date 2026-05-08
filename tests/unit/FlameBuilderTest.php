<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( FlameBuilder::class )]
class FlameBuilderTest extends TestCase {
	public function test_constructor_initializes_empty(): void {
		$fb = new FlameBuilder();
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_processing_completed_request_aggregates_events(): void {
		$fb  = new FlameBuilder();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = \json_encode( [
			'rid'    => 'r1',
			'url'    => '/x',
			'events' => [
				[ 'k' => 'hook', 'name' => 'init', 'time' => 0.5 ],
				[ 'k' => 'hook', 'name' => 'init', 'time' => 1.5 ],
				[ 'k' => 'hook', 'name' => 'parse_request', 'time' => 0.2 ],
			],
		] );
		$fb->fill( $msg );

		$stats = $fb->flush();
		$this->assertArrayHasKey( 'init', $stats );
		$this->assertSame( 2, $stats['init']['count'] );
		$this->assertSame( 2.0, $stats['init']['sum_time'] );
		$this->assertSame( 1, $stats['parse_request']['count'] );
	}

	public function test_flush_clears_stats(): void {
		$fb  = new FlameBuilder();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = \json_encode( [
			'events' => [ [ 'k' => 'hook', 'name' => 'a', 'time' => 1.0 ] ],
		] );
		$fb->fill( $msg );
		$fb->flush();
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_invalid_json_skipped(): void {
		$fb  = new FlameBuilder();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'not-json';
		$fb->fill( $msg );
		$this->assertSame( 0, $fb->stats_count() );
	}
}
