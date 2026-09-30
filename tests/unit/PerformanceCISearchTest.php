<?php
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

/**
 * A URL search is an index: each settle files every word of each URL it
 * saw under the hour it saw it in `stats:search`, and a search reads the
 * URLs its words name, then the url rows of those URLs alone — never a
 * scope's rows whole.
 */
#[CoversClass( Performance_CI_Node::class )]
#[CoversClass( Stats_Store::class )]
#[CoversClass( Flame_Builder_Node::class )]
class PerformanceCISearchTest extends TestCase {

	/** The hour every test's traffic starts in. */
	private const H = 1790000400 - 1790000400 % Stats_Store::HOUR_SECONDS;

	/** The reply's tick, two hours on and 37 minutes 40 seconds in. */
	private const READ_AT = self::H + 2 * Stats_Store::HOUR_SECONDS + 37 * 60 + 40;

	private const SKU_41  = 'https://kea.test/blog/sku-41';
	private const AISLE_9 = 'https://wren.test/blog/aisle-9';

	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp  = $this->make_temp_dir( 'eln-perf-search-' );
		Core::$memd = new InMemoryMemcached();
		// A one-hour retention: a reply reads 13 buckets, from 25 minutes into the hour before.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 6, 'min_lifetime' => Stats_Store::MIN_RETENTION_SECONDS ] );
		$GLOBALS['_current_user_can'] = true;
		$this->activate_shipped( 'performance', 6 );
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		Core::$clock                      = null;
		Performance_CI_Node::$match_names = false;
		$GLOBALS['_current_user_can']     = false;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/** Pin the clock at `$at`. */
	private static function at( int $at ): void {
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
	}

	/** @var array<int,Flame_Builder_Node> Each partition's one builder, which folds all its traffic. */
	private array $builders = [];

	/** The hub builder writing partition `$partition`'s stats. */
	private function builder( int $partition ): Flame_Builder_Node {
		if ( ! isset( $this->builders[ $partition ] ) ) {
			$fb = new Flame_Builder_Node();
			$fb->name( "fb-search-p{$partition}" );
			$fb->set_is_hub( true );
			$fb->set_stats_store( $this->stats_store( $partition, $fb ) );
			$this->builders[ $partition ] = $fb;
		}
		return $this->builders[ $partition ];
	}

	/** Fold one request for `$url` served by its own host, finished now, and settle it. */
	private function fold( int $partition, string $url, bool $worker = false ): void {
		$fb                        = $this->builder( $partition );
		$rid                       = 'r' . \bin2hex( \random_bytes( 6 ) );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::ID ]    = "{$partition}:{$rid}:43";
		$message[ Message::VALUE ] = [
			'rid'            => $rid,
			'url'            => $url,
			'duration_ms'    => 41.0,
			'status_code'    => 200,
			'error_status'   => '-',
			'peak_mb'        => 9.0,
			'request_method' => 'GET',
			'server_name'    => (string) \parse_url( $url, \PHP_URL_HOST ),
			'is_worker'      => $worker,
			'timestamp'      => (int) Core::$now,
			'entries'        => [],
			'profiles'       => [],
		];
		$fb->fill( $message );
		$fb->settle();
	}

	/** Fire `urls` as the dashboard does. */
	private static function urls( string ...$args ): mixed {
		return VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $args );
	}

	/**
	 * The members every url-rows read named: a search reads its candidates
	 * by key, so each read carries them, and none reads a scope whole.
	 *
	 * @return list<list<string>>
	 */
	private function url_rows_read(): array {
		$xs = [];
		foreach ( $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ) as [ $verb, $query ] ) {
			if ( 'APPEND' === $verb ) {
				continue;
			}
			$this->assertSame( 'SUM', $verb, 'a search pages no TOP' );
			$this->assertArrayHasKey( 'xs', $query, 'a search reads no scope whole' );
			$xs[] = $query['xs'];
		}
		return $xs;
	}

	/** The words a search looked up, in the order it asked. */
	private function words_read(): array {
		$words = [];
		foreach ( $this->ledger_asks( Stats_Store::LEDGER_SEARCH ) as [ $verb, $query ] ) {
			if ( 'MEMBERS' === $verb ) {
				$words[] = $query['k'];
			}
		}
		return $words;
	}

	public function test_a_search_finds_each_url_carrying_every_word_and_reads_only_those(): void {
		self::at( self::READ_AT );
		$this->fold( 3, self::SKU_41 );
		$this->fold( 5, self::AISLE_9 );
		$this->forget_stats_asks();

		$blog = self::urls( '--search=blog', '--sort=url', '--order=asc' );
		$this->assertIsArray( $blog, (string) \json_encode( $blog ) );
		$this->assertSame( [ self::SKU_41, self::AISLE_9 ], \array_column( $blog['data'], 'url' ) );
		$this->assertSame( 2, $blog['rows'] );
		$this->assertSame( 2, $blog['totals']['requests'] );
		$this->assertContains( [ self::SKU_41, self::AISLE_9 ], $this->url_rows_read() );

		$sku = self::urls( '--search=blog sku' );
		$this->assertSame( [ self::SKU_41 ], \array_column( $sku['data'], 'url' ) );
		$this->assertSame( 1, $sku['totals']['requests'] );
	}

	public function test_a_server_scope_keeps_the_urls_of_its_host_before_reading_rows(): void {
		self::at( self::READ_AT );
		$this->fold( 3, self::SKU_41 );
		$this->fold( 5, self::AISLE_9 );
		$this->forget_stats_asks();

		$page = self::urls( '--server=wren.test', '--search=blog' );

		$this->assertSame( [ self::AISLE_9 ], \array_column( $page['data'], 'url' ) );
		$this->assertSame( [ [ self::AISLE_9 ] ], \array_unique( $this->url_rows_read(), \SORT_REGULAR ), 'kea.test\'s URL is never asked for' );
	}

	/** Worker traffic files its words too, so a search that keeps workers finds a job. */
	public function test_worker_traffic_is_searchable_with_the_workers_it_keeps(): void {
		self::at( self::READ_AT );
		$this->fold( 3, 'https://kea.test/jobs/aisle-12', true );

		$this->assertSame( 0, self::urls( '--search=aisle' )['rows'] );
		$this->assertSame( [ 'https://kea.test/jobs/aisle-12' ], \array_column( self::urls( '--search=aisle', '--include_workers=1' )['data'], 'url' ) );
		$this->assertNotEmpty( $this->url_rows_read() );
	}

	/**
	 * A settle files a URL's words in each hour it saw the URL, whatever an
	 * earlier settle filed: the hour the URL was first filed in leaves the
	 * window, and the later filing still finds it.
	 */
	public function test_a_later_hour_refiles_a_url_so_it_outlives_its_first_hour(): void {
		self::at( self::H + 20 * 60 );
		$this->fold( 3, self::SKU_41 );
		self::at( self::H + 2 * Stats_Store::HOUR_SECONDS + 20 * 60 );
		$this->fold( 3, self::SKU_41 );
		self::at( self::READ_AT );

		$page = self::urls( '--search=sku' );

		$this->assertSame( [ self::SKU_41 ], \array_column( $page['data'], 'url' ) );
		$this->assertSame( 1, $page['data'][0]['count'], 'the first hour\'s request is out of the window' );
		$this->assertNotEmpty( $this->url_rows_read() );
	}

	/**
	 * A word is filed at its hour's start, so the read opens on the hour
	 * the window starts inside: a URL seen after the window opened, in that
	 * hour, is found.
	 */
	public function test_a_url_filed_in_the_hour_the_window_opens_inside_is_found(): void {
		self::at( self::H + Stats_Store::HOUR_SECONDS + 36 * 60 );
		$this->fold( 5, self::AISLE_9 );
		self::at( self::READ_AT );

		$this->forget_stats_asks();

		$this->assertSame( [ self::AISLE_9 ], \array_column( self::urls( '--search=aisle' )['data'], 'url' ) );
		$asks = $this->ledger_asks( Stats_Store::LEDGER_SEARCH );
		$this->assertSame( [ [ 'MEMBERS', self::H + Stats_Store::HOUR_SECONDS ] ], \array_map( static fn ( array $ask ): array => [ $ask[0], $ask[1]['from'] ], $asks ) );
	}

	/** The three longest words are looked up; any other is checked against each URL's path. */
	public function test_a_search_looks_up_its_three_longest_words_and_checks_the_rest(): void {
		self::at( self::READ_AT );
		$this->fold( 3, 'https://kea.test/aisle-9/blog/sku-41' );
		$this->fold( 5, 'https://kea.test/aisle-9/blog/sku-43' );
		$this->forget_stats_asks();

		$page = self::urls( '--search=41 sku blog aisle' );

		$this->assertSame( [ 'https://kea.test/aisle-9/blog/sku-41' ], \array_column( $page['data'], 'url' ) );
		$this->assertSame( [ 'aisle', 'blog', 'sku' ], $this->words_read() );
		$this->assertSame( [ Stats_Store::URL_SEARCH_MAX ], \array_values( \array_unique( \array_column( \array_column( $this->ledger_asks( Stats_Store::LEDGER_SEARCH ), 1 ), 'limit' ) ) ) );
	}

	/** A word naming no URL ends the lookup: nothing after it can widen an empty set. */
	public function test_a_word_naming_nothing_ends_the_lookup(): void {
		self::at( self::READ_AT );
		$this->fold( 3, self::SKU_41 );
		$this->forget_stats_asks();

		$page = self::urls( '--search=blog takahe' );

		$this->assertSame( 0, $page['rows'] );
		$this->assertSame( [ 'takahe' ], $this->words_read() );
		$this->assertSame( [], $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ), 'no candidate, no row read' );
	}

	/** An index that does not answer is a provisional page, never a scope read whole. */
	public function test_an_unanswered_lookup_is_a_provisional_empty_page(): void {
		self::at( self::READ_AT );
		$this->fold( 3, self::SKU_41 );
		$this->refuse_stats_reads( '/^stats:search/' );

		$page = self::urls( '--search=blog' );

		$this->assertSame( 0, $page['rows'] );
		$this->assertTrue( $page['provisional'] );
		$this->url_rows_read();
	}

	/**
	 * File `$word` for more URLs than a search reads, each on kea.test, in
	 * the hour the reply reads.
	 */
	private function file_too_many( string $word ): void {
		$rows = [];
		for ( $i = 0; $i <= Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$rows[] = [ self::H + 2 * Stats_Store::HOUR_SECONDS, $word, "https://kea.test/{$word}-{$i}", [] ];
		}
		$this->stats_store( 3 )->append_span( [ Stats_Store::LEDGER_SEARCH => $rows ] );
	}

	/**
	 * A term whose every word files more URLs than a search reads is too
	 * common for the index: refused, naming the limit, while the name scan
	 * is off, and no row is read.
	 */
	public function test_a_term_too_common_for_the_index_is_refused_while_the_name_scan_is_off(): void {
		self::at( self::READ_AT );
		$this->file_too_many( 'aisle' );
		$this->forget_stats_asks();

		$refused = self::urls( '--search=aisle' );

		$this->assertIsString( $refused );
		$this->assertStringContainsString( 'aisle', $refused );
		$this->assertStringContainsString( 'is too common: its URLs run past the ' . Stats_Store::URL_SEARCH_MAX . ' a search reads; add a word', $refused );
		$this->assertSame( [ Stats_Store::URL_SEARCH_MAX ], \array_column( \array_column( $this->ledger_asks( Stats_Store::LEDGER_SEARCH ), 1 ), 'limit' ), 'the word is read up to the limit' );
		$this->assertSame( [], $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ) );
	}

	/** While it is on, the name scan answers a term too common for the index, by its words. */
	public function test_a_term_too_common_for_the_index_falls_to_the_name_scan_while_it_is_on(): void {
		self::at( self::READ_AT );
		$this->file_too_many( 'aisle' );
		$this->fold( 5, self::AISLE_9 );
		$this->fold( 3, 'https://kea.test/aisles-4' );
		Performance_CI_Node::$match_names = true;

		$page = self::urls( '--search=aisle' );

		$this->assertSame( [ self::AISLE_9 ], \array_column( $page['data'], 'url' ), 'whole words, as the index matches' );
	}

	/** A word too common narrows nothing, and the term's other words still do; it is checked on each path. */
	public function test_a_word_too_common_narrows_nothing_and_the_rest_still_narrow(): void {
		self::at( self::READ_AT );
		$this->file_too_many( 'aisle' );
		$this->fold( 5, self::AISLE_9 );
		$this->fold( 3, self::SKU_41 );
		$this->forget_stats_asks();

		$page = self::urls( '--search=aisle blog' );

		$this->assertSame( [ self::AISLE_9 ], \array_column( $page['data'], 'url' ) );
		$this->assertSame( [ 'aisle', 'blog' ], $this->words_read() );
		$this->assertSame( [ [ self::AISLE_9 ] ], \array_unique( $this->url_rows_read(), \SORT_REGULAR ), 'blog named both; the path check kept one' );
	}

	/** A group of words every one too common reads the term's next group. */
	public function test_a_group_of_words_too_common_reads_the_next(): void {
		self::at( self::READ_AT );
		foreach ( [ 'aisle', 'shelf', 'stock' ] as $word ) {
			$this->file_too_many( $word );
		}
		$this->fold( 5, 'https://wren.test/aisle/shelf/stock/sku-43' );
		$this->forget_stats_asks();

		$page = self::urls( '--search=aisle shelf stock sku' );

		$this->assertSame( [ 'https://wren.test/aisle/shelf/stock/sku-43' ], \array_column( $page['data'], 'url' ) );
		$this->assertSame( [ 'aisle', 'shelf', 'stock', 'sku' ], $this->words_read() );
	}

	/**
	 * The read opens on the hour the window starts inside, and no earlier:
	 * a URL seen only in the hour before it is out of the window.
	 */
	public function test_a_url_filed_only_before_the_windows_hour_is_not_found(): void {
		self::at( self::H + 20 * 60 );
		$this->fold( 3, self::SKU_41 );
		self::at( self::READ_AT );
		$this->forget_stats_asks();

		$this->assertSame( 0, self::urls( '--search=sku' )['rows'] );
		$this->assertSame( [ self::H + Stats_Store::HOUR_SECONDS ], \array_column( \array_column( $this->ledger_asks( Stats_Store::LEDGER_SEARCH ), 1 ), 'from' ) );
		$this->assertSame( [], $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ) );
	}

	/** The name scan stays the fallback for a term of no word, reading the scope while it is on. */
	public function test_a_term_of_no_word_falls_to_the_name_scan_alone(): void {
		self::at( self::READ_AT );
		$this->fold( 3, self::SKU_41 );

		$this->assertSame( 0, self::urls( '--search=4' )['rows'], 'no word to look up, and the scan is off' );
		Performance_CI_Node::$match_names = true;
		$this->assertSame( [ self::SKU_41 ], \array_column( self::urls( '--search=-4' )['data'], 'url' ) );
		$this->assertSame( [], $this->words_read() );
	}
}
