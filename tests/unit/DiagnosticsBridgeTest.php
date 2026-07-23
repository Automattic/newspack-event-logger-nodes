<?php
/**
 * Tests for Diagnostics_Bridge — the listener that carries substrate
 * `newspack_nodes/stderr` lines into the firehose / Error Log.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Diagnostics_Bridge;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Diagnostics_Bridge::class )]
class DiagnosticsBridgeTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-nodes-test';

	/** @var array<string, mixed> Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();
		$this->orig_server = $_SERVER;
		$this->rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . \dirname( __DIR__ ) . '/configs/logging-enabled.php' );
		Config::reset();
		$_SERVER['REQUEST_URI']    = '/diag/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'] );
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		Config::reset();
		$_SERVER = $this->orig_server;
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		$this->rmdir_recursive( self::TEST_DIR );
		parent::tearDown();
	}

	/** A started Log_Manager whose '/' rule logs every URL. */
	private function started_log_manager(): Log_Manager {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
		];
		Log_Manager::reset();
		Config::reset();
		$lm = Log_Manager::instance();
		$lm->start( 'diag' );
		return $lm;
	}

	/** Decode every firehose/errors partition entry as { key, value }. */
	private function read_entries( string $partition_dir ): array {
		$out = [];
		foreach ( $this->read_raw_lines( $partition_dir ) as $line ) {
			$message = Message::unpacked( $line );
			$value   = $message[ Message::VALUE ] ?? null;
			if ( \is_array( $value ) ) {
				$out[] = [ 'key' => (string) ( $message[ Message::KEY ] ?? '' ), 'value' => $value ];
			}
		}
		return $out;
	}

	/** Raw packed segment lines (newline-stripped) for a partition dir. */
	private function read_raw_lines( string $partition_dir ): array {
		$dir   = self::TEST_DIR . '/logs/' . $partition_dir;
		$lines = [];
		foreach ( \glob( $dir . '/*.log' ) ?: [] as $file ) {
			foreach ( \array_filter( \explode( "\n", (string) \file_get_contents( $file ) ) ) as $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	// ── stderr bridge ─────────────────────────────────────────────────────────

	public function test_stderr_logs_to_the_started_log_manager(): void {
		$lm = $this->started_log_manager();
		Diagnostics_Bridge::on_stderr( 'worker spinner detected 7731' );
		$lm->finish();

		$stderr = \array_values( \array_filter(
			$this->read_entries( 'firehose.p0' ),
			fn ( $e ) => 'stderr' === ( $e['value']['k'] ?? '' )
		) );
		$this->assertCount( 1, $stderr, 'stderr line logged to the active request firehose' );
		$this->assertSame( 'worker spinner detected 7731', $stderr[0]['value']['m'] );
	}

	public function test_stderr_is_dropped_when_no_started_log_manager(): void {
		Log_Manager::reset();
		Diagnostics_Bridge::on_stderr( 'orphan diagnostic 7732' );
		$this->assertNull( Log_Manager::started_instance() );
		$this->assertDirectoryDoesNotExist( self::TEST_DIR . '/logs/firehose.p0' );
		$this->assertDirectoryDoesNotExist( self::TEST_DIR . '/logs/errors.p0' );
	}

	// ── bootstrap wiring ──────────────────────────────────────────────────────

	public function test_bootstrap_registers_only_the_stderr_bridge(): void {
		$boot = $GLOBALS['_eln_boot_actions'] ?? [];
		$this->assertArrayHasKey( 'newspack_nodes/stderr', $boot );
		$this->assertContains( [ Diagnostics_Bridge::class, 'on_stderr' ], $boot['newspack_nodes/stderr'] );
		$this->assertArrayNotHasKey(
			'newspack_nodes/alert',
			$boot,
			'the alert bridge was replaced by the substrate alerts.p0 journal'
		);
	}
}
