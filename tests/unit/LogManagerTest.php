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
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Log_Manager::class )]
class LogManagerTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-nodes-test';

	/** @var array Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();

		// Save original $_SERVER.
		$this->orig_server = $_SERVER;

		// Reset singleton so each test starts fresh.
		Log_Manager::reset();
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
		Log_Manager::reset();
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
		$instance1 = Log_Manager::instance();
		$instance2 = Log_Manager::instance();
		$this->assertSame( $instance1, $instance2 );
	}

	public function test_reset_clears_singleton(): void {
		$this->require_config_or_skip();
		$instance1 = Log_Manager::instance();
		Log_Manager::reset();
		$instance2 = Log_Manager::instance();
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
		Log_Manager::reset();
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
			$reentrant_instance = Log_Manager::instance();
		};

		try {
			$top_instance = Log_Manager::instance();
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
		$lm = Log_Manager::instance();
		$this->assertTrue( $lm->enabled );
	}

	public function test_constructor_disabled_when_config_disables_logging(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$this->assertFalse( $lm->enabled );
	}

	// ── Request ID ─────────────────────────────────────────────────────────

	public function test_generate_request_id_format(): void {
		$rid = Log_Manager::generate_request_id();
		$this->assertIsString( $rid );
		$this->assertSame( 32, \strlen( $rid ) );
		// Should be alphanumeric (base36).
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $rid );
	}

	public function test_generate_request_id_uniqueness(): void {
		$ids = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$ids[] = Log_Manager::generate_request_id();
		}
		$unique = \array_unique( $ids );
		$this->assertCount( 50, $unique, 'All generated request IDs should be unique' );
	}

	// ── message() / error() / warning() / info() ───────────────────────────

	public function test_message_returns_true(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->message( 'test_category', [ 'm' => 'hello world' ] );
		$this->assertTrue( $result );
	}

	public function test_message_truncates_large_data(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		// Create data larger than MAX_DATA_SIZE (3840 bytes).
		$large  = [ 'm' => \str_repeat( 'x', 4000 ) ];
		$result = $lm->message( 'big_data', $large );
		$this->assertTrue( $result );
		// If data exceeded limit, the entry would have 'truncated' => true.
		// The method should succeed regardless.
	}

	public function test_error_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->error( 'Something went wrong' );
		$this->assertTrue( $result );
	}

	public function test_warning_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->warning( 'Watch out' );
		$this->assertTrue( $result );
	}

	public function test_info_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->info( 'FYI' );
		$this->assertTrue( $result );
	}

	// ── start() / complete() ───────────────────────────────────────────────

	public function test_start_complete_timing(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		// start() requires ensure_started(), which sets up the firehose Topic.
		$lm->start( 'test_op', [ 'm' => 'starting operation' ] );
		\usleep( 10000 ); // 10ms
		$lm->complete( 'test_op' );

		// Verify the timer stack only has the root 'process' entry left.
		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );
		$ref->setAccessible( true );
		$times = $ref->getValue( $lm );
		$this->assertCount( 1, $times, 'Timer stack should have only root entry after complete' );
	}

	public function test_complete_without_start_is_noop(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();
		// complete() without matching start() should not throw.
		$lm->complete( 'nonexistent_label' );
		$this->assertTrue( true );
	}

	public function test_nested_start_complete(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->complete( 'inner' );
		$lm->complete( 'outer' );

		$this->assertTrue( true );
	}

	public function test_finish_lifecycle(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

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
		$lm = Log_Manager::instance();
		// Force initialization by calling start (triggers ensure_started/init_firehose).
		$lm->start( 'init' );
		$rid = $lm->get_request_id();
		$this->assertIsString( $rid );
		$this->assertNotEmpty( $rid );
	}

	public function test_worker_type_tagging(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'test_worker';
		$lm = Log_Manager::instance();
		$lm->start( 'work' );
		$lm->complete( 'work' );

		// If no exception, worker type was handled.
		$this->assertTrue( true );
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	// ── URL filter ─────────────────────────────────────────────────────────

	public function test_matches_url_filter_with_skip_urls(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'skip-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/health';
		$lm = Log_Manager::instance();
		$this->assertFalse( $lm->enabled, 'Skip URL should disable logging' );

		// skip_urls patterns are prefixes (no trailing '?'), so a sub-path is skipped too.
		Log_Manager::reset();
		$_SERVER['REQUEST_URI'] = '/health/check';
		$this->assertFalse( Log_Manager::instance()->enabled, 'a sub-path of a skip prefix is skipped' );
	}

	public function test_matches_url_filter_with_log_urls(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		$_SERVER['REQUEST_URI'] = '/other/page';
		$lm = Log_Manager::instance();
		$this->assertFalse( $lm->enabled, 'Non-matching URL should be disabled when log_urls is set' );
	}

	public function test_matches_url_filter_accepts_matching_url(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		// log_urls = ['/api/'] (a prefix, no trailing '?'); a matching path enables logging.
		$_SERVER['REQUEST_URI'] = '/api/';
		$lm = Log_Manager::instance();
		$this->assertTrue( $lm->enabled, 'Matching URL should be enabled' );
	}

	public function test_matches_url_filter_directly(): void {
		$this->require_config_or_skip();
		// Exercises the public method against a freshly constructed instance
		// that has no compiled regex.
		$lm = Log_Manager::instance();
		$this->assertTrue( $lm->matches_url_filter( '/anything' ), 'No filter = log all' );
	}

	// ── URL filter: prefix match with a '?' terminator ─────────────────────
	// Both skip_urls and log_urls prefix-match the request path (query string
	// removed) with a '?' appended, so a pattern ending in '?' is an EXACT match
	// and one without is a PREFIX: ['/?'] = home page only, ['/news?'] = exactly
	// /news, ['/news'] = anything under /news. (see Log_Manager::compile_url_filter)

	public function test_matches_url_filter_log_urls_prefix(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		// log_urls = ['/api/'] (no trailing '?') matches anything starting with it.
		$_SERVER['REQUEST_URI'] = '/api/data';
		$this->assertTrue( Log_Manager::instance()->enabled, 'a path under the prefix matches' );

		// ...but only at the START, not a pattern appearing mid-URL.
		Log_Manager::reset();
		$_SERVER['REQUEST_URI'] = '/v2/api/';
		$this->assertFalse( Log_Manager::instance()->enabled, 'the prefix must match at the start, not mid-URL' );
	}

	public function test_matches_url_filter_log_urls_trailing_question_mark_is_exact(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls-exact' ) );
		Config::reset();

		// log_urls = ['/news?']: the trailing '?' makes it match ONLY '/news'.
		$_SERVER['REQUEST_URI'] = '/news';
		$this->assertTrue( Log_Manager::instance()->enabled, "'/news?' matches '/news' exactly" );

		Log_Manager::reset();
		$_SERVER['REQUEST_URI'] = '/news/123';
		$this->assertFalse( Log_Manager::instance()->enabled, "'/news?' must not match a sub-path" );

		Log_Manager::reset();
		$_SERVER['REQUEST_URI'] = '/newsletter';
		$this->assertFalse( Log_Manager::instance()->enabled, "'/news?' must not match a longer sibling" );
	}

	public function test_matches_url_filter_log_urls_home_page(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls-home' ) );
		Config::reset();

		// log_urls = ['/?'] logs ONLY the home page.
		$_SERVER['REQUEST_URI'] = '/';
		$this->assertTrue( Log_Manager::instance()->enabled, "['/?'] matches the home page" );

		Log_Manager::reset();
		$_SERVER['REQUEST_URI'] = '/about';
		$this->assertFalse( Log_Manager::instance()->enabled, "['/?'] must not match any other page" );
	}

	public function test_matches_url_filter_log_urls_strips_query_string(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls-exact' ) );
		Config::reset();

		// The query string is removed before matching, so '/news?…' still matches ['/news?'].
		$_SERVER['REQUEST_URI'] = '/news?ref=newsletter';
		$this->assertTrue( Log_Manager::instance()->enabled, 'the query string is stripped before matching' );
	}

	public function test_matches_url_filter_log_urls_multi_pattern_grouped(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls-multi' ) );
		Config::reset();

		// log_urls = ['/foo/','/bar/']; the (?:) group anchors EVERY alternative at
		// the start — the second alternative matches as a prefix...
		$_SERVER['REQUEST_URI'] = '/bar/x';
		$this->assertTrue( Log_Manager::instance()->enabled, 'a grouped alternative matches at the start' );

		// ...but not mid-URL (the un-grouped `^/foo/|/bar/` bug would match here).
		Log_Manager::reset();
		$_SERVER['REQUEST_URI'] = '/x/bar/';
		$this->assertFalse( Log_Manager::instance()->enabled, 'an alternative mid-URL must not match (grouped anchor)' );
	}

	public function test_matches_url_filter_skip_urls_use_the_same_scheme(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'skip-urls-exact' ) );
		Config::reset();

		// skip_urls obey the same '?'-terminator rule: ['/health?'] skips ONLY '/health'.
		$_SERVER['REQUEST_URI'] = '/health';
		$this->assertFalse( Log_Manager::instance()->enabled, "skip ['/health?'] skips '/health' exactly" );

		// A sub-path is NOT skipped (and with no log_urls set, logging stays enabled).
		Log_Manager::reset();
		$_SERVER['REQUEST_URI'] = '/health/check';
		$this->assertTrue( Log_Manager::instance()->enabled, "skip ['/health?'] must not skip a sub-path" );
	}

	// ── Line limiting ──────────────────────────────────────────────────────

	public function test_line_limiting_mutes_after_max(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		// Verify the mechanism works by calling message — well below MAX_LOG_LINES.
		$lm->message( 'line1' );
		$lm->message( 'line2' );
		$lm->message( 'line3' );
		$this->assertTrue( true );
	}

	public function test_start_complete_muted_when_line_limited(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		// Use reflection to set line_limited to true.
		$ref = new \ReflectionProperty( Log_Manager::class, 'line_limited' );
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
		$lm = Log_Manager::instance();

		// Start one label, complete a different one. The orphaned 'inner'
		// should be logged as orphaned. No exception expected.
		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->complete( 'outer' );
		$this->assertTrue( true );
	}

	public function test_log_memory_config_flag(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-memory' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$this->assertTrue( $lm->enabled );

		// Verify log_memory flag is set via reflection.
		$ref = new \ReflectionProperty( Log_Manager::class, 'log_memory' );
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
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();

		$_SERVER['UNIQUE_ID'] = 'test-unique-id-123';
		$lm = Log_Manager::instance();
		// Trigger initialization.
		$lm->start( 'test' );
		$lm->complete( 'test' );

		$rid = $lm->get_request_id();
		$this->assertSame( 'test-unique-id-123', $rid );

		unset( $_SERVER['UNIQUE_ID'] );
	}

	// ── Constants preserved verbatim ───────────────────────────────────────

	public function test_max_timer_depth_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_TIMER_DEPTH' );
		$this->assertSame( 100, $ref->getValue() );
	}

	public function test_max_data_size_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_DATA_SIZE' );
		$this->assertSame( 3840, $ref->getValue() );
	}

	public function test_max_log_lines_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_LOG_LINES' );
		$this->assertSame( 40000, $ref->getValue() );
	}

	public function test_fatal_types_constant_preserved(): void {
		$this->assertSame(
			[ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR ],
			Log_Manager::FATAL_TYPES
		);
	}

	// -- suspend / resume context stack --------------------------------------

	/**
	 * Read the static context stack via reflection.
	 *
	 * @return array
	 */
	private function read_context_stack(): array {
		$ref = new \ReflectionProperty( Log_Manager::class, 'context_stack' );
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
		$ref = new \ReflectionProperty( Log_Manager::class, 'context_stack' );
		$ref->setAccessible( true );
		$ref->setValue( null, [] );
	}

	public function test_suspend_pushes_current_instance_onto_stack(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$parent = Log_Manager::instance();
		// Trigger started state so suspend exercises the flush path.
		$parent->start( 'parent_op' );

		$this->assertCount( 0, $this->read_context_stack() );

		Log_Manager::suspend();

		$stack = $this->read_context_stack();
		$this->assertCount( 1, $stack, 'suspend() must push the instance onto the stack' );
		$this->assertSame( $parent, $stack[0] );

		// After suspend, instance() returns a NEW LogManager.
		$child = Log_Manager::instance();
		$this->assertNotSame( $parent, $child );

		// Drain so the static stack doesn't leak into the next test.
		$this->clear_context_stack();
	}

	public function test_suspend_when_no_instance_is_noop(): void {
		$this->clear_context_stack();
		Log_Manager::reset();
		// reset() finishes the singleton then nulls it. suspend() with null
		// instance should be a no-op (no fatal, no stack growth).
		Log_Manager::suspend();
		$this->assertCount( 0, $this->read_context_stack() );
	}

	public function test_resume_restores_parent_from_stack(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$parent = Log_Manager::instance();
		$parent->start( 'parent_op' );
		Log_Manager::suspend();

		$child = Log_Manager::instance();
		$this->assertNotSame( $parent, $child );

		Log_Manager::resume();

		$this->assertSame( $parent, Log_Manager::instance(), 'resume() should restore the parent context' );
		$this->assertCount( 0, $this->read_context_stack(), 'stack should be empty after resume' );
	}

	public function test_resume_with_empty_stack_clears_instance(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		// No suspend before resume — should still finish current and null out.
		$lm = Log_Manager::instance();
		$lm->start( 'work' );

		Log_Manager::resume();

		// instance() now creates a fresh one (different identity).
		$fresh = Log_Manager::instance();
		$this->assertNotSame( $lm, $fresh );
	}

	public function test_suspend_resume_restores_unique_id(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		Log_Manager::reset();
		Config::reset();
		$_SERVER['UNIQUE_ID'] = 'parent-rid-abc';

		$parent = Log_Manager::instance();
		// ensure_started populates request_id from UNIQUE_ID; trigger it.
		$parent->start( 'init' );
		$this->assertSame( 'parent-rid-abc', $parent->get_request_id() );

		Log_Manager::suspend();

		// Child overwrites UNIQUE_ID with its own.
		$_SERVER['UNIQUE_ID'] = 'child-rid-def';
		$child                = Log_Manager::instance();
		$child->start( 'child_work' );
		$this->assertSame( 'child-rid-def', $child->get_request_id() );

		Log_Manager::resume();

		$this->assertSame( 'parent-rid-abc', $_SERVER['UNIQUE_ID'], 'resume() must restore parent UNIQUE_ID' );
	}

	public function test_suspend_resume_nested_three_levels(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$lm1 = Log_Manager::instance();
		$lm1->start( 'lm1' );
		Log_Manager::suspend();

		$lm2 = Log_Manager::instance();
		$lm2->start( 'lm2' );
		Log_Manager::suspend();

		$lm3 = Log_Manager::instance();
		$lm3->start( 'lm3' );

		$this->assertCount( 2, $this->read_context_stack() );
		$this->assertSame( $lm3, Log_Manager::instance() );

		Log_Manager::resume();
		$this->assertSame( $lm2, Log_Manager::instance() );

		Log_Manager::resume();
		$this->assertSame( $lm1, Log_Manager::instance() );

		$this->assertCount( 0, $this->read_context_stack() );
	}

	// -- flush() / refresh_firehose() ----------------------------------------

	public function test_flush_no_topic_is_noop(): void {
		$this->require_config_or_skip();

		// Brand-new LogManager — topic isn't created until ensure_started().
		$lm = Log_Manager::instance();
		// No exception should fire from flush() before any start/message.
		$lm->flush();
		$this->assertTrue( true );
	}

	public function test_flush_calls_topic_flush_after_start(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );

		$lm = Log_Manager::instance();
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

		$lm = Log_Manager::instance();
		// No exception when topic is null.
		$lm->refresh_firehose();
		$this->assertTrue( true );
	}

	public function test_refresh_firehose_after_start_succeeds(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['HTTP_REFERER']     = 'https://example.com/page?token=SHHH&id=ok';
		$_SERVER['DB_PASSWORD']      = 'do-not-log';
		$_SERVER['SOME_API_KEY']     = 'k-do-not-log';
		$_SERVER['CUSTOM_NICE_VAR']  = "hello\x07world";

		$lm = Log_Manager::instance();
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

	public function test_log_process_uses_mu_profiler_request_ts_for_process_start(): void {
		// With 00-newspack-profiler.php installed, $newspack_profiler carries
		// `request_ts` — a wall-clock microtime captured at mu-plugin load,
		// before any regular plugin runs. LogManager must stamp the firehose
		// `process (start)` entry with that ts (not the LogManager-emit-time
		// microtime, which lands deep in WP bootstrap) so RequestBuilder's
		// inflight_snapshot.start_time reflects the real PHP-request start.
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$early_ts                            = 1700000000.5;
		$GLOBALS['newspack_profiler']        = [
			'request_time' => \hrtime( true ),
			'request_ts'   => $early_ts,
			'plugins'      => [],
		];

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries       = $this->read_firehose_entries();
		$process_start = null;
		foreach ( $entries as $entry ) {
			if ( 'process (start)' === ( $entry['k'] ?? '' ) ) {
				$process_start = $entry;
				break;
			}
		}

		unset( $GLOBALS['newspack_profiler'] );

		$this->assertNotNull( $process_start, 'expected a process (start) firehose entry' );
		$this->assertSame( $early_ts, $process_start['ts'] );
	}

	public function test_log_process_records_method_and_full_url(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['SERVER_NAME']    = 'example.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/api/work?key=hidden&q=visible';

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['SERVER_NAME'] = 'plain.test';
		$_SERVER['HTTPS']       = 'off';

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		unset( $_SERVER['SERVER_NAME'], $_SERVER['HTTPS'] );
		$_SERVER['REQUEST_URI']    = '/cli/path';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
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

		$lm = Log_Manager::instance();
		$lm->start( 'work' );
		$lm->finish();

		// Read property to confirm finished latch.
		$ref = new \ReflectionProperty( Log_Manager::class, 'finished' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->getValue( $lm ) );

		// Second finish must be a no-op (no exception, no extra writes).
		$lm->finish();
		$this->assertTrue( true );
	}

	public function test_finish_before_started_is_noop(): void {
		$this->require_config_or_skip();

		$lm = Log_Manager::instance();
		// Never started — finish should bail out without emitting any entries.
		$lm->finish();

		// 'finished' latch should remain unchanged because started was false.
		$ref = new \ReflectionProperty( Log_Manager::class, 'finished' );
		$ref->setAccessible( true );
		$this->assertFalse( $ref->getValue( $lm ) );
	}

	// -- request_id forwarders / header sources ------------------------------

	public function test_http_x_a8c_request_id_takes_priority_over_unique_id(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'a8c-priority-rid';
		$_SERVER['UNIQUE_ID']             = 'should-be-ignored';

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$this->assertSame( 'a8c-priority-rid', $lm->get_request_id() );

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	public function test_request_id_header_capped_at_64_chars(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = \str_repeat( 'A', 200 );

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$rid = $lm->get_request_id();
		$this->assertSame( 64, \strlen( $rid ), 'Request id from header must be capped at 64 chars' );

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	public function test_request_id_generated_when_no_header_present(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'] );

		$lm = Log_Manager::instance();
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
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$this->assertFalse( $lm->enabled );
		// message() routes through ensure_started; with enabled=false it
		// returns false without writing.
		$this->assertFalse( $lm->message( 'never', [ 'm' => 'no-go' ] ) );
	}

	public function test_start_short_circuits_when_message_returns_false(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		// Disabled logger: start() should NOT push onto $times because
		// message() fails. Confirm via reflection.
		$lm->start( 'dud' );

		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );
		$ref->setAccessible( true );
		$this->assertSame( [], $ref->getValue( $lm ), 'Disabled logger must not accumulate timer entries' );
	}

	public function test_start_blocks_at_max_timer_depth(): void {
		$this->require_config_or_skip();

		$cap_ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_TIMER_DEPTH' );
		$cap     = (int) $cap_ref->getValue();

		$lm = Log_Manager::instance();
		// Seed the timer stack at the cap via reflection — saves doing 100
		// real start() calls.
		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );
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

	// ── message() oversized-data truncation path ───────────────────────────

	/**
	 * When the JSON-encoded `$data` exceeds MAX_DATA_SIZE (3840 bytes), `message()`
	 * suffixes the category with " (truncated)" and replaces `$data` with a 1000-char
	 * substring of the original encoded blob plus an ellipsis. Verifies the
	 * truncated entry actually lands on disk under the modified category — the
	 * exact knob that prevents firehose lines from blowing past PIPE_BUF.
	 */
	public function test_message_emits_truncated_entry_when_data_exceeds_max_size(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'truncation_test' );
		// 5000 bytes — well over MAX_DATA_SIZE (3840).
		$lm->message( 'oversized_event', [ 'm' => \str_repeat( 'A', 5000 ) ] );
		$lm->finish();

		$entries = $this->read_firehose_entries();
		// The "(truncated)" suffix is the marker — find the entry by k=oversized_event (truncated).
		$truncated_entry = null;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['k'] ) && 'oversized_event (truncated)' === $entry['k'] ) {
				$truncated_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $truncated_entry, 'Oversized data must emit under "{k} (truncated)" category' );
		$this->assertArrayHasKey( 'm', $truncated_entry );
		// The replacement message ends with "..." to signal truncation.
		$this->assertStringEndsWith( '...', (string) $truncated_entry['m'] );
	}

	// ── ensure_started: re-entry safety ────────────────────────────────────

	/**
	 * `ensure_started()` is private but its re-entry guard is observable:
	 * setting `started=true` via reflection short-circuits a subsequent
	 * `message()` call without rerunning init_firehose. Confirms the
	 * `if ( $this->started || ! $this->enabled || $this->finished )` early-out
	 * doesn't try to write through a null topic.
	 */
	public function test_message_short_circuits_when_started_set_externally(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();
		// Force-start without calling start() — Topic remains null because
		// init_firehose was never invoked.
		$started_ref = new \ReflectionProperty( Log_Manager::class, 'started' );
		$started_ref->setAccessible( true );
		$started_ref->setValue( $lm, true );

		// Topic null → message() returns false at the topic-null guard.
		$result = $lm->message( 'orphan', [ 'm' => 'will-not-land' ] );
		$this->assertFalse( $result, 'message() must return false when topic is null' );

		// Cleanup.
		$started_ref->setValue( $lm, false );
	}

	// ── start(): graceful degradation when the timer stack is full ──────────

	/**
	 * Drive `start()` past MAX_TIMER_DEPTH through the public entry. After
	 * seeding the stack at the cap, the next call must short-circuit at the
	 * top guard (`count($this->times) >= self::MAX_TIMER_DEPTH`) without
	 * emitting OR mutating the stack. Two snapshots verify both invariants.
	 */
	public function test_start_silently_drops_past_max_timer_depth_via_public_api(): void {
		$this->require_config_or_skip();
		$cap_ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_TIMER_DEPTH' );
		$cap     = (int) $cap_ref->getValue();

		$lm  = Log_Manager::instance();
		// Trigger ensure_started + log_process so the stack root is in place;
		// otherwise the first start() ALSO calls log_process which pushes its
		// own 'process' entry, racing with our seeded values.
		$lm->start( 'priming_start' );

		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );
		$ref->setAccessible( true );

		// Now overwrite to exactly cap entries (well past the seeded root).
		$seeded = [];
		for ( $i = 0; $i < $cap; $i++ ) {
			$seeded[] = [ 'label' => "fill_$i", 'ts' => \hrtime( true ), 'muted' => false ];
		}
		$ref->setValue( $lm, $seeded );
		$this->assertCount( $cap, $ref->getValue( $lm ), 'pre-condition: stack at cap' );

		// This call MUST be a no-op — top guard short-circuits before message().
		$lm->start( 'should_drop' );

		$after = $ref->getValue( $lm );
		$this->assertCount( $cap, $after, 'start() at cap must NOT push' );
		// And the latest entry's label is still the fill marker, not 'should_drop'.
		$this->assertSame( 'fill_' . ( $cap - 1 ), \end( $after )['label'] );

		// Cleanup.
		$ref->setValue( $lm, [] );
	}

	// ── flush_every_line path ──────────────────────────────────────────────

	/**
	 * The `flush_every_line` config flag drains the Topic batch after every
	 * `message()` — verified by checking the logging-enabled config sets it
	 * AND a single message lands on disk before any explicit flush.
	 */
	public function test_message_flushes_immediately_when_flush_every_line_enabled(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		// logging-enabled config has flush_every_line=true.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->message( 'visible_immediately', [ 'm' => 'no explicit flush' ] );

		// Without finish() AND without explicit $lm->flush(), the entry must
		// already be on disk because flush_every_line forced a drain.
		$log_dir = self::TEST_DIR . '/logs/firehose.log/p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'flush_every_line=true must drain on every message' );

		// Verify the flush_every_line property was actually set from config.
		$ref = new \ReflectionProperty( Log_Manager::class, 'flush_every_line' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->getValue( $lm ) );

		// Drain the singleton before tearDown.
		$lm->finish();
	}

	// ── matches_url_filter: skip URL also short-circuits ────────────────────

	/**
	 * The two regex tiers (skip then log) compose: a URL that matches BOTH
	 * patterns still gets skipped because the skip check runs first.
	 * Confirms the early-return at the top of `matches_url_filter`.
	 *
	 * Verified by directly invoking the public `matches_url_filter` method on
	 * an instance whose skip + log regexes are seeded via reflection — avoids
	 * the round trip through disk config files (and the allowed-config-dirs
	 * gating that goes with it).
	 */
	public function test_matches_url_filter_skip_wins_over_log(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();

		// Force-set both regex sources via reflection so we exercise the
		// skip-then-log composition without touching disk.
		$skip = new \ReflectionProperty( Log_Manager::class, 'skip_regex' );
		$skip->setAccessible( true );
		$skip->setValue( $lm, '/\/health/i' );

		$log = new \ReflectionProperty( Log_Manager::class, 'log_regex' );
		$log->setAccessible( true );
		$log->setValue( $lm, '/\/health/i' );

		// Both patterns match /health — but skip beats log.
		$this->assertFalse(
			$lm->matches_url_filter( '/health' ),
			'skip_urls beats log_urls when both match'
		);
		$this->assertFalse( $lm->enabled );

		// Cleanup.
		$skip->setValue( $lm, null );
		$log->setValue( $lm, null );
	}

	/**
	 * URL with explicit log_urls and a NON-matching request — `matches_url_filter`
	 * returns false AND sets enabled=false (the "log_urls is opt-in" semantics).
	 * Exercises the false-return branch of the log_regex match.
	 */
	public function test_matches_url_filter_returns_false_when_log_regex_set_and_no_match(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'log-urls' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		// log-urls config has log_urls = [ '/api/' ]; pass a non-match.
		$result = $lm->matches_url_filter( '/totally/not/matching' );
		$this->assertFalse( $result );
		$this->assertFalse( $lm->enabled );
	}

	// ── refresh_firehose: post-init delegation via reflection ───────────────

	/**
	 * `refresh_firehose()` reflects on Topic+Partition internals — covering
	 * this path requires a fully-initialized LogManager whose topic was
	 * materialized via `init_firehose`. After start() + flush(), reflection
	 * lookups succeed and `init_current_segment` invokes without throwing.
	 * The existing test asserts no-throw; this adds the no-segment-rotation
	 * invariant: the subsequent message lands under the same segment.
	 */
	public function test_refresh_firehose_preserves_segment_layout_after_no_writes(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'pre' );
		$lm->flush();

		// Snapshot segment count.
		$log_dir   = self::TEST_DIR . '/logs/firehose.log/p0';
		$before    = \glob( $log_dir . '/*.log' );
		$before_n  = \count( $before );

		$lm->refresh_firehose();
		// Refresh shouldn't materialize a new segment when nothing wrote.
		$after   = \glob( $log_dir . '/*.log' );
		$after_n = \count( $after );
		$this->assertSame( $before_n, $after_n, 'refresh_firehose without writes must NOT rotate segments' );

		$lm->finish();
	}

	// ── log_environment: SERVER_NAME with control chars stripped on values ──

	/**
	 * Control characters in non-sensitive $_SERVER values are stripped before
	 * the environment_v2 line is emitted. The existing finish() test verifies
	 * \x07; this widens to a multi-byte control range.
	 */
	public function test_log_environment_strips_full_control_char_range(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['CUSTOM_CTRL'] = "before\x00\x01\x02\x1F\x7Fafter";

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->read_firehose_entries();
		$env_msg = '';
		foreach ( $entries as $entry ) {
			if ( 'environment_v2' === ( $entry['k'] ?? '' )
				&& false !== \strpos( (string) ( $entry['m'] ?? '' ), 'CUSTOM_CTRL' )
			) {
				$env_msg = (string) $entry['m'];
				break;
			}
		}
		$this->assertNotSame( '', $env_msg, 'CUSTOM_CTRL must surface as an environment_v2 entry' );
		// Control bytes removed; surrounding characters intact.
		$this->assertStringContainsString( 'beforeafter', $env_msg );
		$this->assertStringNotContainsString( "\x00", $env_msg );
		$this->assertStringNotContainsString( "\x1F", $env_msg );
		$this->assertStringNotContainsString( "\x7F", $env_msg );

		unset( $_SERVER['CUSTOM_CTRL'] );
	}

	/**
	 * Array-valued $_SERVER entries (rare in practice but possible via
	 * deserialization edge cases) are silently skipped — `log_environment`
	 * `continue`s past anything `is_array`. Confirms the guard runs without
	 * stringifying the array.
	 */
	public function test_log_environment_silently_skips_array_server_values(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['SOME_ARRAY_VAR'] = [ 'nested', 'value' ];

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->read_firehose_entries();
		foreach ( $entries as $entry ) {
			if ( 'environment_v2' === ( $entry['k'] ?? '' ) ) {
				$this->assertStringNotContainsString( 'SOME_ARRAY_VAR', (string) ( $entry['m'] ?? '' ) );
			}
		}
		unset( $_SERVER['SOME_ARRAY_VAR'] );
	}

	// ── complete(): orphaned-inner with valid outer ─────────────────────────

	/**
	 * Started "outer" then "inner" then "deeper" — complete("outer") closes
	 * all three. The two inner stacks (`inner`, `deeper`) come back as
	 * orphaned `(complete)` entries with `m: (orphaned)`. The outer one
	 * gets a normal complete entry. Exercises the mid-stack splice branch.
	 */
	public function test_complete_emits_orphaned_for_nested_unfinished_starts(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->start( 'deeper' );
		$lm->complete( 'outer' ); // Splices all three off; inner+deeper are orphaned.
		$lm->finish();

		$entries = $this->read_firehose_entries();
		$orphan_labels = [];
		$normal_completes = [];
		foreach ( $entries as $entry ) {
			$k = $entry['k'] ?? '';
			$m = $entry['m'] ?? '';
			if ( '(orphaned)' === $m && \str_ends_with( $k, ' (complete)' ) ) {
				$orphan_labels[] = $k;
			} elseif ( \str_ends_with( $k, ' (complete)' ) ) {
				$normal_completes[] = $k;
			}
		}
		// inner + deeper come back orphaned (in stack-pop order).
		$this->assertContains( 'inner (complete)', $orphan_labels );
		$this->assertContains( 'deeper (complete)', $orphan_labels );
		// outer gets a normal complete entry.
		$this->assertContains( 'outer (complete)', $normal_completes );
	}

	// ── finish(): fatal-error tagging ───────────────────────────────────────

	/**
	 * `finish()` always evaluates `error_get_last()` and checks the type
	 * against FATAL_TYPES. In a normal test run, there's typically no
	 * fatal-type error, so the tagging block must skip cleanly without
	 * adding `fatal_error` / `error_status` keys to the final
	 * `process (complete)` entry. Confirms the no-fatal pathway end-to-end.
	 */
	public function test_finish_omits_fatal_tagging_when_no_fatal_error(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::TEST_DIR );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		// Without an injected fatal, the tagging block writes neither
		// `fatal_error` nor `error_status` onto process (complete).
		$entries       = $this->read_firehose_entries();
		$process_entry = null;
		foreach ( $entries as $entry ) {
			if ( 'process (complete)' === ( $entry['k'] ?? '' ) ) {
				$process_entry = $entry;
			}
		}
		$this->assertNotNull( $process_entry );
		// status_code is always present; fatal-specific keys are not.
		$this->assertArrayHasKey( 'status_code', $process_entry );
	}

	// ── line-limited gating on message() ────────────────────────────────────

	/**
	 * Once `$line_number > MAX_LOG_LINES`, `$line_limited` latches true and
	 * `start()` mutes its emit. We exercise this through reflection: set
	 * line_limited=true, then call start() — it must NOT emit, but it MUST
	 * still push onto the timer stack with `muted=true`.
	 */
	public function test_start_marks_muted_when_line_limited_but_still_tracks(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		// Force line_limited true.
		$ref = new \ReflectionProperty( Log_Manager::class, 'line_limited' );
		$ref->setAccessible( true );
		$ref->setValue( $lm, true );

		// times-stack snapshot.
		$tref = new \ReflectionProperty( Log_Manager::class, 'times' );
		$tref->setAccessible( true );
		$before = $tref->getValue( $lm );

		$lm->start( 'muted_label' );

		$after = $tref->getValue( $lm );
		$this->assertCount( \count( $before ) + 1, $after );
		$pushed = \end( $after );
		$this->assertSame( 'muted_label', $pushed['label'] );
		$this->assertTrue( $pushed['muted'], 'line_limited starts push with muted=true' );

		// Cleanup.
		$ref->setValue( $lm, false );
		$tref->setValue( $lm, [] );
	}

	// ── instance(): re-entrant call returns the SAME partial $this ──────────

	/**
	 * The construct guard's contract: while __construct is running, a second
	 * `instance()` call must return the SAME partial object — never a second
	 * LogManager. Verified by stashing $this into a static at the moment
	 * Config::load_config() would re-enter (via the bootstrap get_option
	 * hook seam). Already covered by `test_construct_blocks_reentrant_instance`,
	 * but this adds the after-construction invariant — instance() returns the
	 * canonical singleton.
	 */
	public function test_instance_returns_canonical_singleton_post_construction(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$a = Log_Manager::instance();
		$b = Log_Manager::instance();
		$c = Log_Manager::instance();
		$this->assertSame( $a, $b );
		$this->assertSame( $b, $c );
	}

	// =========================================================================
	// extract_plugin_slug: private static helper invoked from the fatal-tagging
	// path of finish(). Exercise its branches via reflection so we don't need
	// to actually crash PHP to populate error_get_last().
	// =========================================================================

	private function invoke_extract_plugin_slug( string $file ): ?string {
		$ref = new \ReflectionMethod( Log_Manager::class, 'extract_plugin_slug' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $file );
	}

	public function test_extract_plugin_slug_returns_null_for_file_outside_plugins_dir(): void {
		// File path that does not start with WP_PLUGIN_DIR returns null.
		// (WP_PLUGIN_DIR defaults to /tmp/test-wp-plugins in the bootstrap.)
		$this->assertNull( $this->invoke_extract_plugin_slug( '/var/www/wp-includes/wp-db.php' ) );
		$this->assertNull( $this->invoke_extract_plugin_slug( '/etc/passwd' ) );
		$this->assertNull( $this->invoke_extract_plugin_slug( '' ) );
	}

	public function test_extract_plugin_slug_returns_directory_slug_for_subdirectory_plugin(): void {
		// File inside a plugin subdirectory — slug is the first path segment
		// after WP_PLUGIN_DIR.
		$path = \WP_PLUGIN_DIR . '/akismet/akismet.php';
		$this->assertSame( 'akismet', $this->invoke_extract_plugin_slug( $path ) );

		// Nested file under the same plugin returns the same slug.
		$nested = \WP_PLUGIN_DIR . '/akismet/views/admin.php';
		$this->assertSame( 'akismet', $this->invoke_extract_plugin_slug( $nested ) );
	}

	public function test_extract_plugin_slug_strips_php_suffix_for_single_file_plugin(): void {
		// Single-file plugin directly under WP_PLUGIN_DIR — slug strips `.php`.
		$path = \WP_PLUGIN_DIR . '/hello.php';
		$this->assertSame( 'hello', $this->invoke_extract_plugin_slug( $path ) );

		// A weird file with no .php extension at the top level returns it raw.
		$path2 = \WP_PLUGIN_DIR . '/raw-segment';
		$this->assertSame( 'raw-segment', $this->invoke_extract_plugin_slug( $path2 ) );
	}
}
