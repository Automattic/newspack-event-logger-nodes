<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Event_Logger_Nodes\Url_Sketch;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Url_Sketch::class )]
class UrlSketchTest extends TestCase {

	/** Three standard errors of the sketch: 3 × 1.04 / √16,384. */
	private const THREE_SIGMA = 3 * 1.04 / 128;

	/**
	 * The url_hash of every URL `$shape` spells for `$from` to `$to - 1`,
	 * distinct, as the ranker hands them over.
	 *
	 * @return list<string>
	 */
	private static function hashes( string $shape, int $from, int $to ): array {
		$out = [];
		for ( $i = $from; $i < $to; $i++ ) {
			$out[ Log_Manager::url_hash( \sprintf( $shape, $i ) ) ] = true;
		}
		return \array_map( 'strval', \array_keys( $out ) );
	}

	public function test_sequential_urls_estimate_within_three_standard_errors(): void {
		// Post ids differ in their last characters, which is where FNV-1a's
		// top bits barely move: read unmixed, these land 11 to 14% short.
		foreach ( [ 'https://kea.test/wombat-%d', 'https://moa.test/?p=%d', '/kereru-%d' ] as $shape ) {
			$hashes = self::hashes( $shape, 0, 4217 );
			$this->assertEqualsWithDelta(
				\count( $hashes ),
				Url_Sketch::estimate( Url_Sketch::of( $hashes ) ),
				\count( $hashes ) * self::THREE_SIGMA,
				$shape
			);
		}
	}

	public function test_a_union_counts_a_hash_both_sketches_hold_once(): void {
		$kea  = self::hashes( 'https://kea.test/takahe-%d', 0, 3000 );
		$moa  = self::hashes( 'https://kea.test/takahe-%d', 2000, 5000 );
		$both = \array_values( \array_unique( [ ...$kea, ...$moa ] ) );

		$union = Url_Sketch::union( Url_Sketch::of( $kea ), Url_Sketch::of( $moa ) );

		$this->assertSame( Url_Sketch::of( $both ), $union, 'register for register, one sketch of both sets' );
		$this->assertEqualsWithDelta( 5000, Url_Sketch::estimate( $union ), 5000 * self::THREE_SIGMA );
	}

	public function test_a_union_of_many_is_one_sketch_of_them_all(): void {
		// A reader folding a window's records unions them in one call, the
		// accumulator unpacked throughout; none of the three is left out.
		$sets = [
			self::hashes( '/kea-%d', 0, 700 ),
			self::hashes( '/moa-%d', 0, 1300 ),
			self::hashes( '/tui-%d', 0, 2100 ),
		];

		$union = Url_Sketch::union( ...\array_map( Url_Sketch::of( ... ), $sets ) );

		$this->assertSame( Url_Sketch::of( \array_merge( ...$sets ) ), $union );
		$this->assertSame( Url_Sketch::of( [] ), Url_Sketch::union(), 'of none, the empty sketch' );
	}

	public function test_an_empty_sketch_counts_nothing(): void {
		$this->assertSame( 0, Url_Sketch::estimate( Url_Sketch::of( [] ) ) );
	}

	public function test_a_sketch_is_stored_deflated_and_its_registers_come_back_whole(): void {
		// A header record carries one per list set, and a small set's is
		// almost every register empty: 16 KiB stored raw.
		$empty  = Url_Sketch::of( [] );
		$fifty  = Url_Sketch::of( self::hashes( '/kakapo-%d', 0, 50 ) );
		$dense  = Url_Sketch::of( self::hashes( '/weka-%d', 0, 30000 ) );

		$this->assertSame( \gzdeflate( \str_repeat( "\0", Url_Sketch::BYTES ), 1 ), $empty, 'zlib\'s fastest level: a record is under 1% of a ranking\'s bytes' );
		$this->assertLessThanOrEqual( 128, \strlen( $empty ) );
		$this->assertLessThanOrEqual( 320, \strlen( $fifty ) );
		$this->assertLessThan( 3000, \substr_count( (string) \gzinflate( $dense ), "\0" ), 'dense: most registers set' );
		$this->assertLessThan( Url_Sketch::BYTES / 2, \strlen( $dense ), 'and still under half' );
		$this->assertSame( Url_Sketch::BYTES, \strlen( (string) \gzinflate( $fifty ) ), 'one byte a register, inflated' );
		$this->assertSame( 50, Url_Sketch::estimate( $fifty ) );
	}

	public function test_only_a_deflated_register_string_is_a_sketch(): void {
		$this->assertTrue( Url_Sketch::is_sketch( Url_Sketch::of( self::hashes( '/tui-%d', 0, 7 ) ) ) );
		$this->assertFalse( Url_Sketch::is_sketch( \str_repeat( "\0", Url_Sketch::BYTES ) ), 'the raw registers are not the stored form' );
		$this->assertFalse( Url_Sketch::is_sketch( \gzdeflate( \str_repeat( "\0", Url_Sketch::BYTES - 1 ) ) ), 'nor a register short' );
		$this->assertFalse( Url_Sketch::is_sketch( 'wombat-7731' ) );
	}
}
