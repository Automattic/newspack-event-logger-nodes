<?php
/**
 * FlameBuilderSweepTest: the flame builder heals memcache from its mirror.
 *
 * Memcache evicts, and a dashboard reads memcache alone, so the builder walks
 * its own mirror partition a chunk a tick, newest frame first, and adds back
 * each key memcache no longer holds. A key it restores into a bucket, and
 * each hour its frames name, send the bucket's lists and the hour's keys
 * back through the builder's own ranking and fold.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

#[CoversClass( Flame_Builder_Node::class )]
#[CoversClass( Stats_Store::class )]
class FlameBuilderSweepTest extends TestCase {

	/** Where every test's clock starts: twenty minutes into an hour. */
	private const INTO_HOUR = 20 * 60;

	/** @var list<string> Partition dirs to remove. */
	private array $dirs = [];

	private int $hour_start;

	protected function setUp(): void {
		parent::setUp();
		Core::$memd                                                  = new InMemoryMemcached();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [ [ 'id' => 'r', 'pattern' => '/', 'action' => 'log' ] ];
		// The wall's hour: a pass dates its segments by their index's mtime.
		$wall             = \time();
		$this->hour_start = $wall - $wall % 3600;
		Core::$now        = $this->hour_start + self::INTO_HOUR;
	}

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			$this->rmdir_recursive( $dir );
		}
		parent::tearDown();
	}

	/** A key memcache lost comes back from its frame, for what is left of its window. */
	public function test_the_sweep_adds_an_evicted_key_back_from_its_frame(): void {
		[ $fb, $p ] = $this->builder();
		$bucket     = Stats_Store::bucket_key( (int) Core::$now - 3 * 3600 );
		$this->frame( $p, Stats_Store::NS_HOURLY . ':' . $bucket, [ 'count' => 4127, 'sum_ms' => 3.0, 'sum_peak_mb' => 2.0 ] );

		$fb->fire_cb();

		$store = new Stats_Store( 0, 86400 );
		$this->assertSame( 4127, $this->get_hourly_bucket( $store, $bucket )['count'] ?? null );
		$expires = Core::$memd->expiries()[ self::cache_key( 0, Stats_Store::NS_HOURLY . ':' . $bucket ) ] ?? 0;
		$this->assertEqualsWithDelta( (int) Stats_Store::bucket_end( $bucket ) + 86400 - (int) Core::$now, $expires - \time(), 2, 'held for what is left of its window' );
	}

	/** A value memcache holds stands, whatever the mirror's older copy says. */
	public function test_the_sweep_never_displaces_a_value_memcache_holds(): void {
		[ $fb, $p ] = $this->builder();
		$bucket     = Stats_Store::bucket_key( (int) Core::$now - 3 * 3600 );
		$store      = new Stats_Store( 0, 86400 );
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 9, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] );
		$this->frame( $p, Stats_Store::NS_HOURLY . ':' . $bucket, [ 'count' => 4127, 'sum_ms' => 3.0, 'sum_peak_mb' => 2.0 ] );

		$fb->fire_cb();

		$this->assertSame( 9, $this->get_hourly_bucket( $store, $bucket )['count'] ?? null );
	}

	/** A key written twice comes back as its newest frame. */
	public function test_the_newest_frame_of_a_key_is_the_one_restored(): void {
		[ $fb, $p ] = $this->builder();
		$bucket     = Stats_Store::bucket_key( (int) Core::$now - 3 * 3600 );
		$this->frame( $p, Stats_Store::NS_HOURLY . ':' . $bucket, [ 'count' => 11, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] );
		$this->frame( $p, Stats_Store::NS_HOURLY . ':' . $bucket, [ 'count' => 4127, 'sum_ms' => 3.0, 'sum_peak_mb' => 2.0 ] );

		$fb->fire_cb();

		$this->assertSame( 4127, $this->get_hourly_bucket( new Stats_Store( 0, 86400 ), $bucket )['count'] ?? null );
	}

	/**
	 * An hour key memcache lost is folded again from its buckets once the
	 * sweep pass ends, when the roll-up reads every hour afresh.
	 */
	public function test_the_sweep_re_derives_an_evicted_hour_key(): void {
		[ $fb ] = $this->builder();
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start - 3600 );
		$shard  = Stats_Store::url_shard( \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/post/123' ) );
		Core::$now = $this->hour_start - 3600 + 100;
		$this->fill_request( $fb, [ 'timestamp' => (int) Core::$now ] );
		$fb->fire_cb();
		// The hour closes: the tick folds it and mirrors its buckets.
		Core::$now = $this->hour_start + 30;
		$fb->fire_cb();
		$this->assertNotSame( [], $this->get_url_hour( $store, $hour, $shard ), 'folded at its close' );
		$store->bucket_forget( Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), $shard ), $hour );

		foreach ( [ 1, 2 ] as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertNotSame( [], $this->get_url_hour( $store, $hour, $shard ), 'folded again' );
	}

	/**
	 * Every flush writes its chart deltas through to the hour keys as well as
	 * the buckets — the site's totals and dimensions, a server's on a hub,
	 * and each URL's — so an hour's keys are whole the moment it closes.
	 */
	public function test_a_flush_writes_its_chart_deltas_through_to_the_hour_keys(): void {
		[ $fb ] = $this->builder();
		$fb->set_is_hub( true );
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start );
		$hash   = \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/post/123' );
		$keys   = static fn (): array => [
			$store->get_hourly_buckets( [ $hour ] )[ $hour ]['count'] ?? null,
			$store->get_dimensional_buckets( 'status', [ $hour ] )[ $hour ]['5xx'][ Stats_Store::DIM_COUNT ] ?? null,
			$store->get_dimensional_buckets( 'ua', [ $hour ], self::SEED_SERVER )[ $hour ]['kakapo/2'][ Stats_Store::DIM_COUNT ] ?? null,
			$store->get_url_dimension_buckets( $hash, 'ua', [ $hour ] )[ $hour ]['kakapo/2'][ Stats_Store::DIM_COUNT ] ?? null,
		];
		foreach ( [ 100, 1300 ] as $at ) {
			Core::$now = $this->hour_start + $at;
			$this->fill_request( $fb, [ 'timestamp' => (int) Core::$now, 'status_code' => 503, 'user_agent' => 'kakapo/2', 'duration_ms' => 3719.0 ] );
			$fb->fire_cb();
		}
		$open = $keys();

		Core::$now = $this->hour_start + 3600 + 30;
		$fb->fire_cb();

		$this->assertSame( [ 2, 2, 2, 2 ], $open, 'the totals, a site dimension, a server\'s and a URL\'s, while the hour is open' );
		$this->assertSame( [ 2, 2, 2, 2 ], $keys(), 'and whole at its close' );
	}

	/**
	 * Deployed over a store whose older hours hold their URL index and lists
	 * but no chart hour key, flush after flush and pass after pass: a missing
	 * chart key never refolds an hour, so the index and lists stand as seeded.
	 */
	public function test_a_missing_chart_hour_key_never_refolds_an_hours_url_index(): void {
		[ $fb ] = $this->builder();
		$store  = new Stats_Store( 0, 86400 );
		$row    = [ 'b8823bc1d2ef' => [ 'url' => 'https://' . self::SEED_SERVER . '/numbat-4471', 'count' => 5519 ] ];
		$reads  = [];
		foreach ( [ 5, 6 ] as $back ) {
			$hour = \gmdate( 'Y-m-d-H', $this->hour_start - $back * 3600 );
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'b8823bc1d2ef' ), $row );
			$this->set_url_rank_lists( $store, $hour, $row, true );
			$reads[] = [ Stats_Store::url_srv_parts( true ), $hour ];
			$reads[] = [ Stats_Store::url_rank_parts( 'count', 'desc', '', true ), $hour ];
			$reads[] = [ Stats_Store::url_rank_parts( 'count', 'desc', self::SEED_SERVER, true ), $hour ];
		}
		$seeded = $store->bucket_get_multi( $reads );

		foreach ( \range( 1, 6 ) as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertNotContains( null, $seeded );
		$this->assertSame( $seeded, $store->bucket_get_multi( $reads ) );
	}

	/**
	 * An older hour memcache lost one shard of reads as unfolded, but its
	 * fine tier has expired: the fold writes nothing, and the shards and
	 * index it still holds stand.
	 */
	public function test_an_hour_whose_fine_tier_expired_is_never_folded_empty(): void {
		[ $fb ] = $this->builder();
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start - 5 * 3600 );
		$kept   = [ 'b8823bc1d2ef' => [ 'url' => 'https://' . self::SEED_SERVER . '/numbat-4471', 'count' => 5519 ] ];
		$lost   = [ 'c9934cd2e3f0' => [ 'url' => 'https://' . self::SEED_SERVER . '/quokka-8842', 'count' => 3319 ] ];
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'b8823bc1d2ef' ), $kept );
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'c9934cd2e3f0' ), $lost );
		$store->bucket_forget( Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), Stats_Store::url_shard( 'c9934cd2e3f0' ) ), $hour );
		$reads  = [
			[ Stats_Store::url_srv_parts( true ), $hour ],
			[ Stats_Store::url_hour_parts( Stats_Store::server_key( self::SEED_SERVER ), Stats_Store::url_shard( 'b8823bc1d2ef' ) ), $hour ],
		];
		$seeded = $store->bucket_get_multi( $reads );

		foreach ( \range( 1, 3 ) as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertNotContains( null, $seeded );
		$this->assertSame( $seeded, $store->bucket_get_multi( $reads ) );
	}

	/**
	 * A backlog replayed into the hour before, at twenty past, whose site key
	 * is missing: the flush leaves the key missing, and the flush after it
	 * refolds that hour whole, without waiting for the sweep pass to end.
	 */
	public function test_a_replay_into_an_hour_missing_its_site_key_shows_after_one_more_flush(): void {
		[ $fb ]    = $this->builder();
		$store     = new Stats_Store( 0, 86400 );
		$hour      = \gmdate( 'Y-m-d-H', $this->hour_start - 3600 );
		$count     = static fn (): ?int => $store->get_hourly_buckets( [ $hour ] )[ $hour ]['count'] ?? null;
		$fb->fire_cb();
		$store->bucket_forget( Stats_Store::hour_parts( Stats_Store::hourly_parts() ), $hour );
		// Mid-pass: nothing but the replay itself owes a refold.
		( new \ReflectionProperty( $fb, 'refold_due' ) )->setValue( $fb, false );

		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		foreach ( [ 700, 1900, 3100 ] as $at ) {
			$this->fill_request( $fb, [ 'timestamp' => $this->hour_start - 3600 + $at ] );
		}
		$fb->fire_cb();
		$replayed = $count();
		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->fire_cb();

		$this->assertNull( $replayed, 'not recreated holding the replay alone' );
		$this->assertSame( 3, $count(), 'refolded by the next flush' );
	}

	/** A server past the index cap files its rows under `Other` and its charts' hour keys under its own name. */
	public function test_a_server_past_the_index_cap_gets_its_chart_hour_keys(): void {
		[ $fb ] = $this->builder();
		$fb->set_is_hub( true );
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start );
		$index  = self::index_of( \array_map( static fn ( int $i ): string => "spray{$i}.test", \range( 0, Stats_Store::MAX_SERVER_VALUES - 1 ) ) );
		$store->bucket_set_multi( [ [ Stats_Store::url_srv_parts( false ), Stats_Store::bucket_key( (int) Core::$now ), $index ] ] );

		$this->fill_request( $fb, [ 'url' => 'https://late-4471.test/kokako', 'server_name' => 'late-4471.test', 'timestamp' => (int) Core::$now, 'user_agent' => 'kea/7' ] );
		$fb->fire_cb();
		Core::$now = $this->hour_start + 3600 + 30;
		$fb->fire_cb();

		$this->assertSame( 1, $store->get_dimensional_buckets( 'ua', [ $hour ], 'late-4471.test' )[ $hour ]['kea/7'][ Stats_Store::DIM_COUNT ] ?? null );
	}

	/** A URL first seen in a late record of a closed hour gets that hour's URL chart keys. */
	public function test_a_late_first_seen_url_gets_its_url_hour_keys(): void {
		[ $fb ] = $this->builder();
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start - 3600 );
		$late   = \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/takahe-7731' );
		Core::$now = $this->hour_start - 3600 + 100;
		$this->fill_request( $fb, [ 'timestamp' => (int) Core::$now ] );
		$fb->fire_cb();
		Core::$now = $this->hour_start + 30;
		$fb->fire_cb();

		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->fill_request( $fb, [ 'url' => '/takahe-7731', 'timestamp' => $this->hour_start - 3600 + 2900, 'user_agent' => 'weka/3' ] );
		$fb->fire_cb();

		$this->assertSame( 1, $store->get_url_dimension_buckets( $late, 'ua', [ $hour ] )[ $hour ]['weka/3'][ Stats_Store::DIM_COUNT ] ?? null );
	}

	/** A late record into a folded hour reaches that hour's chart keys as well as its bucket. */
	public function test_a_late_record_reaches_a_folded_hours_chart_keys(): void {
		[ $fb ] = $this->builder();
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start - 3600 );
		Core::$now = $this->hour_start - 3600 + 100;
		$this->fill_request( $fb, [ 'timestamp' => (int) Core::$now, 'country_code' => 'NZ' ] );
		$fb->fire_cb();
		Core::$now = $this->hour_start + 30;
		$fb->fire_cb();

		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->fill_request( $fb, [ 'timestamp' => $this->hour_start - 3600 + 2900, 'country_code' => 'NZ' ] );
		$fb->fire_cb();

		$this->assertSame( 2, $store->get_dimensional_buckets( 'country', [ $hour ] )[ $hour ]['NZ'][ Stats_Store::DIM_COUNT ] ?? null );
		$this->assertSame( 2, $store->get_hourly_buckets( [ $hour ] )[ $hour ]['count'] ?? null );
	}

	/**
	 * A site chart hour key memcache lost is folded again from its buckets
	 * once the sweep pass ends, and that refold writes no URL key.
	 */
	public function test_an_evicted_site_chart_hour_key_is_refolded_after_a_pass(): void {
		$memd       = self::recording_memd();
		Core::$memd = $memd;
		[ $fb ]     = $this->builder();
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start - 3600 );
		foreach ( [ 100, 1900 ] as $at ) {
			Core::$now = $this->hour_start - 3600 + $at;
			$this->fill_request( $fb, [ 'timestamp' => (int) Core::$now, 'request_method' => 'PATCH' ] );
			$fb->fire_cb();
		}
		Core::$now = $this->hour_start + 30;
		$fb->fire_cb();
		$count = static fn (): ?int => $store->get_hourly_buckets( [ $hour ] )[ $hour ]['count'] ?? null;
		$this->assertSame( 2, $count(), 'whole at its close' );
		$store->bucket_forget( Stats_Store::hour_parts( Stats_Store::hourly_parts() ), $hour );
		$memd->written = [];

		foreach ( [ 1, 2 ] as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertSame( 2, $count(), 'folded again' );
		$url_hour = [ Stats_Store::NS_URLS_HOUR, Stats_Store::NS_URLSRV_HOUR, Stats_Store::NS_URLRANK_HOUR_S, Stats_Store::NS_URLHDR_HOUR ];
		$touched  = \array_filter(
			$memd->written,
			static fn ( string $key ): bool => \str_ends_with( $key, ":{$hour}" )
				&& [] !== \array_filter( $url_hour, static fn ( string $ns ): bool => \str_starts_with( $key, self::cache_key( 0, $ns . ':' ) ) )
		);
		$this->assertSame( [], \array_values( $touched ), 'no URL key of the hour written' );
	}

	/**
	 * A memcached double recording the key of every set it takes, oldest
	 * first; a test clears `written` to start counting.
	 *
	 * @return InMemoryMemcached&object{written: list<string>}
	 */
	private static function recording_memd(): InMemoryMemcached {
		return new class() extends InMemoryMemcached {
			/** @var list<string> */
			public array $written = [];

			public function set( string $key, mixed $value, int $expiration = 0 ): bool {
				$this->written[] = $key;
				return parent::set( $key, $value, $expiration );
			}
		};
	}

	/**
	 * A late record into an hour whose site key memcache lost leaves that key
	 * missing rather than recreating it short, so the refold makes it whole.
	 */
	public function test_a_late_record_waits_for_the_refold_of_a_lost_site_hour_key(): void {
		[ $fb ] = $this->builder();
		$store  = new Stats_Store( 0, 86400 );
		$hour   = \gmdate( 'Y-m-d-H', $this->hour_start - 3600 );
		Core::$now = $this->hour_start - 3600 + 100;
		$this->fill_request( $fb, [ 'timestamp' => (int) Core::$now ] );
		$fb->fire_cb();
		Core::$now = $this->hour_start + 30;
		$fb->fire_cb();
		$store->bucket_forget( Stats_Store::hour_parts( Stats_Store::hourly_parts() ), $hour );
		$count = static fn (): ?int => $store->get_hourly_buckets( [ $hour ] )[ $hour ]['count'] ?? null;
		// The late flush alone: the pass has not ended since the key was lost.
		( new \ReflectionProperty( $fb, 'refold_due' ) )->setValue( $fb, false );

		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$this->fill_request( $fb, [ 'timestamp' => $this->hour_start - 3600 + 2900 ] );
		$fb->fire_cb();
		$late = $count();
		foreach ( [ 1, 2 ] as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertNull( $late, 'not recreated holding the late record alone' );
		$this->assertSame( 2, $count(), 'whole after the refold' );
	}

	/**
	 * A closed bucket of the current hour whose rows and lists memcache lost:
	 * the sweep restores the rows, and the next flush ranks them again.
	 */
	public function test_the_sweep_re_ranks_a_bucket_whose_rows_it_restores(): void {
		[ $fb ]    = $this->builder();
		$store     = new Stats_Store( 0, 86400 );
		$bucket    = Stats_Store::bucket_key( $this->hour_start + 5 * 60 );
		$shard     = Stats_Store::url_shard( \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/post/123' ) );
		$list      = Stats_Store::url_rank_parts( 'count', 'desc', '', false );
		Core::$now = $this->hour_start + 5 * 60 + 10;
		$this->fill_request( $fb, [ 'timestamp' => (int) Core::$now ] );
		$fb->fire_cb();
		// The bucket closes and is mirrored, rows and index.
		Core::$now = $this->hour_start + 10 * 60 + 30;
		$fb->fire_cb();
		$this->assertNotNull( $store->bucket_get_multi( [ [ $list, $bucket ] ] )[0], 'ranked' );
		$store->bucket_forget( Stats_Store::url_shard_parts( Stats_Store::server_key( self::SEED_SERVER ), $shard ), $bucket );
		$store->bucket_forget( $list, $bucket );

		foreach ( [ 1, 2 ] as $tick ) {
			Core::$now += Stats_Store::BUCKET_SECONDS + 1;
			$fb->fire_cb();
		}

		$this->assertNotSame( [], $this->get_url_shard( $store, $bucket, $shard ), 'the rows come back' );
		$this->assertNotNull( $store->bucket_get_multi( [ [ $list, $bucket ] ] )[0], 'and their list' );
	}

	/**
	 * A late record re-holds a closed bucket's frame: the sweep leaves that
	 * key to the builder, whose next merge reads the held value, and never
	 * adds back the older frame on disk.
	 */
	public function test_the_sweep_leaves_a_key_the_builder_holds(): void {
		[ $fb ]    = $this->builder();
		$store     = new Stats_Store( 0, 86400 );
		$bucket    = Stats_Store::bucket_key( $this->hour_start + 5 * 60 );
		$key       = Stats_Store::NS_HOURLY . ':' . $bucket;
		$late      = fn () => $this->fill_request( $fb, [ 'timestamp' => $this->hour_start + 5 * 60 + 10 ] );
		Core::$now = $this->hour_start + 5 * 60 + 10;
		$late();
		$fb->fire_cb();
		// The bucket closes and its frame is written, one request in it.
		Core::$now = $this->hour_start + 10 * 60 + 30;
		$fb->fire_cb();
		$late();
		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->fire_cb();
		Core::$memd->delete( self::cache_key( 0, $key ) );

		Core::$now += Stats_Store::BUCKET_SECONDS;
		$fb->fire_cb();
		$swept = $this->hourly_count( $store, $key );
		$late();
		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->fire_cb();

		$this->assertNull( $swept, 'the disk frame is not added back' );
		$this->assertSame( 3, $this->hourly_count( $store, $key ), 'the merge reads the held frame' );
	}

	/**
	 * A pass offers each key once: an older frame of a key it already offered
	 * is skipped, even where memcache lost the key in between.
	 */
	public function test_a_pass_offers_each_key_once(): void {
		[ $fb, $p ] = $this->builder();
		$store      = new Stats_Store( 0, 86400 );
		$key        = Stats_Store::NS_HOURLY . ':' . Stats_Store::bucket_key( (int) Core::$now - 3 * 3600 );
		$filler     = static fn ( int $from ): array => \array_map(
			static fn ( int $i ): array => [ Stats_Store::NS_LB_S . ":filler-{$i}:" . Stats_Store::bucket_key( (int) Core::$now - 3 * 3600 ), [ 'count' => 1, 'sum_req_time' => 1.0, 'categories' => [] ] ],
			\range( $from, $from + 499 )
		);
		$this->frames( $p, [ [ $key, [ 'count' => 11, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] ], ...$filler( 0 ), [ $key, [ 'count' => 22, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] ], ...$filler( 500 ), [ $key, [ 'count' => 33, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] ] ] );
		$chunk = new \ReflectionMethod( $fb, 'sweep_chunk' );
		$seen  = [];

		foreach ( [ 1, 2, 3 ] as $at ) {
			$chunk->invoke( $fb, $p, $store, (int) Core::$now );
			$seen[] = $this->hourly_count( $store, $key );
			Core::$memd->delete( self::cache_key( 0, $key ) );
		}

		$this->assertSame( [ 33, null, null ], $seen );
	}

	/**
	 * A pass reads no segment whose index took its last line longer ago than
	 * the longest life a key has: every frame in it is spent.
	 */
	public function test_a_pass_stops_at_a_segment_past_every_keys_life(): void {
		[ $fb, $p ] = $this->builder();
		$key        = Stats_Store::NS_URLMAP . ':d5e6f7a8b9c0';
		$this->frame( $p, $key, [ self::SEED_SERVER, '/tuatara-7719' ] );
		\touch( $p->partition_dir() . '/0.idx', (int) Core::$now - 86400 - Stats_Store::BUCKET_SECONDS - 60 );

		$fb->fire_cb();

		$this->assertSame( [], ( new Stats_Store( 0, 86400 ) )->get_url_names( [ 'd5e6f7a8b9c0' ] ) );
	}

	/**
	 * A key's life runs from its bucket's END, so a segment whose last line is
	 * a longest life old, but less than a bucket past it, may hold a live key.
	 */
	public function test_a_pass_reads_a_segment_a_bucket_past_the_longest_life(): void {
		[ $fb, $p ] = $this->builder();
		$bucket     = Stats_Store::bucket_key( (int) Core::$now - 86400 + 60 );
		$this->frame( $p, Stats_Store::NS_HOURLY . ':' . $bucket, [ 'count' => 2291, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] );
		\touch( $p->partition_dir() . '/0.idx', (int) Core::$now - 86400 - 60 );

		$fb->fire_cb();

		$this->assertSame( 2291, $this->hourly_count( new Stats_Store( 0, 86400 ), Stats_Store::NS_HOURLY . ':' . $bucket ) );
	}

	/**
	 * A respawned worker goes on from the checkpointed cursor: only the
	 * frame older than the cursor comes back, the newer one waits for the
	 * next pass, which starts again at the newest frame.
	 */
	public function test_the_sweep_resumes_from_a_saved_cursor_and_wraps(): void {
		[ , $p ] = $this->builder();
		// Names, which no fold or refold reads back through the builder's own seam.
		$this->frame( $p, Stats_Store::NS_URLMAP . ':a3a3a3a3a3a3', [ self::SEED_SERVER, '/kiwi-31' ] );
		\clearstatcache();
		$cursor = [ 'segment' => 0, 'end' => (int) \filesize( $p->partition_dir() . '/0.idx' ) ];
		$this->frame( $p, Stats_Store::NS_URLMAP . ':b4b4b4b4b4b4', [ self::SEED_SERVER, '/kiwi-47' ] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( new Stats_Store( 0, 86400 ) );
		$fb->set_stats_target( $p->name() );
		$fb->sink( new Capture_Sink_Node() );
		$fb->restore_state( [ 'sweep' => $cursor ] );
		$store = new Stats_Store( 0, 86400 );
		$named = static fn (): array => \array_keys( $store->get_url_names( [ 'a3a3a3a3a3a3', 'b4b4b4b4b4b4' ] ) );

		$fb->fire_cb();
		$first = $named();
		Core::$now += Stats_Store::BUCKET_SECONDS;
		$fb->fire_cb();

		$this->assertSame( [ 'a3a3a3a3a3a3' ], $first, 'the older frame, not the newer' );
		$this->assertSame( [ 'a3a3a3a3a3a3', 'b4b4b4b4b4b4' ], $named(), 'the next pass starts at the newest' );
	}

	/** A cursor inside the mirror's index rides the checkpoint as it was restored. */
	public function test_the_cursor_rides_the_checkpoint(): void {
		[ $fb, $p ] = $this->builder();
		$this->three_frames( $p );
		$fb->restore_state( [ 'sweep' => [ 'segment' => 0, 'end' => 74 ] ] );

		$this->assertSame( [ 'segment' => 0, 'end' => 74 ], $fb->save_state()['sweep'] );
	}

	/**
	 * A checkpoint whose cursor is missing or not two ints starts a fresh pass.
	 *
	 * @param array<string,mixed> $saved The checkpoint.
	 */
	#[DataProvider( 'malformed_cursors' )]
	public function test_a_malformed_cursor_starts_a_fresh_pass( array $saved ): void {
		[ $fb ] = $this->builder();
		$fb->restore_state( $saved );

		$this->assertSame( [ 'segment' => -1, 'end' => 0 ], $fb->save_state()['sweep'] );
	}

	/** @return array<string,array{0: array<string,mixed>}> */
	public static function malformed_cursors(): array {
		return [
			'no cursor at all'  => [ [ 'probes' => [] ] ],
			'a string segment'  => [ [ 'sweep' => [ 'segment' => '0', 'end' => 74 ] ] ],
			'a float end'       => [ [ 'sweep' => [ 'segment' => 0, 'end' => 74.0 ] ] ],
			'no end'            => [ [ 'sweep' => [ 'segment' => 0 ] ] ],
		];
	}

	/** A cursor on a segment the mirror no longer has goes to the next older one at once. */
	public function test_a_vanished_segment_moves_on_at_once(): void {
		[ $fb, $p ] = $this->builder();
		$keys       = $this->three_frames( $p );
		( new \ReflectionProperty( $fb, 'sweep' ) )->setValue( $fb, [ 'segment' => 4, 'end' => 5000 * 37 ] );

		$this->chunk( $fb, $p );

		$this->assertSame( [ 8, 7, 6 ], \array_map( fn ( string $key ): ?int => $this->hourly_count( new Stats_Store( 0, 86400 ), $key ), $keys ) );
	}

	/** A cursor on a segment past every key's life ends the pass unread. */
	public function test_an_expired_segment_is_skipped(): void {
		[ $fb, $p ] = $this->builder();
		$keys       = $this->three_frames( $p );
		\touch( $p->partition_dir() . '/0.idx', (int) Core::$now - 86400 - Stats_Store::BUCKET_SECONDS - 60 );
		$sweep = new \ReflectionProperty( $fb, 'sweep' );
		$sweep->setValue( $fb, [ 'segment' => 0, 'end' => 3 * 37 ] );

		$this->chunk( $fb, $p );

		$this->assertNull( $this->hourly_count( new Stats_Store( 0, 86400 ), $keys[0] ) );
		$this->assertSame( [ 'segment' => -1, 'end' => 0 ], $sweep->getValue( $fb ) );
	}

	/** An end at the index's size resumes the whole segment, and the segment is then done. */
	public function test_an_end_at_the_index_size_sweeps_the_segment_once(): void {
		[ $fb, $p ] = $this->builder();
		$keys       = $this->three_frames( $p );
		$sweep      = new \ReflectionProperty( $fb, 'sweep' );
		\clearstatcache();
		$sweep->setValue( $fb, [ 'segment' => 0, 'end' => (int) \filesize( $p->partition_dir() . '/0.idx' ) ] );

		$this->chunk( $fb, $p );

		$this->assertSame( [ 8, 7, 6 ], \array_map( fn ( string $key ): ?int => $this->hourly_count( new Stats_Store( 0, 86400 ), $key ), $keys ) );
		$this->assertSame( [ 'segment' => -1, 'end' => 0 ], $sweep->getValue( $fb ), 'nothing more in that segment' );
	}

	/** An end mid-line reads only the whole lines before it. */
	public function test_an_end_mid_line_rounds_down_to_a_whole_line(): void {
		[ $fb, $p ] = $this->builder();
		$keys       = $this->three_frames( $p );
		( new \ReflectionProperty( $fb, 'sweep' ) )->setValue( $fb, [ 'segment' => 0, 'end' => 3 * 37 - 1 ] );

		$this->chunk( $fb, $p );

		$this->assertSame( [ 8, 7, null ], \array_map( fn ( string $key ): ?int => $this->hourly_count( new Stats_Store( 0, 86400 ), $key ), $keys ) );
	}

	/**
	 * A torn index line — a short write of 20 bytes and no newline — offsets
	 * every line after it. The pass still offers every whole line, across the
	 * chunk boundaries of the 1,100 lines written after the tear.
	 */
	public function test_a_torn_index_line_leaves_every_whole_line_offered(): void {
		[ $fb, $p ] = $this->builder();
		$bucket     = Stats_Store::bucket_key( (int) Core::$now - 3 * 3600 );
		$filler     = static fn ( int $from, int $to ): array => \array_map(
			static fn ( int $i ): array => [ Stats_Store::NS_LB_S . ":kahu-{$i}:{$bucket}", [ 'count' => 1, 'sum_req_time' => 1.0, 'categories' => [] ] ],
			\range( $from, $to )
		);
		$this->frames( $p, $filler( 1, 3 ) );
		\file_put_contents( $p->partition_dir() . '/0.idx', \str_repeat( '7', 20 ), \FILE_APPEND );
		$this->frames( $p, $filler( 4, 1103 ) );
		$chunk = new \ReflectionMethod( $fb, 'sweep_chunk' );
		$store = new Stats_Store( 0, 86400 );

		for ( $i = 0; $i < 10 && $chunk->invoke( $fb, $p, $store, (int) Core::$now ); $i++ ) {
			continue;
		}

		$this->assertLessThan( 10, $i, 'the pass ended' );
		$this->assertSame( 1103, ( new \ReflectionProperty( $fb, 'tally' ) )->getValue( $fb )[ Flame_Tree::STATS_WRITES ]['restored'] ?? 0 );
	}

	/**
	 * Three frames into segment 0 of `$p`, counting 8, 7 and 6.
	 *
	 * @return list<string> Their keys, oldest first.
	 */
	private function three_frames( Partition_Node $p ): array {
		$keys = [];
		foreach ( [ 5, 4, 3 ] as $hours ) {
			$keys[] = Stats_Store::NS_HOURLY . ':' . Stats_Store::bucket_key( (int) Core::$now - $hours * 3600 );
			$this->frame( $p, \end( $keys ), [ 'count' => 3 + $hours, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] );
		}
		return $keys;
	}

	/** One sweep chunk of `$fb` over `$p`, at the tick. */
	private function chunk( Flame_Builder_Node $fb, Partition_Node $p ): void {
		( new \ReflectionMethod( $fb, 'sweep_chunk' ) )->invoke( $fb, $p, new Stats_Store( 0, 86400 ), (int) Core::$now );
	}

	/**
	 * A restored name files its search tokens at the next flush, from its
	 * path alone: a name stored as a whole `http://` URL files no host word.
	 */
	public function test_a_restored_name_files_its_path_tokens_at_the_next_flush(): void {
		[ $fb, $p ] = $this->builder();
		$hash       = 'c4d5e6f7a8b9';
		$this->frame( $p, Stats_Store::NS_URLMAP . ':' . $hash, [ self::SEED_SERVER, 'http://www.kakapo.test/numbat-5821' ] );
		$sets = fn (): array => ( new Stats_Store( 0, 86400 ) )->url_token_sets( [ 'numbat', 'numb', '5821', 'kakapo', 'www' ], [ self::SEED_SERVER ] );

		$fb->fire_cb();
		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->fire_cb();

		$this->assertContains( $hash, $sets()['numbat'] ?? [] );
		$this->assertContains( $hash, $sets()['5821'] ?? [] );
		$this->assertNotContains( $hash, $sets()['numb'] ?? [], 'a whole word, never its prefix' );
		$this->assertNotContains( $hash, $sets()['kakapo'] ?? [], 'no host word' );
		$this->assertNotContains( $hash, $sets()['www'] ?? [], 'no host word' );
	}

	/** A restored name's token entry carries the name's own write time, so it ages out with the name. */
	public function test_a_restored_names_token_ages_out_with_the_name(): void {
		[ $fb, $p ] = $this->builder();
		$hash       = 'd4e5f6a7b8c9';
		$written    = (int) Core::$now - 20 * 3600;
		$now        = Core::$now;
		Core::$now  = $written;
		$this->frame( $p, Stats_Store::NS_URLMAP . ':' . $hash, [ self::SEED_SERVER, '/numbat-6613' ] );
		Core::$now = $now;

		$fb->fire_cb();
		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->fire_cb();

		$set = ( new Stats_Store( 0, 86400 ) )->bucket_get_multi( [ [ Stats_Store::url_token_parts( Stats_Store::server_key( self::SEED_SERVER ) ), 'numbat' ] ] )[0] ?? [];
		$this->assertSame( $written, $set[ $hash ] ?? null );
	}

	/** A chunk restoring many names writes no token inside the sweep's tick; the next flush does. */
	public function test_a_chunk_restoring_names_writes_no_token_in_the_sweep(): void {
		[ $fb, $p ] = $this->builder();
		$names      = [];
		foreach ( \range( 1, 500 ) as $i ) {
			$names[] = [ Stats_Store::NS_URLMAP . ':' . \sprintf( 'ab%010x', $i ), [ self::SEED_SERVER, "/kereru-{$i}" ] ];
		}
		$this->frames( $p, $names );
		$tokens = static fn (): int => \count( \array_filter( Core::$memd->keys(), static fn ( string $key ): bool => \str_contains( $key, ':' . Stats_Store::NS_URLTOKEN . ':' ) ) );

		$fb->fire_cb();
		$swept = $tokens();
		Core::$now += Flame_Builder_Node::FLUSH_INTERVAL_SEC;
		$fb->fire_cb();

		$this->assertSame( 0, $swept, 'no token written by the sweep' );
		$this->assertGreaterThan( 0, $tokens(), 'the next flush files them' );
	}

	/** Restored names alone are owed a flush: a quiet partition mid-pass files them. */
	public function test_a_quiet_partition_flushes_the_names_it_restored(): void {
		[ $fb ]  = $this->builder();
		$plan    = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, (int) Core::$now ) );
		$written = (int) Core::$now - 3 * 3600;
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, \array_fill_keys( $plan['hours'], true ) );
		( new \ReflectionProperty( $fb, 'sweep_due' ) )->setValue( $fb, (int) Core::$now + 60 );
		( new \ReflectionProperty( $fb, 'restored_names' ) )->setValue( $fb, [ self::SEED_SERVER => [ 'e6f7a8b9c0d1' => [ '/takahe-4417', $written ] ] ] );

		$fb->fire_cb();

		$set = ( new Stats_Store( 0, 86400 ) )->bucket_get_multi( [ [ Stats_Store::url_token_parts( Stats_Store::server_key( self::SEED_SERVER ) ), 'takahe' ] ] )[0] ?? [];
		$this->assertSame( $written, $set['e6f7a8b9c0d1'] ?? null );
	}

	/** A store of another keyspace forgets the names the old one restored. */
	public function test_a_store_swap_forgets_the_restored_names(): void {
		[ $fb ]   = $this->builder();
		$restored = new \ReflectionProperty( $fb, 'restored_names' );
		$restored->setValue( $fb, [ self::SEED_SERVER => [ 'f7a8b9c0d1e2' => [ '/weka-3301', (int) Core::$now ] ] ] );

		$fb->set_stats_store( new Stats_Store( 1, 86400 ) );

		$this->assertSame( [], $restored->getValue( $fb ) );
	}

	/** With no store to file them in, restored names owe no flush. */
	public function test_restored_names_owe_no_flush_without_a_store(): void {
		$fb = new Flame_Builder_Node();
		( new \ReflectionProperty( $fb, 'restored_names' ) )->setValue( $fb, [ self::SEED_SERVER => [ 'a8b9c0d1e2f3' => [ '/kea-5519', (int) Core::$now ] ] ] );

		$this->assertFalse( ( new \ReflectionMethod( $fb, 'flush_owed' ) )->invoke( $fb, (int) Core::$now ) );
	}

	/**
	 * After a pass ends, the next waits a bucket's width before it starts. A
	 * name, which no fold reads back through the builder's own seam.
	 */
	public function test_the_next_pass_waits_a_bucket(): void {
		[ $fb, $p ] = $this->builder();
		$store      = new Stats_Store( 0, 86400 );
		$key        = Stats_Store::NS_URLMAP . ':e2f3a4b5c6d7';
		$this->frame( $p, $key, [ self::SEED_SERVER, '/moa-4127' ] );
		$name = static fn (): array => $store->get_url_names( [ 'e2f3a4b5c6d7' ] );
		$fb->fire_cb();
		Core::$memd->delete( self::cache_key( 0, $key ) );

		Core::$now += Stats_Store::BUCKET_SECONDS - 1;
		$fb->fire_cb();
		$waiting = $name();
		Core::$now += 1;
		$fb->fire_cb();

		$this->assertSame( [], $waiting, 'inside the wait, no pass' );
		$this->assertNotSame( [], $name(), 'then the next pass' );
	}

	public function test_a_pass_ending_reprobes_both_tiers(): void {
		[ $fb, $p ] = $this->builder();
		$due        = new \ReflectionProperty( $fb, 'probe_due' );
		$this->frame( $p, Stats_Store::NS_HOURLY . ':' . Stats_Store::bucket_key( (int) Core::$now - 7200 ), [ 'count' => 3319, 'sum_ms' => 3.0, 'sum_peak_mb' => 2.0 ] );
		$fb->fire_cb();
		$due->setValue( $fb, [ 'hour' => null, 'fine' => null ] );

		Core::$now += Stats_Store::BUCKET_SECONDS;
		$fb->fire_cb();

		$this->assertSame( [ 'hour' => 'reprobe', 'fine' => 'reprobe' ], $due->getValue( $fb ), 'an idle builder reprobes once a pass' );
	}

	/**
	 * The builder's own read of a fine bucket warms it for what is left of
	 * the fine tier's life, as the sweep would, not for the whole window.
	 */
	public function test_a_rehydrated_fine_bucket_lives_its_fine_life(): void {
		[ , $p ] = $this->builder();
		$store   = new Stats_Store( 0, 86400 );
		$fb      = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );
		$bucket  = Stats_Store::bucket_key( (int) Core::$now - 90 * 60 );
		$key     = Stats_Store::NS_URLSRV . ':' . $bucket;
		$this->frame( $p, $key, self::index_of( [ self::SEED_SERVER ] ) );

		$this->assertNotNull( $store->bucket_get_multi( [ [ Stats_Store::url_srv_parts( false ), $bucket ] ] )[0] );
		$expires = Core::$memd->expiries()[ self::cache_key( 0, $key ) ] ?? 0;
		$this->assertEqualsWithDelta( (int) Stats_Store::bucket_end( $key ) + $store->ttl_url_fine() - (int) Core::$now, $expires - \time(), 2 );
	}

	/**
	 * A builder mirroring into a fresh indexed partition of its own.
	 *
	 * @return array{0: Flame_Builder_Node, 1: Partition_Node}
	 */
	private function builder(): array {
		$dir          = \Newspack_Nodes\Config::get_base_directory() . '/sweep_' . \uniqid();
		$this->dirs[] = $dir;
		$p            = new Partition_Node();
		$p->arguments( [ $dir, '67108864' ] );
		$p->name( 'sweep-stats-' . \uniqid() );
		$p->void_warranty();
		$p->with_index( Flame_Builder_Node::format_stats_index_entry( ... ) );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb-' . \uniqid() );
		$fb->set_stats_store( new Stats_Store( 0, 86400 ) );
		$fb->set_stats_target( $p->name() );
		$fb->sink( new Capture_Sink_Node() );
		return [ $fb, $p ];
	}

	/**
	 * One mirror frame, written and flushed as the builder writes one.
	 *
	 * @param array<array-key,mixed> $data The frame's value.
	 */
	private function frame( Partition_Node $p, string $key, array $data ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::KEY ]       = Stats_Store::entry_key( 0, $key );
		$msg[ Message::VALUE ]     = [ 'data' => $data, 'ttl' => 86400 ];
		$p->fill( $msg );
		$p->flush();
	}

	/**
	 * Several mirror frames in order, flushed once.
	 *
	 * @param list<array{0: string, 1: array<array-key,mixed>}> $frames Key and value, oldest first.
	 */
	private function frames( Partition_Node $p, array $frames ): void {
		foreach ( $frames as [ $key, $data ] ) {
			$msg                       = Message::new_message();
			$msg[ Message::TYPE ]      = Message::TM_STRUCT;
			$msg[ Message::TIMESTAMP ] = Core::$now;
			$msg[ Message::KEY ]       = Stats_Store::entry_key( 0, $key );
			$msg[ Message::VALUE ]     = [ 'data' => $data, 'ttl' => 86400 ];
			$p->fill( $msg );
		}
		$p->flush();
	}

	/** An `hourly` key's stored count, or null where memcache holds none. */
	private function hourly_count( Stats_Store $store, string $key ): ?int {
		$value = $store->bucket_get_multi( [ [ Stats_Store::hourly_parts(), Stats_Store::bucket_of( $key ) ] ] )[0];
		return null === $value ? null : Core::num_int( $value['count'] ?? null );
	}

	/**
	 * Fill one completed request, as the requests Consumer delivers it.
	 *
	 * @param array<string,mixed> $overrides Fields over the base record.
	 */
	private function fill_request( Flame_Builder_Node $fb, array $overrides ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = \array_replace(
			[
				'rid'            => 'r' . \uniqid(),
				'url'            => '/post/123',
				'rule_id'        => 'r',
				'duration_ms'    => 100.0,
				'status_code'    => 200,
				'error_status'   => '-',
				'peak_mb'        => 32.0,
				'request_method' => 'GET',
				'server_name'    => self::SEED_SERVER,
				'country_code'   => 'US',
				'http_from'      => '',
				'user_agent'     => 'curl/7.85',
				'ja4_hash'       => '',
				'is_worker'      => false,
				'timestamp'      => (int) Core::$now,
				'entries'        => [],
				'profiles'       => [],
			],
			$overrides
		);
		$fb->fill( $message );
	}
}
