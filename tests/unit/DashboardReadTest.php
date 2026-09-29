<?php
/**
 * DashboardReadTest: what a dashboard verb reads, and what it never does.
 *
 * It reads a five-minute bucket of the URL index only inside the current
 * hour: every older hour is its hour key or nothing. A leaderboard or a
 * chart series reads hour keys alone.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;

#[CoversClass( Performance_CI_Node::class )]
#[CoversClass( Stats_Store::class )]
class DashboardReadTest extends TestCase {
	/** The URL every seed and every detail verb names. */
	private const HASH = 'a4471ab0c0de';

	/** Flame-builder partitions, so every store of a reply is covered. */
	private const PARTITIONS = 2;

	/** Seconds into its hour the reply's tick falls: bucket :35 is open. */
	private const INTO_HOUR = 37 * 60 + 40;

	/** The five-minute namespaces an hour key stands in for. */
	private const TIERED = [
		Stats_Store::NS_URLS,
		Stats_Store::NS_URLSRV,
		Stats_Store::NS_URLRANK_S,
		Stats_Store::NS_URLHDR,
	];

	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = '/tmp/dashboard-read-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		Core::$memd = new \Newspack_Nodes\Tests\Helpers\InMemoryMemcached();
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => self::PARTITIONS, 'min_lifetime' => 86400 ] );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
		$this->activate_shipped( 'performance', self::PARTITIONS );
		$at          = self::tick() - self::tick() % 3600 + self::INTO_HOUR;
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
	}

	protected function tearDown(): void {
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		VerbHarness::reset();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Every verb a dashboard or an agent polls, with the arguments that reach
	 * the most of the store.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function verbs(): array {
		return [
			'overview'      => [ 'overview', '--categories --breakdown=status' ],
			'urls'          => [ 'urls', '' ],
			'urls --search' => [ 'urls', '--search=wombat' ],
			'dump_url'      => [ 'dump_url', self::HASH . ' --categories' ],
			'ask overview'  => [ 'ask', 'overview:site' ],
			'ask url'       => [ 'ask', 'url:' . self::HASH ],
		];
	}

	/** A reply counts what the store holds. */
	public function test_a_reply_is_what_the_stats_tables_hold(): void {
		$buckets = Stats_Store::retention_buckets( 86400, self::tick() );
		$this->set_hour_slot( $this->stats_store( 0, 86400 ), Stats_Store::hourly_parts(), $buckets[3], [ 'count' => 3, 'sum_ms' => 30.0, 'sum_peak_mb' => 6.0 ] );

		$reply = self::fire( 'overview', '' );

		$this->assertSame( 3, $reply['total_requests'] );
	}

	/**
	 * Over a day's window, a reply asks the URL index's five-minute tier for
	 * the current hour's buckets alone, and one key an hour for every hour
	 * before it.
	 */
	#[DataProvider( 'verbs' )]
	public function test_a_day_reads_the_current_hours_buckets_and_one_key_an_older_hour( string $verb, string $args ): void {
		$this->seed_site();

		$this->assertIsArray( self::fire( $verb, $args ), "{$verb} answers" );

		$current = \gmdate( 'Y-m-d-H', self::tick() );
		$fine    = $this->asked_fine();
		$this->assertLessThanOrEqual( 12, \count( $fine ), "{$verb}: twelve buckets at most" );
		foreach ( $fine as $bucket ) {
			$this->assertStringStartsWith( $current, $bucket, "{$verb}: {$bucket} is outside the current hour" );
		}
		$hours = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) )['hours'];
		$this->assertLessThanOrEqual( \count( $hours ), \count( $this->asked_hours() ), "{$verb}: one key an hour" );
		$this->assertNotContains( $current, $hours, 'the current hour is no hour key' );
	}

	/**
	 * Every verb that draws a chart, with the arguments that reach each chart
	 * namespace, the server-scoped leaderboard included.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function chart_verbs(): array {
		return [
			'overview'          => [ 'overview', '--categories --breakdown=status,server' ],
			'overview --server' => [ 'overview', '--server=kea.test --categories --breakdown=ua' ],
			'dump_url'          => [ 'dump_url', self::HASH . ' --categories --breakdown=status' ],
			'url_breakdown'     => [ 'url_breakdown', self::HASH . ' --breakdown=method' ],
			'ask overview'      => [ 'ask', 'overview:site' ],
		];
	}

	/**
	 * A chart series, or the leaderboard, asks one key an hour and no
	 * five-minute bucket, per namespace and scope: the current hour and the
	 * `CHART_HOURS - 1` before it, whatever the retention window.
	 */
	#[DataProvider( 'chart_verbs' )]
	public function test_a_days_chart_reads_one_key_an_hour_and_no_bucket( string $verb, string $args ): void {
		$this->seed_site();

		$this->assertIsArray( self::fire( $verb, $args ), "{$verb} answers" );

		$current = \gmdate( 'Y-m-d-H', self::tick() );
		$scopes  = $this->asked_chart_scopes();
		$this->assertNotSame( [], $scopes, "{$verb} reads a chart" );
		foreach ( $scopes as $scope => [ $fine, $hour_keys ] ) {
			$this->assertSame( [], $fine, "{$verb}: {$scope} reads no five-minute bucket" );
			$this->assertCount( Stats_Store::CHART_HOURS, $hour_keys, "{$verb}: {$scope} reads one key an hour" );
			$this->assertSame( $current, \max( $hour_keys ), "{$verb}: {$scope} reads the current hour's key" );
		}
	}

	/**
	 * The hour just closed with its hour keys evicted and its five-minute
	 * buckets still cached: the reply asks none of those buckets and counts
	 * none of their traffic.
	 */
	public function test_an_evicted_hour_key_costs_no_fine_read_for_its_hour(): void {
		$this->seed_site();
		$previous = \gmdate( 'Y-m-d-H', self::tick() - 3600 );
		$store    = $this->stats_store( 0, 86400 );
		foreach ( Stats_Store::buckets_in_hour( $previous ) as $bucket ) {
			$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( self::HASH ), [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 911 ] ] );
		}
		$this->forget_stats_asks();

		self::fire( 'overview', '' );
		$page = self::fire( 'urls', '--include_workers' );

		$this->assertSame( [], \array_filter( $this->asked_fine(), static fn ( string $b ): bool => \str_starts_with( $b, $previous ) ), 'no bucket of the evicted hour' );
		$this->assertSame( [ 1 ], \array_column( \array_filter( $page['data'], static fn ( array $row ): bool => self::HASH === $row['hash'] ), 'count' ), 'nor does the fold' );
	}

	/** The fold folds the current hour's rows and hour keys, never a row older than the hour. */
	public function test_the_fold_never_folds_fine_rows_older_than_the_last_hour(): void {
		$shard  = Stats_Store::url_shard( self::HASH );
		$store  = $this->stats_store( 0, 86400 );
		$now    = self::tick();
		$recent = Stats_Store::bucket_key( $now - 5 * 60 );
		$old    = Stats_Store::bucket_key( $now - self::INTO_HOUR - 10 * 60 );
		$this->seed_url_shard( $store, $recent, $shard, [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 4 ] ] );
		$this->seed_url_shard( $store, $old, $shard, [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 700 ] ] );

		$rows = Performance_CI_Node::load_index_default( $shard, '', [ $store ], $now );

		$this->assertSame( [ 4 ], \array_column( $rows, 'count' ) );
	}

	/**
	 * The recent rate divides the current hour's closed buckets by the time
	 * they span, and counts nothing of the hour before.
	 */
	public function test_the_rate_is_the_current_hours_closed_buckets(): void {
		$shard    = Stats_Store::url_shard( self::HASH );
		$store    = $this->stats_store( 0, 86400 );
		$current  = \gmdate( 'Y-m-d-H', self::tick() );
		$previous = \gmdate( 'Y-m-d-H', self::tick() - 3600 );
		$this->seed_url_shard( $store, "{$current}-10", $shard, [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 630 ] ] );
		$this->seed_url_shard( $store, "{$previous}-45", $shard, [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 900 ] ] );

		$totals = self::fire( 'urls', '--include_workers' )['totals'];

		$this->assertEqualsWithDelta( 630 / ( 7 * Stats_Store::BUCKET_SECONDS ), $totals['requests_per_second'], 1e-9 );
	}

	/** Until the current hour's first bucket closes, the rate is the hour before's. */
	public function test_before_a_bucket_closes_the_rate_is_the_last_hours(): void {
		$at          = self::tick() - self::tick() % 3600 + 2 * 60;
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
		$shard    = Stats_Store::url_shard( self::HASH );
		$store    = $this->stats_store( 0, 86400 );
		$previous = \gmdate( 'Y-m-d-H', $at - 3600 );
		$this->seed_url_hour( $store, $previous, $shard, [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 7200 ] ] );
		$this->seed_url_shard( $store, "{$previous}-45", $shard, [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 900 ] ] );

		$totals = self::fire( 'urls', '--include_workers' )['totals'];

		$this->assertEqualsWithDelta( 2.0, $totals['requests_per_second'], 1e-9 );
	}

	/**
	 * In the current hour's first bucket, the hour just closed may be owed
	 * its fold: a reply short of it says so, and is not cached, ranked or
	 * folded alike.
	 */
	public function test_a_reply_short_of_the_hour_just_closed_is_provisional(): void {
		$at          = self::tick() - self::tick() % 3600 + 2 * 60;
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
		$this->seed_site( [ \gmdate( 'Y-m-d-H', $at - 3600 ) ] );

		$ranked = self::fire( 'urls', '' );
		$folded = self::fire( 'urls', '--include_workers' );

		$this->assertTrue( $ranked['ranked'], 'the lists answer, lagging the fold' );
		$this->assertTrue( $ranked['provisional'] );
		$this->assertFalse( $folded['ranked'] );
		$this->assertTrue( $folded['provisional'] );
	}

	/**
	 * Decision 3: a fold missing an older hour's keys is a short answer,
	 * cached like any other.
	 */
	public function test_a_fold_missing_an_older_hour_fails_soft(): void {
		$this->seed_site( [ \gmdate( 'Y-m-d-H', self::tick() - 5 * 3600 ) ] );

		$folded = self::fire( 'urls', '--include_workers' );

		$this->assertFalse( $folded['provisional'] );
		$this->assertNotSame( [], $this->cached_pages(), 'the page is cached' );
	}

	/** Decision 3: a fold missing a shard of an older hour's index is short, not provisional. */
	public function test_a_fold_missing_a_shard_of_an_older_hour_fails_soft(): void {
		$this->seed_site();
		$hour = \gmdate( 'Y-m-d-H', self::tick() - 5 * 3600 );
		( $this->stats_store( 1, 86400 ) )->bucket_forget_multi( [ [ Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), Stats_Store::url_shard( 'b8823bc1d2ef' ) ), $hour ] ] );

		$folded = self::fire( 'urls', '--include_workers' );

		$this->assertFalse( $folded['provisional'] );
		$this->assertNotSame( [], $this->cached_pages(), 'the page is cached' );
	}

	/**
	 * Decision 3: an hour-shard read a Table left unanswered fails soft. The
	 * fold is short, not provisional, and its page is cached like any other,
	 * so a sick Table is not asked again on every poll.
	 */
	public function test_a_fold_whose_shard_read_went_unanswered_fails_soft(): void {
		$this->seed_site();
		$shard = Stats_Store::NS_URLS_HOUR . ':' . Stats_Store::server_key( self::SEED_SERVER ) . ':' . Stats_Store::url_shard( 'b8823bc1d2ef' ) . ':';
		$this->refuse_stats_reads( ":{$shard}" );

		$folded = self::fire( 'urls', '--include_workers' );

		$this->assertNotSame( [], \array_filter( $this->asked_keys( Stats_Store::NS_URLS_HOUR ), static fn ( string $key ): bool => \str_starts_with( $key, $shard ) ), 'the refused shard was asked' );
		$this->assertFalse( $folded['provisional'] );
		$this->assertNotSame( [], $this->cached_pages(), 'the page is cached' );
	}

	/** @return list<string> The URL page cache's keys memcache holds. */
	private function cached_pages(): array {
		return \array_values( \array_filter( Core::$memd->keys(), static fn ( string $key ): bool => \str_contains( $key, 'eln-urls-page' ) ) );
	}

	/**
	 * One reply, as its own request makes it.
	 *
	 * @return mixed What the verb answered.
	 */
	private static function fire( string $verb, string $args ): mixed {
		return VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $args );
	}

	/**
	 * The site's URL index and rank lists in every hour and every live bucket
	 * of the window, on every partition, so a reader has every key it plans
	 * to read: all but the hours named unfolded.
	 *
	 * @param list<string> $unfolded Hours the writer has yet to fold.
	 */
	private function seed_site( array $unfolded = [] ): void {
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) );
		$row  = [ 'b8823bc1d2ef' => [ 'url' => 'https://example.com/numbat', 'count' => 2 ] ];
		$live = \array_filter(
			Stats_Store::retention_buckets( 7200, self::tick() ),
			static fn ( string $b ): bool => $b <= Stats_Store::bucket_key( self::tick() )
		);
		foreach ( \range( 0, self::PARTITIONS - 1 ) as $p ) {
			$store = $this->stats_store( $p, 86400 );
			foreach ( \array_diff( $plan['hours'], $unfolded ) as $hour ) {
				$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'b8823bc1d2ef' ), $row );
				$this->set_url_rank_lists( $store, $hour, $row, true );
			}
			foreach ( $live as $bucket ) {
				$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( 'b8823bc1d2ef' ), $row );
				$this->set_url_rank_lists( $store, $bucket, $row );
			}
		}
		$this->seed_url_shard( $this->stats_store( 0, 86400 ), Stats_Store::bucket_key( self::tick() ), Stats_Store::url_shard( self::HASH ), [ self::HASH => [ 'url' => 'https://example.com/wombat', 'count' => 1 ] ] );
		$this->forget_stats_asks();
	}

	/**
	 * The five-minute buckets the reply asked the tiered namespaces for.
	 *
	 * @return list<string>
	 */
	private function asked_fine(): array {
		return $this->asked_segments( '/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}$/D' );
	}

	/**
	 * The hours the reply asked the hour tier for.
	 *
	 * @return list<string>
	 */
	private function asked_hours(): array {
		return $this->asked_segments( '/^\d{4}-\d{2}-\d{2}-\d{2}$/D' );
	}

	/**
	 * The chart and leaderboard keys the reply asked for, by namespace and
	 * scope: `ns[:scope]` => [ five-minute buckets, hours ].
	 *
	 * @return array<string,array{0: list<string>, 1: list<string>}>
	 */
	private function asked_chart_scopes(): array {
		$charts = 'hourly|dim|categories|lb|lb_s|url_dim|url_cat';
		$out    = [];
		foreach ( $this->asked_keys() as $key ) {
			if ( 1 !== \preg_match( '/^(' . $charts . ')(?:_h|h)?:(?:(.*):)?(\d{4}-\d{2}-\d{2}-\d{2}(?:-\d{2})?)$/', $key, $m ) ) {
				continue;
			}
			$scope = "{$m[1]}:{$m[2]}";
			$out[ $scope ] ??= [ [], [] ];
			$out[ $scope ][ 16 === \strlen( $m[3] ) ? 0 : 1 ][ $m[3] ] = $m[3];
		}
		return \array_map( static fn ( array $pair ): array => [ \array_values( $pair[0] ), \array_values( $pair[1] ) ], $out );
	}

	/**
	 * The distinct last segments of the asked keys of a tiered namespace, or
	 * of its hour twin, matching `$shape`.
	 *
	 * @return list<string>
	 */
	private function asked_segments( string $shape ): array {
		$tiers = \implode( '|', [ ...self::TIERED, Stats_Store::NS_URLS_HOUR, Stats_Store::NS_URLSRV_HOUR, Stats_Store::NS_URLRANK_HOUR_S, Stats_Store::NS_URLHDR_HOUR ] );
		$out   = [];
		foreach ( $this->asked_keys() as $key ) {
			if ( 1 === \preg_match( '/^(' . $tiers . '):(?:.*:)?([0-9-]+)$/', $key, $m ) && 1 === \preg_match( $shape, $m[2] ) ) {
				$out[ $m[2] ] = true;
			}
		}
		return \array_keys( $out );
	}
}
