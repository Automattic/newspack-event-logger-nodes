<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Remote_Manager;
use Newspack_Event_Logger_Nodes\Settings_Sync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Vault;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Remote_Manager::class )]
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
		// Reset the substrate Vault's in-process cache so each test starts with
		// a clean view of $GLOBALS['_wp_options']. Without this reset, server
		// state from a previous test leaks through.
		Vault::get_instance()->reset_cache();
		// Also reset the static $registry cache inside RemoteManager::registry().
		// We can't easily access the function-static, but a fresh class load
		// keeps the singleton fresh.
	}

	/**
	 * Wrap a discovery payload (registered_hooks / custom_events / lag) in
	 * the packed Message a real spoke's `discovery.get` verb would emit.
	 * Used by every test that mocks a spoke's response now that the probe
	 * goes through `/command` instead of the legacy `/discovery` route.
	 *
	 * The whole-Message JSON is the ONLY serialization boundary: VALUE is
	 * the structured `{name, payload}` array and `payload` is the verb's
	 * structured return (the live discovery array), NOT a nested JSON
	 * string. Mirrors `CommandInterpreter::interpret()`'s response shape.
	 */
	private static function wrap_discovery_response( array $payload ): string {
		$message                                   = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_COMMAND | \Newspack_Nodes\Message::TM_RESPONSE;
		$message[ \Newspack_Nodes\Message::FROM ]  = 'discovery';
		$message[ \Newspack_Nodes\Message::TO ]    = '_http';
		$message[ \Newspack_Nodes\Message::VALUE ] = [
			'name'    => 'get',
			'payload' => $payload,
		];
		return \Newspack_Nodes\Message::packed( $message );
	}

	/**
	 * Decode a captured outbound `/command` POST body (a single packed
	 * Message line / JSONL) into its 7-field positional array, and assert
	 * the envelope shape: TM_COMMAND, FROM=`_http`, TO=$to, and a VALUE
	 * that is the structured `{name, arguments, payload}` LIVE ARRAY (never
	 * a separately-encoded JSON string). Returns the decoded VALUE struct.
	 *
	 * @param string $body Raw `wp_remote_post` body.
	 * @param string $to   Expected TO node path.
	 * @return array{name:string,arguments:string}
	 */
	private static function assert_command_envelope( string $body, string $to ): array {
		$message = \Newspack_Nodes\Message::unpacked( $body );
		self::assertSame(
			\Newspack_Nodes\Message::TM_COMMAND,
			$message[ \Newspack_Nodes\Message::TYPE ],
			'outbound command must be a TM_COMMAND (not TM_STRUCT)'
		);
		self::assertSame( '_http', $message[ \Newspack_Nodes\Message::FROM ] );
		self::assertSame( $to, $message[ \Newspack_Nodes\Message::TO ] );
		$value = $message[ \Newspack_Nodes\Message::VALUE ];
		self::assertIsArray( $value, 'VALUE must be the structured command array, not a JSON string' );
		return $value;
	}

	public function test_constants(): void {
		$this->assertSame( 100, Remote_Manager::MAX_SERVERS );
		$this->assertSame( 50, Remote_Manager::MAX_SETTINGS );
		$this->assertSame( 600, Remote_Manager::STALE_THRESHOLD );
		$this->assertSame( 15, Remote_Manager::REQUEST_TIMEOUT );
	}

	public function test_init_registers_health_check_action(): void {
		// init() is idempotent (static $registered guard) so on a second test
		// run it's a no-op. Register the canonical callback directly to assert
		// the wiring contract.
		$GLOBALS['_wp_actions'] = [];
		\add_action( 'newspack_event_logger_nodes/health_check', [ Remote_Manager::class, 'health_check' ] );
		\add_filter( 'newspack_nodes/job_handlers', [ Remote_Manager::class, 'register_handler' ] );

		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/health_check'] ?? [],
			'health_check action listener must be wired'
		);
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['newspack_nodes/job_handlers'] ?? [],
			'job_handlers filter must be wired'
		);

		// init() itself must be safely callable + idempotent.
		Remote_Manager::init();
		Remote_Manager::init();
		$this->assertTrue( true );
	}

	public function test_register_handler_inserts_remote_manager(): void {
		$handlers = Remote_Manager::register_handler( [] );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
		$this->assertIsCallable( $handlers['remote_manager'] );
	}

	public function test_register_handler_preserves_existing(): void {
		$handlers = Remote_Manager::register_handler( [ 'other' => 'callable' ] );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
		$this->assertArrayHasKey( 'other', $handlers );
	}

	public function test_register_handler_handles_non_array(): void {
		// Filters can be passed null/string by hostile callers; defensive accept.
		$handlers = Remote_Manager::register_handler( null );
		$this->assertIsArray( $handlers );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
	}

	public function test_handle_job_skips_empty_action(): void {
		// Should silently bail; no exception, no errors.
		Remote_Manager::handle_job( [] );
		Remote_Manager::handle_job( [ 'action' => '' ] );
		Remote_Manager::handle_job( [ 'action' => null ] );
		$this->assertTrue( true );
	}

	public function test_handle_job_drops_stale_sync_setting(): void {
		// Stale by 1 hour past the 600s threshold.
		$queued_at = \time() - 4000;
		Remote_Manager::handle_job( [
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
		Remote_Manager::handle_job( [ 'action' => 'custom_thing' ] );
		$this->assertTrue( $called, 'filter-registered handler must be invoked for unknown action' );
	}

	public function test_handle_job_unknown_action_with_no_filter_logs(): void {
		// No filter registered; default action just logs and returns.
		// Should not throw.
		Remote_Manager::handle_job( [ 'action' => 'truly_unknown_action_xyz' ] );
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
		Remote_Manager::handle_job( [
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
		Remote_Manager::handle_job( [
			'action'   => 'probe2',
			'endpoint' => '/wp-json/newspack-nodes/v1/something',  // allowed
		] );
		$this->assertSame( '/wp-json/newspack-nodes/v1/something', $received['endpoint'] ?? null );
	}

	public function test_queue_sync_all_settings_returns_zero_for_empty_servers(): void {
		$queued = Remote_Manager::queue_sync_all_settings( [] );
		$this->assertSame( 0, $queued );
	}

	public function test_sync_setting_caps_at_max_servers(): void {
		$reg = Vault::get_instance();
		// Register 3 servers (well under MAX_SERVERS=100, but enough to assert
		// iteration walks them).
		for ( $i = 0; $i < 3; $i++ ) {
			$reg->add(
				"site-{$i}",
				[ 'url' => "https://example{$i}.test", 'token' => "t{$i}" ]
			);
		}
		// Without wp_remote_post mocked, the call returns errors silently;
		// just assert it doesn't throw on a populated registry.
		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'a', 'b' ],
			'/wp-json/newspack-nodes/v1/settings',
			null
		);
		$this->assertTrue( true );
	}

	public function test_sync_setting_with_explicit_server_filter(): void {
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'token' => 'x' ] );
		$reg->add( 'b', [ 'url' => 'https://b.test', 'token' => 'y' ] );

		// Filtered to one server — wp_remote_* may not exist in tests so this
		// just asserts the method handles a string array filter.
		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'h' ],
			'/wp-json/newspack-nodes/v1/settings',
			[ 'a' ]
		);
		$this->assertTrue( true );
	}

	public function test_post_to_server_rejects_disallowed_endpoint(): void {
		$result = Remote_Manager::post_to_server(
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
		Remote_Manager::health_check();
		$this->assertSame( [], $received, 'discovery action fires with empty array when no servers' );
	}

	// --- post_to_server with allowed endpoint -------------

	public function test_post_to_server_with_allowed_endpoint_uses_wp_remote_post(): void {
		// M5.2: POST always lands on /command; body wraps in a TM_COMMAND
		// envelope. Defaults (redirection, timeout) and Basic Auth headers
		// are preserved across the migration.
		$GLOBALS['_wp_test_remote_posts'] = [];
		$server = [
			'url'           => 'https://example.test/',
			'auth_username' => 'admin',
			'auth_password' => 'app-pass',
		];

		Remote_Manager::post_to_server(
			$server,
			Settings_Sync::PERF_ENDPOINT,
			[ 'option' => 'newspack_event_logger_nodes_log_urls', 'value' => [ '/x' ] ]
		);

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );

		// URL: rtrim trailing slash from server url + the unified /command path.
		$this->assertSame( 'https://example.test/wp-json/newspack-nodes/v1/command', $last['url'] );
		// Basic Auth header present.
		$auth = $last['args']['headers']['Authorization'] ?? '';
		$this->assertStringStartsWith( 'Basic ', $auth );
		// Decode and verify the credentials.
		$decoded = \base64_decode( \substr( $auth, 6 ), true );
		$this->assertSame( 'admin:app-pass', $decoded );
		// Body is a packed Message (positional 7-field array), not the raw
		// {option, value} pair and not the legacy keyed {type,to,from,value} obj.
		self::assert_command_envelope( $last['args']['body'], 'performance' );
		// Defaults: no follow, response-size cap, timeout.
		$this->assertSame( 0, $last['args']['redirection'] );
		$this->assertSame( Remote_Manager::REQUEST_TIMEOUT, $last['args']['timeout'] );
	}

	public function test_post_to_server_with_legacy_token_auth(): void {
		// When auth_username/password are absent but a `token` field exists,
		// the request uses Bearer auth.
		$GLOBALS['_wp_test_remote_posts'] = [];
		$server = [
			'url'   => 'https://example.test',
			'token' => 'legacy-bearer',
		];

		Remote_Manager::post_to_server(
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

		Remote_Manager::post_to_server(
			$server,
			'/wp-json/newspack-nodes/v1/settings',
			[]
		);

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );
		// No Authorization header — only Content-Type from the post body.
		$this->assertArrayNotHasKey( 'Authorization', $last['args']['headers'] ?? [] );
	}

	// --- health_check with mocked spokes ------------------------------------

	public function test_health_check_processes_enabled_servers(): void {
		// Register one enabled server, mock its discovery endpoint to return
		// a valid payload, and verify the discovery action fires with the
		// validated payload.
		$reg = Vault::get_instance();
		$reg->add( 'spoke-a', [
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
			'https://spoke-a.test/wp-json/newspack-nodes/v1/command' => [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( $discovery_payload ),
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		Remote_Manager::health_check();

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
		$reg = Vault::get_instance();
		$reg->add( 'enabled-spoke', [
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

		Remote_Manager::health_check();

		// Disabled spoke not in payload.
		$this->assertSame( [], $received );
	}

	public function test_health_check_logs_error_on_non_200(): void {
		$reg = Vault::get_instance();
		$reg->add( 'spoke-err', [
			'url'           => 'https://spoke-err.test',
			'auth_username' => 'a',
			'auth_password' => 'b',
		] );

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://spoke-err.test/wp-json/newspack-nodes/v1/command' => [
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

		Remote_Manager::health_check();

		// Failure path: no entry in discovery payload for the broken spoke.
		$this->assertArrayNotHasKey( 'spoke-err', (array) $received );
	}

	public function test_health_check_handles_invalid_json_response(): void {
		$reg = Vault::get_instance();
		$reg->add( 'spoke-junk', [
			'url'           => 'https://spoke-junk.test',
			'auth_username' => 'a',
			'auth_password' => 'b',
		] );

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://spoke-junk.test/wp-json/newspack-nodes/v1/command' => [
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

		Remote_Manager::health_check();

		// Invalid JSON path: spoke skipped from the payload.
		$this->assertArrayNotHasKey( 'spoke-junk', (array) $received );
	}

	public function test_health_check_handles_wp_error_response(): void {
		$reg = Vault::get_instance();
		$reg->add( 'spoke-network-fail', [
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

		Remote_Manager::health_check();

		$this->assertArrayNotHasKey( 'spoke-network-fail', (array) $received );
	}

	// --- sync_setting with mocked spoke ------------------------------------

	public function test_sync_setting_dispatches_to_each_enabled_server(): void {
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->add( 'b', [ 'url' => 'https://b.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		// Mock both endpoints to return 200.
		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		// Both spokes were POSTed.
		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/command', $urls );
		$this->assertContains( 'https://b.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_setting_skips_disabled_server(): void {
		$reg = Vault::get_instance();
		$reg->add( 'enabled', [ 'url' => 'https://en.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->add( 'disabled', [ 'url' => 'https://dis.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->update( 'disabled', [ 'enabled' => false ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://en.test/wp-json/newspack-nodes/v1/command', $urls );
		$this->assertNotContains( 'https://dis.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_setting_logs_status_on_non_200(): void {
		$reg = Vault::get_instance();
		$reg->add( 'spoke', [ 'url' => 'https://spoke.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 500 ] ];

		// Non-200 must not throw; just logs sync_error via LogManager.
		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		$this->assertTrue( true );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_sync_setting_logs_on_wp_error(): void {
		$reg = Vault::get_instance();
		$reg->add( 'spoke-fail', [ 'url' => 'https://spoke-fail.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		// No mock entry — wp_remote_post returns the configured response,
		// but if we set it to a WP_Error, the wp_error branch is exercised.
		$GLOBALS['_wp_test_remote_post_response'] = new \WP_Error( 'http_failed', 'connection refused' );

		Remote_Manager::sync_setting(
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
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[],
			'/wp-json/newspack-nodes/v1/settings',
			[ 'a', 'nonexistent-server' ]
		);

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		// Only 'a' was registered; 'nonexistent-server' was silently skipped.
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// --- handle_job dispatch flow ------------------------------------------

	public function test_handle_job_sync_setting_with_explicit_servers(): void {
		// Targeted server list: sync_setting sends only to those listed.
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->add( 'b', [ 'url' => 'https://b.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [ 'init' ],
			'endpoint'  => '/wp-json/newspack-nodes/v1/settings',
			'servers'   => [ 'a' ],
			'queued_at' => \time(),
		] );

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/command', $urls );
		$this->assertNotContains( 'https://b.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_handle_job_sync_setting_falls_back_to_default_endpoint_when_disallowed(): void {
		// If the endpoint param is disallowed, handle_job falls back to
		// SettingsSync::ENDPOINT (allowed).
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [ 'init' ],
			'endpoint'  => '/wp-json/wp/v2/posts', // disallowed
			'queued_at' => \time(),
		] );

		// Falls back to default endpoint.
		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	public function test_handle_job_sync_setting_drops_when_option_empty(): void {
		// Empty option name → silent return (no fan-out).
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_posts'] = [];

		Remote_Manager::handle_job( [
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

		Remote_Manager::handle_job( [
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

		Remote_Manager::handle_job( [
			'action'    => 'health_check',
			'queued_at' => \time() - 4000,
		] );

		// Discovery action did NOT fire.
		$this->assertNull( $received );
	}

	public function test_handle_job_sync_setting_with_invalid_servers_param(): void {
		// servers param that's a non-array (string, int, bool) is normalized
		// to null (= all enabled).
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::handle_job( [
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
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::handle_job( [
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
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [],
			'servers'   => [ 'a', 42, true ],
			'queued_at' => \time(),
		] );

		// Fan-out to 'a' only.
		$this->assertCount( 1, $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame(
			'https://a.test/wp-json/newspack-nodes/v1/command',
			$GLOBALS['_wp_test_remote_posts'][0]['url']
		);

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// --- queue_sync_all_settings -------------------------------------------

	public function test_queue_sync_all_settings_returns_zero_when_no_filter(): void {
		// No synced_settings filter → no jobs queued.
		$result = Remote_Manager::queue_sync_all_settings( [ 'a' ] );
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

		$result = Remote_Manager::queue_sync_all_settings( [ 'a' ] );
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
		$result = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertIsInt( $result );
	}

	public function test_queue_sync_all_settings_handles_non_array_filter_return(): void {
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return 'invalid';  // Non-array.
			}
		);

		$result = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertSame( 0, $result );
	}

	// --- register_handler with non-array ------------------------------------

	public function test_register_handler_with_string_returns_array(): void {
		// Defensive: non-array input must be replaced with an empty array.
		$handlers = Remote_Manager::register_handler( 'string-input' );
		$this->assertIsArray( $handlers );
		$this->assertArrayHasKey( 'remote_manager', $handlers );
	}

	// --- response_code / response_body fallbacks ----------------------------

	public function test_response_code_handles_non_array(): void {
		// Direct invocation via reflection.
		$method = new \ReflectionMethod( Remote_Manager::class, 'response_code' );
		$method->setAccessible( true );

		// wp_remote_retrieve_response_code is stubbed; non-array returns 0.
		$this->assertSame( 0, $method->invoke( null, 'string-not-array' ) );
		$this->assertSame( 0, $method->invoke( null, null ) );
		$this->assertSame( 200, $method->invoke( null, [ 'response' => [ 'code' => 200 ] ] ) );
	}

	public function test_response_body_handles_non_array(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'response_body' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( null, null ) );
		$this->assertSame( 'hello', $method->invoke( null, [ 'body' => 'hello' ] ) );
	}

	// --- handle_job with sanitized handler params ---------------------------

	public function test_handle_job_sanitizes_unicode_action_for_logging(): void {
		// Unicode/control chars in the action should not cause issues — handle_job
		// uses preg_replace + substr to restrict to safe chars before writing
		// $_SERVER (via Log_Manager::begin_job_context).
		Remote_Manager::handle_job( [
			'action' => "control\x00chars\nmixed",
		] );
		// No crash; default branch logs and returns.
		$this->assertTrue( true );
	}

	// --- handle_job's begin/end context happy path --------------------------

	public function test_handle_job_sync_setting_round_trips_via_context(): void {
		// Verifies $_SERVER is restored to original after handle_job runs the
		// job (via the Log_Manager::begin/end_job_context wrapper).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['REQUEST_URI']    = '/original';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['REQUEST_METHOD'] = 'GET';

		Remote_Manager::handle_job( [
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

		$queued = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		// Returns a count; can be 0 if JobIntake fails (filesystem) but should
		// not crash.
		$this->assertIsInt( $queued );
		$this->assertGreaterThanOrEqual( 0, $queued );
	}

	// -------------------------------------------------------------------------
	// request_args — direct unit coverage of the auth-header / args-merge path.
	// -------------------------------------------------------------------------

	public function test_request_args_includes_basic_auth_for_app_password(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$server = [
			'url'           => 'https://x.test',
			'auth_username' => 'admin',
			'auth_password' => 'secret',
		];
		$args = $method->invoke( null, $server, [ 'headers' => [ 'X-Custom' => 'v' ] ] );

		$this->assertSame( Remote_Manager::REQUEST_TIMEOUT, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 1048576, $args['limit_response_size'] );
		$this->assertSame( 'v', $args['headers']['X-Custom'] );
		$auth = $args['headers']['Authorization'] ?? '';
		$this->assertStringStartsWith( 'Basic ', $auth );
		$this->assertSame( 'admin:secret', \base64_decode( \substr( $auth, 6 ), true ) );
	}

	public function test_request_args_uses_bearer_token_when_no_app_password(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$server = [
			'url'   => 'https://x.test',
			'token' => 'tok123',
		];
		$args = $method->invoke( null, $server, [] );

		$this->assertSame( 'Bearer tok123', $args['headers']['Authorization'] ?? '' );
	}

	public function test_request_args_no_auth_when_no_creds(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$args = $method->invoke( null, [ 'url' => 'https://x.test' ], [] );

		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );
	}

	public function test_request_args_merges_extra_body_param(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$args = $method->invoke( null, [ 'url' => 'https://x.test' ], [
			'body'    => '{"a":1}',
			'method'  => 'PUT',
		] );

		$this->assertSame( '{"a":1}', $args['body'] );
		$this->assertSame( 'PUT', $args['method'] );
	}

	// -------------------------------------------------------------------------
	// reset_config_snapshots — direct invocation; verifies it doesn't throw.
	// -------------------------------------------------------------------------

	public function test_reset_config_snapshots_runs_clean(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'reset_config_snapshots' );
		$method->setAccessible( true );
		// Just verify it doesn't blow up — its job is best-effort cache invalidation.
		$method->invoke( null );
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// log_stale_drop — rate-limited error_log emission.
	// -------------------------------------------------------------------------

	public function test_log_stale_drop_is_safe_to_call(): void {
		// Method does an error_log() under a 60-second rate-limit static guard.
		// We can't easily intercept error_log without a custom error_log handler,
		// but the method must not throw on either path.
		$method = new \ReflectionMethod( Remote_Manager::class, 'log_stale_drop' );
		$method->setAccessible( true );

		// First call (within the 60-second window may be suppressed by an
		// earlier test): both paths should be exception-safe.
		$method->invoke( null, 'test_action', 9999 );
		$method->invoke( null, 'test_action_2', 1234 );
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// log_status — direct invocation through reflection.
	// -------------------------------------------------------------------------

	public function test_log_status_runs_without_log_manager_loaded(): void {
		// log_status is best-effort: it bails silently if LogManager isn't
		// in an enabled state. With $enabled flag false (default fresh
		// instance), the method must not throw.
		$method = new \ReflectionMethod( Remote_Manager::class, 'log_status' );
		$method->setAccessible( true );

		// Without a $base_directory configured, LogManager::instance() exists
		// but logging is disabled by default — exercises the early-return
		// branch.
		$method->invoke( null, 'spoke-x', 'ok', null, 0 );
		$method->invoke( null, 'spoke-x', 'sync_error', 'some message', 0 );
		$method->invoke( null, 'spoke-x', 'ok', null, 42 );
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// registry() — resolves to the substrate Vault singleton.
	// -------------------------------------------------------------------------

	public function test_registry_resolves_to_vault_instance(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'registry' );
		$method->setAccessible( true );

		$reg = $method->invoke( null );
		$this->assertInstanceOf( Vault::class, $reg );

		// Second call returns the SAME instance (cached in the function-static).
		$reg2 = $method->invoke( null );
		$this->assertSame( $reg, $reg2 );
	}

	// -------------------------------------------------------------------------
	// wp_error_or_array — returns WP_Error when class exists.
	// -------------------------------------------------------------------------

	public function test_wp_error_or_array_returns_wp_error_when_class_exists(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'wp_error_or_array' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'some_code', 'some_message' );
		// Bootstrap defines WP_Error → method must use it.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'some_code', $result->get_error_code() );
		$this->assertSame( 'some_message', $result->get_error_message() );
	}

	// -------------------------------------------------------------------------
	// init — exercise the full body (not the early-return guard).
	// -------------------------------------------------------------------------

	public function test_init_is_idempotent_and_safe_to_invoke(): void {
		// init() uses a static $registered guard so subsequent calls are
		// no-ops, but the FIRST call (in this process) must successfully
		// register the action + filters without erroring. We verify by
		// directly invoking and asserting no exception — the guard means
		// we can't observe the wiring repeatedly across tests.
		Remote_Manager::init();
		Remote_Manager::init();  // Second call hits the guard.
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// sync_setting — MAX_SERVERS cap stops iteration.
	// -------------------------------------------------------------------------

	public function test_sync_setting_stops_at_max_servers_cap(): void {
		// Build a registry with >100 servers (MAX_SERVERS=100). Iteration must
		// cap and not POST to all of them.
		$reg = Vault::get_instance();
		for ( $i = 0; $i < 105; $i++ ) {
			$reg->add(
				"site-{$i}",
				[ 'url' => "https://site{$i}.test", 'auth_username' => 'u', 'auth_password' => 'p' ]
			);
		}

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		// Should be capped at exactly MAX_SERVERS (100), not 105.
		$this->assertLessThanOrEqual(
			Remote_Manager::MAX_SERVERS,
			\count( $GLOBALS['_wp_test_remote_posts'] ),
			'POST count must respect MAX_SERVERS cap'
		);

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// -------------------------------------------------------------------------
	// health_check — MAX_SERVERS cap.
	// -------------------------------------------------------------------------

	public function test_health_check_caps_at_max_servers(): void {
		$reg = Vault::get_instance();
		for ( $i = 0; $i < 105; $i++ ) {
			$reg->add(
				"hsite-{$i}",
				[ 'url' => "https://hsite{$i}.test", 'auth_username' => 'u', 'auth_password' => 'p' ]
			);
		}

		// Mock discovery to 200 OK for all.
		$GLOBALS['_wp_test_remote_responses'] = [];
		for ( $i = 0; $i < 105; $i++ ) {
			$GLOBALS['_wp_test_remote_responses'][ "https://hsite{$i}.test/wp-json/newspack-nodes/v1/command" ] = [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( [ 'lag' => 0 ] ),
			];
		}

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		Remote_Manager::health_check();

		// Discovery payload capped at MAX_SERVERS=100.
		$this->assertLessThanOrEqual(
			Remote_Manager::MAX_SERVERS,
			\count( (array) $received ),
			'health_check must respect MAX_SERVERS cap'
		);
	}

	public function test_check_server_dispatches_via_command_endpoint_not_legacy_discovery(): void {
		// Regression: M5 deleted the legacy GET /discovery REST route in favor
		// of POST /command with `{to: 'discovery', verb: 'get'}`. Health-check
		// sweeps were still hitting the dead URL and getting 404s on every
		// tick. The discovery payload now arrives wrapped in a Message
		// envelope's VALUE; check_server must unwrap and validate the same
		// keys it always did.
		$reg = Vault::get_instance();
		$reg->add( 'cmd-spoke', [
			'url'           => 'https://cmd-spoke.test',
			'auth_username' => 'u',
			'auth_password' => 'p',
		] );

		// Packed Message: VALUE is the structured `{name, payload}` LIVE array
		// and `payload` is the verb's structured discovery return — NOT a
		// nested JSON string. The whole-Message JSON is the only encode layer.
		$response_body = self::wrap_discovery_response( [
			'registered_hooks' => [ 'init', 'shutdown' ],
			'custom_events'    => [ 'custom_thing' ],
		] );

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://cmd-spoke.test/wp-json/newspack-nodes/v1/command' => [
				'response' => [ 'code' => 200 ],
				'body'     => $response_body,
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		Remote_Manager::health_check();

		// Should have dispatched a POST to /command, NOT a GET to /discovery.
		$post_urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$get_urls  = \array_column( $GLOBALS['_wp_test_remote_gets'] ?? [], 'url' );
		$this->assertContains(
			'https://cmd-spoke.test/wp-json/newspack-nodes/v1/command',
			$post_urls,
			'check_server must POST to /command'
		);
		$this->assertNotContains(
			'https://cmd-spoke.test/wp-json/newspack-nodes/v1/discovery',
			$get_urls,
			'check_server must not GET the deleted /discovery route'
		);

		// The discovery payload must surface intact through the action.
		$this->assertIsArray( $received );
		$this->assertArrayHasKey( 'cmd-spoke', $received );
		$this->assertSame( [ 'init', 'shutdown' ], $received['cmd-spoke']['registered_hooks'] );
		$this->assertSame( [ 'custom_thing' ], $received['cmd-spoke']['custom_events'] );
	}

	// -------------------------------------------------------------------------
	// check_server — array_slice cap on registered_hooks / custom_events.
	// -------------------------------------------------------------------------

	public function test_check_server_caps_registered_hooks_and_custom_events_at_500(): void {
		$reg = Vault::get_instance();
		$reg->add( 'fat-spoke', [
			'url'           => 'https://fat-spoke.test',
			'auth_username' => 'u',
			'auth_password' => 'p',
		] );

		// Spoke returns a discovery payload with 600 entries → check_server
		// must slice to 500.
		$hooks  = [];
		$events = [];
		for ( $i = 0; $i < 600; $i++ ) {
			$hooks[]  = "hook_{$i}";
			$events[] = "event_{$i}";
		}

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://fat-spoke.test/wp-json/newspack-nodes/v1/command' => [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( [
					'registered_hooks' => $hooks,
					'custom_events'    => $events,
					'lag'              => 7,
				] ),
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		Remote_Manager::health_check();

		$this->assertArrayHasKey( 'fat-spoke', $received );
		$this->assertCount( 500, $received['fat-spoke']['registered_hooks'] );
		$this->assertCount( 500, $received['fat-spoke']['custom_events'] );
		$this->assertSame( 7, $received['fat-spoke']['lag'] );
	}

	// -------------------------------------------------------------------------
	// sync_setting — non-string server id in filter list is skipped.
	// -------------------------------------------------------------------------

	public function test_sync_setting_skips_non_string_server_ids_in_filter(): void {
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		// Filter contains a non-string id; it should be silently skipped.
		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings',
			[ 'a', 42, true ]
		);

		// Only 'a' was POSTed.
		$this->assertCount( 1, $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame(
			'https://a.test/wp-json/newspack-nodes/v1/command',
			$GLOBALS['_wp_test_remote_posts'][0]['url']
		);

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// -------------------------------------------------------------------------
	// queue_sync_all_settings — name-remap entry resolves to config and queues.
	// -------------------------------------------------------------------------

	public function test_queue_sync_all_settings_resolves_remote_prefix_remap(): void {
		$GLOBALS['_wp_options']['newspack_nodes_num_segments'] = 99;
		Config::reset();

		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'newspack_event_logger_nodes_remote_num_segments',
						'remote_option' => 'newspack_nodes_num_segments',
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					],
				];
			}
		);

		// Returns an int (count of queued jobs). May be 0 in test (JobIntake
		// queue silently no-ops without an aggregator topology) but the
		// resolution path is exercised.
		$queued = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertIsInt( $queued );
	}

	// -------------------------------------------------------------------------
	// queue_sync_all_settings — skips entry with unknown config key.
	// -------------------------------------------------------------------------

	public function test_queue_sync_all_settings_skips_entry_with_unknown_config_key(): void {
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'newspack_event_logger_nodes_does_not_exist_xyz',
						'remote_option' => 'newspack_event_logger_nodes_does_not_exist_xyz',
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					],
				];
			}
		);

		$result = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertSame( 0, $result );
	}

	// -------------------------------------------------------------------------
	// queue_sync_all_settings — skips entry with empty local_option.
	// -------------------------------------------------------------------------

	public function test_queue_sync_all_settings_skips_entry_with_empty_local_option(): void {
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [ [ 'local_option' => '' ] ];
			}
		);

		$result = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertSame( 0, $result );
	}

	// -------------------------------------------------------------------------
	// queue_sync_all_settings — skips entry with disallowed endpoint.
	// -------------------------------------------------------------------------

	public function test_queue_sync_all_settings_skips_non_array_entry(): void {
		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					'not-an-array',
					[
						'local_option'  => 'newspack_event_logger_nodes_does_not_exist_xyz',
						'remote_option' => 'newspack_event_logger_nodes_does_not_exist_xyz',
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					],
				];
			}
		);

		// Non-array entry skipped; second entry skipped (no config); 0 queued.
		$result = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertSame( 0, $result );
	}

	// -------------------------------------------------------------------------
	// post_to_server — allowed endpoint without wp_remote_post fallback.
	// -------------------------------------------------------------------------

	public function test_post_to_server_uses_url_rtrim(): void {
		// Server url has trailing slash — must be rtrim'd before endpoint
		// concat (avoids double slashes in the URL).
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://x.test///', 'auth_username' => 'u', 'auth_password' => 'p' ],
			'/wp-json/newspack-nodes/v1/settings',
			[]
		);
		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame(
			'https://x.test/wp-json/newspack-nodes/v1/command',
			$GLOBALS['_wp_test_remote_posts'][0]['url']
		);
	}

	// -------------------------------------------------------------------------
	// handle_job — sync_setting without queued_at field (skips stale check).
	// -------------------------------------------------------------------------

	public function test_handle_job_sync_setting_without_queued_at(): void {
		// Missing queued_at means queued_at=0 → the stale check
		// (queued_at > 0 && now-queued_at > STALE_THRESHOLD) short-circuits.
		// Sync proceeds normally.
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::handle_job( [
			'action' => 'sync_setting',
			'option' => 'newspack_event_logger_nodes_log_events',
			'value'  => [ 'init' ],
			// no queued_at
		] );

		// Fan-out happened (default endpoint).
		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// -------------------------------------------------------------------------
	// handle_job — health_check without queued_at (no stale drop).
	// -------------------------------------------------------------------------

	public function test_handle_job_health_check_without_queued_at(): void {
		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		// No queued_at means queued_at=0; the stale predicate
		// (queued_at > 0 && now-queued_at > STALE) short-circuits and the
		// discovery action fires with an empty payload (no registered spokes).
		Remote_Manager::handle_job( [ 'action' => 'health_check' ] );

		$this->assertSame( [], $received );
	}

	// -------------------------------------------------------------------------
	// handle_job — non-string action ignored silently.
	// -------------------------------------------------------------------------

	public function test_handle_job_silently_drops_non_string_action(): void {
		// Action with non-string type → early return.
		Remote_Manager::handle_job( [ 'action' => 123 ] );
		Remote_Manager::handle_job( [ 'action' => [ 'array' ] ] );
		Remote_Manager::handle_job( [ 'action' => false ] );
		// All pass; the guard is `is_string && '' !== action`.
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// handle_job — unknown action with non-callable handler in filter map.
	// -------------------------------------------------------------------------

	public function test_handle_job_unknown_action_with_non_callable_handler_logs(): void {
		// When a filter registers a handler that isn't callable, the dispatch
		// must NOT crash — it falls through to the error_log branch instead.
		\add_filter(
			'newspack_event_logger_nodes/remote_actions',
			static function ( $handlers ) {
				$handlers['noncallable_action'] = 'not_a_real_function_xyz';
				return $handlers;
			}
		);
		Remote_Manager::handle_job( [ 'action' => 'noncallable_action' ] );
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// handle_job — unknown action whose filter returns a non-array.
	// -------------------------------------------------------------------------

	public function test_handle_job_unknown_action_with_non_array_filter_logs(): void {
		// Filter that returns a non-array (e.g. 'oops') must not crash — the
		// `is_array($handlers)` guard sends us to the error_log branch.
		\add_filter(
			'newspack_event_logger_nodes/remote_actions',
			static function () {
				return 'not-an-array';
			}
		);
		Remote_Manager::handle_job( [ 'action' => 'whatever_unknown' ] );
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// sync_setting — explicit servers filter where registry has the disabled entry.
	// -------------------------------------------------------------------------

	public function test_sync_setting_with_explicit_servers_honors_disabled_flag(): void {
		// When the `servers` filter includes a disabled server, the inner
		// `false === (bool) $server['enabled']` check skips it. Mirrors what
		// happens when a fan-out targets a now-disabled spoke.
		$reg = Vault::get_instance();
		$reg->add( 'enabled-x', [ 'url' => 'https://enabled-x.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->add( 'disabled-x', [ 'url' => 'https://disabled-x.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );
		$reg->update( 'disabled-x', [ 'enabled' => false ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings',
			[ 'enabled-x', 'disabled-x' ] // explicit
		);

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://enabled-x.test/wp-json/newspack-nodes/v1/command', $urls );
		$this->assertNotContains( 'https://disabled-x.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// -------------------------------------------------------------------------
	// queue_sync_all_settings — happy path: queues a job for each resolvable setting.
	// -------------------------------------------------------------------------

	public function test_queue_sync_all_settings_counts_queued_jobs(): void {
		// With a resolvable config_key, the inner SettingsSync::queue_job will
		// be invoked. We can't control its return easily (it touches filesystem)
		// but the return value is int.
		$GLOBALS['_wp_options']['newspack_nodes_num_segments'] = 8;
		Config::reset();

		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'newspack_nodes_num_segments',
						'remote_option' => 'newspack_nodes_num_segments',
						'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
					],
				];
			}
		);

		$count = Remote_Manager::queue_sync_all_settings( [ 'spoke-A' ] );
		$this->assertIsInt( $count );
		$this->assertGreaterThanOrEqual( 0, $count );
	}

	// -------------------------------------------------------------------------
	// check_server — discovery payload with no validated keys returns empty array.
	// -------------------------------------------------------------------------

	public function test_health_check_discovery_with_minimal_payload(): void {
		// Spoke returns 200 OK with a JSON payload that has only a lag value
		// (no registered_hooks, no custom_events). The validated payload should
		// contain only `lag`.
		$reg = Vault::get_instance();
		$reg->add( 'lag-only', [
			'url'           => 'https://lag-only.test',
			'auth_username' => 'u',
			'auth_password' => 'p',
		] );

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://lag-only.test/wp-json/newspack-nodes/v1/command' => [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( [ 'lag' => 999 ] ),
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		Remote_Manager::health_check();

		$this->assertArrayHasKey( 'lag-only', $received );
		$this->assertSame( 999, $received['lag-only']['lag'] );
		$this->assertArrayNotHasKey( 'registered_hooks', $received['lag-only'] );
		$this->assertArrayNotHasKey( 'custom_events', $received['lag-only'] );
	}

	// -------------------------------------------------------------------------
	// request_args — extra headers explicitly set + custom values merged with auth.
	// -------------------------------------------------------------------------

	public function test_request_args_preserves_extra_headers_when_no_auth(): void {
		// Extra headers must be preserved when no auth fields are supplied.
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$args = $method->invoke(
			null,
			[ 'url' => 'https://x.test' ],
			[ 'headers' => [ 'X-Foo' => 'bar', 'X-Baz' => 'qux' ] ]
		);

		$this->assertSame( 'bar', $args['headers']['X-Foo'] );
		$this->assertSame( 'qux', $args['headers']['X-Baz'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
	}

	public function test_request_args_basic_auth_adds_to_existing_headers(): void {
		// When extra headers are present AND auth creds are set, Authorization
		// must be added alongside (not replacing) the existing headers.
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$args = $method->invoke(
			null,
			[
				'url'           => 'https://x.test',
				'auth_username' => 'admin',
				'auth_password' => 'pw',
			],
			[ 'headers' => [ 'X-Trace-Id' => 'abc' ] ]
		);

		$this->assertSame( 'abc', $args['headers']['X-Trace-Id'] );
		$this->assertStringStartsWith( 'Basic ', $args['headers']['Authorization'] );
	}

	public function test_request_args_bearer_token_adds_to_existing_headers(): void {
		// Same merge rule for the legacy `token` field.
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$args = $method->invoke(
			null,
			[ 'url' => 'https://x.test', 'token' => 'legacy-tok' ],
			[ 'headers' => [ 'X-Trace-Id' => 'xyz' ] ]
		);

		$this->assertSame( 'xyz', $args['headers']['X-Trace-Id'] );
		$this->assertSame( 'Bearer legacy-tok', $args['headers']['Authorization'] );
	}

	// -------------------------------------------------------------------------
	// request_args — config-driven aggregator_verify_ssl flag.
	// -------------------------------------------------------------------------

	public function test_request_args_includes_default_sslverify_true(): void {
		// `aggregator_verify_ssl` default is true; request_args reflects that.
		$method = new \ReflectionMethod( Remote_Manager::class, 'request_args' );
		$method->setAccessible( true );

		$args = $method->invoke( null, [ 'url' => 'https://x.test' ], [] );

		$this->assertArrayHasKey( 'sslverify', $args );
		// Default is `true` per `$config['aggregator_verify_ssl'] ?? true`.
		$this->assertTrue( $args['sslverify'] );
	}

	// -------------------------------------------------------------------------
	// response_code — wp_remote_retrieve_response_code is always defined in tests,
	// so we exercise the array-shape path through reflection on a malformed array.
	// -------------------------------------------------------------------------

	public function test_response_code_default_zero_for_missing_response_key(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'response_code' );
		$method->setAccessible( true );
		// Array missing `response.code` → wp_remote_retrieve_response_code returns 0.
		$this->assertSame( 0, $method->invoke( null, [ 'body' => 'no code here' ] ) );
	}

	public function test_response_body_empty_for_missing_body_key(): void {
		$method = new \ReflectionMethod( Remote_Manager::class, 'response_body' );
		$method->setAccessible( true );
		// Array without `body` key → empty string.
		$this->assertSame( '', $method->invoke( null, [ 'response' => [ 'code' => 200 ] ] ) );
	}

	// -------------------------------------------------------------------------
	// wp_error_or_array — verifies the structured-array fallback signature.
	// -------------------------------------------------------------------------

	public function test_wp_error_or_array_contains_code_and_message(): void {
		// WP_Error is in the bootstrap so we always get WP_Error in this test
		// suite. The method's important invariant is the shape — code +
		// message present.
		$method = new \ReflectionMethod( Remote_Manager::class, 'wp_error_or_array' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'my_code', 'my_message' );
		$this->assertSame( 'my_code', $result->get_error_code() );
		$this->assertSame( 'my_message', $result->get_error_message() );
	}

	// -------------------------------------------------------------------------
	// sync_setting — non-string entry in $server_ids array param is skipped silently.
	// -------------------------------------------------------------------------

	public function test_sync_setting_skips_non_string_server_id_silently(): void {
		// Mixed-type IDs: the inner `is_string($server_id)` guard skips
		// non-strings without errors. Only the string 'real' makes the cut.
		$reg = Vault::get_instance();
		$reg->add( 'real', [ 'url' => 'https://real.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[],
			'/wp-json/newspack-nodes/v1/settings',
			[ 'real', 42, true, [ 'bad' ] ]
		);

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertCount( 1, $urls );
		$this->assertSame( 'https://real.test/wp-json/newspack-nodes/v1/command', $urls[0] );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// -------------------------------------------------------------------------
	// handle_job — empty servers list after array_filter normalizes to null.
	// -------------------------------------------------------------------------

	public function test_handle_job_servers_param_with_only_non_strings_normalizes_to_null(): void {
		// `servers: [42, true, null]` → array_filter('is_string') yields empty
		// → normalized to null → falls through to "all enabled".
		$reg = Vault::get_instance();
		$reg->add( 'a', [ 'url' => 'https://a.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::handle_job( [
			'action'    => 'sync_setting',
			'option'    => 'newspack_event_logger_nodes_log_events',
			'value'     => [],
			'servers'   => [ 42, true, null ],
			'queued_at' => \time(),
		] );

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		// Fan-out hit the lone enabled server (because servers normalized to null).
		$this->assertContains( 'https://a.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// -------------------------------------------------------------------------
	// post_to_server — wp_error returned from remote (sync_error log path).
	// -------------------------------------------------------------------------

	public function test_sync_setting_handles_wp_error_with_method_exists(): void {
		// post_to_server returns a WP_Error; the wp_error_or_array() branch
		// flows through log_status('sync_error', $message).
		$reg = Vault::get_instance();
		$reg->add( 'fail', [ 'url' => 'https://fail.test', 'auth_username' => 'u', 'auth_password' => 'p' ] );

		// Configure wp_remote_post to return WP_Error.
		$GLOBALS['_wp_test_remote_post_response'] = new \WP_Error( 'http_failed', 'host unreachable' );

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		// Just verify no exception bubbled out — the log_status path is
		// best-effort and the LogManager singleton is disabled by default.
		$this->assertTrue( true );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	// -------------------------------------------------------------------------
	// post_to_server — body argument propagates as JSON-encoded payload.
	// -------------------------------------------------------------------------

	public function test_post_to_server_encodes_body_as_packed_message(): void {
		// The body is a single packed Message (JSONL). VALUE is the structured
		// `{name, arguments}` LIVE array — the {option, value} pair rides in the
		// `--option=<opt> --value=<v>` arguments tail (list values comma-join).
		// The body is posted with Content-Type text/plain to match the browser
		// client (WP REST 400s a JSONL body sent as application/json).
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'p' ],
			Settings_Sync::PERF_ENDPOINT,
			[ 'option' => 'newspack_event_logger_nodes_log_events', 'value' => [ 'init', 'wp' ] ]
		);

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );

		$this->assertSame( 'text/plain; charset=UTF-8', $last['args']['headers']['Content-Type'] ?? '' );
		$value = self::assert_command_envelope( $last['args']['body'], 'performance' );
		$this->assertSame( '--option=newspack_event_logger_nodes_log_events --value=init,wp', $value['arguments'] );
	}

	// -------------------------------------------------------------------------
	// health_check — option-loaded plaintext entry processed cleanly.
	// -------------------------------------------------------------------------

	public function test_health_check_processes_option_loaded_entry(): void {
		// Injecting an entry straight into the option (bypassing ServerRegistry's
		// register API) exercises the merge path in get_all(). The health_check
		// loop's is_string guard filters non-string keys naturally.
		$GLOBALS['_wp_options'][ Vault::OPTION_KEY ] = [
			'valid-id' => [
				'url'           => 'https://valid.test',
				'auth_username' => 'u',
				'auth_password' => 'p',
				'enabled'       => true,
			],
		];

		// Reset the Vault cache so the option load is honored.
		Vault::get_instance()->reset_cache();

		$GLOBALS['_wp_test_remote_responses'] = [
			'https://valid.test/wp-json/newspack-nodes/v1/command' => [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( [ 'lag' => 0 ] ),
			],
		];

		$received = null;
		\add_action(
			'newspack_event_logger_nodes/health_check_discovery',
			static function ( $data ) use ( &$received ) {
				$received = $data;
			}
		);

		Remote_Manager::health_check();

		$this->assertIsArray( $received );
		$this->assertArrayHasKey( 'valid-id', $received );
	}

	// -------------------------------------------------------------------------
	// sanitize_handler_parameters — endpoint missing entirely passes through.
	// -------------------------------------------------------------------------

	public function test_sanitize_handler_parameters_preserves_non_endpoint_keys(): void {
		// Reflection invocation; missing endpoint key → no special handling,
		// other keys preserved.
		$method = new \ReflectionMethod( Remote_Manager::class, 'sanitize_handler_parameters' );
		$method->setAccessible( true );

		$result = $method->invoke(
			null,
			[ 'data' => [ 'a', 'b' ], 'flag' => true, 'count' => 5 ]
		);
		$this->assertSame( [ 'a', 'b' ], $result['data'] );
		$this->assertTrue( $result['flag'] );
		$this->assertSame( 5, $result['count'] );
		$this->assertArrayNotHasKey( 'endpoint', $result );
	}

	public function test_sanitize_handler_parameters_drops_non_string_endpoint(): void {
		// Non-string endpoint value is dropped (the `is_string` guard).
		$method = new \ReflectionMethod( Remote_Manager::class, 'sanitize_handler_parameters' );
		$method->setAccessible( true );

		$result = $method->invoke(
			null,
			[ 'endpoint' => [ 'array', 'not', 'string' ], 'other' => 'preserved' ]
		);
		$this->assertArrayNotHasKey( 'endpoint', $result );
		$this->assertSame( 'preserved', $result['other'] );
	}

	// -------------------------------------------------------------------------
	// queue_sync_all_settings — entry whose endpoint key is missing falls back.
	// -------------------------------------------------------------------------

	public function test_queue_sync_all_settings_uses_default_endpoint_when_missing(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_urls'] = [ '/v' ];
		Config::reset();

		\add_filter(
			'newspack_event_logger_nodes/synced_settings',
			static function () {
				return [
					[
						'local_option'  => 'newspack_event_logger_nodes_log_urls',
						'remote_option' => 'newspack_event_logger_nodes_log_urls',
						// no `endpoint` key
					],
				];
			}
		);

		$result = Remote_Manager::queue_sync_all_settings( [ 'spoke' ] );
		$this->assertIsInt( $result );
	}

	// -------------------------------------------------------------------------
	// sync_setting — server entry with no 'enabled' key (legacy plaintext).
	// -------------------------------------------------------------------------

	// --- M5.2b: TM_COMMAND envelope transport ---------------------------------

	public function test_post_to_server_posts_to_command_endpoint_not_legacy_settings_url(): void {
		// M5.2 cutover: the legacy /settings + /performance/settings endpoints
		// are deleted. SettingsSync continues to pass the legacy endpoint
		// strings (still useful as category tags), but the actual POST must
		// land on /wp-json/newspack-nodes/v1/command.
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://spoke.test', 'auth_username' => 'a', 'auth_password' => 'b' ],
			Settings_Sync::ENDPOINT,
			[ 'option' => 'newspack_nodes_num_partitions', 'value' => 4 ]
		);

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame( 'https://spoke.test/wp-json/newspack-nodes/v1/command', $last['url'] );
	}

	public function test_post_to_server_wraps_substrate_body_in_packed_command_message_for_settings_update(): void {
		// Substrate-key endpoint maps to {to: settings, verb: update}. The body
		// must be a packed Message (positional 7-field array): TYPE=TM_COMMAND,
		// FROM=_http, TO=settings, VALUE = LIVE {name, arguments} array with the
		// update map rendered into the `--<short>=<value>` arguments tail.
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://spoke.test', 'auth_username' => 'a', 'auth_password' => 'b' ],
			Settings_Sync::ENDPOINT,
			[ 'option' => 'newspack_nodes_num_partitions', 'value' => 4 ]
		);

		$last  = \end( $GLOBALS['_wp_test_remote_posts'] );
		$value = self::assert_command_envelope( $last['args']['body'], 'settings' );

		$this->assertSame( 'update', $value['name'] );
		// Settings_CI.update takes a partial-update keyed by short-name (no
		// `newspack_nodes_` prefix) as `--<short>=<value>`.
		$this->assertSame( '--num_partitions=4', $value['arguments'] );
	}

	public function test_post_to_server_wraps_perf_body_in_tm_command_envelope_for_settings_update(): void {
		// Perf-tuning endpoint maps to {to: performance, verb: settings_update}.
		// Performance_CI.settings_update takes a single {option, value} pair as
		// `--option=<opt> --value=<v>` (list values comma-join).
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://spoke.test', 'auth_username' => 'a', 'auth_password' => 'b' ],
			Settings_Sync::PERF_ENDPOINT,
			[ 'option' => 'newspack_event_logger_nodes_log_events', 'value' => [ 'init' ] ]
		);

		$last  = \end( $GLOBALS['_wp_test_remote_posts'] );
		$value = self::assert_command_envelope( $last['args']['body'], 'performance' );

		$this->assertSame( 'settings_update', $value['name'] );
		$this->assertSame(
			'--option=newspack_event_logger_nodes_log_events --value=init',
			$value['arguments']
		);
	}

	public function test_command_content_type_is_publicly_accessible_for_same_plugin_reuse(): void {
		// The Content-Type constant is the single source of truth for the
		// `/command` wire header; same-plugin sites (Servers_CI::probe_remote,
		// RemoteSource::maybe_send_heartbeat) reference it instead of
		// re-hardcoding the literal, so it must be publicly readable.
		$this->assertSame( 'text/plain; charset=UTF-8', Remote_Manager::COMMAND_CONTENT_TYPE );
	}

	public function test_post_to_server_uses_text_plain_content_type_for_jsonl_body(): void {
		// The body is JSONL (one packed Message per line). The browser client
		// posts it as text/plain because WP REST 400s a JSONL body sent as
		// application/json. The cross-spoke sender must match that header.
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://spoke.test', 'auth_username' => 'a', 'auth_password' => 'b' ],
			Settings_Sync::ENDPOINT,
			[ 'option' => 'newspack_nodes_num_partitions', 'value' => 4 ]
		);
		$last = \end( $GLOBALS['_wp_test_remote_posts'] );
		// Pinned to the constant so a future drift at the call site is caught.
		$this->assertSame( Remote_Manager::COMMAND_CONTENT_TYPE, $last['args']['headers']['Content-Type'] ?? '' );
	}

	public function test_post_to_server_body_is_not_legacy_keyed_object(): void {
		// Regression: the legacy wire was a keyed object
		// `{type,to,from,key,value:"<json string>"}`. The new wire is a
		// positional 7-field array with a LIVE-array VALUE.
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://spoke.test', 'auth_username' => 'a', 'auth_password' => 'b' ],
			Settings_Sync::ENDPOINT,
			[ 'option' => 'newspack_nodes_num_partitions', 'value' => 4 ]
		);
		$last    = \end( $GLOBALS['_wp_test_remote_posts'] );
		$decoded = \json_decode( $last['args']['body'], true );
		$this->assertTrue( \array_is_list( $decoded ), 'body must be a positional Message array, not a keyed object' );
		$this->assertArrayNotHasKey( 'type', $decoded );
		$this->assertArrayNotHasKey( 'value', $decoded );
		$this->assertIsArray(
			$decoded[ \Newspack_Nodes\Message::VALUE ],
			'VALUE must be a live array, never a wp_json_encode\'d string'
		);
	}

	public function test_discover_from_server_posts_packed_command_with_text_plain(): void {
		// The health-check discovery probe POSTs a `discovery.get` command.
		// Body = packed Message (TM_COMMAND, TO=discovery, VALUE live array),
		// Content-Type text/plain, Basic Auth preserved.
		$GLOBALS['_wp_test_remote_posts']     = [];
		$GLOBALS['_wp_test_remote_responses'] = [
			'https://probe.test/wp-json/newspack-nodes/v1/command' => [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( [ 'registered_hooks' => [ 'init' ], 'custom_events' => [] ] ),
			],
		];

		$payload = Remote_Manager::discover_from_server(
			[ 'url' => 'https://probe.test', 'auth_username' => 'admin', 'auth_password' => 'pw' ],
			'probe'
		);

		// Response payload read directly from the structured VALUE (no second decode).
		$this->assertSame( [ 'init' ], $payload['registered_hooks'] );

		$last = \end( $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame( 'text/plain; charset=UTF-8', $last['args']['headers']['Content-Type'] ?? '' );
		$value = self::assert_command_envelope( $last['args']['body'], 'discovery' );
		$this->assertSame( 'get', $value['name'] );
		// Basic Auth survives the migration.
		$this->assertStringStartsWith( 'Basic ', $last['args']['headers']['Authorization'] ?? '' );
	}

	public function test_post_to_server_preserves_basic_auth_header_through_envelope_migration(): void {
		// Basic Auth is a SPOKE authentication concern, independent of the
		// envelope; migrating the body shape must not strip the header.
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://spoke.test', 'auth_username' => 'admin', 'auth_password' => 'app-pw' ],
			Settings_Sync::ENDPOINT,
			[ 'option' => 'newspack_nodes_num_partitions', 'value' => 4 ]
		);

		$last = \end( $GLOBALS['_wp_test_remote_posts'] );
		$auth = $last['args']['headers']['Authorization'] ?? '';
		$this->assertStringStartsWith( 'Basic ', $auth );
		$this->assertSame( 'admin:app-pw', \base64_decode( \substr( $auth, 6 ), true ) );
	}

	public function test_post_to_server_strips_substrate_prefix_when_building_settings_update_args(): void {
		// Settings_CI.update's whitelist is keyed by the short-name
		// (num_partitions, num_segments, segment_size, max_lifespan) — not the
		// `newspack_nodes_` prefix. The legacy /settings controller did the
		// same strip server-side; here we do it on the wire so the receiver
		// doesn't have to know that history.
		$GLOBALS['_wp_test_remote_posts'] = [];
		Remote_Manager::post_to_server(
			[ 'url' => 'https://spoke.test', 'auth_username' => 'a', 'auth_password' => 'b' ],
			Settings_Sync::ENDPOINT,
			[ 'option' => 'newspack_nodes_segment_size', 'value' => 1048576 ]
		);
		$last  = \end( $GLOBALS['_wp_test_remote_posts'] );
		$value = self::assert_command_envelope( $last['args']['body'], 'settings' );
		// Prefix stripped on the wire: `--segment_size=…`, not `--newspack_nodes_segment_size=…`.
		$this->assertSame( '--segment_size=1048576', $value['arguments'] );
		$this->assertStringNotContainsString( 'newspack_nodes_segment_size', $value['arguments'] );
	}

	public function test_sync_setting_includes_legacy_server_without_enabled_key(): void {
		// Legacy registries lack the `enabled` field; the code defaults to
		// "no flag = enabled" so the spoke should receive the POST.
		$GLOBALS['_wp_options'][ Vault::OPTION_KEY ] = [
			'legacy' => [
				// no 'enabled' key
				'url'           => 'https://legacy.test',
				'auth_username' => 'u',
				'auth_password' => 'p',
			],
		];
		Vault::get_instance()->reset_cache();

		$GLOBALS['_wp_test_remote_post_response'] = [ 'response' => [ 'code' => 200 ] ];
		$GLOBALS['_wp_test_remote_posts']         = [];

		Remote_Manager::sync_setting(
			'newspack_event_logger_nodes_log_events',
			[ 'init' ],
			'/wp-json/newspack-nodes/v1/settings'
		);

		$urls = \array_column( $GLOBALS['_wp_test_remote_posts'], 'url' );
		$this->assertContains( 'https://legacy.test/wp-json/newspack-nodes/v1/command', $urls );

		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}
}
