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

	public function test_an_empty_sketch_counts_nothing(): void {
		$this->assertSame( 0, Url_Sketch::estimate( Url_Sketch::of( [] ) ) );
		$this->assertSame( Url_Sketch::BYTES, \strlen( Url_Sketch::of( [] ) ) );
	}
}
