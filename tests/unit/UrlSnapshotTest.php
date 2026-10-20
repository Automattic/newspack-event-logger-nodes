<?php
/**
 * UrlSnapshotTest: the URL snapshot the overview-prefetch job publishes.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Stats_Query;
use Newspack_Event_Logger_Nodes\App\Url_Snapshot;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;

/**
 * One file holds every URL of the window, its totals and the stored
 * answers; a page read off it is the page `Stats_Query` answers live.
 */
#[CoversClass( Url_Snapshot::class )]
#[CoversClass( Stats_Query::class )]
final class UrlSnapshotTest extends TestCase {

	use FactsSeeder;

	private const NOW = 1_790_011_903;

	/** A snapshot lifetime that matches no job's interval, so a TTL read elsewhere fails. */
	private const TTL = 613;

	/** The second server the parity traffic files under. */
	private const KAKA = 'kaka-2207.example';

	protected function setUp(): void {
		parent::setUp();
		Core::$clock = static fn (): float => (float) self::NOW;
		Core::right_now();
		$this->activate_shipped( 'flame-builder', 4 );
	}

	protected function tearDown(): void {
		Core::$clock = null;
		parent::tearDown();
	}

	/**
	 * A request in the window, `$age` buckets back, finishing `$second`
	 * seconds into its bucket.
	 *
	 * @param string              $path   The URL's path on its server.
	 * @param int                 $age    Buckets before the tick's.
	 * @param int                 $second Seconds into the bucket.
	 * @param array<string,mixed> $over   Columns by name.
	 * @return array<string,mixed>
	 */
	private static function request( string $path, int $age, int $second, array $over ): array {
		$server = Core::as_string( $over['server'] ?? self::$seed_server );
		$bucket = Bucket::id( self::NOW ) - $age;
		return $over + [
			'url'         => "https://{$server}{$path}",
			'bucket'      => $bucket,
			'finished_at' => (float) ( $bucket * Bucket::SECONDS + $second ),
		];
	}

	/**
	 * Traffic spread over partitions 0, 1 and 3, so every URL's numbers
	 * merge across partitions: two servers, a worker URL, two untimed URLs,
	 * URLs tied on count, a timeout and a fatal, statuses from 2xx to 5xx,
	 * every peak and finish distinct, and one request past the window.
	 */
	private function seed_parity_traffic(): void {
		$kaka = [ 'server' => self::KAKA ];
		$this->seed_requests(
			[
				self::request( '/tui-4410/', 3, 11, [ 'ms' => 40.5, 'peak_mb' => 31.5, 'status' => 200 ] ),
				self::request( '/pukeko-8821/', 7, 12, [ 'ms' => 250.5, 'peak_mb' => 41.25, 'status' => 404 ] ),
				self::request( '/hoiho-6612/', 9, 13, [ 'ms' => null, 'peak_mb' => 17.75, 'status' => 200 ] ),
				self::request( '/jobs/kereru-1190', 2, 14, [ 'ms' => 75.5, 'peak_mb' => 52.5, 'status' => 200, 'worker' => 1 ] ),
				self::request( '/kokako-5530/', 4, 15, $kaka + [ 'ms' => 140.5, 'peak_mb' => 61.25, 'status' => 201 ] ),
				self::request( '/takahe-9045/', 6, 16, $kaka + [ 'ms' => 77.25, 'peak_mb' => 28.75, 'status' => 200 ] ),
				self::request( '/tui-4410/', 300, 17, [ 'ms' => 9000.5, 'peak_mb' => 99.5, 'status' => 200 ] ),
			],
			0
		);
		$this->seed_requests(
			[
				self::request( '/tui-4410/', 5, 21, [ 'ms' => 60.25, 'peak_mb' => 33.25, 'status' => 302 ] ),
				self::request( '/weta-3307/', 1, 22, [ 'ms' => 500.5, 'peak_mb' => 71.5, 'status' => 500, 'error' => 'F' ] ),
				self::request( '/hoiho-6612/', 11, 23, [ 'ms' => null, 'peak_mb' => 19.25, 'status' => 200 ] ),
				self::request( '/kokako-5530/', 8, 24, $kaka + [ 'ms' => 20.5, 'peak_mb' => 26.5, 'status' => 301 ] ),
			],
			1
		);
		$this->seed_requests(
			[
				self::request( '/tui-4410/', 10, 31, [ 'ms' => 12.75, 'peak_mb' => 35.75, 'status' => 200 ] ),
				self::request( '/pukeko-8821/', 12, 32, [ 'ms' => 90.0, 'peak_mb' => 44.5, 'status' => 0, 'error' => 'T' ] ),
				self::request( '/weta-3307/', 13, 33, [ 'ms' => null, 'peak_mb' => 38.25, 'status' => 503 ] ),
				self::request( '/jobs/kereru-1190', 14, 34, [ 'ms' => 81.25, 'peak_mb' => 57.75, 'status' => 200, 'worker' => 1 ] ),
				self::request( '/takahe-9045/', 15, 35, $kaka + [ 'ms' => 66.5, 'peak_mb' => 22.25, 'status' => 410 ] ),
				self::request( '/ruru-5182/', 16, 36, [ 'ms' => null, 'peak_mb' => 47.25, 'status' => 204 ] ),
			],
			3
		);
	}

	/**
	 * The live answer to the question a snapshot page answers.
	 *
	 * @param string              $server  '' for every server.
	 * @param bool                $workers Whether worker URLs count.
	 * @param array<string,mixed> $over    The rest of the spec.
	 * @return array<string,mixed>
	 */
	private static function live( string $server, bool $workers, array $over ): array {
		return Stats_Query::run(
			$over + [
				'source'  => 'requests',
				'filter'  => Performance_CI_Node::scope( $server, $workers ),
				'buckets' => Bucket::last( Flame_Facts_Row_Schema::WINDOW_BUCKETS, self::NOW ),
			]
		);
	}

	/** A snapshot's life counts from the end of its build, so a build's own length never shortens it. */
	public function test_a_snapshot_expires_its_ttl_after_its_build_ends(): void {
		$this->seed_parity_traffic();
		Url_Snapshot::publish(
			self::NOW,
			self::TTL,
			static function (): array {
				Core::$clock = static fn (): float => (float) ( self::NOW + 47 );
				return [];
			}
		);

		$this->assertInstanceOf( Url_Snapshot::class, Url_Snapshot::open( self::NOW + 47 + self::TTL - 1 ), 'still serving at its build\'s end plus its ttl, less a second' );
		$this->assertNull( Url_Snapshot::open( self::NOW + 47 + self::TTL ) );
		$this->assertSame( self::NOW, Url_Snapshot::open( self::NOW + 47 )?->as_of(), 'dated by its run' );
	}

	/**
	 * The handed-in list a published snapshot's answers are asked with.
	 *
	 * @return list<string>|null
	 */
	private static function servers_handed(): ?array {
		$handed = null;
		Url_Snapshot::publish(
			self::NOW,
			self::TTL,
			static function ( array $servers ) use ( &$handed ): array {
				$handed = $servers;
				return [];
			}
		);
		return $handed;
	}

	/** The answers are asked with every server of the window, worker traffic counted, busiest first. */
	public function test_publish_asks_its_answers_with_the_windows_servers_busiest_first(): void {
		$this->seed_request( [ 'server' => 'kea-7040.example', 'url' => 'https://kea-7040.example/a/' ] );
		$this->seed_requests(
			[
				[ 'server' => 'kaka-7040.example', 'url' => 'https://kaka-7040.example/wp-cron.php', 'worker' => 1 ],
				[ 'server' => 'kaka-7040.example', 'url' => 'https://kaka-7040.example/b/' ],
			],
			partition: 1
		);

		$this->assertSame( [ 'kaka-7040.example', 'kea-7040.example' ], self::servers_handed() );
	}

	/** The list names no more servers than a live read's one page. */
	public function test_publish_names_no_more_servers_than_a_live_read(): void {
		$rows = [];
		for ( $i = 0; $i <= Stats_Query::MAX_LIMIT; $i++ ) {
			$rows[] = [ 'server' => "s{$i}-7041.example", 'url' => "https://s{$i}-7041.example/" ];
		}
		$this->seed_requests( $rows );

		$this->assertCount( Stats_Query::MAX_LIMIT, self::servers_handed() ?? [] );
	}

	/**
	 * Assert a published snapshot's page is the live page, and answer it.
	 *
	 * @return list<list<mixed>> The page's rows.
	 */
	private function assert_page_is_live( string $sort, string $order, string $server, bool $workers, int $offset, int $limit ): array {
		$this->seed_parity_traffic();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$snapshot = Url_Snapshot::open( self::NOW );
		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );

		$live = self::live(
			$server,
			$workers,
			[
				'group'   => [ 'url' ],
				'measure' => Performance_CI_Node::URL_MEASURES,
				'sort'    => $sort,
				'order'   => $order,
				'limit'   => $limit,
				'offset'  => $offset,
				'also'    => [
					'sort'  => 'avg_ms',
					'order' => 'desc',
					'limit' => Performance_CI_Node::SLOWEST_ROWS,
				],
			]
		);
		$page = $snapshot->page( $server, $workers, $sort, $order, $offset, $limit );

		$this->assertNotSame( [], $live['rows'], 'the question pages real rows' );
		$this->assertSame( $live['columns'], $page['columns'] );
		$this->assertSame( $live['rows'], $page['rows'] );
		$this->assertSame( $live['also'], $page['also'] );
		$this->assertTrue( $page['available'] );
		return $page['rows'];
	}

	/** Every sort, both orders, every server, both views: the snapshot page is the live page. */
	#[DataProvider( 'questions' )]
	public function test_a_snapshot_page_is_the_live_page( string $sort, string $order, string $server, bool $workers ): void {
		$this->assert_page_is_live( $sort, $order, $server, $workers, 1, 3 );
	}

	/** A nullable sort's tail page ranks its tied nulls as the live page does. */
	#[DataProvider( 'tails' )]
	public function test_a_tail_page_ranks_tied_nulls_as_the_live_page( string $sort, string $order, bool $workers ): void {
		$rows   = $this->assert_page_is_live( $sort, $order, '', $workers, 4, 10 );
		$column = \array_search( $sort, [ 'url', 'url_name', ...Performance_CI_Node::URL_MEASURES ], true );

		$this->assertSame( [ null, null ], \array_slice( \array_column( $rows, (int) $column ), -2 ), 'two tied nulls close the page' );
	}

	/**
	 * Each nullable sort, both orders, both views, every server.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function tails(): array {
		$cases = [];
		foreach ( [ 'avg_ms', 'min_ms', 'max_ms' ] as $sort ) {
			foreach ( [ 'asc', 'desc' ] as $order ) {
				foreach ( [ false, true ] as $workers ) {
					$cases[ "{$sort} {$order} " . ( $workers ? 'workers' : 'no workers' ) ] = [ $sort, $order, $workers ];
				}
			}
		}
		return $cases;
	}

	/**
	 * Every page reads its rows in order off the one index its sort and
	 * scope name: `urls_<sort>` site-wide and `urls_server_<sort>` for a
	 * server, each `_null` ascending on a nullable sort, and `urls_url_name`
	 * in both scopes. No page sorts.
	 */
	#[DataProvider( 'questions' )]
	public function test_a_page_reads_its_sort_and_scopes_index( string $sort, string $order, string $server, bool $workers ): void {
		$this->seed_parity_traffic();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$db = Row_Table_Node::open_snapshot( Url_Snapshot::NAME );
		$this->assertInstanceOf( Row_Reader::class, $db, 'the substrate publishes the snapshot under its name' );
		[ $sql, $binds ] = ( new \ReflectionMethod( Url_Snapshot::class, 'listing' ) )->invoke( null, $server, $workers, $sort, $order );

		$plan = self::plan_text( $db, "{$sql} LIMIT ? OFFSET ?", [ ...$binds, 3, 1 ] );

		$this->assertStringNotContainsString( 'USE TEMP B-TREE', $plan );
		$this->assertMatchesRegularExpression( '/^(SCAN|SEARCH) urls USING INDEX ' . self::index_of( $sort, $order, $server ) . '( |$)/m', $plan );
	}

	/**
	 * The index a page of `$sort` in `$order` over `$server` reads.
	 */
	private static function index_of( string $sort, string $order, string $server ): string {
		if ( 'url_name' === $sort ) {
			return 'urls_url_name';
		}
		$scoped = '' === $server ? 'urls_' : 'urls_server_';
		return $scoped . $sort . ( 'asc' === $order && \in_array( $sort, [ 'avg_ms', 'min_ms', 'max_ms' ], true ) ? '_null' : '' );
	}

	/** `urls` takes its rows in hash order, and `servers` its names in name order. */
	public function test_rowids_rank_urls_by_hash_and_ids_rank_servers_by_name(): void {
		$this->seed_parity_traffic();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$db = Row_Table_Node::open_snapshot( Url_Snapshot::NAME );
		$this->assertInstanceOf( Row_Reader::class, $db );

		$hashes = \array_column( $db->rows( 'SELECT url FROM urls ORDER BY rowid', [] ), 0 );
		$names  = \array_column( $db->rows( 'SELECT name FROM servers ORDER BY id', [] ), 0 );
		$ranked = $hashes;
		\sort( $ranked, \SORT_STRING );

		$this->assertCount( 8, $hashes, 'every URL of the window' );
		$this->assertSame( $ranked, $hashes );
		$this->assertSame( [ self::KAKA, self::$seed_server ], $names );
	}

	/**
	 * A scope the snapshot cannot apply is refused, never ignored.
	 *
	 * @param array<string,list<scalar>> $scope A scope filter.
	 */
	#[DataProvider( 'unmapped_scopes' )]
	public function test_a_scope_the_snapshot_cannot_apply_is_refused( array $scope, string $refusal ): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( $refusal );
		( new \ReflectionMethod( Url_Snapshot::class, 'where' ) )->invoke( null, $scope );
	}

	/**
	 * A field the snapshot maps no column for, and a field naming two values.
	 *
	 * @return array<string,array{0:array<string,list<scalar>>,1:string}>
	 */
	public static function unmapped_scopes(): array {
		return [
			'an unmapped field' => [ [ 'country' => [ 'NZ' ] ], 'the URL snapshot scopes no country' ],
			'two servers'       => [ [ 'server' => [ self::KAKA, 'tui-6103.example' ] ], 'the URL snapshot scopes one server, not 2' ],
		];
	}

	/** Building `urls` sorts nothing: the group emits hash order and the copy keeps it. */
	public function test_building_urls_sorts_nothing(): void {
		$this->seed_parity_traffic();
		$plans = [];
		$steps = [];
		Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			'urls-plan-5528',
			static function ( Row_Reader $db ) use ( &$plans, &$steps ): bool {
				foreach ( ( new \ReflectionMethod( Url_Snapshot::class, 'url_steps' ) )->invoke( null, $db, self::NOW ) as $span => $statements ) {
					$steps[ $span ] = \count( $statements );
					foreach ( $statements as [ $sql, $binds ] ) {
						$plans[ $sql ] = self::plan_text( $db, $sql, $binds );
						$db->rows( $sql, $binds );
					}
				}
				return false;
			}
		);

		$this->assertSame( [ Flame_Tree::SNAPSHOT_GROUP => 1, Flame_Tree::SNAPSHOT_COPY => 4 ], $steps, 'the group; the servers, their names, the copy and the drop' );
		foreach ( $plans as $sql => $plan ) {
			$this->assertStringNotContainsString( 'USE TEMP B-TREE FOR ORDER BY', $plan, $sql );
		}
	}

	/**
	 * The plan of the statement `$method` makes, in a scratch build over
	 * `seed_page_traffic()` that has run the URL steps and `$before`.
	 *
	 * @param string       $method A `Url_Snapshot` method making a statement.
	 * @param list<string> $before `Url_Snapshot` methods whose statements run first.
	 */
	private function built_plan( string $method, array $before = [] ): string {
		$this->seed_page_traffic();
		$plan = Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			'pages-plan-6120',
			static function ( Row_Reader $db ) use ( $method, $before ): string {
				foreach ( ( new \ReflectionMethod( Url_Snapshot::class, 'url_steps' ) )->invoke( null, $db, self::NOW ) as $statements ) {
					foreach ( $statements as [ $sql, $binds ] ) {
						$db->rows( $sql, $binds );
					}
				}
				foreach ( $before as $made ) {
					$db->rows( Core::as_string( ( new \ReflectionMethod( Url_Snapshot::class, $made ) )->invoke( null ) ), [] );
				}
				return self::plan_text( $db, Core::as_string( ( new \ReflectionMethod( Url_Snapshot::class, $method ) )->invoke( null ) ), [] );
			}
		);
		$this->assertIsString( $plan );
		return $plan;
	}

	/** Building `pages` sorts by group alone: its ORDER BY is the order its GROUP BY emits. */
	public function test_building_pages_sorts_once(): void {
		$plan = $this->built_plan( 'pages_table' );

		$this->assertStringContainsString( 'USE TEMP B-TREE FOR GROUP BY', $plan );
		$this->assertStringNotContainsString( 'USE TEMP B-TREE FOR ORDER BY', $plan );
	}

	/** `totals` counts its pages off one read of `pages`, however many views and scopes it holds. */
	public function test_totals_read_pages_once(): void {
		$plan = $this->built_plan( 'totals_table', [ 'pages_table' ] );

		$this->assertSame( 1, \preg_match_all( '/^\s*(SCAN|SEARCH) pages( |$)/m', $plan ), $plan );
		$this->assertSame( 0, \preg_match_all( '/^\s*(SCAN|SEARCH) p( |$)/m', $plan ), $plan );
	}

	/** A server the snapshot does not hold pages no rows. */
	public function test_an_unknown_server_pages_no_rows(): void {
		$this->seed_parity_traffic();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$snapshot = Url_Snapshot::open( self::NOW );
		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );

		$page = $snapshot->page( 'absent-4471.example', true, 'count', 'desc', 0, 50 );

		$this->assertSame( [], $page['rows'] );
		$this->assertSame( [], $page['also'] );
	}

	/**
	 * Each sort a `urls` page takes, both orders, every scope and both views.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public static function questions(): array {
		$cases = [];
		foreach ( [ 'count', 'url_name', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'last_seen' ] as $sort ) {
			foreach ( [ 'asc', 'desc' ] as $order ) {
				foreach ( [ '', self::KAKA, self::$seed_server ] as $server ) {
					foreach ( [ false, true ] as $workers ) {
						$cases[ "{$sort} {$order} '{$server}' " . ( $workers ? 'workers' : 'no workers' ) ] = [ $sort, $order, $server, $workers ];
					}
				}
			}
		}
		return $cases;
	}

	/** The sorts a page takes are `urls`' sorts. */
	public function test_the_parity_sorts_are_the_urls_verbs_sorts(): void {
		$this->assertSame(
			[ 'count', 'url_name', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'last_seen' ],
			\array_values( Performance_CI_Node::URL_SORTS )
		);
	}

	public function test_snapshot_totals_are_the_live_totals(): void {
		$this->seed_parity_traffic();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$snapshot = Url_Snapshot::open( self::NOW );
		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );

		foreach ( [ '', self::KAKA, self::$seed_server, 'absent-4471.example' ] as $server ) {
			foreach ( [ false, true ] as $workers ) {
				$live   = self::live( $server, $workers, [ 'group' => [], 'measure' => [ 'count', 'avg_ms', 'avg_peak_mb', 'urls', 'errors' ] ] );
				$totals = $snapshot->totals( $server, $workers );
				$label  = "'{$server}' " . ( $workers ? 'workers' : 'no workers' );

				$this->assertSame( $live['columns'], $totals['columns'], $label );
				$this->assertSame( $live['rows'], $totals['rows'], $label );
				$this->assertTrue( $totals['available'], $label );
			}
		}
		$this->assertSame( [ [ 0, null, null, 0, 0 ] ], $snapshot->totals( 'absent-4471.example', true )['rows'], 'a server with no URLs answers zeroes' );
	}

	/**
	 * The parity traffic, plus a second shape of `/tui-4410/` in another case
	 * and a worker shape of `/weta-3307/`, the page's newest request, so pages
	 * fold more than one shape and the two views part on one page.
	 */
	private function seed_page_traffic(): void {
		$this->seed_parity_traffic();
		$this->seed_requests(
			[
				self::request( '/Tui-4410/?utm=?', 2, 41, [ 'ms' => 30.25, 'peak_mb' => 29.5, 'status' => 200 ] ),
				self::request( '/weta-3307/?cron=?', 0, 42, [ 'ms' => 610.75, 'peak_mb' => 81.25, 'status' => 200, 'worker' => 1 ] ),
			],
			1
		);
		$this->seed_request( self::request( '/weta-3307/?cron=?', 5, 43, [ 'ms' => 4.5, 'peak_mb' => 12.75, 'status' => 500, 'worker' => 1 ] ), 3 );
	}

	/** The connection to the snapshot published over `seed_page_traffic()`. */
	private function published_pages(): Row_Reader {
		$this->seed_page_traffic();
		return $this->published();
	}

	/**
	 * The clause keeping the `pages` rows of a view and a server, and its
	 * binds: the default view drops `views` 1, Include Workers drops 2.
	 *
	 * @return array{0:string,1:list<scalar>}
	 */
	private static function pages_scope( string $server, bool $workers ): array {
		return '' === $server
			? [ 'views <> ?', [ $workers ? 2 : 1 ] ]
			: [ 'views <> ? AND server_id = ( SELECT id FROM servers WHERE name = ? )', [ $workers ? 2 : 1, $server ] ];
	}

	/** The published snapshot's connection. */
	private function published(): Row_Reader {
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$db = Row_Table_Node::open_snapshot( Url_Snapshot::NAME );
		$this->assertInstanceOf( Row_Reader::class, $db, 'the substrate publishes the snapshot under its name' );
		return $db;
	}

	/** When a request `$age` buckets back, `$second` seconds in, finished. */
	private static function finished( int $age, int $second ): float {
		return (float) ( ( Bucket::id( self::NOW ) - $age ) * Bucket::SECONDS + $second );
	}

	/**
	 * A page with a visitor shape and a worker shape, the worker's the newest
	 * request: Include Workers reads its `views` 1 row of both shapes, the
	 * default view its `views` 2 row of the visitor's.
	 */
	public function test_a_page_carries_its_shapes_totals_per_view(): void {
		$visitor = 'https://' . self::$seed_server . '/Kea-5521/?a=?';
		$rows    = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$rows[] = self::request( '/Kea-5521/?a=?', $i + 1, 10 + $i, [ 'ms' => 20.5 + $i ] );
		}
		$this->seed_requests( $rows );
		$this->seed_requests(
			[
				self::request( '/kea-5521/?b=?', 0, 31, [ 'ms' => 100.25, 'worker' => 1 ] ),
				self::request( '/kea-5521/?b=?', 4, 32, [ 'ms' => 140.75, 'worker' => 1 ] ),
				self::request( '/kea-5521/?b=?', 6, 33, [ 'ms' => 180.5, 'worker' => 1 ] ),
			],
			2
		);
		$db   = $this->published();
		$page = self::$seed_server . '/kea-5521/';

		$this->assertSame(
			[
				[ 1, $page, 10, 1, self::finished( 0, 31 ), 2, null ],
				[ 2, $page, 7, 0, self::finished( 1, 10 ), 1, Log_Manager::url_hash( $visitor ) ],
			],
			$db->rows( 'SELECT views, page, count, worker, last_seen, shapes, hash FROM pages WHERE page_hash = ? ORDER BY views', [ Log_Manager::url_hash( $page ) ] )
		);
	}

	/** A page of visitor shapes alone is one `views` 0 row, a page of worker shapes alone one `views` 1 row. */
	public function test_a_page_of_one_kind_is_one_row(): void {
		$this->seed_requests(
			[
				self::request( '/Moa-1184/?x=?', 2, 14, [ 'ms' => 33.5 ] ),
				self::request( '/moa-1184/', 3, 15, [ 'ms' => 44.25 ] ),
				self::request( '/jobs/huia-2290', 4, 16, [ 'ms' => 55.75, 'worker' => 1 ] ),
			]
		);
		$db  = $this->published();
		$row = static fn ( string $page ): array => $db->rows( 'SELECT views, count, worker, shapes FROM pages WHERE page_hash = ?', [ Log_Manager::url_hash( self::$seed_server . $page ) ] );

		$this->assertSame( [ [ 0, 2, 0, 2 ] ], $row( '/moa-1184/' ) );
		$this->assertSame( [ [ 1, 1, 1, 1 ] ], $row( '/jobs/huia-2290' ) );
	}

	/** A page of one shape names that shape's URL by its hash. */
	public function test_a_one_shape_page_names_its_url(): void {
		$url = 'https://' . self::$seed_server . '/Ruru-7302/?q=?';
		$this->seed_request( self::request( '/Ruru-7302/?q=?', 3, 19, [ 'ms' => 61.5 ] ) );
		$db = $this->published();

		$this->assertSame(
			[ [ 0, 1, Log_Manager::url_hash( $url ) ] ],
			$db->rows( 'SELECT views, shapes, hash FROM pages WHERE page_hash = ?', [ Log_Manager::url_hash( self::$seed_server . '/ruru-7302/' ) ] )
		);
	}

	/**
	 * Every view and scope: each page's row is the live `page` group's, every
	 * URL measure folded exactly, and its shapes are the URLs it groups.
	 */
	public function test_pages_are_the_live_page_group(): void {
		$db = $this->published_pages();

		foreach ( [ '', self::KAKA, self::$seed_server, 'absent-4471.example' ] as $server ) {
			foreach ( [ false, true ] as $workers ) {
				[ $where, $binds ] = self::pages_scope( $server, $workers );
				$label             = "'{$server}' " . ( $workers ? 'workers' : 'no workers' );
				$live              = self::live( $server, $workers, [ 'group' => [ 'page' ], 'measure' => Performance_CI_Node::URL_MEASURES, 'sort' => 'page' ] );
				$shapes            = self::live( $server, $workers, [ 'group' => [ 'page' ], 'measure' => [ 'urls' ], 'sort' => 'page' ] );

				$this->assertSame( $live['rows'], $db->rows( 'SELECT page_hash, page, ' . \implode( ', ', Performance_CI_Node::URL_MEASURES ) . " FROM pages WHERE {$where} ORDER BY page_hash", $binds ), $label );
				$this->assertSame( $shapes['rows'], $db->rows( "SELECT page_hash, page, shapes FROM pages WHERE {$where} ORDER BY page_hash", $binds ), $label );
			}
		}
		$this->assertContains( [ 2 ], $db->rows( 'SELECT shapes FROM pages WHERE views = 0', [] ), 'a page folds two visitor shapes' );
		$this->assertSame( [ [ 1 ], [ 2 ] ], $db->rows( 'SELECT views FROM pages WHERE page_hash = ? ORDER BY views', [ Log_Manager::url_hash( self::$seed_server . '/weta-3307/' ) ] ), 'the mixed page holds both rows' );
	}

	/** `pages` takes its rows in hash order, so a rowid breaks a tie as the hash does. */
	public function test_pages_rowids_rank_by_hash(): void {
		$db     = $this->published_pages();
		$rows   = $db->rows( 'SELECT page_hash, views FROM pages ORDER BY rowid', [] );
		$ranked = $rows;
		\usort( $ranked, static fn ( array $a, array $b ): int => \strcmp( Core::as_string( $a[0] ), Core::as_string( $b[0] ) ) ?: $a[1] <=> $b[1] );

		$this->assertCount( 9, $rows, 'six visitor pages, the worker page and both rows of the mixed one' );
		$this->assertSame( $ranked, $rows );
	}

	/**
	 * Every page of `pages` a view and scope keep reads its rows in order off
	 * the index its sort and scope name, `pages_` where `urls` reads `urls_`,
	 * its view a filter on the scan as a `urls` view is.
	 */
	#[DataProvider( 'questions' )]
	public function test_a_pages_listing_reads_its_sort_and_scopes_index( string $sort, string $order, string $server, bool $workers ): void {
		$db                = $this->published_pages();
		[ $where, $binds ] = self::pages_scope( $server, $workers );
		$sorted            = 'url_name' === $sort ? [ 'server_id', 'page' ] : [ $sort ];
		$ranking           = Stats_Query::ranking( $sorted, [ 'rowid' ], $order, \in_array( $sort, [ 'avg_ms', 'min_ms', 'max_ms' ], true ) );

		$plan = self::plan_text( $db, "SELECT page_hash FROM pages WHERE {$where} ORDER BY {$ranking} LIMIT ? OFFSET ?", [ ...$binds, 3, 1 ] );

		$this->assertStringNotContainsString( 'USE TEMP B-TREE', $plan );
		$this->assertMatchesRegularExpression( '/^(SCAN|SEARCH) pages USING INDEX pages_' . \substr( self::index_of( $sort, $order, $server ), \strlen( 'urls_' ) ) . '( |$)/m', $plan );
	}

	/** Each `urls` row carries its page, and a page's shapes seek `urls_page` in either view. */
	public function test_a_pages_shapes_seek_urls_page(): void {
		$db   = $this->published_pages();
		$page = self::$seed_server . '/tui-4410/';
		$tui  = [ Log_Manager::url_hash( 'https://' . self::$seed_server . '/Tui-4410/?utm=?' ), Log_Manager::url_hash( 'https://' . self::$seed_server . '/tui-4410/' ) ];
		\sort( $tui, \SORT_STRING );

		$this->assertSame( [ [ $tui[0], $page ], [ $tui[1], $page ] ], $db->rows( 'SELECT url, page FROM urls WHERE page_hash = ? ORDER BY url', [ Log_Manager::url_hash( $page ) ] ) );
		$this->assertStringContainsString( 'SEARCH urls USING INDEX urls_page (page_hash=? AND worker=?)', self::plan_text( $db, 'SELECT url FROM urls WHERE page_hash = ? AND worker = ?', [ Log_Manager::url_hash( $page ), 0 ] ) );
		$this->assertStringContainsString( 'SEARCH urls USING INDEX urls_page (page_hash=?)', self::plan_text( $db, 'SELECT url FROM urls WHERE page_hash = ?', [ Log_Manager::url_hash( $page ) ] ) );
	}

	/** Each totals row counts the pages its view and scope keep. */
	public function test_totals_count_the_pages_each_view_and_scope_keeps(): void {
		$db = $this->published_pages();

		foreach ( [ '', self::KAKA, self::$seed_server ] as $server ) {
			foreach ( [ false, true ] as $workers ) {
				$live   = self::live( $server, $workers, [ 'group' => [ 'page' ], 'measure' => [ 'count' ] ] );
				$scoped = '' === $server ? [ '0', [] ] : [ '( SELECT id FROM servers WHERE name = ? )', [ $server ] ];

				$this->assertSame( [ [ \count( $live['rows'] ) ] ], $db->rows( "SELECT pages FROM totals WHERE workers = ? AND server_id = {$scoped[0]}", [ $workers ? 1 : 0, ...$scoped[1] ] ), "'{$server}' " . ( $workers ? 'workers' : 'no workers' ) );
			}
		}
		$this->assertSame( [ [ 0, 7 ], [ 1, 8 ] ], $db->rows( 'SELECT workers, pages FROM totals WHERE server_id = 0 ORDER BY workers', [] ) );
	}

	public function test_a_published_snapshot_answers_its_stored_answers(): void {
		$this->seed_request();
		$stored = [
			'available' => true,
			'rows'      => [ [ 9 ] ],
		];
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [ 'query:6f1e' => $stored ] );

		$snapshot = Url_Snapshot::open( self::NOW );

		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );
		$this->assertSame( $stored, $snapshot->answer( 'query:6f1e' ) );
		$this->assertNull( $snapshot->answer( 'query:absent' ) );
		$this->assertSame( self::NOW, $snapshot->as_of() );
	}

	public function test_a_snapshot_past_its_expiry_does_not_open(): void {
		$this->seed_request();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );

		$this->assertNull( Url_Snapshot::open( self::NOW + self::TTL ) );
		$this->assertInstanceOf( Url_Snapshot::class, Url_Snapshot::open( self::NOW + self::TTL - 1 ) );
	}

	public function test_no_snapshot_does_not_open(): void {
		$this->assertFalse( self::snapshot_published() );
		$this->assertNull( Url_Snapshot::open( self::NOW ) );
	}

	public function test_a_file_that_is_not_a_database_does_not_open(): void {
		$this->seed_request();
		\file_put_contents( $this->snapshot_file(), 'kiwi-9931' );

		$this->assertNull( Url_Snapshot::open( self::NOW ) );
	}

	/**
	 * A published file with no meta table, or an empty one, does not open.
	 *
	 * @return array<string,array{0:list<string>}>
	 */
	public static function meta_less(): array {
		return [
			'no meta table' => [ [] ],
			'no meta row'   => [ [ 'CREATE TABLE meta ( as_of INTEGER NOT NULL, expires INTEGER NOT NULL )' ] ],
		];
	}

	/**
	 * @param list<string> $statements What the build writes.
	 */
	#[DataProvider( 'meta_less' )]
	public function test_a_snapshot_with_no_meta_does_not_open( array $statements ): void {
		$this->seed_request();
		Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			Url_Snapshot::NAME,
			static function ( Row_Reader $db ) use ( $statements ): bool {
				foreach ( $statements as $sql ) {
					$db->rows( $sql, [] );
				}
				return true;
			}
		);
		$this->assertTrue( self::snapshot_published() );

		$this->assertNull( Url_Snapshot::open( self::NOW ) );
	}

	/**
	 * What a publish under a started record writes, and what it threw.
	 *
	 * @param array<string,array<string,mixed>> $answers The stored answers.
	 * @param bool                              $seeded  Whether a partition file holds a request.
	 * @return array{0:list<array<string,mixed>>, 1:\Throwable|null}
	 */
	private function logged_publish( array $answers, bool $seeded = true ): array {
		$thrown  = null;
		$entries = $this->logged_in(
			$this->make_temp_dir(),
			function () use ( $answers, $seeded, &$thrown ): void {
				if ( $seeded ) {
					$this->seed_request( self::request( '/tieke-7716/', 3, 11, [ 'ms' => 40.5 ] ) );
				}
				try {
					Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => $answers );
				} catch ( \Throwable $e ) {
					$thrown = $e;
				}
			}
		);
		return [ $entries, $thrown ];
	}

	/**
	 * Each snapshot span line among `$entries`, in order, as its category,
	 * `l` and `m`.
	 *
	 * @param list<array<string,mixed>> $entries Firehose entries.
	 * @return list<array{0:mixed,1:mixed,2:mixed}>
	 */
	private static function snapshot_spans( array $entries ): array {
		return self::spans_of( $entries, Flame_Tree::SNAPSHOT_BUILD, Flame_Tree::SNAPSHOT_GROUP, Flame_Tree::SNAPSHOT_COPY, Flame_Tree::SNAPSHOT_PAGES, Flame_Tree::SNAPSHOT_INDEX, Flame_Tree::SNAPSHOT_TOTALS );
	}

	/** The index names `indexes()` declares, as the file names them. */
	private static function index_names(): array {
		return \array_keys( ( new \ReflectionMethod( Url_Snapshot::class, 'indexes' ) )->invoke( null ) );
	}

	/** The build is one span on the job's record, each step a span inside it, each index labelled. */
	public function test_a_logged_publish_spans_the_build_and_each_step(): void {
		[ $entries, $thrown ] = $this->logged_publish( [] );

		$step    = static fn ( string $span, ?string $l = null ): array => [ [ "{$span} (start)", $l, null ], [ "{$span} (complete)", null, 'ok' ] ];
		$indexes = [];
		foreach ( self::index_names() as $name ) {
			\array_push( $indexes, ...$step( 'snapshot index', $name ) );
		}
		$this->assertNull( $thrown );
		$this->assertContains( [ 'snapshot index (start)', 'urls_server_avg_ms_null', null ], $indexes );
		$this->assertContains( [ 'snapshot index (start)', 'pages_server_avg_ms_null', null ], $indexes );
		$this->assertContains( [ 'snapshot index (start)', 'urls_page', null ], $indexes );
		$this->assertSame(
			[
				[ 'snapshot build (start)', null, null ],
				...$step( 'snapshot group' ),
				...$step( 'snapshot copy' ),
				...$step( 'snapshot pages' ),
				...$indexes,
				...$step( 'snapshot totals' ),
				[ 'snapshot build (complete)', null, 'published' ],
			],
			self::snapshot_spans( $entries )
		);
		$this->assertTrue( self::snapshot_published() );
	}

	/** Folded into a flame, every index stays its own node under the build. */
	public function test_each_index_folds_into_its_own_node(): void {
		[ $entries ] = $this->logged_publish( [] );
		$names       = OverviewPrefetchTest::flame_names( Flame_Tree::build_flame_data( $entries ) );

		$this->assertSame(
			\array_map( static fn ( string $name ): string => Flame_Tree::SNAPSHOT_INDEX . ": {$name}", self::index_names() ),
			\array_values( \array_filter( $names, static fn ( string $name ): bool => \str_starts_with( $name, Flame_Tree::SNAPSHOT_INDEX ) ) )
		);
	}

	/** A build that throws closes its span with what it threw, and leaves no publish. */
	public function test_a_logged_build_that_throws_closes_its_span(): void {
		[ $entries, $thrown ] = $this->logged_publish( [ 'query:7716' => [ 'mean' => \NAN ] ] );
		$spans                = self::snapshot_spans( $entries );

		$this->assertInstanceOf( \RuntimeException::class, $thrown );
		$this->assertSame( [ 'snapshot build (start)', null, null ], $spans[0] );
		$this->assertSame( [ 'snapshot build (complete)', null, 'RuntimeException: overview prefetch: answer query:7716 will not encode' ], $spans[ \count( $spans ) - 1 ] );
		$this->assertFalse( self::snapshot_published() );
	}

	/** A build with no partition file to attach says it published nothing. */
	public function test_a_logged_build_with_no_partition_file_says_so(): void {
		[ $entries, $thrown ] = $this->logged_publish( [], false );

		$this->assertNull( $thrown );
		$this->assertSame( [ [ 'snapshot build (start)', null, null ], [ 'snapshot build (complete)', null, 'no partition file' ] ], self::snapshot_spans( $entries ) );
	}

	public function test_a_fleet_declaring_no_facts_table_refuses_to_publish(): void {
		$this->activate_shipped( 'job-router', 1 );

		$this->expectException( Table_Unavailable::class );
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
	}

	public function test_a_build_that_throws_leaves_no_file(): void {
		$this->seed_request( self::request( '/tui-4410/', 3, 11, [ 'ms' => 40.5 ] ) );

		try {
			Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [ 'query:3c9a' => [ 'mean' => \NAN ] ] );
			$this->fail( 'an answer that will not encode publishes nothing' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'query:3c9a will not encode', $e->getMessage() );
		}

		$this->assertFalse( self::snapshot_published() );
		$this->assertSame( [], Row_Table_Node::remove_snapshot( Url_Snapshot::NAME ), 'no publish and no build stands' );
	}

	public function test_no_partition_file_publishes_nothing(): void {
		$tables = \dirname( $this->facts_file( 0 ) );
		$this->assertDirectoryDoesNotExist( $tables );
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$this->assertDirectoryDoesNotExist( $tables, 'publishing nothing makes no directory' );

		\mkdir( $tables, 0777, true );
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );

		$this->assertFalse( self::snapshot_published() );
		$this->assertSame( [], Row_Table_Node::remove_snapshot( Url_Snapshot::NAME ), 'no publish and no build stands' );
	}

	/** An empty window is still a snapshot: no URLs, zero totals. */
	public function test_a_window_with_no_requests_publishes_an_empty_snapshot(): void {
		$this->seed_request( self::request( '/tui-4410/', 400, 41, [ 'ms' => 12.5 ] ) );

		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$snapshot = Url_Snapshot::open( self::NOW );

		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );
		$this->assertSame( [], $snapshot->page( '', true, 'count', 'desc', 0, 50 )['rows'] );
		$this->assertSame( [ [ 0, null, null, 0, 0 ] ], $snapshot->totals( '', true )['rows'] );
	}

	public function test_a_publish_leaves_no_build_file_and_replaces_the_last(): void {
		$this->seed_request( self::request( '/tui-4410/', 3, 11, [ 'ms' => 40.5 ] ) );
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$this->seed_request( self::request( '/moa-6638/', 1, 51, [ 'ms' => 18.5 ] ), 1 );

		Url_Snapshot::publish( self::NOW + 120, self::TTL, static fn (): array => [] );
		$snapshot = Url_Snapshot::open( self::NOW + 120 );

		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );
		$this->assertSame( self::NOW + 120, $snapshot->as_of() );
		$this->assertSame(
			[ 'https://seed.example/moa-6638/', 'https://seed.example/tui-4410/' ],
			\array_column( $snapshot->page( '', false, 'url_name', 'asc', 0, 50 )['rows'], 1 )
		);
		$this->assertSame( [ $this->snapshot_file() ], Row_Table_Node::remove_snapshot( Url_Snapshot::NAME ), 'the publish alone stands' );
	}

	/** A page takes the bounds a live page takes. */
	#[DataProvider( 'out_of_bounds' )]
	public function test_a_page_refuses_a_bound_the_live_page_refuses( int $offset, int $limit ): void {
		$this->seed_request();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$snapshot = Url_Snapshot::open( self::NOW );
		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );

		$this->expectException( \InvalidArgumentException::class );
		$snapshot->page( '', false, 'count', 'desc', $offset, $limit );
	}

	/**
	 * An offset and a limit just past each bound.
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function out_of_bounds(): array {
		return [
			'no rows'         => [ 0, 0 ],
			'every row'       => [ 0, -1 ],
			'too many rows'   => [ 0, Stats_Query::MAX_LIMIT + 1 ],
			'before the start' => [ -1, 50 ],
			'too far'         => [ Stats_Query::MAX_OFFSET + 1, 50 ],
		];
	}

	public function test_a_page_refuses_a_sort_urls_does_not_take(): void {
		$this->seed_request();
		Url_Snapshot::publish( self::NOW, self::TTL, static fn (): array => [] );
		$snapshot = Url_Snapshot::open( self::NOW );
		$this->assertInstanceOf( Url_Snapshot::class, $snapshot );

		$this->expectException( \InvalidArgumentException::class );
		$snapshot->page( '', false, 'sum_ms', 'desc', 0, 50 );
	}

	/**
	 * A spec `into_statement()` refuses, and the refusal it names.
	 *
	 * @param array<string,mixed> $over The spec's top or also.
	 */
	#[DataProvider( 'paged_specs' )]
	public function test_into_refuses_a_top_or_an_also( array $over ): void {
		$this->seed_request();

		$this->expectExceptionMessage( 'into stores whole groups: no top and no also' );
		$this->into( $over + [ 'group' => [ 'url' ] ] );
	}

	/**
	 * A top and an also.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function paged_specs(): array {
		return [
			'also' => [ [ 'also' => [ 'sort' => 'count', 'order' => 'desc', 'limit' => 5 ] ] ],
			'top'  => [
				[
					'group'  => [ 'bucket', 'url' ],
					'top'    => 3,
					'top_by' => 'count',
				],
			],
		];
	}

	/**
	 * `into_statement()` stores a page group as its hash `page` and its
	 * `page_name`, one row however many partitions hold its URLs, and
	 * `named()` reads that layout back as an answer's columns.
	 */
	public function test_into_stores_a_page_as_its_hash_and_its_name(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/Hoiho-7718/?a=?' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/hoiho-7718/' ], partition: 2 );
		$spec = self::count_spec( [ 'group' => [ 'page' ] ] );

		$stored = Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			'into-scratch-7718',
			static function ( Row_Reader $db ) use ( $spec ): array {
				$db->rows( ...Stats_Query::into_statement( $db, 'pages', $spec ) );
				return [ \array_column( $db->rows( 'PRAGMA table_info( pages )', [] ), 1 ), $db->rows( 'SELECT * FROM pages', [] ) ];
			}
		);

		$page = 'seed.example/hoiho-7718/';
		$this->assertSame( [ 'page', 'page_name', 'count' ], $stored[0] );
		$this->assertSame( [ [ Log_Manager::url_hash( $page ), $page, 2 ] ], Stats_Query::named( [ 'page' ], $stored[1] ) );
	}

	public function test_into_refuses_a_builder_attaching_no_partition(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'into reads at least one partition' );
		Stats_Query::into_statement( new Row_Reader( new \PDO( 'sqlite::memory:' ), [] ), 'urls', self::count_spec( [ 'group' => [ 'url' ] ] ) );
	}

	/**
	 * `into_statement()` orders the stored groups by a group field alone,
	 * the order its GROUP BY already emits.
	 *
	 * @param array<string,mixed> $over The spec's group and sort.
	 */
	#[DataProvider( 'unstorable_sorts' )]
	public function test_into_refuses_a_sort_naming_no_group_field( array $over ): void {
		$this->seed_request();

		$this->expectExceptionMessage( 'into orders by a group field' );
		$this->into( $over );
	}

	/**
	 * A sort by a measure and a sort by a URL's name.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function unstorable_sorts(): array {
		return [
			'a measure'  => [ [ 'group' => [ 'url' ], 'sort' => 'count' ] ],
			'a url name' => [ [ 'group' => [ 'url' ], 'sort' => 'url_name' ] ],
		];
	}

	/**
	 * Ask `Stats_Query::into_statement()` for a `requests` count spec in a
	 * scratch build.
	 *
	 * @param array<string,mixed> $over The spec's group and paging.
	 */
	private function into( array $over ): void {
		$spec = self::count_spec( $over );
		Row_Table_Node::build( Flame_Facts_Row_Schema::class, 'into-scratch-7718', static fn ( Row_Reader $db ) => Stats_Query::into_statement( $db, 'urls', $spec ) );
	}

	/**
	 * A `requests` count spec over the window.
	 *
	 * @param array<string,mixed> $over The spec's group and paging.
	 * @return array<string,mixed>
	 */
	private static function count_spec( array $over ): array {
		return $over + [
			'source'  => 'requests',
			'filter'  => [],
			'measure' => [ 'count' ],
			'buckets' => Bucket::last( Flame_Facts_Row_Schema::WINDOW_BUCKETS, self::NOW ),
		];
	}
}
