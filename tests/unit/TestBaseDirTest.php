<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * A test process removes the base it named, and the `-logging` tree beside
 * it, when it exits: `run-coverage.sh` sweeps only what a crashed run left.
 */
#[CoversNothing]
class TestBaseDirTest extends TestCase {

	/** @var list<string> Trees a child process was handed. */
	private array $child_trees = [];

	protected function tearDown(): void {
		foreach ( $this->child_trees as $tree ) {
			$this->rmdir_recursive( $tree );
		}
		parent::tearDown();
	}

	/**
	 * A writer registered after the bootstrap, as a Log_Manager finishing at
	 * shutdown is, recreates both trees; the removal still runs after it.
	 */
	public function test_a_run_leaves_no_tree_a_late_shutdown_writer_touched(): void {
		$script = 'require ' . \var_export( \dirname( __DIR__ ) . '/bootstrap.php', true ) . ';'
			. ' $base = (string) \getenv( "NEWSPACK_TEST_BASE_DIR" );'
			. ' \register_shutdown_function( static function () use ( $base ): void {'
			. ' @\mkdir( "{$base}-logging/logs", 0700, true ); \touch( "{$base}-logging/logs/kea-4471.log" );'
			. ' @\mkdir( $base, 0700, true ); \touch( "{$base}/kea-4471" ); } );'
			. ' echo $base;';
		$env = \getenv();
		unset( $env['NEWSPACK_TEST_BASE_DIR'], $env['LOCAL_NEWSPACK_NODES_CONF'] );
		$process = \proc_open( [ \PHP_BINARY, '-r', $script ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env );
		$this->assertIsResource( $process );
		$base = (string) \stream_get_contents( $pipes[1] );
		$err  = (string) \stream_get_contents( $pipes[2] );
		\fclose( $pipes[1] );
		\fclose( $pipes[2] );
		$this->assertSame( 0, \proc_close( $process ), $err );
		$this->child_trees = [ $base, "{$base}-logging" ];

		$this->assertStringContainsString( '/newspack-event-logger-nodes-test-', $base );
		$this->assertDirectoryDoesNotExist( $base );
		$this->assertDirectoryDoesNotExist( "{$base}-logging" );
	}
}
