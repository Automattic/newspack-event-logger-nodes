<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
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
		$at   = 1_790_008_017.0;
		$tick = $this->prefetch_tick( '0', 43, $at );

		$this->ring( $tick, $at + 43 );
		$this->ring( $tick, $at + 43 + 42 );
		$this->ring( $tick, $at + 43 + 43 );

		$fed = $this->fed();
		$this->assertSame( [ 'jobfeed.p0', 'jobfeed.p0' ], \array_column( $fed, 0 ) );
		$this->assertSame( Overview_Prefetch::HANDLER, $fed[0][1]['handler'] );
		$this->assertSame( [ 'queued_at' => 1_790_008_060, 'interval' => 43 ], $fed[0][1]['parameters'] );
		$this->assertSame(
			[
				[ 'handler' => Overview_Prefetch::HANDLER, 'id' => '', 'parameters' => [ 'queued_at' => 1_790_008_060, 'interval' => 43 ] ],
				[ 'handler' => Overview_Prefetch::HANDLER, 'id' => '', 'parameters' => [ 'queued_at' => 1_790_008_103, 'interval' => 43 ] ],
			],
			$this->firehose_jobs()
		);
	}

	public function test_a_respawned_worker_waits_an_interval_before_its_first_job(): void {
		$at   = 1_790_008_017.0;
		$tick = $this->prefetch_tick( '0', 43, $at );

		$this->ring( $tick, $at + 1 );
		$this->ring( $tick, $at + 42 );

		$this->assertSame( [], $this->fed() );
		$this->assertSame( [], $this->firehose_jobs() );
	}

	public function test_a_firehose_that_logs_nothing_for_the_job_is_said(): void {
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'jobs', 'pattern' => '/jobs', 'action' => 'skip' ],
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
		];
		\Newspack_Event_Logger_Nodes\Log_Manager::reset();
		$errors = $this->capture_stderr();
		$at     = 1_790_008_017.0;
		$tick   = $this->prefetch_tick( '0', 43, $at );

		$this->ring( $tick, $at + 43 );

		$this->assertSame( [], $this->firehose_jobs() );
		$this->assertCount( 1, $this->fed(), 'the feed still carried it' );
		$said = (array) $errors;
		$this->assertCount( 1, $said );
		$this->assertStringEndsWith( 'overview-prefetch:tick: WARNING: ' . Overview_Prefetch::HANDLER . " not logged to the firehose\n", $said[0] );
	}

	public function test_any_other_partition_queues_nothing(): void {
		$tick = $this->prefetch_tick( '2', 43, 1_790_008_017.0 );
		$this->ring( $tick, 1_790_008_017.0 + 43 );

		$this->assertSame( [], $this->fed() );
		$this->assertSame( [], $this->firehose_jobs() );
	}

	public function test_it_rides_the_router_tick(): void {
		$tick = $this->prefetch_tick( '0', 43, 1_790_008_017.0 );

		$this->assertSame( 'router', ( fn () => $this->mode )->call( $tick ) );
	}
}
