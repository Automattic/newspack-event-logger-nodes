<?php
declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Cli;

use Newspack_Event_Logger_Nodes\CLI\Ruleset_Bench_Command;
use PHPUnit\Framework\TestCase;

final class RulesetBenchCommandTest extends TestCase {

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
}
