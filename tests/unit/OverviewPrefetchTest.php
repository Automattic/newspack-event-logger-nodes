<?php
/**
 * OverviewPrefetchTest: the overview-prefetch job and the verbs it serves.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Overview_Prefetch;
use Newspack_Event_Logger_Nodes\App\Url_Snapshot;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Job_Worker_Node;
use Newspack_Nodes\Jobstats_Record;
use Newspack_Nodes\Message;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;

/**
 * The job publishes the URL snapshot, with the four other answers a default
 * Overview poll asks; `urls` serves any page of it and the others their
 * stored answer until it expires, and every other question answers live.
 */
#[CoversClass( Overview_Prefetch::class )]
#[CoversClass( Performance_CI_Node::class )]
final class OverviewPrefetchTest extends TestCase {

	use FactsSeeder;

	private const NOW = 1_790_007_215;

	/** An interval no default names, so a TTL read from anywhere else fails. */
	private const INTERVAL = 47;

	/** A snapshot's life: four of its job's intervals. */
	private const TTL = 4 * self::INTERVAL;

	protected function setUp(): void {
		parent::setUp();
		Core::$clock = static fn (): float => (float) self::NOW;
		Core::right_now();
		Core::$var['partition'] = '0';
		$GLOBALS['_current_user_can'] = true;
		$this->use_scratch_config( $this->make_temp_dir( 'overview-prefetch-' ) );
		$this->activate_shipped( 'flame-builder', 3 );
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		Core::$clock = null;
		unset( $GLOBALS['_current_user_can'] );
		parent::tearDown();
	}

	/**
	 * The `urls` verb's reply to `$tokens`, through the request graph.
	 *
	 * @param list<string> $tokens The verb's tokens.
	 * @return array<string,mixed>
	 */
	private function urls( array $tokens ): array {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $tokens );
		$this->assertIsArray( $reply, \is_string( $reply ) ? $reply : 'urls answered no structure' );
		return $reply;
	}

	/**
	 * `overview`'s args bound from `$tokens`, as the verb binds them.
	 *
	 * @param list<string> $tokens The verb's tokens.
	 * @return array<array-key,mixed>
	 */
	private static function overview_args( array $tokens ): array {
		return Command_Args::bind( Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class )['overview']['args'], $tokens );
	}

	/**
	 * Publish a snapshot holding only `$answers`, each its raw stored value
	 * by key, dated as a job run at `NOW` dates it: no URL table, so only
	 * the stored answers read.
	 *
	 * @param array<string,string> $answers Raw stored values by key.
	 */
	private static function snapshot_of( array $answers ): void {
		Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			Url_Snapshot::NAME,
			static function ( Row_Reader $db ) use ( $answers ): bool {
				$db->rows( 'CREATE TABLE answers ( "key" TEXT PRIMARY KEY, value TEXT NOT NULL )', [] );
				foreach ( $answers as $key => $value ) {
					$db->rows( 'INSERT INTO answers ( "key", value ) VALUES ( ?, ? )', [ $key, $value ] );
				}
				$db->rows( 'CREATE TABLE meta ( as_of INTEGER NOT NULL, expires INTEGER NOT NULL )', [] );
				$db->rows( 'INSERT INTO meta VALUES ( ?, ? )', [ self::NOW, self::NOW + self::TTL ] );
				return true;
			}
		);
	}

	/**
	 * A stored question's tokens as a poll scoped to `$server` sends them;
	 * a question naming no server comes back as stored.
	 *
	 * @param string $name   The question's name in `stored-answers.json`.
	 * @param string $server The server the poll scopes to.
	 * @return list<string>
	 */
	private static function asked_for( string $name, string $server ): array {
		return \str_replace( Overview_Prefetch::SERVER, $server, Overview_Prefetch::questions()[ $name ]['tokens'] );
	}

	/** Run the job as the tick queues it now. */
	private function run_job( int $queued_at = self::NOW ): void {
		Overview_Prefetch::run( '', [ 'queued_at' => $queued_at, 'interval' => self::INTERVAL ] );
	}

	/** Move the clock to `$at`, inside the bucket the job ran in. */
	private static function clock( int $at ): void {
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
	}

	/**
	 * Every firehose entry a logged run of the job writes.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function logged_job(): array {
		return $this->logged_in(
			$this->make_temp_dir(),
			function (): void {
				$this->seed_request( [ 'url' => 'https://seed.example/kakariki-5543/', 'ms' => 21.5 ] );
				$this->run_job();
			}
		);
	}

	/** A stop falling due while the job runs waits for its publish, then raises at the next pump. */
	public function test_a_stop_due_mid_job_waits_for_the_publish(): void {
		$stop    = null;
		$entries = $this->logged_in(
			$this->make_temp_dir(),
			function () use ( &$stop ): void {
				$this->seed_request( [ 'url' => 'https://seed.example/tieke-5561/', 'ms' => 33.5 ] );
				$at = (float) self::NOW;
				// Each read moves a second, the pump's interval, so every write checks.
				Core::$clock = static function () use ( &$at ): float {
					return $at += 1.0;
				};
				$stop = $this->with_stop_due(
					function (): void {
						$this->run_job();
						Event_Framework::instance()->pump();
					}
				);
			}
		);

		$this->assertNotNull( $stop, 'the stop raised once the job was done' );
		$this->assertTrue( self::snapshot_published(), 'the build ran to its publish' );
		$closed = self::entries_of( $entries, Flame_Tree::SNAPSHOT_BUILD . ' (complete)' );
		$this->assertSame( 'published', $closed[0]['m'] ?? null );
	}

	/** Each stored question is a span on the job's record, labelled as `stored-answers.json` names it, inside the build after its totals. */
	public function test_a_logged_job_spans_each_answer_inside_the_build_after_its_totals(): void {
		$categories = [];
		foreach ( [ Flame_Tree::PREFETCH_ANSWER, Flame_Tree::SNAPSHOT_BUILD, Flame_Tree::SNAPSHOT_TOTALS ] as $span ) {
			\array_push( $categories, "{$span} (start)", "{$span} (complete)" );
		}
		$spans  = \array_map( static fn ( array $entry ): array => [ $entry['k'], $entry['l'] ?? null, $entry['m'] ?? null ], self::entries_of( $this->logged_job(), ...$categories ) );
		$answer = static fn ( string $l ): array => [ [ 'prefetch answer (start)', $l, null ], [ 'prefetch answer (complete)', null, 'ok' ] ];

		$this->assertSame(
			[
				[ 'snapshot build (start)', null, null ],
				[ 'snapshot totals (start)', null, null ],
				[ 'snapshot totals (complete)', null, 'ok' ],
				...$answer( 'overview' ),
				...$answer( 'chart_by_server' ),
				...$answer( 'chart_by_status_class' ),
				...$answer( 'categories' ),
				...$answer( 'server_overview' ),
				...$answer( 'server_chart_by_status_class' ),
				...$answer( 'server_categories' ),
				[ 'snapshot build (complete)', null, 'published' ],
			],
			$spans
		);
		$this->assertTrue( self::snapshot_published() );
	}

	/** An overview handed its servers names them and reads none. */
	public function test_an_overview_handed_its_servers_names_them(): void {
		$this->seed_request( [ 'server' => 'kea-7010.example', 'url' => 'https://kea-7010.example/a/', 'ms' => 8.5 ] );
		$answer = Performance_CI_Node::answer_live( 'overview', self::overview_args( [] ), self::NOW, [ 'moa-7010.example', 'kea-7010.example' ] );

		$this->assertSame( [ 'moa-7010.example', 'kea-7010.example' ], $answer['servers'], 'the list handed in, never a read' );
		$this->assertSame( 1, $answer['totals']['requests'] );
	}

	/** The job answers its overview with the servers the build hands it, never a read of its own. */
	public function test_the_jobs_overview_names_the_servers_it_is_handed(): void {
		$this->seed_request( [ 'server' => 'kea-7012.example', 'url' => 'https://kea-7012.example/a/', 'ms' => 8.5 ] );

		$answers = ( new \ReflectionMethod( Overview_Prefetch::class, 'answers' ) )->invoke( null, Overview_Prefetch::stored(), self::NOW, [ 'moa-7012.example', 'kea-7012.example' ] );

		$this->assertSame( [ 'moa-7012.example', 'kea-7012.example' ], $answers[ Overview_Prefetch::key( 'overview', self::overview_args( [] ) ) ]['servers'] );
	}

	/** The stored servers are the live read's, ties and worker traffic included. */
	public function test_the_stored_servers_are_the_live_reads_ties_broken_alike(): void {
		$this->seed_request( [ 'server' => 'kea-7011.example', 'url' => 'https://kea-7011.example/a/', 'ms' => 8.5 ] );
		$this->seed_request( [ 'server' => 'tui-7011.example', 'url' => 'https://tui-7011.example/a/', 'ms' => 2.5 ], partition: 1 );
		$this->seed_request( [ 'server' => 'ruru-7011.example', 'url' => 'https://ruru-7011.example/wp-cron.php', 'ms' => 1.5, 'worker' => 1 ], partition: 2 );
		$this->seed_request( [ 'server' => 'ruru-7011.example', 'url' => 'https://ruru-7011.example/wp-cron.php', 'ms' => 1.5, 'worker' => 1 ], partition: 2 );
		$live = Performance_CI_Node::answer_live( 'overview', self::overview_args( [] ), self::NOW, null )['servers'];
		$this->run_job();
		self::clock( self::NOW + 9 );

		$stored = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] );

		$this->assertSame( [ 'ruru-7011.example', 'tui-7011.example', 'kea-7011.example' ], $live, 'busiest first, a tie by name descending' );
		$this->assertSame( $live, \is_array( $stored ) ? $stored['servers'] : $stored );
	}

	/** Folded into a flame, the four answers stay four nodes. */
	public function test_each_answer_folds_into_its_own_node(): void {
		$names = self::flame_names( Flame_Tree::build_flame_data( $this->logged_job() ) );

		$this->assertSame(
			[ 'prefetch answer: overview', 'prefetch answer: chart_by_server', 'prefetch answer: chart_by_status_class', 'prefetch answer: categories', 'prefetch answer: server_overview', 'prefetch answer: server_chart_by_status_class', 'prefetch answer: server_categories' ],
			\array_values( \array_filter( $names, static fn ( string $name ): bool => \str_starts_with( $name, Flame_Tree::PREFETCH_ANSWER ) ) )
		);
	}

	/**
	 * Every node name under a flame node, depth first.
	 *
	 * @param array<array-key,mixed> $node A flame node.
	 * @return list<string>
	 */
	public static function flame_names( array $node ): array {
		$names = [];
		foreach ( Core::arr( $node['children'] ?? [] ) as $child ) {
			$child = Core::arr( $child );
			\array_push( $names, Core::as_string( $child['name'] ?? '' ), ...self::flame_names( $child ) );
		}
		return $names;
	}

	/** A template naming its server where `templated()` cannot find it would store answers no poll reads, so the job refuses it. */
	public function test_a_template_templated_cannot_invert_is_refused(): void {
		Overview_Prefetch::$read_file = static fn ( string $path ): string => (string) \wp_json_encode(
			[ 'kea_7025' => [ 'verb' => 'query', 'tokens' => [ 'requests', 'bucket', '--search={server}', '--measure=count' ] ] ]
		);
		try {
			Overview_Prefetch::stored();
			$this->fail( 'a template templated() cannot invert is refused' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'kea_7025', $e->getMessage() );
		} finally {
			Overview_Prefetch::$read_file = null;
		}
	}

	/** A questions file the job refuses fails the job before the build starts. */
	public function test_refused_questions_fail_the_job_before_the_build(): void {
		Overview_Prefetch::$read_file = static fn ( string $path ): string => (string) \wp_json_encode(
			[
				'kea_7026'  => [ 'verb' => 'overview', 'tokens' => [ '--server=kaka-7026.example' ] ],
				'weka_7026' => [ 'verb' => 'overview', 'tokens' => [ '--server=kaka-7026.example' ] ],
			]
		);
		$thrown = null;
		try {
			$entries = $this->logged_in(
				$this->make_temp_dir(),
				function () use ( &$thrown ): void {
					$this->seed_request( [ 'url' => 'https://seed.example/kea-7026/', 'ms' => 8.5 ] );
					try {
						$this->run_job();
					} catch ( \LogicException $e ) {
						$thrown = $e;
					}
				}
			);
		} finally {
			Overview_Prefetch::$read_file = null;
		}

		$this->assertInstanceOf( \LogicException::class, $thrown );
		$this->assertSame( [], self::entries_of( $entries, Flame_Tree::SNAPSHOT_BUILD . ' (start)' ), 'no build was started' );
	}

	/** Two stored questions binding to one key would store one answer for both, so the job refuses them. */
	public function test_two_questions_binding_alike_are_refused(): void {
		Overview_Prefetch::$read_file = static fn ( string $path ): string => (string) \wp_json_encode(
			[
				'kea_5545'  => [ 'verb' => 'overview', 'tokens' => [ '--server=kaka-5545.example' ] ],
				'weka_5545' => [ 'verb' => 'overview', 'tokens' => [ '--server=kaka-5545.example' ] ],
			]
		);
		try {
			Overview_Prefetch::stored();
			$this->fail( 'two questions binding alike are refused' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'kea_5545', $e->getMessage() );
			$this->assertStringContainsString( 'weka_5545', $e->getMessage() );
		} finally {
			Overview_Prefetch::$read_file = null;
		}
	}

	public function test_the_job_publishes_a_snapshot_every_url_page_is_served_from(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5519/', 'ms' => 33.5 ], partition: 1 );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-5519/', 'ms' => 12.5 ], partition: 2 );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5519/', 'ms' => 8.5 ] );

		$served = $this->urls( [ '--sort=avg_ms', '--order=asc', '--limit=1', '--offset=1' ] );

		$this->assertSame( [ 'https://seed.example/takahe-5519/' ], \array_column( $served['data'], 'url' ), 'the snapshot, not the live rows' );
		$this->assertSame( 2, $served['rows'] );
		$this->assertSame( self::NOW, $served['as_of'] );
	}

	public function test_a_served_page_is_the_live_page_of_its_moment(): void {
		$this->seed_requests(
			[
				[ 'url' => 'https://seed.example/takahe-5531/', 'ms' => 33.5, 'peak_mb' => 40.25, 'status' => 503 ],
				[ 'url' => 'https://kaka-5531.example/kea-5531/', 'server' => 'kaka-5531.example', 'ms' => 12.5, 'peak_mb' => 30.5 ],
			],
			partition: 1
		);
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5531/', 'ms' => 7.25, 'peak_mb' => 20.75 ], partition: 2 );
		$this->run_job();
		$tokens = [ '--sort=url', '--order=desc', '--limit=7', '--offset=0' ];
		$args   = Command_Args::bind( Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class )['urls']['args'], $tokens );
		$live   = Performance_CI_Node::answer_live( 'urls', $args, self::NOW, null );
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5531/', 'ms' => 8.5 ] );

		// The reply crosses the wire as JSON, as a whole float reads back an int.
		$this->assertSame( \json_decode( (string) \wp_json_encode( $live ), true ), $this->urls( $tokens ) );
	}

	public function test_a_server_and_include_workers_are_served_from_the_snapshot(): void {
		$this->seed_requests(
			[
				[ 'url' => 'https://kaka-5532.example/takahe-5532/', 'server' => 'kaka-5532.example', 'ms' => 33.5 ],
				[ 'url' => 'https://seed.example/wp-cron-5532/', 'ms' => 12.5, 'worker' => 1 ],
				[ 'url' => 'https://seed.example/kea-5532/', 'ms' => 6.5 ],
			],
			partition: 2
		);
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://kaka-5532.example/moa-5532/', 'server' => 'kaka-5532.example', 'ms' => 8.5, 'worker' => 1 ] );
		self::clock( self::NOW + 9 );

		$server  = $this->urls( [ '--server=kaka-5532.example', '--limit=100' ] );
		$workers = $this->urls( [ '--include_workers', '--sort=url', '--order=asc', '--limit=100' ] );

		$this->assertSame( [ 'https://kaka-5532.example/takahe-5532/' ], \array_column( $server['data'], 'url' ) );
		$this->assertSame( 'kaka-5532.example', $server['filters']['server'] );
		$this->assertSame( self::NOW, $server['as_of'] );
		$this->assertSame(
			[ 'https://kaka-5532.example/takahe-5532/', 'https://seed.example/kea-5532/', 'https://seed.example/wp-cron-5532/' ],
			\array_column( $workers['data'], 'url' )
		);
		$this->assertTrue( $workers['filters']['include_workers'] );
		$this->assertSame( self::NOW, $workers['as_of'] );
	}

	/**
	 * A question the snapshot cannot answer: its tokens beside the default
	 * page's.
	 *
	 * @return array<string,array{0:list<string>}>
	 */
	public static function live_questions(): array {
		return [
			'a search'           => [ [ '--search=moa' ] ],
			'a bucket selection' => [ [ '--bucket=' . Bucket::key( Bucket::id( self::NOW ) ) ] ],
			'a filter'           => [ [ '--filter=status_class=5' ] ],
		];
	}

	/**
	 * @param list<string> $tokens What the question adds.
	 */
	#[DataProvider( 'live_questions' )]
	public function test_a_search_a_bucket_or_a_filter_is_answered_live( array $tokens ): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5533/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5533/', 'ms' => 8.5, 'error' => 'F', 'status' => 500 ] );
		self::clock( self::NOW + 9 );

		$served = $this->urls( [ '--limit=100', ...$tokens ] );

		$this->assertContains( 'https://seed.example/moa-5533/', \array_column( $served['data'], 'url' ) );
		$this->assertSame( self::NOW + 9, $served['as_of'] );
	}

	/**
	 * A question naming an arg the snapshot does not vary decides live before
	 * the file is touched: a snapshot that would not open says nothing, and
	 * the default question after it, which opens the file, says so.
	 *
	 * @param list<string> $tokens What the question adds.
	 */
	#[DataProvider( 'live_questions' )]
	public function test_a_live_question_never_opens_the_snapshot( array $tokens ): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5538/', 'ms' => 8.5, 'error' => 'F', 'status' => 500 ] );
		\mkdir( $this->snapshot_file() );
		$said = static fn (): array => \array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not open' ) );

		$served = $this->urls( [ '--limit=100', ...$tokens ] );

		$this->assertSame( [ 'https://seed.example/moa-5538/' ], \array_column( $served['data'], 'url' ) );
		$this->assertSame( [], $said(), 'the live question never opened the file' );
		$this->urls( [ '--limit=100' ] );
		$this->assertNotSame( [], $said(), 'the default question opens it, so the silence above is real' );
	}

	public function test_a_stale_snapshot_is_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5520/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5520/', 'ms' => 8.5 ] );
		self::clock( self::NOW + self::TTL );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertCount( 2, $served['data'] );
		$this->assertSame( self::NOW + self::TTL, $served['as_of'] );
	}

	/** A served snapshot answers for four intervals; `UrlSnapshotTest` dates them from the end of its build. */
	public function test_a_snapshot_lives_four_intervals(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5523/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5523/', 'ms' => 8.5 ] );
		self::clock( self::NOW + self::TTL - 1 );

		$this->assertSame( self::NOW, $this->urls( [ '--limit=100' ] )['as_of'] );
	}

	public function test_no_snapshot_is_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5522/', 'ms' => 8.5 ] );
		self::clock( self::NOW + 9 );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertSame( [ 'https://seed.example/moa-5522/' ], \array_column( $served['data'], 'url' ) );
		$this->assertSame( self::NOW + 9, $served['as_of'] );
		$this->assertFalse( self::snapshot_published() );
	}

	public function test_a_snapshot_that_is_no_database_is_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5527/', 'ms' => 8.5 ] );
		\file_put_contents( $this->snapshot_file(), 'kea-5527 is no sqlite database' );
		self::clock( self::NOW + 9 );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertSame( [ 'https://seed.example/moa-5527/' ], \array_column( $served['data'], 'url' ) );
		$this->assertSame( self::NOW + 9, $served['as_of'] );
	}

	public function test_a_snapshot_that_will_not_open_is_answered_live_and_said(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5528/', 'ms' => 8.5 ] );
		\mkdir( $this->snapshot_file() );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertSame( [ 'https://seed.example/moa-5528/' ], \array_column( $served['data'], 'url' ) );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not open' ) ),
			'the outage is said once'
		);
	}

	public function test_a_snapshot_that_opens_but_will_not_read_is_answered_live_and_said(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5537/', 'ms' => 8.5 ] );
		Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			Url_Snapshot::NAME,
			static function ( Row_Reader $db ): bool {
				$db->rows( 'CREATE TABLE meta ( as_of INTEGER NOT NULL, expires INTEGER NOT NULL )', [] );
				$db->rows( 'INSERT INTO meta VALUES ( ?, ? )', [ self::NOW, self::NOW + self::TTL ] );
				return true;
			}
		);
		self::clock( self::NOW + 9 );

		$served   = $this->urls( [ '--limit=100' ] );
		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] );

		$this->assertSame( [ 'https://seed.example/moa-5537/' ], \array_column( $served['data'], 'url' ) );
		$this->assertSame( self::NOW + 9, $served['as_of'] );
		$this->assertSame( self::NOW + 9, \is_array( $overview ) ? $overview['as_of'] : $overview );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not read' ) ),
			'the outage is said once'
		);
	}

	public function test_a_stored_answer_that_will_not_decode_is_answered_live_and_said(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5541/', 'ms' => 8.5 ] );
		$key = Overview_Prefetch::key( 'overview', self::overview_args( [] ) );
		self::snapshot_of( [ $key => '{"kea-5541": is no json' ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( self::NOW + 9, $overview['as_of'] );
		$this->assertSame( 1, $overview['totals']['urls'], 'the live rows, moa alone' );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not read' ) ),
			'the outage is said once'
		);
	}

	/**
	 * A `query` question whose key the job never stores is decided live
	 * before the file is touched: a snapshot that would not open says
	 * nothing, and the default overview after it says so.
	 */
	public function test_an_unstored_query_never_opens_the_snapshot(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5542/', 'ms' => 8.5 ] );
		\mkdir( $this->snapshot_file() );
		$said = static fn (): array => \array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not open' ) );

		$query = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', [ 'requests', 'bucket', 'status_class', '--top=20', '--top_by=count', '--filter=status_class=5', '--measure=count' ] );

		$this->assertSame( self::NOW, \is_array( $query ) ? $query['as_of'] : $query );
		$this->assertSame( [], $said(), 'the unstored question never opened the file' );
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] );
		$this->assertNotSame( [], $said(), 'the stored question opens it, so the silence above is real' );
	}

	/** A scoped overview's totals are live, its servers the job's: no scope changes them. */
	public function test_a_scoped_overview_names_the_servers_the_snapshot_stored(): void {
		$this->seed_request( [ 'server' => 'kea-6201.example', 'url' => 'https://kea-6201.example/a/', 'ms' => 33.5 ] );
		self::snapshot_of( [ Overview_Prefetch::key( 'overview', self::overview_args( [] ) ) => '{"as_of":1790007215,"available":true,"servers":["kea-6201.example"],"totals":{},"slots":[],"leaderboard":{}}' ] );
		$this->seed_request( [ 'server' => 'kea-6201.example', 'url' => 'https://kea-6201.example/b/', 'ms' => 8.5, 'profiled' => 1 ] );
		$this->seed_request( [ 'server' => 'kaka-6201.example', 'url' => 'https://kaka-6201.example/c/', 'ms' => 12.5 ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=kea-6201.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( self::NOW + 9, $overview['as_of'] );
		$this->assertSame( 2, $overview['totals']['requests'], 'the totals are live' );
		$this->assertSame( [ 'kea-6201.example' ], $overview['servers'], 'the servers are the job\'s, before kaka' );
		$this->assertSame( 1, $overview['leaderboard']['count'], 'the leaderboard is live, the profiled request after the job' );
	}

	/** Every server the job lists gets its scoped poll stored: the overview and both charts. */
	public function test_each_listed_servers_scoped_poll_is_served_from_the_snapshot(): void {
		$this->seed_request( [ 'server' => 'kea-7020.example', 'url' => 'https://kea-7020.example/a/', 'ms' => 33.5 ] );
		$this->seed_request( [ 'server' => 'kaka-7020.example', 'url' => 'https://kaka-7020.example/b/', 'ms' => 12.5 ], partition: 1 );
		$this->run_job();
		$this->seed_request( [ 'server' => 'kea-7020.example', 'url' => 'https://kea-7020.example/c/', 'ms' => 8.5 ] );
		self::clock( self::NOW + 9 );

		foreach ( [ 'kea-7020.example', 'kaka-7020.example' ] as $server ) {
			foreach ( [ 'server_overview', 'server_chart_by_status_class', 'server_categories' ] as $name ) {
				$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', Overview_Prefetch::questions()[ $name ]['verb'], self::asked_for( $name, $server ) );
				$this->assertSame( self::NOW, \is_array( $reply ) ? $reply['as_of'] : $reply, "{$name} for {$server}" );
			}
		}
		$kea = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=kea-7020.example' ] );
		$this->assertIsArray( $kea, \is_string( $kea ) ? $kea : 'overview answered no structure' );
		$this->assertSame( 1, $kea['totals']['requests'], 'the stored totals, c unread' );
		$this->assertSame( [ 'kea-7020.example', 'kaka-7020.example' ], $kea['servers'] );
	}

	/** A server the job never saw has no stored poll: each question answers live. */
	public function test_a_scoped_chart_of_a_server_the_job_never_saw_answers_live(): void {
		$this->seed_request( [ 'server' => 'kea-7021.example', 'url' => 'https://kea-7021.example/a/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'server' => 'ruru-7021.example', 'url' => 'https://ruru-7021.example/b/', 'ms' => 12.5 ] );
		self::clock( self::NOW + 9 );
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', self::asked_for( 'server_chart_by_status_class', 'ruru-7021.example' ) );

		$this->assertSame( self::NOW + 9, \is_array( $reply ) ? $reply['as_of'] : $reply );
	}

	/**
	 * A scoped chart naming two servers, or one only by negation, matches no
	 * template, so it answers live without touching the file.
	 *
	 * @return array<string,array{0:list<string>}>
	 */
	public static function untemplated_server_filters(): array {
		return [
			'two servers' => [ [ '--filter=server=kea-7022.example', '--filter=server=kaka-7022.example' ] ],
			'negated'     => [ [ '--filter=server!=kea-7022.example' ] ],
			'blank'       => [ [ '--filter=server=' ] ],
		];
	}

	/**
	 * @param list<string> $filters
	 */
	#[DataProvider( 'untemplated_server_filters' )]
	public function test_a_chart_naming_servers_no_template_names_never_opens_the_snapshot( array $filters ): void {
		$this->seed_request( [ 'server' => 'kea-7022.example', 'url' => 'https://kea-7022.example/a/', 'ms' => 8.5 ] );
		\mkdir( $this->snapshot_file() );
		$tokens = [ 'requests', 'bucket', 'status_class', '--top=20', '--top_by=count', '--filter=worker=0', ...$filters, '--measure=count', '--measure=sum_ms', '--measure=sum_peak_mb', '--measure=timed' ];

		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', $tokens );

		$this->assertSame( [], \array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not open' ) ) );
	}

	public function test_a_stored_scoped_answer_that_will_not_decode_answers_live_and_says_so(): void {
		$this->seed_request( [ 'server' => 'kea-7023.example', 'url' => 'https://kea-7023.example/a/', 'ms' => 8.5 ] );
		$this->seed_request( [ 'server' => 'kaka-7023.example', 'url' => 'https://kaka-7023.example/b/', 'ms' => 2.5 ] );
		$this->seed_request( [ 'server' => 'kaka-7023.example', 'url' => 'https://kaka-7023.example/c/', 'ms' => 1.5 ] );
		self::snapshot_of( [ Overview_Prefetch::key( 'overview', self::overview_args( [ '--server=kea-7023.example' ] ) ) => '{"kea-7023": is no json' ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=kea-7023.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( self::NOW + 9, $overview['as_of'] );
		$this->assertSame( [ 'kaka-7023.example', 'kea-7023.example' ], $overview['servers'], 'read live' );
		$this->assertNotEmpty( \array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not read' ) ) );
	}

	/** A window with no requests stores the overview, naming no servers, and no scoped answer. */
	public function test_an_empty_window_stores_the_overview_and_no_scoped_answer(): void {
		$this->seed_request( [ 'server' => 'kea-7024.example', 'url' => 'https://kea-7024.example/a/', 'bucket' => Bucket::id( self::NOW ) - 400, 'ms' => 8.5 ] );
		$this->run_job();
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] );
		$scoped   = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=kea-7024.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertIsArray( $scoped, \is_string( $scoped ) ? $scoped : 'overview answered no structure' );
		$this->assertSame( [ self::NOW, [] ], [ $overview['as_of'], $overview['servers'] ] );
		$this->assertSame( self::NOW + 9, $scoped['as_of'], 'no server listed, so none stored' );
	}

	/** A stored list lacking the scoped server is stale for that question. */
	public function test_a_scoped_overview_of_a_server_the_job_never_saw_reads_its_servers_live(): void {
		$this->seed_request( [ 'server' => 'kea-6206.example', 'url' => 'https://kea-6206.example/a/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'server' => 'ruru-6206.example', 'url' => 'https://ruru-6206.example/b/', 'ms' => 12.5 ] );
		$this->seed_request( [ 'server' => 'ruru-6206.example', 'url' => 'https://ruru-6206.example/c/', 'ms' => 4.5 ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=ruru-6206.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( [ 'ruru-6206.example', 'kea-6206.example' ], $overview['servers'], 'the scope stays on a server the job never saw' );
	}

	/** The list counts worker traffic, so a server only workers reached is new too. */
	public function test_a_scoped_overview_of_a_server_only_workers_reached_reads_its_servers_live(): void {
		$this->seed_request( [ 'server' => 'kea-6210.example', 'url' => 'https://kea-6210.example/a/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'server' => 'ruru-6210.example', 'url' => 'https://ruru-6210.example/wp-cron.php', 'ms' => 12.5, 'worker' => 1 ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=ruru-6210.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( 0, $overview['totals']['requests'], 'no non-worker request' );
		$this->assertContains( 'ruru-6210.example', $overview['servers'], 'the scope stays on a server only workers reached' );
	}

	/** A scoped server with no traffic is no new server: the stored list serves it. */
	public function test_a_scoped_overview_of_a_server_with_no_traffic_names_the_stored_servers(): void {
		$this->seed_request( [ 'server' => 'kea-6208.example', 'url' => 'https://kea-6208.example/a/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'server' => 'kaka-6208.example', 'url' => 'https://kaka-6208.example/b/', 'ms' => 12.5 ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=moa-6208.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( 0, $overview['totals']['requests'] );
		$this->assertSame( [ 'kea-6208.example' ], $overview['servers'], 'the stored list, kaka unread' );
	}

	/** A stored overview naming no servers keeps its own line beside a read failure. */
	public function test_a_stored_overview_naming_no_servers_is_said_beside_a_read_failure(): void {
		$this->seed_request( [ 'server' => 'kea-6209.example', 'url' => 'https://kea-6209.example/a/', 'ms' => 8.5 ] );
		$verbs    = Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class );
		$overview = Overview_Prefetch::key( 'overview', Command_Args::bind( $verbs['overview']['args'], [] ) );
		$chart    = Overview_Prefetch::key( 'query', Command_Args::bind( $verbs['query']['args'], Overview_Prefetch::questions()['categories']['tokens'] ) );
		self::snapshot_of( [ $overview => '{"as_of":1790007215,"available":true}', $chart => '{"kea-6209": is no json' ] );
		self::clock( self::NOW + 9 );
		$said = static fn ( string $text ): array => \array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, $text ) );

		$query = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', Overview_Prefetch::questions()['categories']['tokens'] );
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] );

		$this->assertSame( self::NOW + 9, \is_array( $query ) ? $query['as_of'] : $query, 'the query answered live' );
		$this->assertIsArray( $reply, \is_string( $reply ) ? $reply : 'overview answered no structure' );
		$this->assertSame( self::NOW + 9, $reply['as_of'], 'the overview answered live' );
		$this->assertSame( [ 'kea-6209.example' ], $reply['servers'] );
		$this->assertNotEmpty( $said( 'performance: the URL snapshot did not read' ), 'the read failure is said' );
		$this->assertNotEmpty( $said( 'the stored overview names no servers' ), 'and so is the overview naming none' );
	}

	/** A blank server names no server, so the stored overview answers it whole. */
	public function test_an_overview_with_a_blank_server_is_served_from_the_snapshot(): void {
		$this->seed_request( [ 'server' => 'kea-6207.example', 'url' => 'https://kea-6207.example/a/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'server' => 'kaka-6207.example', 'url' => 'https://kaka-6207.example/b/', 'ms' => 12.5 ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( self::NOW, $overview['as_of'], 'served, dated by the job' );
		$this->assertSame( 1, $overview['totals']['requests'], 'the stored totals, kaka unread' );
		$this->assertSame( [ 'kea-6207.example' ], $overview['servers'] );
	}

	public function test_a_scoped_overview_with_no_snapshot_reads_its_servers_live(): void {
		$this->seed_request( [ 'server' => 'kea-6202.example', 'url' => 'https://kea-6202.example/a/', 'ms' => 33.5 ] );
		$this->seed_request( [ 'server' => 'kaka-6202.example', 'url' => 'https://kaka-6202.example/b/', 'ms' => 12.5 ] );
		$this->seed_request( [ 'server' => 'kaka-6202.example', 'url' => 'https://kaka-6202.example/c/', 'ms' => 4.5 ] );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=kea-6202.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( [ 'kaka-6202.example', 'kea-6202.example' ], $overview['servers'], 'busiest first, read live' );
	}

	public function test_a_scoped_overview_past_the_snapshot_reads_its_servers_live(): void {
		$this->seed_request( [ 'server' => 'kea-6203.example', 'url' => 'https://kea-6203.example/a/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'server' => 'kaka-6203.example', 'url' => 'https://kaka-6203.example/b/', 'ms' => 12.5 ] );
		$this->seed_request( [ 'server' => 'kaka-6203.example', 'url' => 'https://kaka-6203.example/c/', 'ms' => 4.5 ] );
		self::clock( self::NOW + self::TTL + 1 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=kea-6203.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( [ 'kaka-6203.example', 'kea-6203.example' ], $overview['servers'] );
	}

	public function test_a_scoped_overview_whose_stored_answer_will_not_decode_reads_its_servers_live_and_says_so(): void {
		$this->seed_request( [ 'server' => 'kea-6204.example', 'url' => 'https://kea-6204.example/a/', 'ms' => 8.5 ] );
		$this->seed_request( [ 'server' => 'kaka-6204.example', 'url' => 'https://kaka-6204.example/b/', 'ms' => 2.5 ] );
		$this->seed_request( [ 'server' => 'kaka-6204.example', 'url' => 'https://kaka-6204.example/c/', 'ms' => 1.5 ] );
		$key = Overview_Prefetch::key( 'overview', self::overview_args( [] ) );
		self::snapshot_of( [ $key => '{"kea-6204": is no json' ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=kea-6204.example' ] );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( [ 'kaka-6204.example', 'kea-6204.example' ], $overview['servers'] );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not read' ) ),
			'the outage is said once'
		);
	}

	/**
	 * A stored overview naming no servers is a stored answer that will not
	 * read: said once, and answered live, at any scope.
	 *
	 * @return array<string,array{0:list<string>}>
	 */
	public static function overview_scopes(): array {
		return [
			'unscoped' => [ [] ],
			'scoped'   => [ [ '--server=kea-6205.example' ] ],
		];
	}

	/**
	 * @param list<string> $tokens
	 */
	#[DataProvider( 'overview_scopes' )]
	public function test_a_stored_overview_naming_no_servers_is_answered_live_and_said( array $tokens ): void {
		$this->seed_request( [ 'server' => 'kea-6205.example', 'url' => 'https://kea-6205.example/a/', 'ms' => 8.5 ] );
		$key = Overview_Prefetch::key( 'overview', self::overview_args( [] ) );
		self::snapshot_of( [ $key => '{"as_of":1790007215,"available":true}' ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', $tokens );

		$this->assertIsArray( $overview, \is_string( $overview ) ? $overview : 'overview answered no structure' );
		$this->assertSame( self::NOW + 9, $overview['as_of'], 'answered live' );
		$this->assertSame( [ 'kea-6205.example' ], $overview['servers'] );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'the stored overview names no servers' ) ),
			'the outage is said once'
		);
	}

	public function test_a_question_the_snapshot_stored_no_answer_for_is_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5536/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5536/', 'ms' => 8.5 ] );
		$this->seed_request( [ 'server' => 'moa-5536.example', 'url' => 'https://moa-5536.example/a/', 'ms' => 4.5 ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=moa-5536.example' ] );
		$query    = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', [ 'requests', 'bucket', '--measure=count' ] );

		$this->assertIsArray( $overview );
		$this->assertSame( self::NOW + 9, $overview['as_of'] );
		$this->assertSame( 1, $overview['totals']['requests'], 'the live totals of a server the job never listed' );
		$this->assertSame( self::NOW + 9, \is_array( $query ) ? $query['as_of'] : $query );
	}

	public function test_overview_and_the_charts_are_served_from_the_snapshot(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5534/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5534/', 'ms' => 8.5 ] );
		self::clock( self::NOW + 9 );

		$questions = Overview_Prefetch::questions();
		$this->assertSame( [ 'overview', 'chart_by_server', 'chart_by_status_class', 'categories', 'server_overview', 'server_chart_by_status_class', 'server_categories' ], \array_keys( $questions ) );
		foreach ( $questions as $name => [ 'verb' => $verb ] ) {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, self::asked_for( $name, 'seed.example' ) );
			$this->assertSame( self::NOW, \is_array( $reply ) ? $reply['as_of'] : $reply, $name );
		}
		$this->assertSame( 1, VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] )['totals']['urls'], 'the stored overview counts takahe alone' );
	}

	/** A stored question is keyed as asked, so its filters are spelled as a verb canonicalizes them. */
	public function test_every_stored_filter_names_its_field_lowercase_and_once(): void {
		foreach ( Overview_Prefetch::questions() as $name => [ 'tokens' => $tokens ] ) {
			$filters = \array_values( \array_filter( $tokens, static fn ( string $token ): bool => \str_starts_with( $token, '--filter=' ) ) );
			$fields  = \array_map( static fn ( string $token ): string => \explode( '=', $token, 3 )[1], $filters );
			$this->assertSame( \array_map( \strtolower( ... ), $fields ), $fields, $name );
			$this->assertSame( \array_values( \array_unique( $filters ) ), $filters, $name );
		}
	}

	public function test_a_stored_chart_spelled_in_another_case_and_repeated_is_served_from_the_snapshot(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5537/', 'ms' => 33.5 ] );
		$this->run_job();
		self::clock( self::NOW + 9 );
		$tokens = Overview_Prefetch::questions()['chart_by_server']['tokens'];

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', [ ...\str_replace( '--filter=worker=0', '--filter=Worker=0', $tokens ), '--filter=worker=0' ] );

		$this->assertSame( self::NOW, \is_array( $reply ) ? $reply['as_of'] : $reply );
	}

	/**
	 * Deliver one job through a real worker under the plugin's own hooks.
	 *
	 * @param int             $queued_at When the tick queued it.
	 * @param string          $handler   The handler the entry names.
	 * @param int             $interval  The interval the entry names.
	 * @param Job_Worker_Node $jw        The worker to run it.
	 * @return Job_Worker_Node The worker that ran it.
	 */
	private function deliver( int $queued_at, string $handler = Overview_Prefetch::HANDLER, int $interval = self::INTERVAL, Job_Worker_Node $jw = new Job_Worker_Node() ): Job_Worker_Node {
		self::with_deferred_bootstrap(
			static function () use ( $jw, $queued_at, $handler, $interval ): void {
				$jw->load_handlers_from_filters();
				$message                   = Message::new_message();
				$message[ Message::TYPE ]  = Message::TM_STRUCT;
				$message[ Message::VALUE ] = [
					'k'          => 'job',
					'handler'    => $handler,
					'parameters' => [ 'queued_at' => $queued_at, 'interval' => $interval ],
				];
				$jw->fill( $message );
			}
		);
		return $jw;
	}

	/** A refused job fails in its handler, which records the error, and leaves the fill for its Consumer to dead-letter. */
	public function test_a_refused_job_fails_in_its_handler_and_records_the_error(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-7028/' ] );
		$jw     = new Job_Worker_Node();
		$thrown = null;
		try {
			$this->deliver( self::NOW, Overview_Prefetch::HANDLER, -47, $jw );
		} catch ( \InvalidArgumentException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( \InvalidArgumentException::class, $thrown );
		$this->assertStringContainsString( 'interval wants 1 to 900 seconds, got -47', $thrown->getMessage() );
		$this->assertFalse( self::snapshot_published() );
		$stats = $jw->probe_stats();
		$this->assertCount( 1, $stats );
		$this->assertSame( 'error', $stats[0][ Jobstats_Record::LAST_STATUS ] ?? null );
	}

	public function test_a_job_its_successor_overtook_is_declined(): void {
		$_SERVER['REQUEST_URI'] = '/outer-5529';

		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5529/' ] );

		$jw = $this->deliver( self::NOW - self::INTERVAL );

		$this->assertFalse( self::snapshot_published() );
		$this->assertSame( [], $jw->probe_stats(), 'a declined job records no outcome' );
		$this->assertSame( '/outer-5529', $_SERVER['REQUEST_URI'], 'a declined job opens no context' );
	}

	/** A later listener can overwrite admit()'s decline of an overtaken job, so the handler asks again. */
	public function test_the_job_run_once_overtaken_publishes_nothing(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-7041/' ] );

		Overview_Prefetch::run( '', [ 'queued_at' => self::NOW - self::INTERVAL, 'interval' => self::INTERVAL ] );

		$this->assertFalse( self::snapshot_published() );
	}

	/** A hub's firehose entry lands on any partition, and that partition's JobWorker runs it. */
	public function test_a_worker_bound_to_another_partition_runs_and_publishes(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5530/' ] );
		Core::$var['partition'] = '3';

		$jw = $this->deliver( self::NOW );

		$this->assertTrue( self::snapshot_published() );
		$this->assertCount( 1, $jw->probe_stats() );
	}

	public function test_a_fresh_job_on_partition_zero_runs_and_publishes(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5535/' ] );

		$jw = $this->deliver( self::NOW - self::INTERVAL + 1 );

		$this->assertTrue( self::snapshot_published() );
		$this->assertCount( 1, $jw->probe_stats() );
	}

	public function test_admit_passes_any_other_handler_through(): void {
		$message = Message::new_message();

		$this->assertTrue( Overview_Prefetch::admit( true, 'kea_5530', '', $message ) );
		$this->assertFalse( Overview_Prefetch::admit( false, 'kea_5530', '', $message ) );
		$this->assertNull( Overview_Prefetch::admit( null, 'kea_5530', '', $message ) );
	}

	/** A job the tick could not have queued reaches its handler to be refused, never declined as overtaken. */
	public function test_admit_passes_a_job_the_tick_could_not_have_queued_to_its_handler(): void {
		$this->assertTrue( Overview_Prefetch::admit( true, Overview_Prefetch::HANDLER, '', [ Message::VALUE => [ 'parameters' => [ 'interval' => 7031 ] ] ] ) );
	}

	/** The first second the tick could have queued a job in is one, which is long overtaken. */
	public function test_admit_declines_a_job_queued_at_one_as_overtaken(): void {
		$this->assertFalse( Overview_Prefetch::admit( true, Overview_Prefetch::HANDLER, '', [ Message::VALUE => [ 'parameters' => [ 'queued_at' => 1, 'interval' => 47 ] ] ] ) );
	}

	public function test_admit_keeps_an_earlier_decline(): void {
		$message                   = Message::new_message();
		$message[ Message::VALUE ] = [ 'parameters' => [ 'queued_at' => self::NOW, 'interval' => self::INTERVAL ] ];

		$this->assertFalse( Overview_Prefetch::admit( false, Overview_Prefetch::HANDLER, '', $message ) );
	}

	public function test_overview_and_query_answer_as_of_live(): void {
		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] );
		$query    = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', [ 'requests', 'bucket', '--measure=count' ] );

		$this->assertSame( self::NOW, \is_array( $overview ) ? $overview['as_of'] : $overview );
		$this->assertSame( self::NOW, \is_array( $query ) ? $query['as_of'] : $query );
	}

	public function test_the_bootstrap_registers_the_job_beside_the_others(): void {
		$other = static fn (): null => null;
		self::with_deferred_bootstrap(
			function () use ( $other ): void {
				$this->assertSame(
					[
						Overview_Prefetch::HANDLER => [ Overview_Prefetch::class, 'run' ],
						'kea_5525'                 => $other,
					],
					\apply_filters( 'newspack_nodes/job_handlers', [ 'kea_5525' => $other ] )
				);
			}
		);
	}

	public function test_a_verb_with_no_stored_answer_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Performance_CI_Node::answer_live( 'dump_url', [], self::NOW, null );
	}

	/**
	 * A job the tick could not have queued fails in its handler.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function unqueueable_jobs(): array {
		$queued_at = 'overview prefetch: queued_at wants an epoch second, got ';
		$bounds    = 'overview prefetch: interval wants 1 to 900 seconds, got ';
		$integer   = 'overview prefetch: interval wants an integer, got ';
		return [
			'an interval below 1'           => [ [ 'queued_at' => 1_790_007_215, 'interval' => -47 ], $bounds . '-47' ],
			'an interval past 900'          => [ [ 'queued_at' => 1_790_007_215, 'interval' => 901 ], $bounds . '901' ],
			'a fraction for its interval'   => [ [ 'queued_at' => 1_790_007_215, 'interval' => 900.7 ], $integer . '900.7' ],
			'a word for its interval'       => [ [ 'queued_at' => 1_790_007_215, 'interval' => 'kea-7029' ], $integer . '"kea-7029"' ],
			'markup for its interval'       => [ [ 'queued_at' => 1_790_007_215, 'interval' => '<b>7037 & up</b>' ], $integer . '"<b>7037 & up<\/b>"' ],
			'no queued_at'                  => [ [ 'interval' => 47 ], $queued_at . 'null' ],
			'a queued_at below 1'           => [ [ 'queued_at' => -5, 'interval' => 47 ], $queued_at . '-5' ],
			'a queued_at of 0'              => [ [ 'queued_at' => 0, 'interval' => 47 ], $queued_at . '0' ],
			'a queued_at no JSON encodes'   => [ [ 'queued_at' => \NAN, 'interval' => 47 ], $queued_at . 'double' ],
			'a fraction for its queued_at'  => [ [ 'queued_at' => 0.5, 'interval' => 47 ], $queued_at . '0.5' ],
			'a word for its queued_at'      => [ [ 'queued_at' => 'kea-7027', 'interval' => 47 ], $queued_at . '"kea-7027"' ],
			'a line break in its queued_at' => [ [ 'queued_at' => "kea\n7040", 'interval' => 47 ], $queued_at . '"kea\\n7040"' ],
			'a cut inside an escape'        => [ [ 'queued_at' => \str_repeat( 'a', 61 ) . 'é', 'interval' => 47 ], $queued_at . '"' . \str_repeat( 'a', 61 ) . '…' ],
			'a cut after an escaped slash'  => [ [ 'queued_at' => \str_repeat( 'a', 61 ) . '\\é', 'interval' => 47 ], $queued_at . '"' . \str_repeat( 'a', 61 ) . '\\\\…' ],
			'a long word for its queued_at' => [ [ 'queued_at' => \str_repeat( 'kea-7039 ', 2000 ), 'interval' => 47 ], $queued_at . \substr( '"' . \str_repeat( 'kea-7039 ', 2000 ), 0, 64 ) . '…' ],
		];
	}

	/**
	 * @param array<string,mixed> $parameters
	 * @param string              $refusal
	 */
	#[DataProvider( 'unqueueable_jobs' )]
	public function test_a_job_the_tick_could_not_have_queued_fails_in_its_handler( array $parameters, string $refusal ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $refusal );
		Overview_Prefetch::run( '', $parameters );
	}

	public function test_a_job_whose_stats_will_not_open_publishes_nothing(): void {
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();
		try {
			$this->run_job();
			$this->fail( 'a job whose stats will not open publishes nothing' );
		} catch ( Table_Unavailable ) {
			$this->assertFalse( self::snapshot_published() );
		}
		$this->activate_shipped( 'flame-builder', 3 );
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5526/', 'ms' => 8.5 ] );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertTrue( $served['available'] );
		$this->assertSame( [ 'https://seed.example/moa-5526/' ], \array_column( $served['data'], 'url' ), 'answered live, not the stored outage' );
	}
}
