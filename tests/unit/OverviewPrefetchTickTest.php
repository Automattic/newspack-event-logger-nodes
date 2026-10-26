<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Overview_Prefetch;
use Newspack_Event_Logger_Nodes\Overview_Prefetch_Tick_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;

/**
 * The tick queues the overview-prefetch job once an interval, from the fleet's
 * one copy, to jobfeed.p0 for a spoke's job-feed and to the firehose for a
 * hub's job-router.
 */
#[CoversClass( Overview_Prefetch_Tick_Node::class )]
final class OverviewPrefetchTickTest extends TestCase {

	/** 1_790_008_017 falls 18 s into the 43-second interval starting 1_790_007_999. */
	private const BUILT_AT = 1_790_008_017.0;

	/** The first boundary after `BUILT_AT`. */
	private const BOUNDARY = 1_790_008_042.0;

	private string $base;

	private Router_Node $router;

	protected function setUp(): void {
		parent::setUp();
		Event_Framework::reset();
		$this->base = $this->make_temp_dir( 'overview-prefetch-tick-' );
		$this->use_base_dir( $this->base );
		$this->use_scratch_config( $this->base );
		$this->router = new Router_Node();
		$this->router->name( Node_Names::ROUTER );
	}

	/** A tick built at `$at`, as a respawned worker builds one. */
	private function prefetch_tick( string $partition, int $interval, float $at ): Overview_Prefetch_Tick_Node {
		Core::$var['partition'] = $partition;
		Core::$now              = $at;
		$tick                   = new Overview_Prefetch_Tick_Node();
		$tick->name( 'overview-prefetch:tick' );
		$tick->sink( $this->router );
		$tick->arguments( [ (string) $interval ] );
		return $tick;
	}

	/** One Router tick at `$at`. */
	private function ring( Overview_Prefetch_Tick_Node $tick, float $at ): void {
		Core::$now = $at;
		$tick->fire_cb();
	}

	/** Each job entry jobfeed.p{N} holds, as [ partition dir, entry ]. */
	private function fed(): array {
		$jobs = [];
		foreach ( \glob( "{$this->base}/logs/jobfeed.p*/*.log" ) ?: [] as $segment ) {
			foreach ( \array_filter( \explode( "\n", (string) \file_get_contents( $segment ) ) ) as $line ) {
				$jobs[] = [ \basename( \dirname( $segment ) ), Message::unpacked( $line )[ Message::VALUE ] ];
			}
		}
		return $jobs;
	}

	/** The firehose's `job` entries' payloads. */
	private function firehose_jobs(): array {
		return \array_values( \array_map( static fn ( array $e ): mixed => $e['m'], \array_filter( self::firehose_entries( $this->base ), static fn ( array $e ): bool => 'job' === ( $e['k'] ?? null ) ) ) );
	}

	public function test_partition_zero_queues_once_an_interval_both_ways(): void {
		$tick = $this->prefetch_tick( '0', 43, self::BUILT_AT );

		$this->ring( $tick, self::BOUNDARY );
		$this->ring( $tick, self::BOUNDARY + 42 );
		$this->ring( $tick, self::BOUNDARY + 43 );

		$fed = $this->fed();
		$this->assertSame( [ 'jobfeed.p0', 'jobfeed.p0' ], \array_column( $fed, 0 ) );
		$this->assertSame( Overview_Prefetch::HANDLER, $fed[0][1]['handler'] );
		$this->assertSame( [ 'queued_at' => 1_790_008_042, 'interval' => 43 ], $fed[0][1]['parameters'] );
		$this->assertSame(
			[
				[ 'handler' => Overview_Prefetch::HANDLER, 'id' => '', 'parameters' => [ 'queued_at' => 1_790_008_042, 'interval' => 43 ] ],
				[ 'handler' => Overview_Prefetch::HANDLER, 'id' => '', 'parameters' => [ 'queued_at' => 1_790_008_085, 'interval' => 43 ] ],
			],
			$this->firehose_jobs()
		);
	}

	/** A respawned worker queues at the next boundary, as its predecessor would have. */
	public function test_a_respawned_worker_queues_at_the_next_boundary(): void {
		$tick = $this->prefetch_tick( '0', 43, self::BUILT_AT );

		$this->ring( $tick, self::BOUNDARY - 1 );
		$this->assertSame( [], $this->fed() );
		$this->ring( $tick, self::BOUNDARY );
		$this->assertSame( [ 1_790_008_042 ], \array_map( static fn ( array $job ): mixed => $job[1]['parameters']['queued_at'], $this->fed() ) );
	}

	/** A worker respawned inside the interval its predecessor queued in queues nothing more there. */
	public function test_a_respawn_inside_its_predecessors_interval_queues_no_second_job(): void {
		$tick = $this->prefetch_tick( '0', 43, self::BOUNDARY + 18 );

		$this->ring( $tick, self::BOUNDARY + 42 );
		$this->assertSame( [], $this->fed() );
		$this->ring( $tick, self::BOUNDARY + 43 );
		$this->assertCount( 1, $this->fed() );
	}

	/** A Router tick late past a boundary moves no later job off the boundaries. */
	public function test_a_late_tick_keeps_the_next_job_on_its_boundary(): void {
		$tick = $this->prefetch_tick( '0', 43, self::BUILT_AT );

		$this->ring( $tick, self::BOUNDARY + 0.9 );
		$this->ring( $tick, self::BOUNDARY + 43 );

		$this->assertCount( 2, $this->fed() );
	}

	public function test_a_firehose_that_logs_nothing_for_the_job_is_said(): void {
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'jobs', 'pattern' => '/jobs', 'action' => 'skip' ],
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
		];
		\Newspack_Event_Logger_Nodes\Log_Manager::reset();
		$errors = $this->capture_stderr();
		$tick   = $this->prefetch_tick( '0', 43, self::BUILT_AT );

		$this->ring( $tick, self::BOUNDARY );

		$this->assertSame( [], $this->firehose_jobs() );
		$this->assertCount( 1, $this->fed(), 'the feed still carried it' );
		$said = (array) $errors;
		$this->assertCount( 1, $said );
		$this->assertStringEndsWith( 'overview-prefetch:tick: WARNING: ' . Overview_Prefetch::HANDLER . " not logged to the firehose\n", $said[0] );
	}

	public function test_any_other_partition_queues_nothing(): void {
		$tick = $this->prefetch_tick( '2', 43, self::BUILT_AT );
		$this->ring( $tick, self::BOUNDARY );

		$this->assertSame( [], $this->fed() );
		$this->assertSame( [], $this->firehose_jobs() );
	}

	public function test_a_line_naming_no_interval_queues_every_five_minutes(): void {
		$tick = $this->prefetch_tick( '0', 43, self::BUILT_AT );
		$tick->arguments( [] );

		// The five-minute interval BUILT_AT falls in ends at 1_790_008_200.
		$this->ring( $tick, 1_790_008_199.0 );
		$this->assertSame( [], $this->fed() );
		$this->ring( $tick, 1_790_008_200.0 );
		$this->assertSame( [ 'queued_at' => 1_790_008_200, 'interval' => 300 ], $this->fed()[0][1]['parameters'] ?? null );
	}

	/**
	 * An interval outside one second to fifteen minutes is refused, naming
	 * the bounds.
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function refused_intervals(): array {
		return [
			'zero'     => [ 0 ],
			'past 900' => [ 901 ],
		];
	}

	#[DataProvider( 'refused_intervals' )]
	public function test_an_interval_outside_its_bounds_is_refused( int $interval ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( "/^Bad arguments for Overview_Prefetch_Tick .*: interval_s wants 1 to 900 seconds, got {$interval}$/" );
		$this->prefetch_tick( '0', $interval, self::BUILT_AT );
	}

	/** A refused line leaves a live tick as it was: its interval and its tokens. */
	public function test_a_refused_retune_leaves_the_tick_unchanged(): void {
		$tick = $this->prefetch_tick( '0', 43, self::BUILT_AT );
		try {
			$tick->arguments( [ '901' ] );
			$this->fail( 'the line is refused' );
		} catch ( \InvalidArgumentException ) {
			$this->assertSame( [ '43' ], $tick->arguments() );
		}
		$this->ring( $tick, self::BOUNDARY );
		$this->assertSame( 43, $this->fed()[0][1]['parameters']['interval'] ?? null );
	}

	/** The palette names the bounds the line is held to and the snapshot's life. */
	public function test_the_interval_description_names_its_bounds(): void {
		$described = Overview_Prefetch_Tick_Node::node_schema()['arguments'][0]['description'];

		$this->assertStringContainsString( '1 to 900', $described );
		$this->assertStringContainsString( 'serves 4 of them', $described );
	}

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function bound_intervals(): array {
		return [
			'one second'      => [ 1 ],
			'fifteen minutes' => [ 900 ],
		];
	}

	#[DataProvider( 'bound_intervals' )]
	public function test_the_bounds_themselves_are_accepted( int $interval ): void {
		$this->assertSame( [ (string) $interval ], $this->prefetch_tick( '0', $interval, self::BUILT_AT )->arguments() );
	}

	/** The shipped line names no interval, so the one it runs is the default the verbs describe. */
	public function test_the_shipped_line_runs_the_default_interval(): void {
		$tsl = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/topologies/stats-builder.tsl' );

		$this->assertMatchesRegularExpression( '/^make_node Overview_Prefetch_Tick overview-prefetch:tick$/m', $tsl );
	}

	public function test_it_rides_the_router_tick(): void {
		$tick = $this->prefetch_tick( '0', 43, self::BUILT_AT );

		$this->assertSame( 'router', ( fn () => $this->mode )->call( $tick ) );
	}
}
