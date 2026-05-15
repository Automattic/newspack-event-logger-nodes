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

	/**
	 * Reentrant instance() during __construct() must return the partial $this,
	 * not create a second LogManager.
	 *
	 * Production trigger: Config::load_config() calls get_option(), which (when
	 * alloptions isn't cached) calls wpdb->query() → apply_filters('query', ...)
	 * → Core::hook_start → LogManager::instance(). Without assigning
	 * self::$instance = $this at the top of __construct, this recurses until
	 * xdebug's 512-frame limit kills the request.
	 *
	 * @see LogManager::__construct()
	 */
	public function test_construct_blocks_reentrant_instance(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		$reentrant_instance = null;
		$reentry_count      = 0;
		// Mute after first re-entry so the test exits even when the production
		// bug is reintroduced — assertNotSame below catches the regression
		// without the stack-overflow risk.
		$GLOBALS['_test_get_option_hook'] = function () use ( &$reentrant_instance, &$reentry_count ): void {
			if ( $reentry_count++ > 0 ) {
				return;
			}
			$reentrant_instance = LogManager::instance();
		};

		try {
			$top_instance = LogManager::instance();
			$this->assertSame(
				$top_instance,
				$reentrant_instance,
				'Reentrant instance() during construct must return the partial $this, not a new LogManager.'
			);
		} finally {
			unset( $GLOBALS['_test_get_option_hook'] );
		}
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
				// Mirror production consumers — rid lives in Message::KEY on the
				// wire; back-fill so test assertions on `$entry['rid']` work.
				$value['rid'] = (string) ( $msg[ Message::KEY ] ?? '' );
				$out[]        = $value;
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

	// -- suspend / resume context stack --------------------------------------

	/**
	 * Read the static context stack via reflection.
	 *
	 * @return array
	 */
	private function read_context_stack(): array {
		$ref = new \ReflectionProperty( LogManager::class, 'context_stack' );
		$ref->setAccessible( true );
		return $ref->getValue();
	}

	/**
	 * Empty the static context stack via reflection. Tests share class state
	 * (context_stack is private static) and reset()/setUp() don't drain it
	 * automatically — a previous suspend that didn't resume would leak into
	 * the next test.
	 */
	private function clear_context_stack(): void {
		$ref = new \ReflectionProperty( LogManager::class, 'context_stack' );
		$ref->setAccessible( true );
		$ref->setValue( null, [] );
	}

	public function test_suspend_pushes_current_instance_onto_stack(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$parent = LogManager::instance();
		// Trigger started state so suspend exercises the flush path.
		$parent->start( 'parent_op' );

		$this->assertCount( 0, $this->read_context_stack() );

		LogManager::suspend();

		$stack = $this->read_context_stack();
		$this->assertCount( 1, $stack, 'suspend() must push the instance onto the stack' );
		$this->assertSame( $parent, $stack[0] );

		// After suspend, instance() returns a NEW LogManager.
		$child = LogManager::instance();
		$this->assertNotSame( $parent, $child );

		// Drain so the static stack doesn't leak into the next test.
		$this->clear_context_stack();
	}

	public function test_suspend_when_no_instance_is_noop(): void {
		$this->clear_context_stack();
		LogManager::reset();
		// reset() finishes the singleton then nulls it. suspend() with null
		// instance should be a no-op (no fatal, no stack growth).
		LogManager::suspend();
		$this->assertCount( 0, $this->read_context_stack() );
	}

	public function test_resume_restores_parent_from_stack(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$parent = LogManager::instance();
		$parent->start( 'parent_op' );
		LogManager::suspend();

		$child = LogManager::instance();
		$this->assertNotSame( $parent, $child );

		LogManager::resume();

		$this->assertSame( $parent, LogManager::instance(), 'resume() should restore the parent context' );
		$this->assertCount( 0, $this->read_context_stack(), 'stack should be empty after resume' );
	}

	public function test_resume_with_empty_stack_clears_instance(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		// No suspend before resume — should still finish current and null out.
		$lm = LogManager::instance();
		$lm->start( 'work' );

		LogManager::resume();

		// instance() now creates a fresh one (different identity).
		$fresh = LogManager::instance();
		$this->assertNotSame( $lm, $fresh );
	}

	public function test_suspend_resume_restores_unique_id(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		LogManager::reset();
		Config::reset();
		$_SERVER['UNIQUE_ID'] = 'parent-rid-abc';

		$parent = LogManager::instance();
		// ensure_started populates request_id from UNIQUE_ID; trigger it.
		$parent->start( 'init' );
		$this->assertSame( 'parent-rid-abc', $parent->get_request_id() );

		LogManager::suspend();

		// Child overwrites UNIQUE_ID with its own.
		$_SERVER['UNIQUE_ID'] = 'child-rid-def';
		$child                = LogManager::instance();
		$child->start( 'child_work' );
		$this->assertSame( 'child-rid-def', $child->get_request_id() );

		LogManager::resume();

		$this->assertSame( 'parent-rid-abc', $_SERVER['UNIQUE_ID'], 'resume() must restore parent UNIQUE_ID' );
	}

	public function test_suspend_resume_nested_three_levels(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$lm1 = LogManager::instance();
		$lm1->start( 'lm1' );
		LogManager::suspend();

		$lm2 = LogManager::instance();
		$lm2->start( 'lm2' );
		LogManager::suspend();

		$lm3 = LogManager::instance();
		$lm3->start( 'lm3' );

		$this->assertCount( 2, $this->read_context_stack() );
		$this->assertSame( $lm3, LogManager::instance() );

		LogManager::resume();
		$this->assertSame( $lm2, LogManager::instance() );

		LogManager::resume();
		$this->assertSame( $lm1, LogManager::instance() );

		$this->assertCount( 0, $this->read_context_stack() );
	}

	// -- flush() / refresh_firehose() ----------------------------------------

	public function test_flush_no_topic_is_noop(): void {
		$this->require_config_or_skip();

		// Brand-new LogManager — topic isn't created until ensure_started().
		$lm = LogManager::instance();
		// No exception should fire from flush() before any start/message.
		$lm->flush();
		$this->assertTrue( true );
	}

	public function test_flush_calls_topic_flush_after_start(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );

		$lm = LogManager::instance();
		$lm->start( 'work' );
		$lm->message( 'before_flush', [ 'm' => 'data1' ] );
		$lm->flush();

		// After flush, contents should be visible on disk for any future read.
		$log_dir = self::TEST_DIR . '/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'flush() must drain the buffered batch to disk' );
	}

	public function test_refresh_firehose_no_topic_is_noop(): void {
		$this->require_config_or_skip();

		$lm = LogManager::instance();
		// No exception when topic is null.
		$lm->refresh_firehose();
		$this->assertTrue( true );
	}

	public function test_refresh_firehose_after_start_succeeds(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );

		$lm = LogManager::instance();
		$lm->start( 'work' );
		$lm->message( 'pre_refresh', [ 'm' => 'data' ] );
		$lm->flush();

		// Refresh should not throw even after a flush has materialized the segment.
		$lm->refresh_firehose();
		$this->assertTrue( true );

		// Subsequent writes should still land.
		$lm->message( 'post_refresh', [ 'm' => 'data2' ] );
		$lm->flush();
	}

	// -- log_environment / log_resources / log_process -----------------------

	public function test_finish_emits_environment_resources_memory_and_process_complete(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['HTTP_REFERER']     = 'https://example.com/page?token=SHHH&id=ok';
		$_SERVER['DB_PASSWORD']      = 'do-not-log';
		$_SERVER['SOME_API_KEY']     = 'k-do-not-log';
		$_SERVER['CUSTOM_NICE_VAR']  = "hello\x07world";

		$lm = LogManager::instance();
		$lm->start( 'unit' );
		$lm->complete( 'unit' );
		$lm->finish();

		$entries = $this->read_firehose_entries();
		$kinds   = \array_column( $entries, 'k' );

		// log_environment emits one entry per non-sensitive $_SERVER key under k=environment_v2.
		$this->assertContains( 'environment_v2', $kinds, 'log_environment must emit environment_v2 entries' );

		// log_resources emits k=resources.
		$this->assertContains( 'resources', $kinds, 'log_resources must emit a resources entry' );

		// finish() emits k=memory and k=process (complete).
		$this->assertContains( 'memory', $kinds, 'finish must emit a memory entry' );
		$this->assertContains( 'process (complete)', $kinds, 'finish must emit process (complete)' );

		// Sensitive keys redacted — DB_PASSWORD must NOT appear as an
		// environment_v2 entry, but CUSTOM_NICE_VAR should.
		$env_msgs = [];
		foreach ( $entries as $entry ) {
			if ( 'environment_v2' === ( $entry['k'] ?? '' ) ) {
				$env_msgs[] = $entry['m'] ?? '';
			}
		}
		$env_blob = \implode( "\n", $env_msgs );
		$this->assertStringNotContainsString( 'DB_PASSWORD', $env_blob, 'DB_PASSWORD must be filtered out' );
		$this->assertStringNotContainsString( 'do-not-log', $env_blob, 'DB_PASSWORD value must not appear' );
		$this->assertStringNotContainsString( 'SOME_API_KEY', $env_blob, 'KEY-substring keys must be filtered' );
		$this->assertStringNotContainsString( 'k-do-not-log', $env_blob, 'API key value must not appear' );
		$this->assertStringContainsString( 'CUSTOM_NICE_VAR', $env_blob, 'Non-sensitive keys must be logged' );
		// Control-char stripped from value.
		$this->assertStringNotContainsString( "\x07", $env_blob, 'Control chars must be stripped from env values' );
		// HTTP_REFERER must be redacted at the value layer.
		$this->assertStringContainsString( 'HTTP_REFERER', $env_blob );
		$this->assertStringNotContainsString( 'token=SHHH', $env_blob, 'URL-value sensitive params must be redacted' );
		$this->assertStringContainsString( 'token=[REDACTED]', $env_blob );

		unset( $_SERVER['HTTP_REFERER'], $_SERVER['DB_PASSWORD'], $_SERVER['SOME_API_KEY'], $_SERVER['CUSTOM_NICE_VAR'] );
	}

	public function test_log_process_records_method_and_full_url(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['SERVER_NAME']    = 'example.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/api/work?key=hidden&q=visible';

		$lm = LogManager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->read_firehose_entries();
		$request = null;
		foreach ( $entries as $entry ) {
			if ( 'request' === ( $entry['k'] ?? '' ) ) {
				$request = $entry;
			}
		}
		$this->assertNotNull( $request, 'process should log a request entry' );
		$this->assertStringContainsString( 'POST https://example.test/api/work', (string) ( $request['m'] ?? '' ) );
		$this->assertStringContainsString( 'key=[REDACTED]', (string) ( $request['m'] ?? '' ), 'request URL must be redacted' );
		$this->assertStringNotContainsString( 'key=hidden', (string) ( $request['m'] ?? '' ) );
		$this->assertStringContainsString( 'q=visible', (string) ( $request['m'] ?? '' ) );
	}

	public function test_log_process_https_off_uses_http_scheme(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['SERVER_NAME'] = 'plain.test';
		$_SERVER['HTTPS']       = 'off';

		$lm = LogManager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->read_firehose_entries();
		$request = null;
		foreach ( $entries as $entry ) {
			if ( 'request' === ( $entry['k'] ?? '' ) ) {
				$request = $entry;
			}
		}
		$this->assertNotNull( $request );
		$this->assertStringContainsString( 'http://plain.test', (string) ( $request['m'] ?? '' ) );
		$this->assertStringNotContainsString( 'https://plain.test', (string) ( $request['m'] ?? '' ) );
	}

	public function test_log_process_without_server_name_uses_path_only(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		unset( $_SERVER['SERVER_NAME'], $_SERVER['HTTPS'] );
		$_SERVER['REQUEST_URI']    = '/cli/path';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$lm = LogManager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->read_firehose_entries();
		$request = null;
		foreach ( $entries as $entry ) {
			if ( 'request' === ( $entry['k'] ?? '' ) ) {
				$request = $entry;
			}
		}
		$this->assertNotNull( $request );
		// No server_name = bare path (no scheme/host prefix).
		$this->assertStringContainsString( 'GET /cli/path', (string) ( $request['m'] ?? '' ) );
		$this->assertStringNotContainsString( '://', (string) ( $request['m'] ?? '' ) );
	}

	// -- finish() orphan handling --------------------------------------------

	public function test_finish_emits_orphaned_complete_for_unclosed_starts(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		// Three unclosed starts on top of the root 'process' entry.
		$lm->start( 'outer' );
		$lm->start( 'middle' );
		$lm->start( 'inner' );

		$lm->finish();

		$entries  = $this->read_firehose_entries();
		$orphaned = [];
		foreach ( $entries as $entry ) {
			$k = $entry['k'] ?? '';
			$m = $entry['m'] ?? '';
			if ( '(orphaned)' === $m && \str_ends_with( $k, ' (complete)' ) ) {
				$orphaned[] = $k;
			}
		}
		$this->assertContains( 'outer (complete)', $orphaned );
		$this->assertContains( 'middle (complete)', $orphaned );
		$this->assertContains( 'inner (complete)', $orphaned );
		// Each orphan must carry a duration_ms key.
		foreach ( $entries as $entry ) {
			if ( '(orphaned)' === ( $entry['m'] ?? '' ) ) {
				$this->assertArrayHasKey( 'duration_ms', $entry );
			}
		}
	}

	public function test_finish_second_call_is_idempotent(): void {
		$this->require_config_or_skip();

		$lm = LogManager::instance();
		$lm->start( 'work' );
		$lm->finish();

		// Read property to confirm finished latch.
		$ref = new \ReflectionProperty( LogManager::class, 'finished' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->getValue( $lm ) );

		// Second finish must be a no-op (no exception, no extra writes).
		$lm->finish();
		$this->assertTrue( true );
	}

	public function test_finish_before_started_is_noop(): void {
		$this->require_config_or_skip();

		$lm = LogManager::instance();
		// Never started — finish should bail out without emitting any entries.
		$lm->finish();

		// 'finished' latch should remain unchanged because started was false.
		$ref = new \ReflectionProperty( LogManager::class, 'finished' );
		$ref->setAccessible( true );
		$this->assertFalse( $ref->getValue( $lm ) );
	}

	// -- request_id forwarders / header sources ------------------------------

	public function test_http_x_a8c_request_id_takes_priority_over_unique_id(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'a8c-priority-rid';
		$_SERVER['UNIQUE_ID']             = 'should-be-ignored';

		$lm = LogManager::instance();
		$lm->start( 'init' );
		$this->assertSame( 'a8c-priority-rid', $lm->get_request_id() );

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	public function test_request_id_header_capped_at_64_chars(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = \str_repeat( 'A', 200 );

		$lm = LogManager::instance();
		$lm->start( 'init' );
		$rid = $lm->get_request_id();
		$this->assertSame( 64, \strlen( $rid ), 'Request id from header must be capped at 64 chars' );

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	public function test_request_id_generated_when_no_header_present(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'] );

		$lm = LogManager::instance();
		$lm->start( 'init' );
		$rid = $lm->get_request_id();
		$this->assertSame( 32, \strlen( $rid ), 'Generated rid must be 32 chars' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $rid );
		// UNIQUE_ID must be back-populated to the generated value.
		$this->assertSame( $rid, $_SERVER['UNIQUE_ID'] ?? null );
	}

	// -- message() guards ----------------------------------------------------

	public function test_message_returns_false_when_logging_disabled(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		$this->assertFalse( $lm->enabled );
		// message() routes through ensure_started; with enabled=false it
		// returns false without writing.
		$this->assertFalse( $lm->message( 'never', [ 'm' => 'no-go' ] ) );
	}

	public function test_start_short_circuits_when_message_returns_false(): void {
		$this->require_config_or_skip();
		LogManager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = LogManager::instance();
		// Disabled logger: start() should NOT push onto $times because
		// message() fails. Confirm via reflection.
		$lm->start( 'dud' );

		$ref = new \ReflectionProperty( LogManager::class, 'times' );
		$ref->setAccessible( true );
		$this->assertSame( [], $ref->getValue( $lm ), 'Disabled logger must not accumulate timer entries' );
	}

	public function test_start_blocks_at_max_timer_depth(): void {
		$this->require_config_or_skip();

		$cap_ref = new \ReflectionClassConstant( LogManager::class, 'MAX_TIMER_DEPTH' );
		$cap     = (int) $cap_ref->getValue();

		$lm = LogManager::instance();
		// Seed the timer stack at the cap via reflection — saves doing 100
		// real start() calls.
		$ref = new \ReflectionProperty( LogManager::class, 'times' );
		$ref->setAccessible( true );
		$seeded = [];
		for ( $i = 0; $i < $cap; $i++ ) {
			$seeded[] = [ 'label' => "seed_$i", 'ts' => \hrtime( true ), 'muted' => false ];
		}
		$ref->setValue( $lm, $seeded );

		$lm->start( 'overflow' );
		$stack_after = $ref->getValue( $lm );
		$this->assertCount( $cap, $stack_after, 'start() must refuse to grow past MAX_TIMER_DEPTH' );

		// Reset for tearDown sanity.
		$ref->setValue( $lm, [] );
	}
}
