<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;

#[CoversClass( Bucket::class )]
class BucketTest extends TestCase {

	/** A clock past every fixture bucket: 2026-10-06 00:00 UTC. */
	private static function now(): int {
		return \gmmktime( 0, 0, 0, 10, 6, 2026 );
	}

	public function test_an_epoch_falls_in_the_bucket_and_hour_it_floors_to(): void {
		$this->assertSame( 5973194, Bucket::id( 1791958200 ) );
		$this->assertSame( 5973194, Bucket::id( 1791958200 + 299 ) );
		$this->assertSame( 5973195, Bucket::id( 1791958200 + 300 ) );
		$this->assertSame( 497766, Bucket::hour( 1791958200 ) );
		$this->assertSame( 497766, Bucket::hour( 497766 * 3600 + 3599 ) );
		$this->assertSame( 300, Bucket::SECONDS );
		$this->assertSame( 3600, Bucket::HOUR_SECONDS );
	}

	public function test_the_last_n_buckets_are_one_range_ending_at_the_clock_s(): void {
		$this->assertSame( [ [ 5973188, 5973194 ] ], Bucket::last( 7, 1791958200 + 137 ) );
		$this->assertSame( [ [ 5973195, 5973195 ] ], Bucket::last( 1, 1791958200 + 300 ) );
	}

	public function test_a_bucket_is_spelled_as_its_utc_key(): void {
		$this->assertSame( '2026-10-14-06-10', Bucket::key( Bucket::id( 1791958200 ) ) );
		// 12:47 UTC belongs to the 12:45 bucket.
		$this->assertSame( '2026-02-03-12-45', Bucket::key( Bucket::id( \gmmktime( 12, 47, 33, 2, 3, 2026 ) ) ) );
	}

	/** A key reads back to the bucket it spells, whatever the zone. */
	public function test_a_key_reads_back_to_its_bucket_in_utc(): void {
		$zone = \date_default_timezone_get();
		\date_default_timezone_set( 'Pacific/Chatham' );
		try {
			$this->assertSame( Bucket::id( \gmmktime( 13, 35, 0, 10, 4, 2026 ) ), Bucket::of_key( '2026-10-04-13-35' ) );
			$this->assertSame( '2026-12-31-23-55', Bucket::key( Bucket::of_key( '2026-12-31-23-55' ) ) );
		} finally {
			\date_default_timezone_set( $zone );
		}
	}

	/**
	 * A key that is no five-minute `Y-m-d-H-i` is refused, naming it.
	 *
	 * @param string $key The refused key.
	 */
	#[DataProvider( 'unread_keys' )]
	public function test_a_key_that_is_no_five_minute_key_is_refused( string $key ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "bucket {$key} must be a five-minute key, Y-m-d-H-i in UTC" );
		Bucket::of_key( $key );
	}

	/** @return array<string,array{0:string}> */
	public static function unread_keys(): array {
		return [
			'garbage'       => [ 'kakapo' ],
			'trailing data' => [ '2026-10-04-13-35x' ],
			'an hour key'   => [ '2026-10-04-13' ],
			'empty'         => [ '' ],
			'off the grid'  => [ '2026-10-04-13-37' ],
			'overflowing'   => [ '2026-10-04-25-00' ],
		];
	}

	/**
	 * `tests/fixtures/bucket-selection.json` lists the selections both
	 * languages spell.
	 *
	 * @return array<string,mixed>
	 */
	private static function selection_cases(): array {
		return Core::arr( \json_decode( (string) \file_get_contents( __DIR__ . '/../fixtures/bucket-selection.json' ), true ) );
	}

	/**
	 * Cases of one fixture section, keyed by their spelling.
	 *
	 * @param string $section The fixture key.
	 * @return array<string,array{0:string,1:list<string>}>
	 */
	private static function spelled( string $section ): array {
		$out = [];
		foreach ( Core::arr( self::selection_cases()[ $section ] ?? null ) as $case ) {
			$case = Core::arr( $case );
			$spelling         = Core::as_string( $case['spelling'] );
			$out[ $spelling ] = [ $spelling, \array_values( \array_map( Core::as_string( ... ), Core::arr( $case['keys'] ) ) ) ];
		}
		return $out;
	}

	/** @return array<string,array{0:string,1:list<string>}> */
	public static function canonical_selections(): array {
		return self::spelled( 'canonical' );
	}

	/** @return array<string,array{0:string,1:list<string>}> */
	public static function loose_selections(): array {
		return self::spelled( 'normalizes' );
	}

	/** @return array<string,array{0:string}> */
	public static function refused_selections(): array {
		$out = [];
		foreach ( Core::arr( self::selection_cases()['refused'] ?? null ) as $spelling ) {
			$out[ Core::as_string( $spelling ) ] = [ Core::as_string( $spelling ) ];
		}
		return $out;
	}

	/**
	 * The keys a list of bucket-id ranges spans, ascending.
	 *
	 * @param list<array{0:int,1:int}> $ranges First and last bucket of each run.
	 * @return list<string>
	 */
	private static function keys_of( array $ranges ): array {
		$keys = [];
		foreach ( $ranges as [ $first, $last ] ) {
			for ( $id = $first; $id <= $last; $id++ ) {
				$keys[] = Bucket::key( $id );
			}
		}
		return $keys;
	}

	/**
	 * A canonical spelling parses to its keys, and its ranges spell it.
	 *
	 * @param string       $spelling Their canonical spelling.
	 * @param list<string> $keys     The selected bucket keys, ascending.
	 */
	#[DataProvider( 'canonical_selections' )]
	public function test_a_canonical_selection_round_trips( string $spelling, array $keys ): void {
		$ranges = Bucket::selection( $spelling, self::now(), Bucket::MAX_SELECTION );

		$this->assertSame( $keys, self::keys_of( $ranges ) );
		$this->assertSame( $spelling, Bucket::spelling( $ranges ) );
	}

	/**
	 * A loose spelling parses to its keys ascending, each once.
	 *
	 * @param string       $spelling A non-canonical spelling.
	 * @param list<string> $keys     The keys it names.
	 */
	#[DataProvider( 'loose_selections' )]
	public function test_a_loose_selection_normalizes( string $spelling, array $keys ): void {
		$this->assertSame( $keys, self::keys_of( Bucket::selection( $spelling, self::now(), Bucket::MAX_SELECTION ) ) );
	}

	/** A selection is ranges of bucket ids, adjacent and overlapping runs merged. */
	public function test_a_selection_is_ascending_merged_ranges_of_bucket_ids(): void {
		$first = Bucket::id( \gmmktime( 16, 55, 0, 10, 5, 2026 ) );
		$apart = Bucket::id( \gmmktime( 18, 30, 0, 10, 5, 2026 ) );

		$this->assertSame(
			[ [ $first, $first + 3 ], [ $apart, $apart ] ],
			Bucket::selection( '2026-10-05-18-30,2026-10-05-16-55..2026-10-05-17-10,2026-10-05-17-00', self::now(), Bucket::MAX_SELECTION )
		);
		$this->assertSame( [], Bucket::selection( '', self::now(), Bucket::MAX_SELECTION ) );
	}

	/**
	 * A spelling naming no five-minute key, a run ending before it starts,
	 * or an empty run is refused.
	 *
	 * @param string $spelling The refused spelling.
	 */
	#[DataProvider( 'refused_selections' )]
	public function test_a_malformed_selection_is_refused( string $spelling ): void {
		$this->expectException( \InvalidArgumentException::class );
		Bucket::selection( $spelling, self::now(), Bucket::MAX_SELECTION );
	}

	/** A refusal names the piece of the spelling it could not read. */
	public function test_a_refused_selection_names_what_it_could_not_read(): void {
		foreach ( [
			'2026-10-05-16-55,2026-10-05-16-57'  => 'bucket 2026-10-05-16-57 must be a five-minute key, Y-m-d-H-i in UTC',
			'2026-10-05-16-55,kea'               => 'bucket kea must be a five-minute key, Y-m-d-H-i in UTC',
			'2026-10-05-17-10..2026-10-05-16-55' => 'bucket run 2026-10-05-17-10..2026-10-05-16-55 ends before it starts',
		] as $spelling => $message ) {
			try {
				Bucket::selection( $spelling, self::now(), Bucket::MAX_SELECTION );
				$this->fail( "{$spelling} parsed" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage() );
			}
		}
	}

	/** A bucket after the one `$now` falls in holds no rows, so it is refused. */
	public function test_a_bucket_after_the_clock_is_refused(): void {
		$now = \gmmktime( 9, 12, 41, 10, 5, 2026 );

		$this->assertSame( [ [ Bucket::id( $now ), Bucket::id( $now ) ] ], Bucket::selection( '2026-10-05-09-10', $now, Bucket::MAX_SELECTION ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'bucket 2026-10-05-09-15 is in the future' );
		Bucket::selection( '2026-10-05-09-10..2026-10-05-09-15', $now, Bucket::MAX_SELECTION );
	}

	/**
	 * A selection holds at most 288 buckets: a run of 288 parses, and one
	 * bucket more is refused naming the limit, before any run that long is
	 * enumerated.
	 */
	public function test_a_selection_past_the_limit_is_refused(): void {
		$first = \gmmktime( 6, 40, 0, 10, 3, 2026 );
		$last  = Bucket::key( Bucket::id( $first ) + 287 );

		$this->assertSame( [ [ Bucket::id( $first ), Bucket::id( $first ) + 287 ] ], Bucket::selection( "2026-10-03-06-40..{$last}", self::now(), Bucket::MAX_SELECTION ) );
		foreach ( [ '2026-10-03-06-35..' . $last, "2026-10-03-06-40..{$last},2026-10-05-23-55", '2026-10-03-06-40..2026-10-05-23-55,2026-10-05-23-55..2026-10-05-23-55' ] as $over ) {
			try {
				Bucket::selection( $over, \PHP_INT_MAX, Bucket::MAX_SELECTION );
				$this->fail( "{$over} parsed" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'bucket selection holds more than 288 buckets', $e->getMessage() );
			}
		}
	}

	/** A run a century long is refused by arithmetic, never walked. */
	public function test_a_century_long_run_is_refused_without_being_walked(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'bucket selection holds more than 288 buckets' );
		Bucket::selection( '2026-10-03-06-40..2126-10-03-06-40', \PHP_INT_MAX, Bucket::MAX_SELECTION );
	}

	/** The limit is the one the shared fixture names, which JS holds to. */
	public function test_the_limit_is_the_fixtures(): void {
		$this->assertSame( self::selection_cases()['max'] ?? null, Bucket::MAX_SELECTION );
	}
}
