<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;

/**
 * What a settle appends: each request the span folded, as one row a
 * measurement in the Ledger that holds it, at its completion's bucket.
 */
#[CoversClass( Flame_Builder_Node::class )]
class FlameBuilderRowsTest extends TestCase {

	/** The pinned tick, on a five-minute boundary. */
	private const T = 1790000400;

	/** The start of the tick's hour, where a name is filed. */
	private const HOUR = self::T - self::T % 3600;

	private const SKU_41 = 'https://kea.test/sku-41';
	private const CRON   = 'https://kea.test/wp-cron.php';

	/** Every APPEND row this test's builder sent, by Ledger then `t k x`. */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		Core::$clock = static fn (): float => (float) self::T;
		Core::right_now();

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb-kea' );
		$fb->set_is_hub( true );
		$fb->set_stats_store( $this->stats_store( 3, $fb ) );
		$profiles = [ 'wpdb' => [ 'time' => 12.5, 'count' => 3, 'ts' => self::T, 'entries' => [ 'SELECT kea' => [ 10.0, 2 ] ] ] ];
		self::fold( $fb, 'r41a', self::SKU_41, [ 'profiles' => $profiles ] );
		self::fold( $fb, 'r41b', self::SKU_41, [ 'profiles' => $profiles ] );
		self::fold( $fb, 'r41c', self::SKU_41, [ 'profiles' => $profiles, 'status_code' => 503, 'error_status' => 'F' ] );
		self::fold( $fb, 'rcron', self::CRON, [ 'is_worker' => true, 'duration_ms' => 41.0 ] );
		$fb->settle();

		foreach ( \array_keys( Stats_Store::LEDGER_COLUMNS ) as $ledger ) {
			foreach ( $this->appends( $ledger ) as $rows ) {
				foreach ( $rows as [ $t, $k, $x, $columns ] ) {
					$this->rows[ $ledger ][ "{$t} {$k} {$x}" ] = $columns;
				}
			}
		}
	}

	/** Fill one completed request for `$url`, finished at the tick, at 37 ms; a null override drops its field. */
	private static function fold( Flame_Builder_Node $fb, string $rid, string $url, array $over = [] ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::ID ]    = "3:{$rid}:41";
		$message[ Message::VALUE ] = \array_filter( $over + [
			'rid'            => $rid,
			'url'            => $url,
			'duration_ms'    => 37.0,
			'status_code'    => 200,
			'error_status'   => '-',
			'peak_mb'        => 12.0,
			'request_method' => 'GET',
			'user_agent'     => 'Kea Bot 7',
			'server_name'    => 'kea.test',
			'is_worker'      => false,
			'timestamp'      => self::T,
			'entries'        => [],
			'profiles'       => [],
		], static fn ( mixed $field ): bool => null !== $field );
		$fb->fill( $message );
	}

	public function test_a_settle_appends_once_to_every_ledger(): void {
		foreach ( \array_keys( Stats_Store::LEDGER_COLUMNS ) as $ledger ) {
			$this->assertCount( 1, $this->appends( $ledger ), $ledger );
		}
	}

	/** Each word of each URL's path files once in the hour, a worker's URL too. */
	public function test_the_word_index_files_each_urls_path_words_in_the_hour(): void {
		$words = \array_keys( $this->rows[ Stats_Store::LEDGER_SEARCH ] );
		\sort( $words );
		$this->assertSame(
			[
				self::HOUR . ' 41 ' . self::SKU_41,
				self::HOUR . ' cron ' . self::CRON,
				self::HOUR . ' php ' . self::CRON,
				self::HOUR . ' sku ' . self::SKU_41,
				self::HOUR . ' wp ' . self::CRON,
			],
			$words
		);
		$this->assertCount( 5, $this->appends( Stats_Store::LEDGER_SEARCH )[0], 'one row a word of a URL' );
	}

	/** Before it appends, a settle reads the servers the names Ledger holds, once a family. */
	public function test_a_settle_reads_the_held_servers_before_it_appends(): void {
		$verbs = \array_column( $this->ledger_asks( Stats_Store::LEDGER_NAMES ), 0 );

		$this->assertSame( [ 'MEMBERS', 'MEMBERS', 'APPEND' ], $verbs );
	}

	public function test_the_site_totals_count_the_readers_alone(): void {
		$this->assertEquals( [ self::T . ' site ' => [ 3, 111.0, 3, 36.0 ] ], $this->rows[ Stats_Store::LEDGER_TOTALS ] );
	}

	public function test_a_url_row_files_under_its_server_and_family(): void {
		$kea = Stats_Store::server_key( 'kea.test' );
		$this->assertEquals(
			[
				self::T . " r:{$kea} " . self::SKU_41 => [ 3, 3, 111.0, 36.0, 2, 0, 0, 1, 1, 37.0, 37.0, 12.0, self::T ],
				self::T . " w:{$kea} " . self::CRON   => [ 1, 1, 41.0, 12.0, 1, 0, 0, 0, 0, 41.0, 41.0, 12.0, self::T ],
			],
			$this->rows[ Stats_Store::LEDGER_URL_ROWS ]
		);
	}

	public function test_the_names_file_each_hash_and_each_family_s_servers_in_the_hour(): void {
		$names = \array_keys( $this->rows[ Stats_Store::LEDGER_NAMES ] );
		\sort( $names );
		$this->assertSame(
			[
				self::HOUR . ' servers:r kea.test',
				self::HOUR . ' servers:w kea.test',
				self::HOUR . ' url:' . Log_Manager::url_hash( self::SKU_41 ) . ' ' . self::SKU_41,
				self::HOUR . ' url:' . Log_Manager::url_hash( self::CRON ) . ' ' . self::CRON,
			],
			$names
		);
	}

	public function test_a_dimension_files_for_the_site_and_per_server_on_a_hub(): void {
		$dims = $this->rows[ Stats_Store::LEDGER_DIMS ];
		$this->assertEquals( [ 2, 74.0, 24.0, 2 ], $dims[ self::T . ' status 2xx' ] );
		$this->assertEquals( [ 1, 37.0, 12.0, 1 ], $dims[ self::T . ' status 5xx' ] );
		$this->assertEquals( [ 3, 111.0, 36.0, 3 ], $dims[ self::T . ' ua Kea Bot 7' ] );
		$this->assertEquals( [ 3, 111.0, 36.0, 3 ], $dims[ self::T . ' server kea.test' ] );
		$this->assertEquals( [ 2, 74.0, 24.0, 2 ], $dims[ self::T . ' ' . Stats_Store::dim_key( 'status', 'kea.test' ) . ' 2xx' ] );
		$this->assertArrayNotHasKey( self::T . ' ' . Stats_Store::dim_key( 'server', 'kea.test' ) . ' kea.test', $dims, 'a server scope repeats no server axis' );
	}

	public function test_a_urls_dimensions_and_categories_file_under_the_url(): void {
		$key = Stats_Store::url_key( self::SKU_41 );
		$this->assertEquals( [ 1, 37.0, 12.0, 1 ], $this->rows[ Stats_Store::LEDGER_URL_DIMS ][ self::T . ' ' . Stats_Store::url_dim_key( 'status', self::SKU_41 ) . ' 5xx' ] );
		$this->assertArrayNotHasKey( self::T . ' ' . Stats_Store::url_dim_key( 'server', self::SKU_41 ) . ' kea.test', $this->rows[ Stats_Store::LEDGER_URL_DIMS ], 'a URL belongs to one server' );
		$this->assertEquals( [ 37.5, 9, 3 ], $this->rows[ Stats_Store::LEDGER_URL_CATS ][ self::T . " {$key} wpdb" ] );
	}

	public function test_the_categories_and_the_leaderboard_file_for_the_site_and_the_server(): void {
		$srv = Stats_Store::server_scope( 'kea.test' );
		$this->assertEquals( [ 37.5, 9, 3 ], $this->rows[ Stats_Store::LEDGER_CATEGORIES ][ self::T . ' site wpdb' ] );
		$this->assertEquals( [ 111.0, 9, 3 ], $this->rows[ Stats_Store::LEDGER_CATEGORIES ][ self::T . ' site total' ] );
		$this->assertEquals( [ 37.5, 9, 3 ], $this->rows[ Stats_Store::LEDGER_CATEGORIES ][ self::T . " {$srv} wpdb" ] );

		$board = $this->rows[ Stats_Store::LEDGER_LEADERBOARD ];
		$this->assertEquals( [ 3, 37.5, 0 ], $board[ self::T . ' site ' ] );
		$this->assertEquals( [ 3, 37.5, 9 ], $board[ self::T . ' site wpdb' ] );
		$this->assertEquals( [ 3, 30.0, 6 ], $board[ self::T . ' site ' . Stats_Store::member( 'wpdb', 'SELECT kea' ) ] );
		$this->assertEquals( [ 3, 37.5, 0 ], $board[ self::T . " {$srv} " ] );
	}

	/**
	 * Each APPEND `$ledger` was sent since the asks were last forgotten.
	 *
	 * @return list<list<array{0:int,1:string,2:string,3:list<int|float>}>>
	 */
	private function appends( string $ledger ): array {
		return \array_values( \array_column( \array_filter( $this->ledger_asks( $ledger ), static fn ( array $ask ): bool => 'APPEND' === $ask[0] ), 1 ) );
	}

	/**
	 * The rows `$ledger` was appended since the asks were last forgotten,
	 * by `t k x`.
	 *
	 * @return array<string,list<int|float>>
	 */
	private function appended( string $ledger ): array {
		$out = [];
		foreach ( $this->appends( $ledger ) as $rows ) {
			foreach ( $rows as [ $t, $k, $x, $columns ] ) {
				$out[ "{$t} {$k} {$x}" ] = $columns;
			}
		}
		return $out;
	}

	/** A hub builder on partition 3, beside setUp's, which filed `kea.test`. */
	private function hub( string $name ): Flame_Builder_Node {
		$fb = new Flame_Builder_Node();
		$fb->name( $name );
		$fb->set_is_hub( true );
		$fb->set_stats_store( $this->stats_store( 3, $fb ) );
		return $fb;
	}

	/**
	 * Past `MAX_SERVER_VALUES` servers, a URL row files under `Other`, so a
	 * Host header cannot mint keys without bound: setUp's `kea.test` and 130
	 * more make three past the cap, and those three land under `Other`.
	 */
	public function test_servers_past_the_cap_file_under_other(): void {
		$this->forget_stats_asks();
		$fb     = $this->hub( 'fb-kea-cap' );
		$over   = [];
		$within = [ 'kea.test' => true ];
		for ( $i = 2; $i <= Stats_Store::MAX_SERVER_VALUES + 3; $i++ ) {
			$server = \sprintf( 'srv-%03d.test', $i );
			self::fold( $fb, "rsrv{$i}", "https://{$server}/p", [ 'server_name' => $server ] );
			if ( $i > Stats_Store::MAX_SERVER_VALUES ) {
				$over[] = "https://{$server}/p";
			} else {
				$within[ $server ] = true;
			}
		}
		$fb->settle();

		$other = Stats_Store::url_rows_key( Stats_Store::OTHER_KEY, false );
		$keys  = [];
		$under = [];
		foreach ( \array_keys( $this->appended( Stats_Store::LEDGER_URL_ROWS ) ) as $row ) {
			[ , $k, $x ] = \explode( ' ', $row, 3 );
			$keys[ $k ]  = true;
			if ( $other === $k ) {
				$under[] = $x;
			}
		}
		$this->assertSame( $over, $under, 'the three past the cap, under Other' );
		$this->assertCount( Stats_Store::MAX_SERVER_VALUES, $keys, '127 of this span\'s own servers, and Other' );
		$servers = [];
		foreach ( \array_keys( $this->appended( Stats_Store::LEDGER_NAMES ) ) as $row ) {
			[ , $k, $x ] = \explode( ' ', $row, 3 );
			if ( Stats_Store::servers_key( false ) === $k ) {
				$servers[ $x ] = true;
			}
		}
		$this->assertSame( [], \array_diff_key( $servers, $within, [ Stats_Store::OTHER_KEY => true ] ), 'no server past the cap is named' );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $servers );
		$other_axis = [ 0, 0.0, 0.0, 0 ];
		foreach ( $this->appends( Stats_Store::LEDGER_DIMS ) as $rows ) {
			foreach ( $rows as [ , $k, $x, $columns ] ) {
				if ( Stats_Store::DIM_SERVER === $k && Stats_Store::OTHER_KEY === $x ) {
					$other_axis = \array_map( static fn ( int|float $sum, int|float $add ): int|float => $sum + $add, $other_axis, $columns );
				}
			}
		}
		$this->assertEquals( [ 3, 111.0, 36.0, 3 ], $other_axis, 'the server axis sums the three under Other' );
	}

	/**
	 * A server the names Ledger already holds keeps its own key once the cap
	 * is full, and a new one files under `Other`, span after span.
	 */
	public function test_a_known_server_keeps_its_key_once_the_cap_is_full(): void {
		$fb = $this->hub( 'fb-kea-full' );
		for ( $i = 2; $i <= Stats_Store::MAX_SERVER_VALUES; $i++ ) {
			$server = \sprintf( 'srv-%03d.test', $i );
			self::fold( $fb, "rfill{$i}", "https://{$server}/p", [ 'server_name' => $server ] );
		}
		$fb->settle();
		$this->forget_stats_asks();

		self::fold( $fb, 'rknown', 'https://srv-005.test/q', [ 'server_name' => 'srv-005.test' ] );
		self::fold( $fb, 'rnew', 'https://srv-777.test/q', [ 'server_name' => 'srv-777.test' ] );
		$fb->settle();

		$rows = $this->appended( Stats_Store::LEDGER_URL_ROWS );
		$this->assertArrayHasKey( self::T . ' ' . Stats_Store::url_rows_key( 'srv-005.test', false ) . ' https://srv-005.test/q', $rows );
		$this->assertArrayHasKey( self::T . ' ' . Stats_Store::url_rows_key( Stats_Store::OTHER_KEY, false ) . ' https://srv-777.test/q', $rows );
		$this->assertCount( 2, $rows );
	}

	/**
	 * A window holding more servers than a reader can name leaves no room:
	 * every server of the span files under `Other`.
	 */
	public function test_a_window_past_the_servers_a_reader_names_files_every_server_under_other(): void {
		$names = [];
		for ( $i = 0; $i <= Stats_Store::SERVERS_READ_MAX; $i++ ) {
			$names[] = [ self::HOUR, Stats_Store::servers_key( true ), \sprintf( 'srv-%03d.test', $i ), [] ];
		}
		$this->stats_store( 3 )->append_span( [ Stats_Store::LEDGER_NAMES => $names ] );
		$this->forget_stats_asks();

		$fb = $this->hub( 'fb-kea-full-window' );
		self::fold( $fb, 'rheld', 'https://srv-005.test/q', [ 'server_name' => 'srv-005.test' ] );
		$fb->settle();

		$this->assertSame( [ self::T . ' ' . Stats_Store::url_rows_key( Stats_Store::OTHER_KEY, false ) . ' https://srv-005.test/q' ], \array_keys( $this->appended( Stats_Store::LEDGER_URL_ROWS ) ) );
	}

	/**
	 * A server whose names were filed in the reader window's oldest, partial
	 * hour still holds its room: its hour starts before the window opens.
	 */
	public function test_a_server_named_in_the_windows_oldest_hour_is_held(): void {
		$oldest = self::HOUR - 24 * Stats_Store::HOUR_SECONDS;
		$names  = [];
		for ( $i = 1; $i <= Stats_Store::MAX_SERVER_VALUES; $i++ ) {
			$names[] = [ $oldest, Stats_Store::servers_key( false ), \sprintf( 'old-%03d.test', $i ), [] ];
		}
		$this->stats_store( 3 )->append_span( [ Stats_Store::LEDGER_NAMES => $names ] );
		self::clock_at( self::HOUR + 37 * 60 + 40 );
		$this->forget_stats_asks();

		$fb = $this->hub( 'fb-kea-oldest' );
		self::fold( $fb, 'rolder', 'https://old-005.test/q', [ 'server_name' => 'old-005.test', 'timestamp' => self::tick() ] );
		self::fold( $fb, 'rnewer', 'https://srv-778.test/q', [ 'server_name' => 'srv-778.test', 'timestamp' => self::tick() ] );
		$fb->settle();

		$keys = \array_map( static fn ( string $row ): string => \explode( ' ', $row, 3 )[1], \array_keys( $this->appended( Stats_Store::LEDGER_URL_ROWS ) ) );
		$this->assertContains( Stats_Store::url_rows_key( 'old-005.test', false ), $keys, 'held, so filed under its own key' );
		$this->assertContains( Stats_Store::url_rows_key( Stats_Store::OTHER_KEY, false ), $keys, 'the window holds 129, so a new one is Other' );
	}

	/** The server field a record carries, or null where it carries none. */
	public static function server_fields(): array {
		return [
			'no server field'      => [ null ],
			'a disagreeing server' => [ 'heron-3301.test' ],
		];
	}

	/** Every per-server row files under the URL's host, whatever the record's server field says. */
	#[DataProvider( 'server_fields' )]
	public function test_a_record_files_under_its_urls_host( ?string $server_name ): void {
		$this->forget_stats_asks();
		$fb = $this->hub( 'fb-kea-tui' );
		self::fold( $fb, 'rtui', 'https://tui-4471.test/x', [ 'server_name' => $server_name ] );
		$fb->settle();

		$url_rows = \array_keys( $this->appended( Stats_Store::LEDGER_URL_ROWS ) );
		$this->assertSame( [ self::T . ' ' . Stats_Store::url_rows_key( 'tui-4471.test', false ) . ' https://tui-4471.test/x' ], $url_rows );
		$this->assertContains( self::HOUR . ' ' . Stats_Store::servers_key( false ) . ' tui-4471.test', \array_keys( $this->appended( Stats_Store::LEDGER_NAMES ) ) );
		$dims = $this->appended( Stats_Store::LEDGER_DIMS );
		$this->assertArrayHasKey( self::T . ' server tui-4471.test', $dims, 'the server axis' );
		$this->assertArrayHasKey( self::T . ' ' . Stats_Store::dim_key( 'status', 'tui-4471.test' ) . ' 2xx', $dims, 'the per-server scope' );
		$this->assertSame( [], \preg_grep( '/heron-3301/', \array_keys( $dims ) ), 'the record\'s own field names nothing' );
	}

	/** A hub and a spoke. */
	public static function roles(): array {
		return [
			'hub'   => [ true ],
			'spoke' => [ false ],
		];
	}

	/**
	 * The server axis files one site row for the reader traffic of each URL
	 * host, on a hub and a spoke alike, and nothing else: no worker's, no
	 * per-server scope's and no URL's.
	 */
	#[DataProvider( 'roles' )]
	public function test_the_server_axis_files_the_readers_host_for_the_site_alone( bool $is_hub ): void {
		$this->forget_stats_asks();
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb-kea-axis' );
		$fb->set_is_hub( $is_hub );
		$fb->set_stats_store( $this->stats_store( 3, $fb ) );
		self::fold( $fb, 'rkaka', 'https://kaka-5519.test/nest', [ 'duration_ms' => 29.0, 'peak_mb' => 7.0 ] );
		self::fold( $fb, 'rkakaw', 'https://kaka-5519.test/wp-cron.php', [ 'is_worker' => true, 'duration_ms' => 53.0 ] );
		$fb->settle();

		$axis = [];
		foreach ( $this->appends( Stats_Store::LEDGER_DIMS ) as $rows ) {
			foreach ( $rows as [ $t, $k, $x, $columns ] ) {
				if ( \str_contains( $k, Stats_Store::DIM_SERVER ) ) {
					$axis[ "{$t} {$k} {$x}" ] = $columns;
				}
			}
		}
		$this->assertEquals( [ self::T . ' server kaka-5519.test' => [ 1, 29.0, 7.0, 1 ] ], $axis );
		$this->assertSame( [], \preg_grep( '/server/', \array_keys( $this->appended( Stats_Store::LEDGER_URL_DIMS ) ) ), 'no URL files a server axis' );
	}

	/** A URL-bucket no timed request reached files its minimum and maximum as not measured. */
	public function test_an_untimed_url_files_no_minimum_or_maximum(): void {
		$this->forget_stats_asks();
		$fb = $this->hub( 'fb-kea-untimed' );
		self::fold( $fb, 'rtimeout', 'https://kea.test/timed-out-4471', [ 'error_status' => 'T', 'duration_ms' => 30000.0 ] );
		$fb->settle();

		$row = $this->appended( Stats_Store::LEDGER_URL_ROWS )[ self::T . ' ' . Stats_Store::url_rows_key( 'kea.test', false ) . ' https://kea.test/timed-out-4471' ] ?? [];
		$this->assertSame( [ 1, 0 ], [ $row[ Stats_Store::ROW_COUNT ] ?? null, $row[ Stats_Store::ROW_TIMED_COUNT ] ?? null ] );
		$this->assertTrue( \array_key_exists( Stats_Store::ROW_MIN_MS, $row ) && null === $row[ Stats_Store::ROW_MIN_MS ], 'no minimum measured' );
		$this->assertTrue( \array_key_exists( Stats_Store::ROW_MAX_MS, $row ) && null === $row[ Stats_Store::ROW_MAX_MS ], 'no maximum measured' );
	}
}
