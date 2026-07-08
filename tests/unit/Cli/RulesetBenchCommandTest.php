<?php
declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Cli;

use Newspack_Event_Logger_Nodes\CLI\Ruleset_Bench_Command;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/WPCLIStub.php';

/**
 * The __invoke sweep runs the full grid at the requested iteration count; the
 * default-iterations (200) paths legitimately exceed the 1s Small-test budget,
 * so raise the per-test limit as the substrate does for its heavy suites.
 */
#[Medium]
final class RulesetBenchCommandTest extends TestCase {

	/** @var \Memcached|null Saved handle restored in tearDown. */
	private mixed $prev_memd = null;

	protected function setUp(): void {
		$GLOBALS['_test_wp_cli_logs']    = [];
		$GLOBALS['_test_wp_cli_warns']   = [];
		$GLOBALS['_test_wp_cli_errors']  = [];
		$GLOBALS['_test_wp_cli_success'] = [];
		$this->prev_memd                 = Core::$memd;
		Core::$memd                      = new InMemoryMemcached();
	}

	protected function tearDown(): void {
		Core::$memd = $this->prev_memd;
	}

	public function test_synthetic_hooks_are_unique_and_sized(): void {
		$hooks = Ruleset_Bench_Command::synthetic_hooks( 65 );
		$this->assertCount( 65, $hooks );
		$this->assertSame( $hooks, \array_unique( $hooks ), 'hook names must be unique' );
		$this->assertContainsOnly( 'string', $hooks );
	}

	public function test_summarize_reports_median_and_p95(): void {
		$samples = [ 10.0, 20.0, 30.0, 40.0, 100.0 ]; // sorted median=30, p95≈100
		$summary = Ruleset_Bench_Command::summarize( $samples );
		$this->assertSame( 30.0, $summary['median'] );
		$this->assertSame( 100.0, $summary['p95'] );
		$this->assertSame( 5, $summary['n'] );
	}

	public function test_summarize_handles_empty(): void {
		$summary = Ruleset_Bench_Command::summarize( [] );
		$this->assertSame( 0.0, $summary['median'] );
		$this->assertSame( 0.0, $summary['p95'] );
		$this->assertSame( 0, $summary['n'] );
	}

	// -------------------------------------------------------------------------
	// __invoke — iteration-count parsing (the header line reflects the parsed N).
	// -------------------------------------------------------------------------

	public function test_invoke_header_reflects_integer_iteration_flag(): void {
		( new Ruleset_Bench_Command() )->__invoke( [], [ 'iterations' => 3 ] );
		$this->assertStringContainsString( '(median, 3 iters)', $GLOBALS['_test_wp_cli_logs'][0] );
	}

	public function test_invoke_defaults_iterations_to_200_when_flag_missing(): void {
		( new Ruleset_Bench_Command() )->__invoke( [], [] );
		$this->assertStringContainsString( '(median, 200 iters)', $GLOBALS['_test_wp_cli_logs'][0] );
	}

	public function test_invoke_clamps_nonpositive_iterations_to_one(): void {
		// max( 1, (int) '0' ) → 1; also exercises the numeric-string branch.
		( new Ruleset_Bench_Command() )->__invoke( [], [ 'iterations' => '0' ] );
		$this->assertStringContainsString( '(median, 1 iters)', $GLOBALS['_test_wp_cli_logs'][0] );
	}

	public function test_invoke_ignores_non_numeric_iterations(): void {
		// Non-numeric flag falls through the ternary to the 200 default.
		( new Ruleset_Bench_Command() )->__invoke( [], [ 'iterations' => 'abc' ] );
		$this->assertStringContainsString( '(median, 200 iters)', $GLOBALS['_test_wp_cli_logs'][0] );
	}

	// -------------------------------------------------------------------------
	// __invoke — full sweep: header + separator + 24 grid rows + blank + guidance.
	// -------------------------------------------------------------------------

	public function test_invoke_emits_full_grid_and_guidance(): void {
		( new Ruleset_Bench_Command() )->__invoke( [], [ 'iterations' => 1 ] );
		$logs = $GLOBALS['_test_wp_cli_logs'];

		// 1 header + 1 separator + (3 rule_counts x 8 hooks_per_rule) rows + 1 blank + 1 guidance.
		$this->assertCount( 28, $logs );
		$this->assertSame( \str_repeat( '-', 72 ), $logs[1] );
		$this->assertStringContainsString( 'Pick INLINE_HOOK_LIMIT', $logs[27] );

		// The 24 rows sit between the separator (index 1) and the trailing blank (index 26).
		$rows = \array_slice( $logs, 2, 24 );
		$this->assertCount( 24, $rows );
		// First cell is K=50, rule_count=1; each row carries three formatted medians.
		$this->assertMatchesRegularExpression( '/^\s*50 x 1 .*\|/', $rows[0] );
		// Every grid row exposes the three timing columns as decimals.
		foreach ( $rows as $row ) {
			$this->assertMatchesRegularExpression( '/\|\s+\d+\.\d\s+\d+\.\d\s+\d+\.\d$/', $row );
		}
	}

	public function test_invoke_covers_all_three_rule_counts(): void {
		( new Ruleset_Bench_Command() )->__invoke( [], [ 'iterations' => 1 ] );
		$rows = \array_slice( $GLOBALS['_test_wp_cli_logs'], 2, 24 );

		// RULE_COUNTS = [1, 10, 50]; each appears as the `x <count>` column 8 times.
		$counts = [ 1 => 0, 10 => 0, 50 => 0 ];
		foreach ( $rows as $row ) {
			if ( \preg_match( '/x\s+(\d+)\s/', $row, $m ) ) {
				$counts[ (int) $m[1] ]++;
			}
		}
		$this->assertSame( [ 1 => 8, 10 => 8, 50 => 8 ], $counts );
	}

	// -------------------------------------------------------------------------
	// measure_cell — pointer path degrades to the in-memory array when there is
	// no memcache handle (Core::$memd === null): no set/get/delete happens.
	// -------------------------------------------------------------------------

	public function test_invoke_runs_without_a_memcache_handle(): void {
		Core::$memd = null;
		( new Ruleset_Bench_Command() )->__invoke( [], [ 'iterations' => 1 ] );
		$logs = $GLOBALS['_test_wp_cli_logs'];

		$this->assertCount( 28, $logs );
		$this->assertStringContainsString( 'Pick INLINE_HOOK_LIMIT', $logs[27] );
		// Pointer column (last decimal on each row) is still produced from $hooks.
		$rows = \array_slice( $logs, 2, 24 );
		$this->assertMatchesRegularExpression( '/\d+\.\d$/', $rows[0] );
	}
}
