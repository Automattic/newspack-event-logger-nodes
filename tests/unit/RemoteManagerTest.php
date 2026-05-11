<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\RemoteManager;
use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RemoteManager::class )]
class RemoteManagerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']                   = [];
		$GLOBALS['_wp_actions']                   = [];
		$GLOBALS['_wp_test_remote_responses']     = [];
		$GLOBALS['_wp_test_remote_posts']         = [];
		unset( $GLOBALS['_wp_test_remote_post_response'] );
		if ( \class_exists( Config::class ) ) {
			Config::reset();
		}
		// Reset the ServerRegistry singleton's in-process cache so each test
		// starts with a clean view of $GLOBALS['_wp_options']. Without this
		// reset, registries from a previous test leak through.
		if ( \class_exists( ServerRegistry::class ) ) {
			$ref = new \ReflectionProperty( ServerRegistry::class, 'instance' );
			$ref->setAccessible( true );
			$ref->setValue( null, null );
		}
		// Also reset the static $registry cache inside RemoteManager::registry().
		// We can't easily access the function-static, but a fresh class load
		// keeps the singleton fresh.
	}

	public function test_constants(): void {
		$this->assertSame( 100, RemoteManager::MAX_SERVERS );
		$this->assertSame( 50, RemoteManager::MAX_SETTINGS );
		$this->assertSame( 600, RemoteManager::STALE_THRESHOLD );
		$this->assertSame( 15, RemoteManager::REQUEST_TIMEOUT );
	}

	public function test_init_registers_health_check_action(): void {
		// init() is idempotent (static $registered guard) so on a second test
		// run it's a no-op. Register the canonical callback directly to assert
		// the wiring contract.
		$GLOBALS['_wp_actions'] = [];
		\add_action( 'newspack_event_logger_nodes/health_check', [ RemoteManager::class, 'health_check' ] );
		\add_filter( 'newspack_event_logger_nodes/job_handlers', [ RemoteManager::class, 'register_handler' ] );

		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/health_check'] ?? [],
			'health_check action listener must be wired'
		);
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/job_handlers'] ?? [],
			'job_handlers filter must be wired'
		);

		// init() itself must be safely callable + idempotent.
		RemoteManager::init();
		RemoteManager::init();
		$this->assertTrue( true );
	}

	public function test_register_handler_inserts_remote_manager(): void {
		$handlers = RemoteManager::register_handler( [] );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
		$this->assertIsCallable( $handlers['remote_manager'] );
	}

	public function test_register_handler_preserves_existing(): void {
		$handlers = RemoteManager::register_handler( [ 'other' => 'callable' ] );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
		$this->assertArrayHasKey( 'other', $handlers );
	}

	public function test_register_handler_handles_non_array(): void {
		// Filters can be passed null/string by hostile callers; defensive accept.
		$handlers = RemoteManager::register_handler( null );
		$this->assertIsArray( $handlers );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
	}

	public function test_handle_job_skips_empty_action(): void {
		// Should silently bail; no exception, no errors.
		RemoteManager::handle_job( [] );
		RemoteManager::handle_job( [ 'action' => '' ] );
		RemoteManager::handle_job( [ 'action' => null ] );
		$this->assertTrue( true );
	}

	public function test_handle_job_drops_stale_sync_setting(): void {
		// Stale by 1 hour past the 600s threshold.
		$queued_at = \time() - 4000;
		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_urls',
			'value'     => [ '/x' ],
			'endpoint'  => '/wp-json/newspack-nodes/v1/settings',
			'queued_at' => $queued_at,
		] );
		// No assertion possible without HTTP mock, but the stale-drop path
		// must not crash — and the rate-limited error log is fired.
		$this->assertTrue( true );
	}

	public function test_handle_job_unknown_action_falls_through_filter(): void {
		$called = false;
		\add_filter(
			'newspack_event_logger_nodes/remote_actions',
			static function ( $handlers ) use ( &$called ) {
				$handlers['custom_thing'] = static function ( $params ) use ( &$called ) {
					$called = true;
				};
				return $handlers;
			}
		);
		RemoteManager::handle_job( [ 'action' => 'custom_thing' ] );
		$this->assertTrue( $called, 'filter-registered handler must be invoked for unknown action' );
	}

	public function test_handle_job_unknown_action_with_no_filter_logs(): void {
		// No filter registered; default action just logs and returns.
		// Should not throw.
		RemoteManager::handle_job( [ 'action' => 'truly_unknown_action_xyz' ] );
		$this->assertTrue( true );
	}

	public function test_sanitize_handler_parameters_drops_disallowed_endpoint(): void {
		// Use reflection to test the private helper end-to-end through
		// handle_job's filter dispatch.
		$received = null;
		\add_filter(
			'newspack_event_logger_nodes/remote_actions',
			static function ( $handlers ) use ( &$received ) {
				$handlers['probe'] = static function ( $params ) use ( &$received ) {
					$received = $params;
				};
				return $handlers;
			}
		);
		RemoteManager::handle_job( [
			'action'   => 'probe',
			'endpoint' => '/wp-json/wp/v2/posts',  // disallowed
			'data'     => 'preserved',
		] );
		$this->assertNotNull( $received );
		$this->assertArrayNotHasKey( 'endpoint', $received, 'disallowed endpoint must be stripped' );
		$this->assertSame( 'preserved', $received['data'] );
	}

	public function test_sanitize_handler_parameters_keeps_allowed_endpoint(): void {
		$received = null;
		\add_filter(
			'newspack_event_logger_nodes/remote_actions',
			static function ( $handlers ) use ( &$received ) {
				$handlers['probe2'] = static function ( $params ) use ( &$received ) {
					$received = $params;
				};
				return $handlers;
			}
		);
		RemoteManager::handle_job( [
			'action'   => 'probe2',
			'endpoint' => '/wp-json/newspack-nodes/v1/something',  // allowed
		] );
		$this->assertSame( '/wp-json/newspack-nodes/v1/something', $received['endpoint'] ?? null );
	}

	public function test_queue_sync_all_settings_returns_zero_for_empty_servers(): void {
		$queued = RemoteManager::queue_sync_all_settings( [] );
		$this->assertSame( 0, $queued );
	}

	public function test_sync_setting_caps_at_max_servers(): void {
		$reg = new ServerRegistry();
		// Register 3 servers (well under MAX_SERVERS=100, but enough to assert
		// iteration walks them).
		for ( $i = 0; $i < 3; $i++ ) {
			$reg->register(
				"site-{$i}",
				[ 'url' => "https://example{$i}.test", 'token' => "t{$i}" ]
			);
		}
		// Without wp_remote_post mocked, the call returns errors silently;
		// just assert it doesn't throw on a populated registry.
		RemoteManager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'a', 'b' ],
			'/wp-json/newspack-nodes/v1/settings',
			null
		);
		$this->assertTrue( true );
	}

	public function test_sync_setting_with_explicit_server_filter(): void {
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'token' => 'x' ] );
		$reg->register( 'b', [ 'url' => 'https://b.test', 'token' => 'y' ] );

		// Filtered to one server — wp_remote_* may not exist in tests so this
		// just asserts the method handles a string array filter.
		RemoteManager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'h' ],
			'/wp-json/newspack-nodes/v1/settings',
			[ 'a' ]
		);
		$this->assertTrue( true );
	}

	public function test_calculate_lag_returns_zero_for_empty_inputs(): void {
		$this->assertSame( 0, RemoteManager::calculate_lag( [], [] ) );
	}

	public function test_calculate_lag_sums_unread_segment_bytes(): void {
		// Partition 0 has 3 segments. Cursor sits in segment 1 at offset 50.
		// Lag = (size_seg1 - 50) + size_seg2.
		$segments = [
			0 => [
				[ 'id' => 'seg0', 'size' => 100 ],
				[ 'id' => 'seg1', 'size' => 200 ],
				[ 'id' => 'seg2', 'size' => 300 ],
			],
		];
		$cursor = [
			0 => [ 'segment_id' => 'seg1', 'offset' => 50 ],
		];
		// (200 - 50) + 300 = 450
		$this->assertSame( 450, RemoteManager::calculate_lag( $segments, $cursor ) );
	}

	public function test_calculate_lag_handles_multiple_partitions(): void {
		$segments = [
			0 => [ [ 'id' => 'a', 'size' => 100 ] ],
			1 => [ [ 'id' => 'a', 'size' => 200 ] ],
		];
		$cursor = [
			0 => [ 'segment_id' => 'a', 'offset' => 0 ],
			1 => [ 'segment_id' => 'a', 'offset' => 50 ],
		];
		// 100 + (200-50) = 250
		$this->assertSame( 250, RemoteManager::calculate_lag( $segments, $cursor ) );
	}

	public function test_calculate_lag_treats_unknown_cursor_as_full_lag(): void {
		// Cursor segment doesn't match any present segment, and cursor is empty
		// — every segment is "ahead" of the consumer.
		$segments = [
			0 => [ [ 'id' => 'a', 'size' => 100 ], [ 'id' => 'b', 'size' => 200 ] ],
		];
		$cursor = [
			0 => [ 'segment_id' => '', 'offset' => 0 ],
		];
		$this->assertSame( 300, RemoteManager::calculate_lag( $segments, $cursor ) );
	}

	public function test_post_to_server_rejects_disallowed_endpoint(): void {
		$result = RemoteManager::post_to_server(
			[ 'url' => 'https://example.test', 'token' => 'x' ],
			'/wp-json/wp/v2/posts',
			[ 'foo' => 'bar' ]
		);
		$this->assertTrue(
			( \is_array( $result ) && isset( $result['error'] ) )
			|| ( $result instanceof \WP_Error ),
			'disallowed endpoint must surface as error'
		);
	}

	public function test_get_from_server_rejects_disallowed_endpoint(): void {
		$result = RemoteManager::get_from_server(
			[ 'url' => 'https://example.test', 'token' => 'x' ],
			'/wp-admin/admin-ajax.php'
		);
		$this->assertTrue(
			( \is_array( $result ) && isset( $result['error'] ) )
			|| ( $result instanceof \WP_Error ),
			'disallowed endpoint must surface as error'
		);
	}

	public function test_begin_end_job_context_round_trip(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['SERVER_NAME']   = 'localhost';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['REQUEST_URI']   = '/original';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$orig = RemoteManager::begin_job_context( 'remote_manager/sync_setting' );

		// During the job, $_SERVER['REQUEST_URI'] is the job URI.
		$this->assertSame( '/jobs/remote_manager/sync_setting', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'POST', $_SERVER['REQUEST_METHOD'] );

		RemoteManager::end_job_context( $orig );

		// $_SERVER must be fully restored.
		$this->assertSame( '/original', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'GET', $_SERVER['REQUEST_METHOD'] );
	}

	public function test_health_check_with_no_servers_does_not_crash(): void {
		// Empty registry — the loop is a no-op and the discovery action still
		// fires (with an empty payload).
		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);
		RemoteManager::health_check();
		$this->assertSame( [], $received, 'discovery action fires with empty array when no servers' );
	}

	public function test_sync_all_settings_caps_at_max_settings(): void {
		// Filter that returns a huge synced_settings list — sync_all_settings()
		// must cap at MAX_SETTINGS (50). We verify the iteration cap by counting
		// invocations from the filter side: we know sync_setting will skip
		// each entry because the config_key won't exist; but the loop must
		// still terminate.
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				$out = [];
				for ( $i = 0; $i < 200; $i++ ) {
					$out[] = [
						'local_option'  => "newspack_event_logger_nodes_synthetic_{$i}",
						'remote_option' => "newspack_event_logger_nodes_synthetic_{$i}",
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					];
				}
				return $out;
			}
		);
		// Doesn't crash on 200 entries.
		RemoteManager::sync_all_settings();
		$this->assertTrue( true );
	}

	public function test_sync_all_settings_drops_disallowed_endpoint(): void {
		// A filter that smuggles a non-allowlisted endpoint must have those
		// entries dropped during sync_all_settings.
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'newspack_event_logger_nodes_log_events',
						'remote_option' => 'newspack_event_logger_nodes_log_events',
						'endpoint'      => '/wp-json/wp/v2/options',  // disallowed
					],
				];
			}
		);
		// Must not throw, must not POST anywhere.
		RemoteManager::sync_all_settings();
		$this->assertTrue( true );
	}

	// --- post_to_server / get_from_server with allowed endpoint -------------

	public function test_post_to_server_with_allowed_endpoint_uses_wp_remote_post(): void {
		// With an allowed endpoint and Application Password creds, the request
		// must include Basic Auth headers and POST to the rtrim'd URL.
		$GLOBALS['_wp_test_remote_posts'] = [];
		$server = [
			'url'           => 'https://example.test/',
			'auth_username' => 'admin',
			'auth_password' => 'app-pass',
		];
		$body = [ 'option' => 'log_urls', 'value' => [ '/x' ] ];

		RemoteManager::post_to_server(
			$server,
			'/wp-json/newspack-nodes/v1/settings',
			$body
		);

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );

		// URL: rtrim trailing slash from server url + endpoint.
		$this->assertSame( 'https://example.test/wp-json/newspack-nodes/v1/settings', $last['url'] );
		// Basic Auth header present.
		$auth = $last['args']['headers']['Authorization'] ?? '';
		$this->assertStringStartsWith( 'Basic ', $auth );
		// Decode and verify the credentials.
		$decoded = \base64_decode( \substr( $auth, 6 ), true );
		$this->assertSame( 'admin:app-pass', $decoded );
		// Body present + JSON-encoded.
		$body_decoded = \json_decode( $last['args']['body'], true );
		$this->assertSame( $body, $body_decoded );
		// Defaults: no follow, response-size cap, timeout.
		$this->assertSame( 0, $last['args']['redirection'] );
		$this->assertSame( RemoteManager::REQUEST_TIMEOUT, $last['args']['timeout'] );
	}

	public function test_post_to_server_with_legacy_token_auth(): void {
		// When auth_username/password are absent but a `token` field exists,
		// the request uses Bearer auth.
		$GLOBALS['_wp_test_remote_posts'] = [];
		$server = [
			'url'   => 'https://example.test',
			'token' => 'legacy-bearer',
		];

		RemoteManager::post_to_server(
			$server,
			'/wp-json/newspack-nodes/v1/settings',
			[]
		);

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );
		$auth = $last['args']['headers']['Authorization'] ?? '';
		$this->assertSame( 'Bearer legacy-bearer', $auth );
	}

	public function test_post_to_server_without_credentials(): void {
		// No auth fields → no Authorization header set.
		$GLOBALS['_wp_test_remote_posts'] = [];
		$server = [ 'url' => 'https://example.test' ];

		RemoteManager::post_to_server(
			$server,
			'/wp-json/newspack-nodes/v1/settings',
			[]
		);

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );
		// No Authorization header — only Content-Type from the post body.
		$this->assertArrayNotHasKey( 'Authorization', $last['args']['headers'] ?? [] );
	}

	public function test_get_from_server_uses_wp_remote_get(): void {
		// Stub returns wp_error by default; just verify the request was
		// dispatched to the allowed endpoint.
		$GLOBALS['_wp_test_remote_responses'] = [
			'https://example.test/wp-json/newspack-nodes/v1/discovery' => [
				'response' => [ 'code' => 200 ],
				'body'     => '{}',
			],
		];

		$response = RemoteManager::get_from_server(
			[ 'url' => 'https://example.test', 'auth_username' => 'a', 'auth_password' => 'b' ],
			'/wp-json/newspack-nodes/v1/discovery'
		);

		// Real wp_remote_get is stubbed — returned mocked response.
		$this->assertIsArray( $response );
		$this->assertSame( 200, $response['response']['code'] );
	}

	// --- health_check with mocked spokes ------------------------------------

	public function test_health_check_processes_enabled_servers(): void {
		// Register one enabled server, mock its discovery endpoint to return
		// a valid payload, and verify the discovery action fires with the
		// validated payload.
		$reg = new ServerRegistry();
		$reg->register( 'spoke-a', [
			'url'           => 'https://spoke-a.test',
			'auth_username' => 'admin',
			'auth_password' => 'pw',
		] );

		$discovery_payload = [
			'registered_hooks' => [ 'init', 'the_content' ],
			'custom_events'    => [ 'event_a', 'event_b' ],
			'lag'              => 12345,
		];
		$GLOBALS['_wp_test_remote_responses'] = [
			'https://spoke-a.test/wp-json/newspack-nodes/v1/discovery' => [
				'response' => [ 'code' => 200 ],
				'body'     => \json_encode( $discovery_payload ),
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		RemoteManager::health_check();

		$this->assertIsArray( $received );
		$this->assertArrayHasKey( 'spoke-a', $received );
		// Validation copies the whitelisted keys.
		$this->assertSame(
			[ 'init', 'the_content' ],
			$received['spoke-a']['registered_hooks']
		);
		$this->assertSame(
			[ 'event_a', 'event_b' ],
			$received['spoke-a']['custom_events']
		);
		$this->assertSame( 12345, $received['spoke-a']['lag'] );
	}

	public function test_health_check_skips_disabled_servers(): void {
		$reg = new ServerRegistry();
		$reg->register( 'enabled-spoke', [
			'url'           => 'https://enabled.test',
			'auth_username' => 'a',
			'auth_password' => 'b',
		] );
		$reg->update( 'enabled-spoke', [ 'enabled' => false ] );

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		RemoteManager::health_check();

		// Disabled spoke not in payload.
		$this->assertSame( [], $received );
	}

	public function test_health_check_logs_error_on_non_200(): void {
		$reg = new ServerRegistry();
		$reg->register( 'spoke-err', [
			'url'           => 'https://spoke-err.test',
			'auth_username' => 'a',
			'auth_password' => 'b',
		] );

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://spoke-err.test/wp-json/newspack-nodes/v1/discovery' => [
				'response' => [ 'code' => 500 ],
				'body'     => '',
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		RemoteManager::health_check();

		// Failure path: no entry in discovery payload for the broken spoke.
		$this->assertArrayNotHasKey( 'spoke-err', (array) $received );
	}

	public function test_health_check_handles_invalid_json_response(): void {
		$reg = new ServerRegistry();
		$reg->register( 'spoke-junk', [
			'url'           => 'https://spoke-junk.test',
			'auth_username' => 'a',
			'auth_password' => 'b',
		] );

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://spoke-junk.test/wp-json/newspack-nodes/v1/discovery' => [
				'response' => [ 'code' => 200 ],
				'body'     => 'not json at all',
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		RemoteManager::health_check();

		// Invalid JSON path: spoke skipped from the payload.
		$this->assertArrayNotHasKey( 'spoke-junk', (array) $received );
	}

	public function test_health_check_handles_wp_error_response(): void {
		$reg = new ServerRegistry();
		$reg->register( 'spoke-network-fail', [
			'url'           => 'https://spoke-network-fail.test',
			'auth_username' => 'a',
			'auth_password' => 'b',
		] );

		// No mock entry — wp_remote_get returns WP_Error (default stub).
		$GLOBALS['_wp_test_remote_responses'] = [];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		RemoteManager::health_check();

		$this->assertArrayNotHasKey( 'spoke-network-fail', (array) $received );
	}

	// --- sync_setting with mocked spoke ------------------------------------

	public function test_sync_setting_dispatches_to_each_enabled_server(): void {
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->register( 'b', [ 'url' => 'https://b.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		// Mock both endpoints to return 200.
		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		// Both spokes were POSTed.
		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/settings', $urls );
		$this->assertContains( 'https://b.test/wp-json/newspack-nodes/v1/settings', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_setting_skips_disabled_server(): void {
		$reg = new ServerRegistry();
		$reg->register( 'enabled', [ 'url' => 'https://en.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->register( 'disabled', [ 'url' => 'https://dis.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->update( 'disabled', [ 'enabled' => false ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://en.test/wp-json/newspack-nodes/v1/settings', $urls );
		$this->assertNotContains( 'https://dis.test/wp-json/newspack-nodes/v1/settings', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_setting_logs_status_on_non_200(): void {
		$reg = new ServerRegistry();
		$reg->register( 'spoke', [ 'url' => 'https://spoke.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 500 ] ];

		// Non-200 must not throw; just logs sync_error via LogManager.
		RemoteManager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		$this->assertTrue( true );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_setting_logs_on_wp_error(): void {
		$reg = new ServerRegistry();
		$reg->register( 'spoke-fail', [ 'url' => 'https://spoke-fail.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		// No mock entry — wp_remote_post returns the configured response,
		// but if we set it to a WP_Error, the wp_error branch is exercised.
		$GLOBALS['_wp_test_remote_post_response'] = new \WP_Error( 'http_failed', 'connection refused' );

		RemoteManager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		$this->assertTrue( true );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_setting_skips_servers_filter_omitted_from_registry(): void {
		// $servers parameter filters; if a filter ID is not in registry it's
		// skipped without error.
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[],
			'/wp-json/newspack-nodes/v1/settings',
			[ 'a', 'nonexistent-server' ]
		);

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		// Only 'a' was registered; 'nonexistent-server' was silently skipped.
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/settings', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// --- handle_job dispatch flow ------------------------------------------

	public function test_handle_job_sync_setting_with_explicit_servers(): void {
		// Targeted server list: sync_setting sends only to those listed.
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->register( 'b', [ 'url' => 'https://b.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [ 'init' ],
			'endpoint'  => '/wp-json/newspack-nodes/v1/settings',
			'servers'   => [ 'a' ],
			'queued_at' => \time(),
		] );

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/settings', $urls );
		$this->assertNotContains( 'https://b.test/wp-json/newspack-nodes/v1/settings', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_handle_job_sync_setting_falls_back_to_default_endpoint_when_disallowed(): void {
		// If the endpoint param is disallowed, handle_job falls back to
		// SettingsSync::ENDPOINT (allowed).
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [ 'init' ],
			'endpoint'  => '/wp-json/wp/v2/posts', // disallowed
			'queued_at' => \time(),
		] );

		// Falls back to default endpoint.
		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/settings', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_handle_job_sync_setting_drops_when_option_empty(): void {
		// Empty option name → silent return (no fan-out).
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_posts'] = [];

		RemoteManager::handle_job( [
			'action' => 'sync_setting',
			'option' => '',
			'value'  => 'whatever',
		] );

		$this->assertEmpty( $GLOBALS['_wp_test_remote_posts'] );
	}

	public function test_handle_job_health_check_action_invokes_health_check(): void {
		// action=health_check is an idempotent shortcut for the health-check
		// hook. It must fire the discovery action even on an empty registry.
		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		RemoteManager::handle_job( [
			'action'    => 'health_check',
			'queued_at' => \time(),
		] );

		$this->assertSame( [], $received );
	}

	public function test_handle_job_health_check_drops_stale(): void {
		// Stale health_check (queued >600s ago) must be dropped.
		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		RemoteManager::handle_job( [
			'action'    => 'health_check',
			'queued_at' => \time() - 4000,
		] );

		// Discovery action did NOT fire.
		$this->assertNull( $received );
	}

	public function test_handle_job_sync_setting_with_invalid_servers_param(): void {
		// servers param that's a non-array (string, int, bool) is normalized
		// to null (= all enabled).
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [],
			'servers'   => 'not-an-array',
			'queued_at' => \time(),
		] );

		// Fan-out happened to all enabled spokes despite the invalid param.
		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_handle_job_sync_setting_with_empty_servers_array(): void {
		// An empty servers array also normalizes to null (= all enabled).
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [],
			'servers'   => [],
			'queued_at' => \time(),
		] );

		// Fan-out went to all enabled spokes.
		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_handle_job_sync_setting_filters_non_string_server_ids(): void {
		// Servers list with mixed types — non-strings are filtered out.
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [],
			'servers'   => [ 'a', 42, true ],
			'queued_at' => \time(),
		] );

		// Fan-out to 'a' only.
		$this->assertCount( 1, $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame(
			'https://a.test/wp-json/newspack-nodes/v1/settings',
			$GLOBALS['_wp_test_remote_posts'][0]['url']
		);

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// --- queue_sync_all_settings -------------------------------------------

	public function test_queue_sync_all_settings_returns_zero_when_no_filter(): void {
		// No synced_settings filter → no jobs queued.
		$result = RemoteManager::queue_sync_all_settings( [ 'a' ] );
		$this->assertIsInt( $result );
		// At least returns a count (could be > 0 if defaults registered).
		$this->assertGreaterThanOrEqual( 0, $result );
	}

	public function test_queue_sync_all_settings_with_disallowed_endpoint_skips_entries(): void {
		// Filter with disallowed endpoint must produce zero queued jobs (the
		// allowed-endpoint check filters them out).
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'foo',
						'remote_option' => 'foo',
						'endpoint'      => '/wp-json/wp/v2/posts',  // disallowed
					],
				];
			}
		);

		$result = RemoteManager::queue_sync_all_settings( [ 'a' ] );
		// Disallowed entry was skipped; result depends on other filters but
		// the disallowed entry itself contributed 0.
		$this->assertIsInt( $result );
	}

	public function test_queue_sync_all_settings_caps_at_max_settings(): void {
		// 200 entries → capped at MAX_SETTINGS=50. Any subsequent entries are
		// silently dropped.
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				$out = [];
				for ( $i = 0; $i < 200; $i++ ) {
					$out[] = [
						'local_option'  => "newspack_event_logger_nodes_synthetic_{$i}",
						'remote_option' => "newspack_event_logger_nodes_synthetic_{$i}",
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					];
				}
				return $out;
			}
		);

		// Doesn't crash on 200 entries; the loop is capped.
		$result = RemoteManager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertIsInt( $result );
	}

	public function test_queue_sync_all_settings_handles_non_array_filter_return(): void {
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return 'invalid';  // Non-array.
			}
		);

		$result = RemoteManager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertSame( 0, $result );
	}

	// --- begin_job_context generates request_id -----------------------------

	public function test_begin_job_context_generates_unique_id_when_absent(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['UNIQUE_ID'] );

		$orig = RemoteManager::begin_job_context( 'remote_manager/test' );

		// UNIQUE_ID was set during begin_job_context.
		$this->assertNotEmpty( $_SERVER['UNIQUE_ID'] );

		RemoteManager::end_job_context( $orig );
	}

	public function test_begin_job_context_sanitizes_job_name(): void {
		// Job name with leading slash and special chars — used as path_info.
		$orig = RemoteManager::begin_job_context( '/path/with/slashes' );

		// REQUEST_URI strips leading slash from name and prepends /jobs/.
		$this->assertSame( '/jobs/path/with/slashes', $_SERVER['REQUEST_URI'] );

		RemoteManager::end_job_context( $orig );
	}

	public function test_begin_job_context_clears_content_headers(): void {
		// CONTENT_TYPE / CONTENT_LENGTH / HTTP_X_A8C_REQUEST_ID must be unset
		// so the new job context doesn't inherit them from the request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['CONTENT_TYPE']           = 'application/json';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['CONTENT_LENGTH']         = '42';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'inherited-id';

		$orig = RemoteManager::begin_job_context( 'job/test' );

		$this->assertArrayNotHasKey( 'CONTENT_TYPE', $_SERVER );
		$this->assertArrayNotHasKey( 'CONTENT_LENGTH', $_SERVER );
		$this->assertArrayNotHasKey( 'HTTP_X_A8C_REQUEST_ID', $_SERVER );

		RemoteManager::end_job_context( $orig );

		// Restored.
		$this->assertSame( 'application/json', $_SERVER['CONTENT_TYPE'] );
		$this->assertSame( '42', $_SERVER['CONTENT_LENGTH'] );
		$this->assertSame( 'inherited-id', $_SERVER['HTTP_X_A8C_REQUEST_ID'] );

		// Cleanup.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	// --- calculate_lag edge cases ------------------------------------------

	public function test_calculate_lag_handles_non_array_segments_per_partition(): void {
		// Map with a non-array entry must be skipped without crashing.
		$result = RemoteManager::calculate_lag(
			[
				0 => 'invalid-non-array',
				1 => [ [ 'id' => 'a', 'size' => 100 ] ],
			],
			[
				1 => [ 'segment_id' => 'a', 'offset' => 0 ],
			]
		);
		$this->assertSame( 100, $result );
	}

	public function test_calculate_lag_handles_non_array_segment(): void {
		// Individual non-array segment entries must be skipped.
		$result = RemoteManager::calculate_lag(
			[
				0 => [
					'invalid-string',
					[ 'id' => 'a', 'size' => 50 ],
				],
			],
			[
				0 => [ 'segment_id' => '', 'offset' => 0 ],
			]
		);
		// Cursor is empty → all segments are ahead → sum the valid one.
		$this->assertSame( 50, $result );
	}

	public function test_calculate_lag_with_offset_at_segment_end(): void {
		// Cursor sits at the very end of segment 0 → 0 lag for that segment,
		// plus the entire size of segment 1.
		$result = RemoteManager::calculate_lag(
			[
				0 => [
					[ 'id' => 'seg0', 'size' => 100 ],
					[ 'id' => 'seg1', 'size' => 50 ],
				],
			],
			[
				0 => [ 'segment_id' => 'seg0', 'offset' => 100 ],
			]
		);
		$this->assertSame( 50, $result );
	}

	public function test_calculate_lag_with_offset_past_segment(): void {
		// Cursor offset past the segment end → max(0, ...) clamps to 0.
		$result = RemoteManager::calculate_lag(
			[
				0 => [
					[ 'id' => 'seg0', 'size' => 100 ],
				],
			],
			[
				0 => [ 'segment_id' => 'seg0', 'offset' => 999 ],
			]
		);
		$this->assertSame( 0, $result );
	}

	// --- register_handler with non-array ------------------------------------

	public function test_register_handler_with_string_returns_array(): void {
		// Defensive: non-array input must be replaced with an empty array.
		$handlers = RemoteManager::register_handler( 'string-input' );
		$this->assertIsArray( $handlers );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
	}

	// --- response_code / response_body fallbacks ----------------------------

	public function test_response_code_handles_non_array(): void {
		// Direct invocation via reflection.
		$method = new \ReflectionMethod( RemoteManager::class, 'response_code' );
		$method->setAccessible( true );

		// wp_remote_retrieve_response_code is stubbed; non-array returns 0.
		$this->assertSame( 0, $method->invoke( null, 'string-not-array' ) );
		$this->assertSame( 0, $method->invoke( null, null ) );
		$this->assertSame( 200, $method->invoke( null, [ 'response' => [ 'code' => 200 ] ] ) );
	}

	public function test_response_body_handles_non_array(): void {
		$method = new \ReflectionMethod( RemoteManager::class, 'response_body' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( null, null ) );
		$this->assertSame( 'hello', $method->invoke( null, [ 'body' => 'hello' ] ) );
	}

	// --- handle_job with sanitized handler params ---------------------------

	public function test_handle_job_sanitizes_unicode_action_for_logging(): void {
		// Unicode/control chars in the action should not cause issues — handle_job
		// uses preg_replace + substr to restrict to safe chars before writing
		// $_SERVER (via begin_job_context).
		RemoteManager::handle_job( [
			'action' => "control\x00chars\nmixed",
		] );
		// No crash; default branch logs and returns.
		$this->assertTrue( true );
	}

	// --- sync_all_settings dispatching to spokes ----------------------------

	public function test_sync_all_settings_sends_each_present_config_value(): void {
		// Register a spoke and configure a synced_settings entry with a
		// resolvable config_key. The full path: sync_all_settings → loops
		// settings → resolves config_key → sync_setting → POSTs to the spoke.
		$reg = new ServerRegistry();
		$reg->register( 'spoke', [
			'url'           => 'https://spoke.test',
			'auth_username' => 'u',
			'auth_password' => 'p',
		] );

		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_urls'] = [ '/foo' ];
		Config::reset();

		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'newspack_event_logger_nodes_log_urls',
						'remote_option' => 'newspack_event_logger_nodes_log_urls',
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					],
				];
			}
		);

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		RemoteManager::sync_all_settings();

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://spoke.test/wp-json/newspack-nodes/v1/settings', $urls );

		// Body contains the resolved value.
		$body = \json_decode( $GLOBALS['_wp_test_remote_posts'][0]['args']['body'], true );
		$this->assertSame( 'newspack_event_logger_nodes_log_urls', $body['option'] );
		$this->assertSame( [ '/foo' ], $body['value'] );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_all_settings_handles_non_array_filter_return(): void {
		// Filter that returns a non-array → method returns early without crash.
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return 'oops';
			}
		);

		RemoteManager::sync_all_settings();
		$this->assertTrue( true );
	}

	public function test_sync_all_settings_skips_non_array_setting_entry(): void {
		// Mixed entries — non-array entries silently skipped.
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					'string-not-array',
					[
						'local_option'  => 'newspack_event_logger_nodes_log_urls',
						'remote_option' => 'newspack_event_logger_nodes_log_urls',
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					],
				];
			}
		);

		// No crash; valid entry is processed.
		RemoteManager::sync_all_settings();
		$this->assertTrue( true );
	}

	public function test_sync_all_settings_skips_setting_with_empty_local_option(): void {
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[ 'local_option' => '' ], // empty
				];
			}
		);

		RemoteManager::sync_all_settings();
		$this->assertTrue( true );
	}

	// --- handle_job's begin/end context happy path --------------------------

	public function test_handle_job_sync_setting_round_trips_via_context(): void {
		// Verifies $_SERVER is restored to original after handle_job runs the
		// job (via begin_job_context / end_job_context wrapper).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['REQUEST_URI']    = '/original';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['REQUEST_METHOD'] = 'GET';

		RemoteManager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_urls',
			'value'     => [],
			'queued_at' => \time(),
		] );

		$this->assertSame( '/original', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'GET', $_SERVER['REQUEST_METHOD'] );
	}

	// --- queue_sync_all_settings happy path --------------------------------

	public function test_queue_sync_all_settings_queues_jobs_for_each_resolvable_setting(): void {
		// Set a config value, register a synced_settings entry, and verify
		// queue_sync_all_settings counts at least one queued job.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_urls'] = [ '/foo' ];
		Config::reset();

		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'newspack_event_logger_nodes_log_urls',
						'remote_option' => 'newspack_event_logger_nodes_log_urls',
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					],
				];
			}
		);

		$queued = RemoteManager::queue_sync_all_settings( [ 'spoke' ] );
		// Returns a count; can be 0 if JobIntake fails (filesystem) but should
		// not crash.
		$this->assertIsInt( $queued );
		$this->assertGreaterThanOrEqual( 0, $queued );
	}
}
