<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

/**
 * What each stats verb answers once two flame-builder partitions have
 * folded known traffic and settled it: the verbs read the Ledgers every
 * partition appended to, through the request graph's read-only mounts.
 */
#[CoversClass( Performance_CI_Node::class )]
class PerformanceStatsTest extends TestCase {

	/** The pinned tick, 37 minutes 40 seconds into its hour. */
	private const T = 1790000400 + 17 * 60 + 40;

	private const SKU_41 = 'https://kea.test/sku-41';
	private const SKU_43 = 'https://kea.test/sku-43';

	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp  = $this->make_temp_dir( 'eln-perf-stats-' );
		Core::$memd = new InMemoryMemcached();
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 6, 'min_lifetime' => 86400 ] );
		$GLOBALS['_current_user_can'] = true;
		$this->activate_shipped( 'performance', 6 );
		Core::$clock = static fn (): float => (float) self::T;
		Core::right_now();

		// Three requests to sku-41 at 37 ms in partition 3, the last a 503 that fataled.
		$p3 = $this->builder( 3 );
		self::fold( $p3, self::SKU_41 );
		self::fold( $p3, self::SKU_41 );
		self::fold( $p3, self::SKU_41, [ 'status_code' => 503, 'error_status' => 'F' ] );
		$p3->settle();
		// Two to sku-43 at 61 ms in partition 5.
		$p5 = $this->builder( 5 );
		self::fold( $p5, self::SKU_43, [ 'duration_ms' => 61.0 ] );
		self::fold( $p5, self::SKU_43, [ 'duration_ms' => 61.0 ] );
		$p5->settle();
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_current_user_can'] = false;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/** A hub builder writing partition `$partition`'s stats. */
	private function builder( int $partition ): Flame_Builder_Node {
		$fb = new Flame_Builder_Node();
		$fb->name( "fb-kea-p{$partition}" );
		$fb->set_is_hub( true );
		$fb->set_stats_store( $this->stats_store( $partition, $fb ) );
		return $fb;
	}

	/** Fill one completed request for `$url`, finished at the tick. */
	private static function fold( Flame_Builder_Node $fb, string $url, array $over = [] ): void {
		$rid                       = 'r' . \bin2hex( \random_bytes( 6 ) );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::ID ]    = "3:{$rid}:41";
		$message[ Message::VALUE ] = $over + [
			'rid'            => $rid,
			'url'            => $url,
			'duration_ms'    => 37.0,
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
		$fb->fill( $message );
	}

	/** Fire one `performance` verb, as the dashboard does. */
	private static function fire( string $verb, array|string $args = [] ): mixed {
		return VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $args );
	}

	public function test_overview_counts_every_partitions_requests_and_the_5xx(): void {
		$reply = self::fire( 'overview', '--breakdown=status' );

		$this->assertIsArray( $reply, (string) \json_encode( $reply ) );
		$this->assertSame( 5, $reply['total_requests'] );
		$this->assertEqualsWithDelta( ( 111.0 + 122.0 ) / 5, $reply['global_avg_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 12.0, $reply['global_avg_peak_mb'], 1e-9 );
		$status = self::wire_series( $reply['breakdowns']['status'] );
		$this->assertSame( [ Stats_Store::bucket_key( self::T ) ], \array_keys( $status ) );
		$this->assertEquals( [ 1, 37.0, 12.0, 1 ], $status[ Stats_Store::bucket_key( self::T ) ]['5xx'] );
		$this->assertSame( 4, $status[ Stats_Store::bucket_key( self::T ) ]['2xx'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_the_chart_draws_288_slots_with_the_tick_s_bucket_holding_the_counts(): void {
		$reply = self::fire( 'overview', '--breakdown=server' );

		$this->assertCount( 288, $reply['slots'] );
		$this->assertSame( Stats_Store::bucket_key( self::T ), $reply['slots'][0], 'newest first' );
		$this->assertSame( Stats_Store::bucket_key( self::T - 287 * 300 ), $reply['slots'][287] );
		$this->assertEquals( [ 5, 233.0, 60.0, 5 ], self::wire_series( $reply['breakdowns']['server'] )[ Stats_Store::bucket_key( self::T ) ]['kea.test'] );
	}

	public function test_the_url_page_ranks_by_count_and_errors_only_keeps_what_errored(): void {
		$page = self::fire( 'urls', '--sort=count --order=desc' );

		$this->assertSame( [ self::SKU_41, self::SKU_43 ], \array_column( $page['data'], 'url' ) );
		$this->assertSame( [ 3, 2 ], \array_column( $page['data'], 'count' ) );
		$this->assertSame( Log_Manager::url_hash( self::SKU_41 ), $page['data'][0]['hash'] );
		$this->assertEqualsWithDelta( 37.0, $page['data'][0]['avg_ms'], 1e-9 );
		$this->assertSame( 2, $page['rows'] );
		$this->assertSame( 5, $page['totals']['requests'] );
		$this->assertSame( 2, $page['totals']['urls'] );
		$this->assertSame( [ self::SKU_43, self::SKU_41 ], \array_column( $page['slowest'], 'url' ), 'slowest by mean duration' );

		$errored = self::fire( 'urls', '--errors_only' );
		$this->assertSame( [ self::SKU_41 ], \array_column( $errored['data'], 'url' ) );
		$this->assertSame( 1, $errored['totals']['errors'] );
	}

	public function test_the_url_page_sorts_by_a_mean_the_ledger_cannot_rank(): void {
		$page = self::fire( 'urls', '--sort=avg_ms --order=desc' );

		$this->assertSame( [ self::SKU_43, self::SKU_41 ], \array_column( $page['data'], 'url' ) );
		$this->assertEqualsWithDelta( 61.0, $page['data'][0]['avg_ms'], 1e-9 );
	}

	public function test_dump_url_answers_a_hash_with_its_stats_and_breakdown(): void {
		$reply = self::fire( 'dump_url', Log_Manager::url_hash( self::SKU_41 ) . ' --breakdown=status' );

		$this->assertIsArray( $reply, (string) \json_encode( $reply ) );
		$this->assertSame( self::SKU_41, $reply['stats']['url'] );
		$this->assertSame( 3, $reply['stats']['count'] );
		$this->assertEqualsWithDelta( 37.0, $reply['stats']['avg_ms'], 1e-9 );
		$this->assertSame( self::T, $reply['stats']['last_updated'] );
		$this->assertEquals( [ 1, 37.0, 12.0, 1 ], self::wire_series( $reply['breakdown_time_series'] )[ Stats_Store::bucket_key( self::T ) ]['5xx'] );
	}

	public function test_url_breakdown_answers_one_urls_series(): void {
		$reply = self::fire( 'url_breakdown', Log_Manager::url_hash( self::SKU_43 ) . ' --breakdown=status' );

		$this->assertEquals( [ 2, 122.0, 24.0, 2 ], self::wire_series( $reply['breakdown_time_series'] )[ Stats_Store::bucket_key( self::T ) ]['2xx'] );
		$this->assertCount( 288, $reply['slots'] );
	}

	public function test_a_hash_no_url_carries_is_not_found(): void {
		$this->assertSame( "URL not found: c0ffee7731ab\n", self::fire( 'dump_url', 'c0ffee7731ab' ) );
	}
}
