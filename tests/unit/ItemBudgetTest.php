<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;

/**
 * One size test per stored blob: the LARGEST value each producer's cap
 * admits stays inside `Stats_Store::ITEM_BUDGET`.
 *
 * A cap that estimates bytes estimates them for the serializer memcached is
 * configured with, so each test runs once per serializer and is measured by
 * that same serializer.
 *
 * "Largest" means every dimension the cap leaves open at once: the most
 * entries, the longest names, and numbers at their longest spelling —
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

	/** Bytes `$value` takes under the serializer the estimate was made for. */
	private static function stored_bytes( mixed $value ): int {
		return Stats_Store::SERIALIZER_IGBINARY === \Newspack_Nodes\Durable_Arm::serializer()
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

	/** A distinct string of exactly `$bytes` bytes. */
	private static function wide( string $prefix, int $bytes ): string {
		return \str_pad( $prefix, $bytes, 'x' );
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

	// ----- a URL's profile: its categories capped -----

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

	// ----- url: the flame tree and profiles -----

	#[DataProvider( 'serializers' )]
	public function test_a_url_blob_of_the_widest_tree_and_profiles_fits_the_item_budget( string $serializer ): void {
		self::estimate_for( $serializer );
		$fb    = new Flame_Builder_Node();
		$store = $this->stats_store( 0, $fb );
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
		( new \ReflectionProperty( $fb, 'url_acc' ) )->setValue(
			$fb,
			[
				$hash => [
					'flame'    => [ 'name' => 'aggregate', 'sum_value' => self::WIDE_FLOAT, 'count' => self::WIDE_COUNT, 'children' => $children ],
					'profiles' => $profiles,
				],
			]
		);

		( new \ReflectionMethod( $fb, 'drain_url_stats' ) )->invoke( $fb, $store, $now );
		$blob = $store->url_aggregate( $hash );

		$this->assertIsArray( $blob, 'the blob was written, not refused' );
		self::assert_fits( $blob, 'a url blob of 8,000 wide leaves and 400 wide categories' );
		$heaviest = $blob['flame_raw']['children'][ \count( $blob['flame_raw']['children'] ) - 1 ]['children'] ?? [];
		$this->assertContains( self::wide( 'leaf39.199.', 300 ), \array_column( $heaviest, 'name' ), 'the heaviest leaf survives the prune' );
	}
}
