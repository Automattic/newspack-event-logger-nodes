<?php
/**
 * OverviewPrefetchTest: the overview-prefetch job and the verbs it serves.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Overview_Prefetch;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Node;

/**
 * The job stores the four answers a default Overview poll asks; each verb
 * serves its stored answer for the same arguments until it expires, and
 * answers live otherwise.
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
		$this->activate_shipped( 'flame-builder', 2 );
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

	public function test_a_job_stores_the_url_page_the_verb_then_serves(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5519/', 'ms' => 33.5 ], partition: 1 );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5519/', 'ms' => 8.5 ] );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertSame( [ 'https://seed.example/takahe-5519/' ], \array_column( $served['data'], 'url' ), 'the stored page, not the live one' );
		$this->assertSame( self::NOW, $served['as_of'] );
	}

	public function test_an_expired_answer_is_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5520/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5520/', 'ms' => 8.5 ] );
		Core::$clock = static fn (): float => (float) ( self::NOW + 2 * self::INTERVAL + 1 );
		Core::right_now();

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertCount( 2, $served['data'] );
		$this->assertSame( self::NOW + 2 * self::INTERVAL + 1, $served['as_of'] );
	}

	public function test_an_answer_lives_two_intervals(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5523/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5523/', 'ms' => 8.5 ] );
		Core::$clock = static fn (): float => (float) ( self::NOW + 2 * self::INTERVAL - 1 );
		Core::right_now();

		$this->assertSame( self::NOW, $this->urls( [ '--limit=100' ] )['as_of'] );
	}

	public function test_other_arguments_are_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/takahe-5521/', 'ms' => 33.5 ] );
		$this->run_job();
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5521/', 'ms' => 8.5 ] );

		$this->assertCount( 2, $this->urls( [ '--limit=100', '--sort=avg_ms' ] )['data'] );
	}

	public function test_no_job_yet_is_answered_live(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5522/', 'ms' => 8.5 ] );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertCount( 1, $served['data'] );
		$this->assertSame( self::NOW, $served['as_of'] );
	}

	public function test_a_job_its_successor_overtook_stores_nothing(): void {
		$this->run_job( self::NOW - self::INTERVAL );

		$this->assertFileDoesNotExist( Table_Node::file( Overview_Prefetch::TABLE, 0 ) );
	}

	public function test_every_question_is_stored_under_its_bound_arguments(): void {
		$this->run_job();
		VerbHarness::request_graph();

		$questions = Overview_Prefetch::questions();
		$this->assertSame( [ 'overview', 'urls', 'chart_by_server', 'chart_by_status_class', 'categories' ], \array_keys( $questions ) );
		foreach ( $questions as $name => [ 'verb' => $verb, 'tokens' => $tokens ] ) {
			$args = Command_Args::bind( Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class )[ $verb ]['args'], $tokens );
			$this->assertSame( self::NOW, Overview_Prefetch::lookup( $verb, $args )['as_of'] ?? null, $name );
		}
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

	public function test_an_answer_the_stats_outage_wrote_is_never_stored(): void {
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();
		$this->run_job();
		$this->activate_shipped( 'flame-builder', 2 );
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5526/', 'ms' => 8.5 ] );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertTrue( $served['available'] );
		$this->assertSame( [ 'https://seed.example/moa-5526/' ], \array_column( $served['data'], 'url' ), 'answered live, not the stored outage' );
	}

	public function test_a_file_that_is_no_database_is_answered_live(): void {
		$file = Table_Node::file( Overview_Prefetch::TABLE, 0 );
		\mkdir( \dirname( $file ), 0755, true );
		\file_put_contents( $file, 'kea-5527 is no sqlite database' );
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5527/', 'ms' => 8.5 ] );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertSame( [ 'https://seed.example/moa-5527/' ], \array_column( $served['data'], 'url' ) );
		$this->assertSame( self::NOW, $served['as_of'] );
	}

	public function test_a_file_that_will_not_open_is_answered_live(): void {
		\mkdir( Table_Node::file( Overview_Prefetch::TABLE, 0 ), 0755, true );
		$this->seed_request( [ 'url' => 'https://seed.example/moa-5528/', 'ms' => 8.5 ] );

		$served = $this->urls( [ '--limit=100' ] );

		$this->assertSame( [ 'https://seed.example/moa-5528/' ], \array_column( $served['data'], 'url' ) );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: stored answers did not open' ) ),
			'the outage is said once'
		);
	}
}
