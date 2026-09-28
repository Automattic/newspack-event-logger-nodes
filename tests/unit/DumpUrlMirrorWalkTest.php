<?php
/**
 * DumpUrlMirrorWalkTest: what the URL-detail poll costs the durable mirror.
 *
 * `Partition_Node::locate_by()` cannot stop early on a key the mirror does
 * not hold, so every Table batch carrying one such key is a full pass over a
 * partition's `flame-stats` index — seconds on a production hub. The detail
 * modal polls `dump_url` every fifteen seconds, so a key walked for on each
 * poll is a walk on each poll.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

#[CoversClass( Performance_CI_Node::class )]
class DumpUrlMirrorWalkTest extends TestCase {
	/** The URL the modal is open on. */
	private const HASH = 'a4471ab0c0de';

	/** Another URL, whose traffic keeps the site's own index in every bucket. */
	private const NEIGHBOUR = 'b8823bc1d2ef';

	/** Flame-builder partitions, so "one walk per partition" means two. */
	private const PARTITIONS = 2;

	/** How long before the poll the URL's last request finished: past its blob's hour. */
	private const IDLE_S = 62 * 60;

	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp  = '/tmp/dump-url-mirror-walk-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		Core::$memd = new InMemoryMemcached();
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => self::PARTITIONS, 'min_lifetime' => 86400, 'stats_mirror_read_budget_ms' => 60000 ] );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		\Newspack_Nodes\Topology_Registry::register_stock_dir( \dirname( __DIR__, 2 ) . '/topologies' );
		\Newspack_Nodes\Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ): array {
				$topologies['performance'] = [ 'topology' => 'performance', 'num_partitions' => self::PARTITIONS, 'stale_timeout' => 60 ];
				return $topologies;
			}
		);
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'performance' ];
		\Newspack_Nodes\Config::reset();
		// A reply dates from a tick a hundred seconds into its bucket.
		$this->pin_tick( self::tick() - self::tick() % Stats_Store::BUCKET_SECONDS + 100 );
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
	 * A poll of a URL last seen an hour ago walks each partition at most once,
	 * and the same poll twenty seconds on walks nothing. Past the next bucket
	 * boundary the bucket that just closed costs one walk per partition at
	 * most, whose absence then holds, so the poll after it walks nothing.
	 */
	public function test_a_sparse_url_poll_walks_each_closed_bucket_once(): void {
		$now = self::tick();
		$this->seed( $now );
		$poll = self::HASH . ' --categories --since=' . ( $now - self::IDLE_S - 1 );

		$first = $this->walks_of( $poll );
		$this->age_cache( Stats_Store::ABSENCE_HOLD_SECONDS + 1 );
		$again = $this->walks_of( $poll );
		$this->pin_tick( $now + Stats_Store::BUCKET_SECONDS );
		$later = $this->walks_of( $poll );
		$this->age_cache( Stats_Store::ABSENCE_HOLD_SECONDS + 1 );
		$after = $this->walks_of( $poll );

		$this->assertLessThanOrEqual( self::PARTITIONS, $first['calls'], 'one pass per partition at most' );
		$this->assertSame( 0, $again['calls'], 'the same poll past the brief hold walks nothing' );
		$this->assertLessThanOrEqual( self::PARTITIONS, $later['calls'], 'the bucket just closed, once per partition at most' );
		$this->assertSame( 0, $after['calls'], 'and its absence holds for the next poll' );
	}

	/**
	 * A bucket closed less than `MIRROR_LAG_S` plus the read budget ago may be
	 * racing the writer's pass, so its absence holds one poll's worth: a poll
	 * inside that race past the brief hold walks it again, and the first poll
	 * past the race marks it for the window.
	 */
	public function test_a_poll_inside_the_writers_race_walks_the_closed_bucket_again(): void {
		$now = self::tick();
		$this->seed( $now );
		$poll     = self::HASH . ' --categories --since=' . ( $now - self::IDLE_S - 1 );
		$boundary = $now - $now % Stats_Store::BUCKET_SECONDS + Stats_Store::BUCKET_SECONDS;
		$this->walks_of( $poll );

		// Seven seconds past the close: walked, and inside the 66s race.
		$this->pin_tick( $boundary + 7 );
		$inside = $this->walks_of( $poll );
		$this->age_cache( Stats_Store::ABSENCE_HOLD_SECONDS + 1 );
		$this->pin_tick( $boundary + 7 + Stats_Store::ABSENCE_HOLD_SECONDS + 1 );
		$again = $this->walks_of( $poll );
		$this->age_cache( 60 );
		$this->pin_tick( $boundary + 90 );
		$past = $this->walks_of( $poll );
		$this->age_cache( Stats_Store::ABSENCE_HOLD_SECONDS + 1 );
		$this->pin_tick( $boundary + 90 + Stats_Store::ABSENCE_HOLD_SECONDS + 1 );
		$after = $this->walks_of( $poll );

		$this->assertSame(
			[ self::PARTITIONS, self::PARTITIONS, self::PARTITIONS, 0 ],
			[ $inside['calls'], $again['calls'], $past['calls'], $after['calls'] ],
			'once a brief hold inside the race, once past it, then none'
		);
	}

	/** Every reply the test fires reads the one instant it pinned, however long the test runs. */
	public function test_a_pinned_tick_is_the_instant_it_names(): void {
		$this->pin_tick( 1_600_000_317 );
		Core::right_now();

		$this->assertSame( 1_600_000_317.0, Core::$now );
	}

	/** The series, read over the whole window, carries every bucket the URL was in. */
	public function test_the_series_keeps_every_bucket_the_url_was_in(): void {
		$this->seed( self::tick() );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::HASH . ' --categories' );

		$this->assertIsArray( $result );
		$this->assertSame( [ 35.0, 35.0, 27.0 ], self::series_ms( $result['category_time_series'] ?? [] ) );
	}

	/**
	 * The milliseconds of each bucket the compact series carries, by bucket.
	 *
	 * @param array<array-key,mixed> $series `category_time_series`.
	 * @return list<float>
	 */
	private static function series_ms( array $series ): array {
		$buckets = Core::arr( $series['buckets'] ?? null );
		\ksort( $buckets );
		$out = [];
		foreach ( $buckets as $rows ) {
			foreach ( Core::arr( $rows ) as $row ) {
				$out[] = Core::num_float( Core::arr( $row )[1] ?? null );
			}
		}
		return $out;
	}

	/**
	 * One poll's mirror reads, as a fresh process makes them: the substrate's
	 * locator memo is per process, and each dashboard poll is a request.
	 *
	 * @return array{calls:int,asked:int,found:int,ns:int,budget_ns:int,spent:bool}
	 */
	private function walks_of( string $args ): array {
		( new \ReflectionProperty( \Newspack_Nodes\Partition_Node::class, 'locator_cache' ) )->setValue( null, [] );
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $args );
		$this->assertIsArray( $result, 'dump_url answers' );
		Core::cleanup_all_nodes();
		return Flame_Builder_Node::mirror_read_tally();
	}

	/**
	 * A busy site's index in every bucket and hour of the window, and the URL
	 * in three places: two old hours on partition 0, and the bucket of its
	 * last request on partition 1. Its blob has left memcache, as it does an
	 * hour after that request, and every mirror holds frames of other keys.
	 */
	private function seed( int $now ): void {
		$url   = 'https://example.com/wombat-4471';
		$last  = $now - self::IDLE_S;
		$shard = Stats_Store::url_shard( self::HASH );
		$plan  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) );
		// This reply's window and the next bucket's, for the poll after it.
		$next  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now + Stats_Store::BUCKET_SECONDS ) );
		foreach ( \range( 0, self::PARTITIONS - 1 ) as $p ) {
			$store = new Stats_Store( $p, 86400 );
			$busy  = [ self::NEIGHBOUR => [ 'url' => 'https://example.com/numbat', 'count' => 2 ] ];
			foreach ( \array_unique( \array_merge( $plan['hours'], $next['hours'] ) ) as $hour ) {
				$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( self::NEIGHBOUR ), $busy );
			}
			// The fine tier, which also answers an hour whose own shard is empty.
			foreach ( Stats_Store::retention_buckets( Stats_Store::FINE_TTL_SECONDS + 3600, $now + Stats_Store::BUCKET_SECONDS ) as $bucket ) {
				$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( self::NEIGHBOUR ), $busy );
			}
			$this->seed_mirror( $p );
		}
		$old = new Stats_Store( 0, 86400 );
		foreach ( [ $plan['hours'][9], $plan['hours'][4] ] as $hour ) {
			$this->seed_url_hour( $old, $hour, $shard, [ self::HASH => [ 'url' => $url, 'count' => 5, 'sum_ms' => 50.0, 'timed_count' => 5, 'last_seen' => $now - 5 * 3600 ] ] );
			$this->set_url_category_bucket( $old, self::HASH, Stats_Store::buckets_in_hour( $hour )[3], [ 'wpdb' => self::cat_entry( 35, 5, 5 ) ] );
		}
		$recent = new Stats_Store( 1, 86400 );
		$this->seed_url_shard( $recent, Stats_Store::bucket_key( $last ), $shard, [ self::HASH => [ 'url' => $url, 'count' => 3, 'sum_ms' => 27.0, 'timed_count' => 3, 'last_seen' => $last ] ] );
		$this->set_url_category_bucket( $recent, self::HASH, Stats_Store::bucket_key( $last ), [ 'wpdb' => self::cat_entry( 27, 3, 3 ) ] );
	}

	/** Frames of other keys on one partition's mirror, so a walk has an index to read. */
	private function seed_mirror( int $partition ): void {
		$mirror = new \Newspack_Nodes\Partition_Node();
		$mirror->arguments( [ \Newspack_Nodes\Bootstrap::node_dirs( 'flame-stats:partition' )[ $partition ], '67108864' ] );
		$mirror->void_warranty();
		$mirror->with_index( Flame_Builder_Node::format_stats_index_entry( ... ) );
		foreach ( \range( 1, 40 ) as $i ) {
			$key                       = Stats_Store::entry_key( $partition, 'url_cat:' . \sprintf( 'c0ffee%06x', $i ) . ':' . Stats_Store::bucket_key( self::tick() - 3600 ) );
			$msg                       = Message::new_message();
			$msg[ Message::TYPE ]      = Message::TM_STRUCT;
			$msg[ Message::TIMESTAMP ] = self::tick();
			$msg[ Message::KEY ]       = $key;
			$msg[ Message::VALUE ]     = [ 'data' => [ 'wpdb' => [ 1, 1, 1 ] ], 'ttl' => 43200 ];
			$mirror->fill( $msg );
		}
		$mirror->flush();
	}

	/** Run the rest of the test on a tick frozen at `$at`; the substrate's tearDown frees it. */
	private function pin_tick( int $at ): void {
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
	}

	/** Move every cached expiry `$seconds` closer, as that much wall time would. */
	private function age_cache( int $seconds ): void {
		$prop  = new \ReflectionProperty( InMemoryMemcached::class, 'store' );
		$store = $prop->getValue( Core::$memd );
		foreach ( $store as $key => $entry ) {
			if ( $entry['expires'] > 0 ) {
				$store[ $key ]['expires'] -= $seconds;
			}
		}
		$prop->setValue( Core::$memd, $store );
	}
}
