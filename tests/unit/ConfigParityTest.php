<?php
/**
 * The scratch-directory invariants the logging suites depend on.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ConfigParityTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function logging_configs(): array {
		return [
			'enabled'  => [ 'logging-enabled' ],
			'disabled' => [ 'logging-disabled' ],
			'memory'   => [ 'logging-memory' ],
		];
	}

	#[DataProvider( 'logging_configs' )]
	public function test_a_logging_config_points_at_the_tree_the_suites_tear_down( string $name ): void {
		$this->assertSame(
			self::TEST_DIR,
			$this->config_values( $name )['base_directory'],
			"{$name}.php must match TestCase::TEST_DIR — storage nodes refuse a path outside the runtime tree."
		);
	}

	public function test_the_torn_down_tree_never_contains_the_baseline_base_directory(): void {
		$baseline = ( (array) require \dirname( __DIR__ ) . '/newspack-event-logger-nodes-test-config.php' )['base_directory'];

		$this->assertNotSame(
			self::TEST_DIR,
			$baseline,
			'The logging suites rmdir_recursive( TEST_DIR ); sharing it with the baseline base directory '
				. "deletes every other test's live make_temp_dir() tree."
		);
		$this->assertStringStartsNotWith( self::TEST_DIR . '/', $baseline );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function config_values( string $name ): array {
		return (array) require $this->config_path( $name );
	}
}
