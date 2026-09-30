<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;

/**
 * The span: what a builder has folded since its last settle. The Consumer's
 * interval checkpoint settles it into the stats Tables; every other
 * checkpoint carries it in the offsetlog, and the successor that restores it
 * settles it once.
 */
#[CoversClass( Flame_Builder_Node::class )]
class FlameBuilderSpanTest extends TestCase {

	/** The flame-builder partition every store here writes. */
	private const PARTITION = 3;

	/** The URL every record is for. */
	private const URL = '/kea-41';

	/** Each record's duration, in ms. */
	private const DURATION_MS = 37.0;

	/** The pinned tick, on a five-minute boundary. */
	private const T = 1790000400;

	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		Core::$now = (float) self::T;
		$this->tmp = $this->make_temp_dir( 'eln-span-' );
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/** A completed request for `/kea-41` at 37 ms, finished at the tick. */
	private static function record(): array {
		return [
			'rid'            => 'r' . \uniqid(),
			'url'            => self::URL,
			'rule_id'        => 'kea',
			'duration_ms'    => self::DURATION_MS,
			'status_code'    => 200,
			'error_status'   => '-',
			'peak_mb'        => 12.0,
			'request_method' => 'GET',
			'server_name'    => 'kea.test',
			'is_worker'      => false,
			'timestamp'      => self::T,
			'entries'        => [],
			'profiles'       => [],
		];
	}

	/** A builder named `$name`, asking the partition-3 stats Tables. */
	private function builder( string $name = 'fb-kea', string $class = Flame_Builder_Node::class ): Flame_Builder_Node {
		$fb = new $class();
		$fb->name( $name );
		$fb->set_stats_store( $this->stats_store( partition: self::PARTITION, max_lifespan: 86400, asker: $fb ) );
		return $fb;
	}

	/** Fill one record as the Consumer delivers it, its crumb in ID. */
	private static function fold( Flame_Builder_Node $fb, string $crumb, string $url = self::URL ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::ID ]    = $crumb;
		$message[ Message::VALUE ] = [ 'url' => $url ] + self::record();
		$fb->fill( $message );
	}

	/** The carry as the offsetlog stores it: plain JSON. */
	private static function as_stored( array $carry ): array {
		return \json_decode( (string) \wp_json_encode( $carry ), true );
	}

	/** The requests the partition-3 Tables hold for the tick's bucket. */
	private function counted(): array {
		$slot = $this->get_hour_slot( $this->stats_store( partition: self::PARTITION ), Stats_Store::hourly_parts(), Stats_Store::bucket_key( self::T ) );
		return [ (int) ( $slot['requests'] ?? 0 ), (float) ( $slot['sum_ms'] ?? 0 ) ];
	}

	/** How many requests the stored `/kea-41` flame blob has folded. */
	private function blob_count(): int {
		$blob = $this->stats_store( partition: self::PARTITION )->url_aggregate( Log_Manager::url_hash( self::URL ) );
		return (int) ( Core::arr( $blob['flame_raw'] ?? null )['count'] ?? 0 );
	}

	public function test_a_checkpoint_with_no_settle_carries_the_span_and_writes_nothing(): void {
		$first = $this->builder();
		foreach ( [ '3:100:41', '3:200:42', '3:300:43' ] as $crumb ) {
			self::fold( $first, $crumb );
		}

		$carry = self::as_stored( $first->save_state() );
		$first->remove_node();

		$this->assertSame( [ 0, 0.0 ], $this->counted(), 'an unsettled checkpoint writes nothing' );
		$this->assertSame( '3:300:43', $carry['counted'] );
		$this->assertSame( 3, $carry['span']['pending'][ Stats_Store::bucket_key( self::T ) ]['hourly']['requests'] ?? null, 'the span holds all three' );

		$successor = $this->builder();
		$successor->restore_state( $carry );
		$successor->settle();

		$this->assertSame( [ 3, 111.0 ], $this->counted(), 'the successor settles exactly three' );
		$this->assertSame( 3, $this->blob_count(), 'the URL blob folds the carried three' );
	}

	public function test_a_settled_builder_carries_an_empty_span(): void {
		$fb = $this->builder();
		self::fold( $fb, '3:100:41' );

		$fb->settle();

		$this->assertSame( [ 'counted' => '3:100:41', 'span' => [] ], $fb->save_state() );
		$this->assertSame( [ 1, 37.0 ], $this->counted() );
	}

	public function test_a_clean_stop_writes_nothing_and_carries_the_span(): void {
		$fb = $this->builder();
		self::fold( $fb, '3:100:41' );

		$fb->shutdown_sweep();

		$this->assertSame( [ 0, 0.0 ], $this->counted(), 'the sweep leaves the span to the frame' );
		$this->assertSame( 1, self::as_stored( $fb->save_state() )['span']['pending'][ Stats_Store::bucket_key( self::T ) ]['hourly']['requests'] ?? null );
	}

	public function test_a_restored_span_settles_once(): void {
		$first = $this->builder();
		foreach ( [ '3:100:41', '3:200:42', '3:300:43' ] as $crumb ) {
			self::fold( $first, $crumb );
		}
		$carry = self::as_stored( $first->save_state() );
		$first->remove_node();

		$successor = $this->builder();
		$successor->restore_state( $carry );
		$successor->settle();
		$successor->settle();

		$this->assertSame( [ 3, 111.0 ], $this->counted(), 'a second settle writes nothing twice' );
		$this->assertSame( 3, $this->blob_count() );
	}

	/**
	 * A restart between two interval checkpoints, through the Consumer: the
	 * graceful frame carries the span, the successor restores it, and its
	 * interval checkpoint settles it once, beside the record it read itself.
	 */
	public function test_a_restart_between_interval_checkpoints_settles_the_carried_span_once(): void {
		$source = new Partition_Node();
		$source->arguments( [ "{$this->tmp}/requests.p3", (string) ( 64 * 1024 ), '4', '86400' ] );
		foreach ( [ 1, 2, 3 ] as $n ) {
			$this->append_record( $source );
		}

		[ $c1, $fb1 ] = $this->worker();
		$this->pump_consumer( $c1 );
		$c1->checkpoint( true );
		$c1->remove_node();
		$fb1->remove_node();
		$this->assertSame( [ 0, 0.0 ], $this->counted(), 'a graceful stop carries the span' );

		$this->append_record( $source );
		[ $c2, $fb2 ] = $this->worker();
		$this->pump_consumer( $c2 );
		$c2->checkpoint( settle: true );
		$this->assertSame( [ 4, 148.0 ], $this->counted(), 'the carried three and the new one, once each' );

		$c2->checkpoint( true );
		$c2->remove_node();
		$fb2->remove_node();
		$this->append_record( $source );
		[ $c3 ] = $this->worker();
		$this->pump_consumer( $c3 );
		$c3->checkpoint( settle: true );
		$this->assertSame( [ 5, 185.0 ], $this->counted(), 'the settled span rides no later frame' );
	}

	/**
	 * A crawl checkpoints every record it reads, each frame carrying the span
	 * and none settling it; once it reaches the end of the log every folded
	 * record rides a committed frame, so the builder keeps no worker up.
	 */
	public function test_a_crawl_reaching_the_end_of_its_log_leaves_the_worker_idle(): void {
		$source = new Partition_Node();
		$source->arguments( [ "{$this->tmp}/requests.p3", (string) ( 64 * 1024 ), '4', '86400' ] );
		foreach ( [ 1, 2, 3, 4 ] as $n ) {
			$this->append_record( $source );
		}
		$this->seed_crawl_frame( "{$this->tmp}/offsets/flame-builder.p3" );

		[ $c, $fb ] = $this->worker();
		$this->pump_consumer( $c );

		$this->assertSame( [ 0, 0.0 ], $this->counted(), 'a crawl\'s checkpoints settle nothing' );
		$this->assertNotNull( $c->idle_since(), 'the reader is caught up' );
		$this->assertNotNull( $fb->idle_since(), 'and every record the builder folded rides a committed frame' );
	}

	/** An offsetlog frame at 0:0 that has crashed `CRASH_MAX_ATTEMPTS` times: the successor crawls. */
	private function seed_crawl_frame( string $dir ): void {
		\mkdir( $dir, 0755, true );
		$frame                   = Message::new_message();
		$frame[ Message::TYPE ]  = Message::TM_STRUCT;
		$frame[ Message::FROM ]  = 'seed';
		$frame[ Message::VALUE ] = [ 'segment' => 0, 'offset' => 0, 'attempts' => Consumer_Node::CRASH_MAX_ATTEMPTS, 'reason' => '', 'first_crash_ts' => null ];
		\file_put_contents( "{$dir}/0.log", Message::packed( $frame ) . "\n" );
	}

	/** One worker's reader and builder, over `requests.p3`, the builder snapshotted. */
	private function worker(): array {
		$fb = $this->builder( 'flame-builder' );
		$c  = new Consumer_Node();
		$c->arguments( [ "{$this->tmp}/requests.p3", "{$this->tmp}/offsets/flame-builder.p3" ] );
		$c->name( 'requests:consumer' );
		$c->sink( $fb );
		$c->add_snapshot_node( 'flame-builder' );
		return [ $c, $fb ];
	}

	/** Append one record to the requests log, visible on disk at once. */
	private function append_record( Partition_Node $source ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = self::record();
		$source->fill( $message );
		$source->flush();
	}

	public function test_the_tick_writes_nothing(): void {
		$fb = $this->builder();
		$fb->arguments( [] );
		self::fold( $fb, '3:100:41' );

		Core::$now += Flame_Builder_Node::AUTO_TUNE_INTERVAL_SEC;
		Core::node( \Newspack_Nodes\Node_Names::ROUTER )->fire_cb();

		$this->assertSame( [ 0, 0.0 ], $this->counted(), 'only the interval checkpoint settles' );
		$this->assertSame( Flame_Builder_Node::AUTO_TUNE_INTERVAL_SEC * 1000, $fb->interval_ms );
	}

	/**
	 * A builder that folded is busy until it settles; one that only restored
	 * a span is not, since a stop would carry that span again.
	 */
	public function test_only_a_span_folded_here_keeps_the_worker_busy(): void {
		$first = $this->builder();
		self::fold( $first, '3:100:41' );
		$this->assertNull( $first->idle_since(), 'folded and unsettled' );
		$carry = self::as_stored( $first->save_state() );
		$first->remove_node();

		Core::$now = (float) self::T + 17;
		$successor = $this->builder();
		$successor->restore_state( $carry );
		$this->assertSame( (float) self::T + 17, $successor->idle_since(), 'a restored span alone is idle' );

		self::fold( $successor, '3:200:42' );
		Core::$now = (float) self::T + 29;
		$successor->settle();
		$this->assertSame( (float) self::T + 29, $successor->idle_since() );
	}

	/**
	 * A span holds every URL it folds, however many: 5,003 distinct URLs in
	 * one span each ride the carry, and the settle hands each URL's fold to
	 * the store. It collects no coverage: 5,003 folds under Xdebug's line
	 * coverage outrun the one-second budget, and every line it runs is
	 * covered by the tests beside it.
	 */
	#[CoversNothing]
	public function test_a_span_holds_every_url_it_folds_past_five_thousand(): void {
		$fb    = new Flame_Builder_Node();
		$fb->name( 'fb-kea' );
		$store = new Url_Blob_Counting_Store( ...$this->stats_store_args( self::PARTITION, 86400, $fb ) );
		// @longform A worker's untimed record, the cheapest a fold takes: it
		// reaches its URL's flame and no global total, and no store is wired
		// until the settle, so no cold read runs per URL.
		$record = [ 'is_worker' => true, 'duration_ms' => 0.0 ] + self::record();
		for ( $n = 1; $n <= 5003; $n++ ) {
			$message                   = Message::new_message();
			$message[ Message::TYPE ]  = Message::TM_STRUCT;
			$message[ Message::ID ]    = "3:{$n}:41";
			$message[ Message::VALUE ] = [ 'url' => "/kea-sku-{$n}" ] + $record;
			$fb->fill( $message );
		}
		$fb->set_stats_store( $store );

		$this->assertSame( 5003, \count( $fb->save_state()['span']['urls'] ?? [] ), 'the carry holds every URL' );
		// @longform The buckets' per-URL hour keys are not this test's subject
		// and would spend its budget, so the settle writes the trees alone.
		( new \ReflectionProperty( Flame_Builder_Node::class, 'pending' ) )->setValue( $fb, [] );
		$fb->settle();

		$this->assertSame( 5003, $store->url_blobs, 'the settle writes every URL\'s fold' );
	}

	/**
	 * Past the carry's byte budget a URL blob is left out, counted; the
	 * pending buckets ride whole.
	 */
	public function test_the_carry_leaves_out_the_url_blobs_past_its_budget(): void {
		$fb = $this->builder( 'fb-kea', Small_Carry_Flame_Builder::class );
		self::fold( $fb, '3:100:41', '/kea-41' );
		self::fold( $fb, '3:200:43', '/kea-43' );

		$span = self::as_stored( $fb->save_state() )['span'];
		$fb->save_state();

		$this->assertCount( 1, $span['urls'], 'one blob fits the budget' );
		$this->assertSame( 2, $span['pending'][ Stats_Store::bucket_key( self::T ) ]['hourly']['requests'] ?? null );
		$tally = ( new \ReflectionProperty( Flame_Builder_Node::class, 'tally' ) )->getValue( $fb );
		$this->assertSame( 1, $tally[ \Newspack_Event_Logger_Nodes\Flame_Tree::STATS_WRITES ]['left out of the carry'] ?? null, 'counted once a span, not once a frame' );
	}
}

/** A builder whose carry fits one `/kea-4x` blob, 125 bytes of JSON, and not two. */
class Small_Carry_Flame_Builder extends Flame_Builder_Node {
	protected const CARRY_URL_BYTES = 200;
}

/**
 * A store that counts the URL blobs a settle hands it and lands them without
 * a Table write, so a test of what the settle offers pays for no SQLite.
 */
class Url_Blob_Counting_Store extends Stats_Store {
	/** @var int URL blobs written since the store was built. */
	public int $url_blobs = 0;

	/**
	 * @param array<int,array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes `[ parts, key, data ]`.
	 * @return array<int,bool>
	 */
	public function bucket_set_multi( array $writes ): array {
		$urls             = \array_filter( $writes, static fn ( array $write ): bool => Stats_Store::NS_URL === $write[0][0] );
		$this->url_blobs += \count( $urls );
		return \array_replace( [] === \array_diff_key( $writes, $urls ) ? [] : parent::bucket_set_multi( \array_diff_key( $writes, $urls ) ), \array_fill_keys( \array_keys( $urls ), true ) );
	}
}
