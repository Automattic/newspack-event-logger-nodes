<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * The LARGEST value a `Stats_Store` cap admits stays inside
 * `Stats_Store::ITEM_BUDGET`, measured by whichever serializer spells it
 * longer. "Largest" means every dimension the cap leaves open at once: the
 * most rows, the longest paths and names, and numbers at their longest
 * spelling — counts of twelve digits and doubles such as
 * -1.2345678901234567E+300. The strings are all distinct, because igbinary
 * stores a repeated string once and would otherwise measure small.
 */
#[CoversClass( Stats_Store::class )]
class ItemBudgetTest extends TestCase {

	/** A double at its longest `serialize()` spelling. */
	private const WIDE_FLOAT = -1.2345678901234567E+300;

	/** A count at twelve digits, past any real bucket's traffic. */
	private const WIDE_COUNT = 999_999_999_999;

	/** A timestamp as the rows carry one. */
	private const LAST_SEEN = 1_790_000_000;

	/** A count-capped value fits under either serializer. */
	private static function assert_fits_both( mixed $value, string $what ): void {
		self::assertLessThanOrEqual( Stats_Store::ITEM_BUDGET, \strlen( \serialize( $value ) ), "{$what}, serialize()" );
		if ( \function_exists( 'igbinary_serialize' ) ) {
			self::assertLessThanOrEqual( Stats_Store::ITEM_BUDGET, \strlen( (string) \igbinary_serialize( $value ) ), "{$what}, igbinary" );
		}
	}

	/** A distinct string of exactly `$bytes` bytes. */
	private static function wide( string $prefix, int $bytes ): string {
		return \str_pad( $prefix, $bytes, 'x' );
	}

	/** A stored URL row with every number at its widest. */
	private static function wide_row( string $path, bool $worker = false ): array {
		return [
			Stats_Store::ROW_COUNT       => self::WIDE_COUNT,
			Stats_Store::ROW_TIMED_COUNT => self::WIDE_COUNT,
			Stats_Store::ROW_SUM_MS      => self::WIDE_FLOAT,
			Stats_Store::ROW_SUM_PEAK_MB => self::WIDE_FLOAT,
			Stats_Store::ROW_COUNT_2XX   => self::WIDE_COUNT,
			Stats_Store::ROW_COUNT_3XX   => self::WIDE_COUNT,
			Stats_Store::ROW_COUNT_4XX   => self::WIDE_COUNT,
			Stats_Store::ROW_COUNT_5XX   => self::WIDE_COUNT,
			Stats_Store::ROW_MIN_MS      => self::WIDE_FLOAT,
			Stats_Store::ROW_MAX_MS      => self::WIDE_FLOAT,
			Stats_Store::ROW_MAX_PEAK_MB => self::WIDE_FLOAT,
			Stats_Store::ROW_LAST_SEEN   => self::LAST_SEEN,
			Stats_Store::ROW_WORKER      => $worker,
			Stats_Store::ROW_PATH        => $path,
		];
	}

	// ----- urls / urls_h: one cap, both tiers -----

	// ----- lb / lb_s: the flush's merge -----

	// ----- lb_h: twelve buckets folded into one -----

	// ----- url: the flame tree and profiles -----

	// ----- urlrank_s / urlrank_sh, and urlhdr / urlhdr_h beside them -----

	public function test_a_ranked_hour_list_of_the_longest_paths_fits_the_item_budget(): void {
		// Two servers, so the site's lists merge a full list from each. The
		// second is the longest name DNS allows, 253 bytes, because the site's
		// `url` lists join each path to its server's name.
		$longest = \implode( '.', [ \str_repeat( 'k', 63 ), \str_repeat( 'e', 63 ), \str_repeat( 'a', 63 ), \str_repeat( 't', 61 ) ] );
		$servers = [];
		foreach ( [ self::SEED_SERVER, $longest ] as $at => $server ) {
			for ( $i = 0; $i < 2 * Stats_Store::URL_RANK_N_HOUR; $i++ ) {
				$servers[ $server ][ \sprintf( '%012x', $at << 20 | $i ) ] = self::wide_row( self::wide( "/{$i}/", Url_Parts::MAX_PATH_BYTES ) );
			}
		}

		$writes = Stats_Store::ranked_writes( self::by_shard( $servers ), true, '2026-09-22-10' );

		// A count, not the lists: an assertion exports its value, megabytes here.
		// Every set's lists take the same bound, so these rows size them all.
		$this->assertSame(
			\count( Stats_Store::RANK_SETS ) * 3 * ( \count( Stats_Store::URL_SORTS ) * \count( Stats_Store::URL_ORDERS ) + 1 ),
			\count( $writes ),
			'each set: each server\'s lists and record, then the site\'s'
		);
		foreach ( $writes as [ $parts, , $entries ] ) {
			self::assert_fits_both( $entries, \implode( ':', $parts ) );
		}
	}

	// ----- url_row_h: counted, twelve slots a family -----

	// ----- urlmap, and the one path cap every stored path takes -----

	public function test_a_url_name_of_a_path_past_the_cap_fits_and_is_cut_to_it(): void {
		$store      = $this->stats_store( partition: 0, max_lifespan: 86400 );
		$url        = 'https://' . self::SEED_SERVER . self::wide( '/long/', 8192 );

		$store->set_url_names( [ self::SEED_SERVER => [ 'a1b2c3d4e5f6' => $url ] ] );
		$name = $store->bucket_get_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a1b2c3d4e5f6' ] ] )[0] ?? null;

		$this->assertIsArray( $name );
		self::assert_fits_both( $name, 'a url name' );
		$this->assertSame( Stats_Store::row_path( $url, self::SEED_SERVER ), $name[1], 'the name takes the row\'s path cap' );
		$this->assertLessThanOrEqual( Url_Parts::MAX_PATH_BYTES, \strlen( $name[1] ) );
	}

	// ----- dim_h, url_dim_h, categories_h, url_cat_h: counted per slot -----
}
