<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\StatsAggregator;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( StatsAggregator::class )]
class StatsAggregatorTest extends TestCase {
	public function test_constructor_initializes_empty(): void {
		$sa = new StatsAggregator();
		$this->assertSame( 0, $sa->url_count() );
	}

	public function test_aggregates_per_url_counter(): void {
		$sa = new StatsAggregator();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'url' => '/x', 'req_time' => 0.5 ] );
		$sa->fill( $msg );

		$msg2 = Message::new_message();
		$msg2[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg2[ Message::VALUE ] = json_encode( [ 'url' => '/x', 'req_time' => 1.5 ] );
		$sa->fill( $msg2 );

		$stats = $sa->flush();
		$this->assertArrayHasKey( '/x', $stats );
		$this->assertSame( 2, $stats['/x']['count'] );
		$this->assertSame( 2.0, $stats['/x']['sum_req_time'] );
	}

	public function test_separate_urls_tracked_separately(): void {
		$sa = new StatsAggregator();
		foreach ( [ '/a', '/b', '/a', '/c' ] as $url ) {
			$msg = Message::new_message();
			$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
			$msg[ Message::VALUE ] = json_encode( [ 'url' => $url, 'req_time' => 1.0 ] );
			$sa->fill( $msg );
		}
		$stats = $sa->flush();
		$this->assertSame( 3, count( $stats ) );
		$this->assertSame( 2, $stats['/a']['count'] );
		$this->assertSame( 1, $stats['/b']['count'] );
	}

	public function test_flush_clears_state(): void {
		$sa = new StatsAggregator();
		$msg = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = json_encode( [ 'url' => '/x', 'req_time' => 1.0 ] );
		$sa->fill( $msg );
		$sa->flush();
		$this->assertSame( 0, $sa->url_count() );
	}
}
