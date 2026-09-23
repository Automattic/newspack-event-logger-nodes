<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

/**
 * One size test per memcache namespace: the LARGEST value each producer's
 * cap admits stays inside `Stats_Store::ITEM_BUDGET`.
 *
 * A cap that estimates bytes estimates them for the serializer memcached is
 * configured with, so each of those runs once per serializer and is measured
 * by that same serializer. A cap that counts is measured by whichever of the
 * two spells its value longer.
 *
 * "Largest" means every dimension the cap leaves open at once: the most
 * rows, the longest paths and names, and numbers at their longest spelling —
 * counts of twelve digits and doubles such as -1.2345678901234567E+300. The
 * strings are all distinct, because igbinary stores a repeated string once
 * and would otherwise measure small.
 */
#[CoversClass( Flame_Builder_Node::class )]
#[CoversClass( Flame_Tree::class )]
#[CoversClass( Stats_Store::class )]
class ItemBudgetTest extends TestCase {

	/** A double at its longest `serialize()` spelling. */
	private const WIDE_FLOAT = -1.2345678901234567E+300;

	/** A count at twelve digits, past any real bucket's traffic. */
	private const WIDE_COUNT = 999_999_999_999;

	/** Longest string one firehose entry carries: `Log_Manager::MAX_DATA_SIZE`. */
	private const FIREHOSE_ENTRY_BYTES = 3840;

	/** A timestamp as the rows carry one. */
	private const LAST_SEEN = 1_790_000_000;

	/** Every serializer an estimate is kept for. */
	public static function serializers(): array {
		return [
			'php'      => [ Stats_Store::SERIALIZER_PHP ],
			'igbinary' => [ Stats_Store::SERIALIZER_IGBINARY ],
		];
	}

	/** Estimate for `$serializer`, as `Stats_Store` would on a handle configured so. */
	private static function estimate_for( string $serializer ): void {
		if ( Stats_Store::SERIALIZER_IGBINARY === $serializer && ! \function_exists( 'igbinary_serialize' ) ) {
			self::markTestSkipped( 'igbinary is not loaded' );
		}
		Stats_Store::$serializer = $serializer;
	}

	/** Bytes `$value` takes under the serializer the estimate was made for. */
	private static function stored_bytes( mixed $value ): int {
		return Stats_Store::SERIALIZER_IGBINARY === Stats_Store::$serializer
			? \strlen( (string) \igbinary_serialize( $value ) )
			: \strlen( \serialize( $value ) );
	}

	private static function assert_fits( mixed $value, string $what ): void {
		self::assertLessThanOrEqual( Stats_Store::ITEM_BUDGET, self::stored_bytes( $value ), $what );
	}

	/** A value a byte cap cut spends most of the budget: the estimate is its serializer's. */
	private static function assert_fills( mixed $value, string $what ): void {
		self::assertGreaterThan( 0.9 * Stats_Store::ITEM_BUDGET, self::stored_bytes( $value ), $what );
	}

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

	/** A plain row: a count and the path, for totals a fold must keep. */
	private static function plain_row( int $count, string $path ): array {
		return [
			Stats_Store::ROW_COUNT       => $count,
			Stats_Store::ROW_TIMED_COUNT => $count,
			Stats_Store::ROW_SUM_MS      => 2.0 * $count,
			Stats_Store::ROW_SUM_PEAK_MB => 0.0,
			Stats_Store::ROW_COUNT_2XX   => $count,
			Stats_Store::ROW_COUNT_3XX   => 0,
			Stats_Store::ROW_COUNT_4XX   => 0,
			Stats_Store::ROW_COUNT_5XX   => 0,
			Stats_Store::ROW_MIN_MS      => 2.0,
			Stats_Store::ROW_MAX_MS      => 2.0,
			Stats_Store::ROW_MAX_PEAK_MB => 0.0,
			Stats_Store::ROW_LAST_SEEN   => self::LAST_SEEN,
			Stats_Store::ROW_WORKER      => false,
			Stats_Store::ROW_PATH        => $path,
		];
	}

	/** Call one of the builder's private static caps. */
	private static function builder( string $method, mixed ...$args ): mixed {
		return ( new \ReflectionMethod( Flame_Builder_Node::class, $method ) )->invoke( null, ...$args );
	}

	/** A leaderboard of `$cats` categories, each `$entries` entries wide. */
	private static function wide_leaderboard( int $cats, int $entries, string $tag = '' ): array {
		$categories = [];
		for ( $c = 0; $c < $cats; $c++ ) {
			$list = [];
			for ( $e = 0; $e < $entries; $e++ ) {
				// Distinct values: igbinary stores one shared array once.
				$list[ self::wide( "{$tag}e{$c}.{$e}.", 200 ) ] = [ self::WIDE_FLOAT, self::WIDE_FLOAT, self::WIDE_COUNT - $c * 1000 - $e ];
			}
			$categories[ self::wide( "{$tag}c{$c}.", 500 ) ] = [
				'samples'   => self::WIDE_COUNT,
				'sum_time'  => (float) ( $cats - $c ),
				'sum_count' => self::WIDE_FLOAT,
				'entries'   => $list,
			];
		}
		return [ 'count' => self::WIDE_COUNT, 'sum_req_time' => self::WIDE_FLOAT, 'categories' => $categories ];
	}

	// ----- urls / urls_h: one cap, both tiers -----

	#[DataProvider( 'serializers' )]
	public function test_a_url_shard_of_the_widest_rows_fits_the_item_budget( string $serializer ): void {
		self::estimate_for( $serializer );
		// 3,000 rows of 1,024-byte paths: 4MB under serialize(), 3MB under
		// igbinary, and nothing but bytes to cap them.
		$rows = [];
		for ( $i = 0; $i < 3000; $i++ ) {
			$rows[ \sprintf( '%012x', $i ) ] = self::wide_row( self::wide( "/{$i}/", Stats_Store::MAX_PATH_BYTES ) );
		}
		$rows[ Stats_Store::OTHER_KEY ]        = self::wide_row( '' );
		$rows[ Stats_Store::OTHER_WORKER_KEY ] = self::wide_row( '', true );

		$capped = self::builder( 'cap_url_rows', $rows );

		self::assert_fits( $capped, 'a shard of the longest paths and widest numbers' );
		self::assert_fills( $capped, 'and wastes little of it' );
	}

	#[DataProvider( 'serializers' )]
	public function test_a_shard_cut_by_bytes_folds_its_tail_and_keeps_the_totals( string $serializer ): void {
		self::estimate_for( $serializer );
		$rows = [];
		for ( $i = 0; $i < 1500; $i++ ) {
			$rows[ \sprintf( '%012x', $i ) ] = self::plain_row( 3000 - $i, self::wide( "/{$i}/", Stats_Store::MAX_PATH_BYTES ) );
		}
		$total = \array_sum( \array_column( $rows, Stats_Store::ROW_COUNT ) );

		$capped = self::builder( 'cap_url_rows', $rows );

		$this->assertLessThan( 1500, \count( $capped ), 'bytes bound this shard' );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $capped, 'the tail folds rather than dropping' );
		$this->assertSame( $total, \array_sum( \array_column( $capped, Stats_Store::ROW_COUNT ) ) );
		$this->assertArrayHasKey( \sprintf( '%012x', 0 ), $capped, 'the busiest row stays' );
		$this->assertArrayNotHasKey( \sprintf( '%012x', 1499 ), $capped, 'the quietest folds' );
	}

	// ----- lb / lb_s: the flush's merge -----

	#[DataProvider( 'serializers' )]
	public function test_a_leaderboard_of_the_widest_categories_fits_the_item_budget( string $serializer ): void {
		self::estimate_for( $serializer );
		$capped = self::builder( 'cap_leaderboard', self::wide_leaderboard( 400, 100 ), Stats_Store::ITEM_BUDGET );

		self::assert_fits( $capped, 'a leaderboard of the most, widest categories' );
		self::assert_fills( $capped, 'and wastes little of it' );
		$this->assertLessThanOrEqual( Stats_Store::MAX_LB_CATEGORIES, \count( $capped['categories'] ) );
	}

	#[DataProvider( 'serializers' )]
	public function test_a_capped_leaderboard_keeps_the_slowest_and_folds_the_rest_into_other( string $serializer ): void {
		self::estimate_for( $serializer );
		$board = self::wide_leaderboard( Stats_Store::MAX_LB_CATEGORIES + 50, 1 );
		$time  = \array_sum( \array_column( $board['categories'], 'sum_time' ) );

		$capped = self::builder( 'cap_leaderboard', $board, Stats_Store::ITEM_BUDGET );

		$this->assertCount( Stats_Store::MAX_LB_CATEGORIES, $capped['categories'] );
		$this->assertArrayHasKey( self::wide( 'c0.', 500 ), $capped['categories'], 'the slowest stays' );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $capped['categories'] );
		$this->assertEqualsWithDelta( $time, \array_sum( \array_column( $capped['categories'], 'sum_time' ) ), 1e-6 );
	}

	// ----- lb_h: twelve buckets folded into one -----

	#[DataProvider( 'serializers' )]
	public function test_an_hour_leaderboard_folded_from_twelve_wide_buckets_fits_the_item_budget( string $serializer ): void {
		self::estimate_for( $serializer );
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hour       = '2026-09-22-10';
		$writes     = [];
		foreach ( Stats_Store::buckets_in_hour( $hour ) as $n => $bucket ) {
			$board    = self::builder( 'cap_leaderboard', self::wide_leaderboard( 150, 10, "b{$n}" ), Stats_Store::ITEM_BUDGET );
			$writes[] = [ Stats_Store::lb_parts( '' ), $bucket, $board ];
		}
		$store->bucket_set_multi( $writes );

		$folded = self::builder( 'fold_hour_leaderboard', $store, $hour );

		self::assert_fits( $folded, 'an hour of twelve disjoint wide buckets' );
		$this->assertLessThanOrEqual( Stats_Store::MAX_LB_CATEGORIES, \count( $folded['categories'] ) );
	}

	// ----- url: the flame tree and profiles -----

	#[DataProvider( 'serializers' )]
	public function test_a_url_blob_of_the_widest_tree_and_profiles_fits_the_item_budget( string $serializer ): void {
		self::estimate_for( $serializer );
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now      = (int) Core::$now;
		$children = [];
		for ( $h = 0; $h < 40; $h++ ) {
			$leaves = [];
			for ( $l = 0; $l < 200; $l++ ) {
				$leaves[] = [ 'name' => self::wide( "leaf{$h}.{$l}.", 300 ), 'sum_value' => (float) ( $h * 200 + $l ), 'ts' => $now, 'children' => [] ];
			}
			$children[] = [ 'name' => self::wide( "hook{$h}.", 300 ), 'sum_value' => self::WIDE_FLOAT, 'ts' => $now, 'children' => $leaves ];
		}
		$profiles = self::wide_leaderboard( 400, 40 );
		foreach ( $profiles['categories'] as &$category ) {
			$category['ts'] = $now;
		}
		unset( $category );
		$hash = 'a1b2c3d4e5f6';
		$store->accumulate_url_stats(
			$hash,
			[
				'flame'    => [ 'name' => 'aggregate', 'sum_value' => self::WIDE_FLOAT, 'count' => self::WIDE_COUNT, 'children' => $children ],
				'profiles' => $profiles,
			]
		);

		( new \ReflectionMethod( $fb, 'mirror_url_stats' ) )->invoke( $fb, $now );
		$blob = $store->bucket_get_multi( [ [ [ Stats_Store::NS_URL ], $hash ] ] )[0] ?? null;

		$this->assertIsArray( $blob, 'the blob was written, not refused' );
		self::assert_fits( $blob, 'a url blob of 8,000 wide leaves and 400 wide categories' );
		$heaviest = $blob['flame_raw']['children'][ \count( $blob['flame_raw']['children'] ) - 1 ]['children'] ?? [];
		$this->assertContains( self::wide( 'leaf39.199.', 300 ), \array_column( $heaviest, 'name' ), 'the heaviest leaf survives the prune' );
	}

	// ----- urlsrv / urlsrv_h -----

	public function test_a_server_index_of_the_longest_names_fits_the_item_budget(): void {
		// A server name is SERVER_NAME as the logger allowlists it: 256 bytes
		// and an ellipsis at most.
		$names = [];
		for ( $i = 0; $i < 3 * Stats_Store::MAX_SERVER_VALUES; $i++ ) {
			$names[] = self::wide( "srv{$i}.", 259 );
		}
		$index = [];
		foreach ( Stats_Store::admit_servers( [], $names ) as $filed ) {
			$index[ Stats_Store::server_key( $filed ) ] = $filed;
		}

		$this->assertCount( Stats_Store::MAX_SERVER_VALUES + 1, $index );
		self::assert_fits_both( $index, 'a server index of the longest names' );
	}

	// ----- urlrank_s / urlrank_sh -----

	public function test_a_ranked_hour_list_of_the_longest_paths_fits_the_item_budget(): void {
		$rows = [];
		for ( $i = 0; $i < 2 * Stats_Store::URL_RANK_N_HOUR; $i++ ) {
			$rows[ \sprintf( '%012x', $i ) ] = self::wide_row( self::wide( "/{$i}/", Stats_Store::MAX_PATH_BYTES ) );
		}

		$writes = Stats_Store::ranked_writes( [ self::SEED_SERVER => $rows ], true, '2026-09-22-10' );

		$this->assertNotSame( [], $writes );
		foreach ( $writes as [ $parts, , $entries ] ) {
			self::assert_fits_both( $entries, \implode( ':', $parts ) );
		}
	}

	// ----- urlmap, and the one path cap every stored path takes -----

	public function test_a_url_name_of_a_path_past_the_cap_fits_and_is_cut_to_it(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$url        = 'https://' . self::SEED_SERVER . self::wide( '/long/', 8192 );

		$store->set_url_names( [ self::SEED_SERVER => [ 'a1b2c3d4e5f6' => $url ] ] );
		$name = $store->bucket_get_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a1b2c3d4e5f6' ] ] )[0] ?? null;

		$this->assertIsArray( $name );
		self::assert_fits_both( $name, 'a url name' );
		$this->assertSame( Stats_Store::row_path( $url, self::SEED_SERVER ), $name[1], 'the name takes the row\'s path cap' );
		$this->assertLessThanOrEqual( Stats_Store::MAX_PATH_BYTES, \strlen( $name[1] ) );
	}

	// ----- urltoken -----

	public function test_a_full_token_set_fits_the_item_budget(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hashes     = [];
		for ( $i = 0; $i < Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$hashes[] = \sprintf( '%012x', $i );
		}

		$set = $store->merge_token_set( [], $hashes, self::LAST_SEEN );

		$this->assertCount( Stats_Store::URL_SEARCH_MAX, $set, 'the largest set short of the sentinel' );
		self::assert_fits_both( $set, 'a token set at URL_SEARCH_MAX' );
	}

	// ----- dim, url_dim, categories, url_cat: counted, names firehose-bound -----

	/** `$n` distinct names as long as one firehose entry allows. */
	private static function firehose_names( int $n, string $tag ): array {
		$names = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$names[] = self::wide( "{$tag}{$i}.", self::FIREHOSE_ENTRY_BYTES );
		}
		return $names;
	}

	/** Run one of the builder's intent merges over nothing stored. */
	private static function merged( string $intent, mixed ...$args ): array {
		$write = self::builder( $intent, ...$args );
		return ( $write['merge'] )( [] );
	}

	public function test_a_server_dimension_of_the_longest_values_fits_the_item_budget(): void {
		$values = [];
		foreach ( self::firehose_names( 3 * Stats_Store::MAX_SERVER_VALUES, 'v' ) as $n => $name ) {
			$values[ $name ] = [ self::WIDE_COUNT - $n, self::WIDE_FLOAT, self::WIDE_FLOAT ];
		}

		$dim = self::merged( 'dimension_intent', '2026-09-22-10-05', Stats_Store::DIM_SERVER, $values, '' );

		$this->assertCount( Stats_Store::MAX_SERVER_VALUES, $dim );
		self::assert_fits_both( $dim, 'dim:server, the widest axis' );
	}

	public function test_a_url_dimension_bucket_of_the_longest_values_fits_the_item_budget(): void {
		$dims = [];
		foreach ( [ 'status', 'method', 'country', 'from', 'ua', 'ja4' ] as $dim ) {
			foreach ( self::firehose_names( 5 * Stats_Store::MAX_URL_DIM_VALUES, $dim ) as $n => $name ) {
				$dims[ $dim ][ $name ] = [ self::WIDE_COUNT - $n, self::WIDE_FLOAT, self::WIDE_FLOAT ];
			}
		}

		$url_dim = self::merged( 'url_dimensions_intent', '2026-09-22-10-05', 'a1b2c3d4e5f6', $dims );

		foreach ( $url_dim as $values ) {
			$this->assertCount( Stats_Store::MAX_URL_DIM_VALUES, $values );
		}
		self::assert_fits_both( $url_dim, 'url_dim, every axis full' );
	}

	public function test_a_category_bucket_of_the_longest_names_fits_the_item_budget(): void {
		$cats = [];
		foreach ( self::firehose_names( 4 * Stats_Store::MAX_CAT_VALUES, 'c' ) as $n => $name ) {
			$cats[ $name ] = [ self::WIDE_FLOAT - $n, self::WIDE_COUNT, self::WIDE_COUNT ];
		}

		$global  = self::merged( 'categories_intent', '2026-09-22-10-05', $cats, '' );
		$per_url = self::merged( 'url_categories_intent', '2026-09-22-10-05', 'a1b2c3d4e5f6', $cats );

		$this->assertCount( Stats_Store::MAX_CAT_VALUES, $global );
		self::assert_fits_both( $global, 'categories' );
		self::assert_fits_both( $per_url, 'url_cat' );
	}
}
