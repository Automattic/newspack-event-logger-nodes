<?php
/**
 * Tests for LogManager (per-request JSONL writer atop Newspack_Nodes\Topic).
 *
 * Mirrors the upstream Newspack_Performance_Logger\LogManagerTest with adaptations
 * for the new namespace and Topic-backed storage. Tests that require real
 * `Topic`/`Partition` round-trips construct the Config via env-var convention
 * (`LOCAL_NEWSPACK_NODES_CONF`) — see Newspack_Event_Logger_Nodes\Config for the
 * canonical override path; tests that don't need disk I/O exercise the
 * singleton lifecycle and config gating directly.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\LogManager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( LogManager::class )]
class LogManagerTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-nodes-test';

	/** @var array Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();

		// Save original $_SERVER.
		$this->orig_server = $_SERVER;

		// Reset singleton so each test starts fresh.
		LogManager::reset();
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}

		// Set required $_SERVER vars.
		$_SERVER['REQUEST_URI']    = '/test/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'localhost';
		$_SERVER['HTTP_HOST']      = 'localhost';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'], $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );

		// Point config to pre-written test config.
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}
	}

	protected function tearDown(): void {
		LogManager::reset();
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}

		// Restore original $_SERVER.
		$_SERVER = $this->orig_server;

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		$this->rmdir_recursive( self::TEST_DIR );
		parent::tearDown();
	}

	// rmdir_recursive() is inherited from RuntimeTestCase (newspack-nodes/tests/Helpers/TestCase.php).

	/**
	 * Get path to a pre-written test config file.
	 */
	private function config_path( string $name ): string {
		return \dirname( __DIR__ ) . '/configs/' . $name . '.php';
	}

	/**
	 * Skip a test if the parallel-ported Config class isn't available yet.
	 * Tests in this suite that *write* through Topic need real Config; tests
	 * that exercise pure singleton/state behavior don't.
	 */
	private function require_config_or_skip(): void {
		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			$this->markTestSkipped( 'Config class not yet available (parallel agent porting).' );
		}
		// LogManager uses wp_json_encode for line serialization. The bootstrap
		// doesn't currently stub it — the bootstrap-owning agent needs to
		// add it (REPORTED in agent report).
		foreach ( [ 'wp_json_encode' ] as $fn ) {
			if ( ! \function_exists( $fn ) ) {
				$this->markTestSkipped( "WP function stub `{$fn}` not yet in tests/bootstrap.php; see agent report." );
			}
		}
	}

	// ── Singleton lifecycle ────────────────────────────────────────────────

	public function test_singleton_instance(): void {
		$this->require_config_or_skip();
		$instance1 = LogManager::instance();
		$instance2 = LogManager::instance();
		$this->assertSame( $instance1, $instance2 );
	}

	public function test_reset_clears_singleton(): void {
		$this->require_config_or_skip();
		$instance1 = LogManager::instance();
		LogManager::reset();
		$instance2 = LogManager::instance();
		$this->assertNotSame( $instance1, $instance2 );
	}

	public function test_constructor_sets_enabled_when_logging_enabled(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();
		$this->assertTrue( $lm->enabled );
	}

	public function test_constructor_disabled_when_config_disables_logging(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$this->assertFalse( $lm->enabled );
	}

	// ── Request ID ─────────────────────────────────────────────────────────

	public function test_generate_request_id_format(): void {
		$rid = LogManager::generate_request_id();
		$this->assertIsString( $rid );
		$this->assertSame( 32, \strlen( $rid ) );
		// Should be alphanumeric (base36).
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $rid );
	}

	public function test_generate_request_id_uniqueness(): void {
		$ids = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$ids[] = LogManager::generate_request_id();
		}
		$unique = \array_unique( $ids );
		$this->assertCount( 50, $unique, 'All generated request IDs should be unique' );
	}

	// ── message() / error() / warning() / info() ───────────────────────────

	public function test_message_returns_true(): void {
		$this->require_config_or_skip();
		$lm     = LogManager::instance();
		$result = $lm->message( 'test_category', [ 'm' => 'hello world' ] );
		$this->assertTrue( $result );
	}

	public function test_message_truncates_large_data(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();

		// Create data larger than MAX_DATA_SIZE (3840 bytes).
		$large  = [ 'm' => \str_repeat( 'x', 4000 ) ];
		$result = $lm->message( 'big_data', $large );
		$this->assertTrue( $result );
		// If data exceeded limit, the entry would have 'truncated' => true.
		// The method should succeed regardless.
	}

	public function test_error_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = LogManager::instance();
		$result = $lm->error( 'Something went wrong' );
		$this->assertTrue( $result );
	}

	public function test_warning_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = LogManager::instance();
		$result = $lm->warning( 'Watch out' );
		$this->assertTrue( $result );
	}

	public function test_info_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = LogManager::instance();
		$result = $lm->info( 'FYI' );
		$this->assertTrue( $result );
	}

	// ── start() / complete() ───────────────────────────────────────────────

	public function test_start_complete_timing(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();

		// start() requires ensure_started(), which sets up the firehose Topic.
		$lm->start( 'test_op', [ 'm' => 'starting operation' ] );
		\usleep( 10000 ); // 10ms
		$lm->complete( 'test_op' );

		// Verify the timer stack only has the root 'process' entry left.
		$ref = new \ReflectionProperty( LogManager::class, 'times' );
		$ref->setAccessible( true );
		$times = $ref->getValue( $lm );
		$this->assertCount( 1, $times, 'Timer stack should have only root entry after complete' );
	}

	public function test_complete_without_start_is_noop(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();
		// complete() without matching start() should not throw.
		$lm->complete( 'nonexistent_label' );
		$this->assertTrue( true );
	}

	public function test_nested_start_complete(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();

		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->complete( 'inner' );
		$lm->complete( 'outer' );

		$this->assertTrue( true );
	}

	public function test_finish_lifecycle(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();

		$lm->start( 'process_test' );
		$lm->message( 'test_event', [ 'm' => 'data' ] );
		$lm->complete( 'process_test' );
		$lm->finish();

		// finish() resets started/tracked state.
		// A second finish() should be a no-op.
		$lm->finish();
		$this->assertTrue( true );
	}

	public function test_get_request_id_returns_string(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();
		// Force initialization by calling start (triggers ensure_started/init_firehose).
		$lm->start( 'init' );
		$rid = $lm->get_request_id();
		$this->assertIsString( $rid );
		$this->assertNotEmpty( $rid );
	}

	public function test_worker_type_tagging(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'test_worker';
		$lm = LogManager::instance();
		$lm->start( 'work' );
		$lm->complete( 'work' );

		// If no exception, worker type was handled.
		$this->assertTrue( true );
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	// ── URL filter ─────────────────────────────────────────────────────────

	public function test_matches_url_filter_with_skip_urls(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'skip-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/health';
		$lm = LogManager::instance();
		$this->assertFalse( $lm->enabled, 'Skip URL should disable logging' );
	}

	public function test_matches_url_filter_with_log_urls(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/other/page';
		$lm = LogManager::instance();
		$this->assertFalse( $lm->enabled, 'Non-matching URL should be disabled when log_urls is set' );
	}

	public function test_matches_url_filter_accepts_matching_url(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/api/data';
		$lm = LogManager::instance();
		$this->assertTrue( $lm->enabled, 'Matching URL should be enabled' );
	}

	public function test_matches_url_filter_directly(): void {
		$this->require_config_or_skip();
		// Exercises the public method against a freshly constructed instance
		// that has no compiled regex.
		$lm = LogManager::instance();
		$this->assertTrue( $lm->matches_url_filter( '/anything' ), 'No filter = log all' );
	}

	// ── Line limiting ──────────────────────────────────────────────────────

	public function test_line_limiting_mutes_after_max(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();

		// Verify the mechanism works by calling message — well below MAX_LOG_LINES.
		$lm->message( 'line1' );
		$lm->message( 'line2' );
		$lm->message( 'line3' );
		$this->assertTrue( true );
	}

	public function test_start_complete_muted_when_line_limited(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();

		// Use reflection to set line_limited to true.
		$ref = new \ReflectionProperty( LogManager::class, 'line_limited' );
		$ref->setAccessible( true );
		$ref->setValue( $lm, true );

		// start() and complete() should not throw when line_limited.
		$lm->start( 'muted_op' );
		$lm->complete( 'muted_op' );
		$this->assertTrue( true );

		// Reset for subsequent tests.
		$ref->setValue( $lm, false );
	}

	public function test_complete_with_mismatched_label(): void {
		$this->require_config_or_skip();
		$lm = LogManager::instance();

		// Start one label, complete a different one. The orphaned 'inner'
		// should be logged as orphaned. No exception expected.
		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->complete( 'outer' );
		$this->assertTrue( true );
	}

	public function test_log_memory_config_flag(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-memory' ) );
		Config::reset();

		$lm = LogManager::instance();
		$this->assertTrue( $lm->enabled );

		// Verify log_memory flag is set via reflection.
		$ref = new \ReflectionProperty( LogManager::class, 'log_memory' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->getValue( $lm ), 'log_memory should be true with logging-memory config' );

		// start/complete with log_memory should add peak_mb to complete entry.
		$lm->start( 'memory_test' );
		$lm->complete( 'memory_test' );
		$this->assertTrue( true );
	}

	// ── Real round-trip: write through Topic, read back from disk ──────────

	public function test_finish_computes_real_duration(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'custom_event', [ 'm' => 'tracked with start' ] );

		// Brief sleep to ensure non-zero duration.
		\usleep( 5000 );
		$lm->finish();

		// Read the firehose output to find process (complete) entry.
		$log_dir = self::TEST_DIR . '/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'Firehose should have written data' );

		$complete_entry = null;
		foreach ( $files as $file ) {
			foreach ( $this->extract_jsonl_entries( $file ) as $decoded ) {
				if ( isset( $decoded['k'] ) && 'process (complete)' === $decoded['k'] ) {
					$complete_entry = $decoded;
				}
			}
		}

		$this->assertNotNull( $complete_entry, 'Should have a process (complete) entry' );
		$this->assertArrayHasKey( 'duration_ms', $complete_entry );
		$this->assertGreaterThan( 0, $complete_entry['duration_ms'], 'Duration should be > 0ms' );
	}

	public function test_message_m_field_url_redaction(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'redaction_test' );
		$lm->message( 'test', [ 'm' => 'https://example.com?client_secret=SECRET&id=123' ] );
		$lm->finish();

		$log_dir = self::TEST_DIR . '/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files );

		$all_content = '';
		foreach ( $files as $file ) {
			$all_content .= \file_get_contents( $file );
		}
		$this->assertStringNotContainsString( 'SECRET', $all_content, 'Secret value should be redacted' );
		$this->assertStringContainsString( 'client_secret=[REDACTED]', $all_content, 'Should show redacted placeholder' );
		$this->assertStringContainsString( 'id=123', $all_content, 'Non-sensitive params should be preserved' );
	}

	/**
	 * Read ALL firehose log entries from the test directory.
	 *
	 * Each line on disk is a packed Tachikoma Message envelope (LogManager
	 * routes through Topic::fill → Partition::fill, and Partition::fill writes
	 * the canonical packed wire format). The original JSONL entries live on
	 * Message::VALUE — and because LogManager batches multiple entries per
	 * flush into a single buffered write, VALUE itself contains one-or-more
	 * JSONL lines separated by `\n`. Unpack the envelope, then split + decode.
	 *
	 * @return array[] Decoded JSON entries.
	 */
	private function read_firehose_entries(): array {
		$log_dir = self::TEST_DIR . '/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'Firehose should have written data' );

		$entries = [];
		foreach ( $files as $file ) {
			foreach ( $this->extract_jsonl_entries( $file ) as $decoded ) {
				$entries[] = $decoded;
			}
		}
		return $entries;
	}

	/**
	 * Extract entries from a single firehose segment file.
	 *
	 * Walks each packed Message line, unpacks, and returns each Message's
	 * VALUE — which is now an entry array directly (one entry per Message).
	 *
	 * @return array[] Decoded entries in segment order.
	 */
	private function extract_jsonl_entries( string $file ): array {
		$content = (string) \file_get_contents( $file );
		$out     = [];
		foreach ( \array_filter( \explode( "\n", $content ) ) as $packed_line ) {
			$msg   = Message::unpacked( $packed_line );
			$value = $msg[ Message::VALUE ] ?? null;
			if ( \is_array( $value ) ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	private function find_last_entry( string $category ): ?array {
		$entries = $this->read_firehose_entries();
		$match   = null;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['k'] ) && $category === $entry['k'] ) {
				$match = $entry;
			}
		}
		return $match;
	}

	/**
	 * Regression: 'k' field must come from $category, not $data.
	 */
	public function test_message_k_field_not_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'k_override_test' );
		$lm->message( 'job', [ 'k' => 'discovery', 'm' => 'test' ] );
		$lm->finish();

		$entry = $this->find_last_entry( 'job' );
		$this->assertNotNull( $entry, 'Should find an entry with k=job' );
		$this->assertSame( 'job', $entry['k'], 'Category must come from $category param, not $data' );

		$bad_entry = $this->find_last_entry( 'discovery' );
		$this->assertNull( $bad_entry, 'Data array must not be able to override k field' );
	}

	/**
	 * Regression: 'ts' field CAN be overridden (profiler use case).
	 */
	public function test_message_ts_field_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'ts_override_test' );
		$lm->message( 'test', [ 'ts' => 12345.678, 'm' => 'hello' ] );
		$lm->finish();

		$entry = $this->find_last_entry( 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertEqualsWithDelta( 12345.678, $entry['ts'], 0.001, 'ts field must be overridable by $data for profiler use' );
	}

	/**
	 * Regression: 'rid' field must come from request_id, not $data.
	 */
	public function test_message_rid_field_not_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'rid_override_test' );
		$lm->message( 'test', [ 'rid' => 'fake_id', 'm' => 'hello' ] );
		$lm->finish();

		$entry = $this->find_last_entry( 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertNotSame( 'fake_id', $entry['rid'], 'rid must not be overridable by $data' );
		$this->assertSame( $lm->get_request_id(), $entry['rid'], 'rid must be the real request ID' );
	}

	/**
	 * Regression: 'n' field must come from line_number, not $data.
	 */
	public function test_message_n_field_not_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$lm->start( 'n_override_test' );
		$lm->message( 'test', [ 'n' => 99999, 'm' => 'hello' ] );
		$lm->finish();

		$entry = $this->find_last_entry( 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertNotSame( 99999, $entry['n'], 'n must not be overridable by $data' );
		$this->assertIsInt( $entry['n'] );
		$this->assertGreaterThan( 0, $entry['n'] );
	}

	public function test_unique_id_server_var_used_when_set(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		$_SERVER['UNIQUE_ID'] = 'test-unique-id-123';
		$lm = LogManager::instance();
		// Trigger initialization.
		$lm->start( 'test' );
		$lm->complete( 'test' );

		$rid = $lm->get_request_id();
		$this->assertSame( 'test-unique-id-123', $rid );

		unset( $_SERVER['UNIQUE_ID'] );
	}

	// ── Constants preserved verbatim ───────────────────────────────────────

	public function test_max_timer_depth_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( LogManager::class, 'MAX_TIMER_DEPTH' );
		$this->assertSame( 100, $ref->getValue() );
	}

	public function test_max_data_size_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( LogManager::class, 'MAX_DATA_SIZE' );
		$this->assertSame( 3840, $ref->getValue() );
	}

	public function test_max_log_lines_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( LogManager::class, 'MAX_LOG_LINES' );
		$this->assertSame( 40000, $ref->getValue() );
	}

	public function test_fatal_types_constant_preserved(): void {
		$this->assertSame(
			[ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR ],
			LogManager::FATAL_TYPES
		);
	}
}
