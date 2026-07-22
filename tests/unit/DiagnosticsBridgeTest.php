<?php
/**
 * Tests for Diagnostics_Bridge — the listener that carries substrate
 * `newspack_nodes/stderr` lines and `newspack_nodes/alert` fleet conditions
 * into the firehose / Error Log.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Diagnostics_Bridge;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
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
		Diagnostics_Bridge::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . \dirname( __DIR__ ) . '/configs/logging-enabled.php' );
		Config::reset();
		$_SERVER['REQUEST_URI']    = '/diag/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'] );
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		Diagnostics_Bridge::reset();
		Diagnostics_Bridge::$write_seam = null;
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
		$this->assertDirectoryDoesNotExist( self::TEST_DIR . '/logs/errors.fleet.p0' );
	}

	// ── alert bridge ──────────────────────────────────────────────────────────

	public function test_alert_rides_the_started_log_manager_firehose(): void {
		$lm = $this->started_log_manager();
		Diagnostics_Bridge::on_alert( [
			'key'      => 'worker_down:combined.p0',
			'severity' => 'critical',
			'message'  => 'Worker combined.p0 stopped heartbeating 9913s ago.',
		] );
		$lm->finish();

		$alerts = \array_values( \array_filter(
			$this->read_entries( 'firehose.p0' ),
			fn ( $e ) => 'alert' === ( $e['value']['k'] ?? '' )
		) );
		$this->assertCount( 1, $alerts, 'alert rode the active request firehose' );
		$this->assertSame( 'Worker combined.p0 stopped heartbeating 9913s ago.', $alerts[0]['value']['m'] );
		// Path (a) rides the firehose, NOT a direct errors.fleet.p0 write.
		$this->assertDirectoryDoesNotExist( self::TEST_DIR . '/logs/errors.fleet.p0' );
	}

	public function test_alert_writes_directly_to_the_fleet_errors_dir_when_no_started_log_manager(): void {
		Log_Manager::reset();
		Diagnostics_Bridge::on_alert( [
			'key'      => 'supervisor_down',
			'severity' => 'critical',
			'message'  => 'Supervisor stopped heartbeating 9914s ago.',
		] );

		// A dedicated single-writer dir, matched by the Error Log's existing
		// `errors.*` glob (glob("{logs}/errors.*")) → surfaces with zero UI change.
		$this->assertTrue( \fnmatch( 'errors.*', 'errors.fleet.p0' ), 'the errors.* subscription matches the fleet dir' );
		$errors = $this->read_entries( 'errors.fleet.p0' );
		$this->assertCount( 1, $errors, 'a fleet alert reaches errors.fleet.p0 with no active request logger' );
		$this->assertSame( 'fleet', $errors[0]['key'], 'KEY is the stable synthetic fleet id' );
		$this->assertSame( 'alert', $errors[0]['value']['k'] );
		$this->assertSame( 'Supervisor stopped heartbeating 9914s ago.', $errors[0]['value']['m'] );
	}

	// ── bootstrap wiring ──────────────────────────────────────────────────────

	public function test_bootstrap_registers_both_diagnostic_bridges(): void {
		$boot = $GLOBALS['_eln_boot_actions'] ?? [];
		$this->assertArrayHasKey( 'newspack_nodes/stderr', $boot );
		$this->assertArrayHasKey( 'newspack_nodes/alert', $boot );
		$this->assertContains( [ Diagnostics_Bridge::class, 'on_stderr' ], $boot['newspack_nodes/stderr'] );
		$this->assertContains( [ Diagnostics_Bridge::class, 'on_alert' ], $boot['newspack_nodes/alert'] );
	}

	public function test_alert_direct_write_fits_a_multibyte_message_under_pipe_buf(): void {
		Log_Manager::reset();
		// 900 × '错' = 2700 UTF-8 bytes, but each JSON-escapes to 6 bytes → ~5400
		// packed bytes, over PIPE_BUF. A char cap misses this; only a packed-byte
		// fit keeps the append atomic.
		Diagnostics_Bridge::on_alert( [
			'severity' => 'warning',
			'message'  => \str_repeat( '错', 900 ),
		] );

		$lines = $this->read_raw_lines( 'errors.fleet.p0' );
		$this->assertCount( 1, $lines, 'multibyte alert written, not dropped as oversize' );
		$this->assertLessThanOrEqual(
			Partition_Node::MAX_LINE_SIZE,
			\strlen( $lines[0] ) + 1,
			'packed line + newline stays under PIPE_BUF'
		);
	}

	public function test_fleet_errors_dir_is_registered_as_a_log_producer(): void {
		// Otherwise the substrate Log_Cleaner sweeps the undeclared dir.
		$producers = \newspack_event_logger_nodes_register_log_producers( [] );
		$this->assertContains( 'errors.fleet', $producers );
	}

	public function test_on_alert_swallows_a_throwing_write_path(): void {
		Log_Manager::reset();
		Diagnostics_Bridge::$write_seam = static function (): void {
			throw new \RuntimeException( 'disk full 6006' );
		};
		// A throw here would unwind the supervisor tick and starve the sibling
		// supervisor_periodic listeners (cron checks) — on_alert must swallow it.
		Diagnostics_Bridge::on_alert( [ 'severity' => 'critical', 'message' => 'x' ] );
		$this->assertTrue( true, 'on_alert swallowed the write throw' );
	}
}
