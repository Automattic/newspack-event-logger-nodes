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
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Job_Worker_Node;
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

	/** Run the job as the tick queues it now. */
	private function run_job( int $queued_at = self::NOW ): void {
		Overview_Prefetch::run( '', [ 'queued_at' => $queued_at, 'interval' => self::INTERVAL ] );
	}

	/** Move the clock to `$at`, inside the bucket the job ran in. */
	private static function clock( int $at ): void {
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
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
		$live   = Performance_CI_Node::answer_live( 'urls', $args, self::NOW );
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
		self::clock( self::NOW + 2 * self::INTERVAL );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertCount( 2, $served['data'] );
		$this->assertSame( self::NOW + 2 * self::INTERVAL, $served['as_of'] );
	}

	public function test_a_snapshot_lives_two_intervals(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5523/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5523/', 'ms' => 8.5 ] );
		self::clock( self::NOW + 2 * self::INTERVAL - 1 );

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
				$db->rows( 'INSERT INTO meta VALUES ( ?, ? )', [ self::NOW, self::NOW + 2 * self::INTERVAL ] );
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

	public function test_a_question_the_snapshot_stored_no_answer_for_is_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5536/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5536/', 'ms' => 8.5 ] );
		self::clock( self::NOW + 9 );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [ '--server=seed.example' ] );
		$query    = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', [ 'requests', 'bucket', '--measure=count' ] );

		$this->assertIsArray( $overview );
		$this->assertSame( self::NOW + 9, $overview['as_of'] );
		$this->assertSame( 2, $overview['totals']['urls'] );
		$this->assertSame( self::NOW + 9, \is_array( $query ) ? $query['as_of'] : $query );
	}

	public function test_overview_and_the_charts_are_served_from_the_snapshot(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5534/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5534/', 'ms' => 8.5 ] );
		self::clock( self::NOW + 9 );

		$questions = Overview_Prefetch::questions();
		$this->assertSame( [ 'overview', 'chart_by_server', 'chart_by_status_class', 'categories' ], \array_keys( $questions ) );
		foreach ( $questions as $name => [ 'verb' => $verb, 'tokens' => $tokens ] ) {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $tokens );
			$this->assertSame( self::NOW, \is_array( $reply ) ? $reply['as_of'] : $reply, $name );
		}
		$this->assertSame( 1, VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', [] )['totals']['urls'], 'the stored overview counts takahe alone' );
	}

	/**
	 * Deliver one job through a real worker under the plugin's own hooks.
	 *
	 * @param int    $queued_at When the tick queued it.
	 * @param string $handler   The handler the entry names.
	 * @return Job_Worker_Node The worker that ran it.
	 */
	private function deliver( int $queued_at, string $handler = Overview_Prefetch::HANDLER ): Job_Worker_Node {
		$jw = new Job_Worker_Node();
		self::with_deferred_bootstrap(
			static function () use ( $jw, $queued_at, $handler ): void {
				$jw->load_handlers_from_filters();
				$message                   = Message::new_message();
				$message[ Message::TYPE ]  = Message::TM_STRUCT;
				$message[ Message::VALUE ] = [
					'k'          => 'job',
					'handler'    => $handler,
					'parameters' => [ 'queued_at' => $queued_at, 'interval' => self::INTERVAL ],
				];
				$jw->fill( $message );
			}
		);
		return $jw;
	}

	public function test_a_job_its_successor_overtook_is_declined(): void {
		$_SERVER['REQUEST_URI'] = '/outer-5529';

		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5529/' ] );

		$jw = $this->deliver( self::NOW - self::INTERVAL );

		$this->assertFalse( self::snapshot_published() );
		$this->assertSame( [], $jw->probe_stats(), 'a declined job records no outcome' );
		$this->assertSame( '/outer-5529', $_SERVER['REQUEST_URI'], 'a declined job opens no context' );
	}

	public function test_a_worker_bound_to_another_partition_declines(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5530/' ] );
		Core::$var['partition'] = '3';

		$jw = $this->deliver( self::NOW );

		$this->assertFalse( self::snapshot_published() );
		$this->assertSame( [], $jw->probe_stats() );
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
		Performance_CI_Node::answer_live( 'dump_url', [], self::NOW );
	}

	public function test_a_job_with_no_interval_fails(): void {
		$this->expectException( \InvalidArgumentException::class );
		Overview_Prefetch::run( '', [ 'queued_at' => self::NOW, 'interval' => 0 ] );
	}

	public function test_a_job_with_no_queue_time_fails(): void {
		$this->expectException( \InvalidArgumentException::class );
		Overview_Prefetch::run( '', [ 'interval' => self::INTERVAL ] );
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
