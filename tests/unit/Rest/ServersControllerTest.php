<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\ServersController;
use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ServersController::class )]
class ServersControllerTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']             = [];
		$GLOBALS['_current_user_can']        = true;
		$GLOBALS['_wp_options']              = [];
		$GLOBALS['_wp_actions']              = [];
		$GLOBALS['_wp_test_remote_responses'] = [];
		ServerRegistry::get_instance()->reset_cache();
		PerformanceControllerBase::set_cache( new FakeMemcached() );

		$this->tmp = '/tmp/servers-controller-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		ServerRegistry::get_instance()->reset_cache();
		Config::reset();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	public function test_register_routes_registers_all_endpoints(): void {
		( new ServersController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/servers', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/servers/(?P<id>[a-zA-Z0-9_-]{1,64})', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/servers/(?P<id>[a-zA-Z0-9_-]{1,64})/test', $GLOBALS['_rest_routes'] );
	}

	public function test_get_items_returns_empty_when_no_servers(): void {
		$ctrl = new ServersController();
		$resp = $ctrl->get_items( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( [], $resp->get_data() );
	}

	public function test_get_items_returns_each_server_metadata(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke1' => [
					'url'           => 'https://spoke1.example/',
					'enabled'       => true,
					'logs'          => [ 'firehose.log', 'jobs.log' ],
					'auth_username' => 'user1',
					'auth_password' => 'pass1',
				],
				'spoke2' => [
					'url'     => 'https://spoke2.example/',
					'enabled' => false,
					'logs'    => [ 'firehose.log' ],
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new ServersController();
		$resp = $ctrl->get_items( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertCount( 2, $body );
		$this->assertArrayHasKey( 'spoke1', $body );
		$this->assertSame( 'spoke1', $body['spoke1']['id'] );
		$this->assertSame( 'https://spoke1.example/', $body['spoke1']['url'] );
		$this->assertTrue( $body['spoke1']['enabled'] );
		$this->assertSame( [ 'firehose.log', 'jobs.log' ], $body['spoke1']['logs'] );
		// `has_credentials` requires the password to decrypt successfully.
		// The fixture above stores plaintext (legacy migration path) which
		// the encryption-only ServerRegistry::decrypt() now treats as
		// undecryptable — so has_credentials is false until the operator
		// re-saves the spoke through the admin form (which encrypts).
		$this->assertFalse( $body['spoke1']['has_credentials'] );
		// is_config flag is false for WP-option-only servers.
		$this->assertFalse( $body['spoke1']['is_config'] );

		// spoke2 has no credentials; has_credentials is false.
		$this->assertFalse( $body['spoke2']['has_credentials'] );
		$this->assertFalse( $body['spoke2']['enabled'] );
	}

	public function test_get_item_returns_404_for_unknown_id(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'unknown' );
		$resp = $ctrl->get_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
		$this->assertSame( 404, $resp->data['status'] ?? 0 );
	}

	public function test_get_item_returns_metadata_for_known_id(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'host42' => [
					'url'     => 'https://host42.example/',
					'enabled' => true,
					'logs'    => [ 'firehose.log' ],
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'host42' );
		$resp = $ctrl->get_item( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'host42', $body['id'] );
		$this->assertSame( 'https://host42.example/', $body['url'] );
		$this->assertTrue( $body['enabled'] );
		$this->assertFalse( $body['has_credentials'] );
	}

	public function test_create_item_rejects_invalid_id(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', '' ); // Invalid.
		$req->set_param( 'url', 'https://example.com' );
		$resp = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_id', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? 0 );
	}

	public function test_create_item_rejects_invalid_id_chars(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'has spaces' ); // Spaces invalid in id.
		$req->set_param( 'url', 'https://example.com' );
		$resp = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_id', $resp->get_error_code() );
	}

	public function test_create_item_rejects_duplicate_id(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke1' => [
					'url'     => 'https://spoke1.example/',
					'enabled' => true,
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke1' );
		$req->set_param( 'url', 'https://spoke2.example/' );
		$req->set_param( 'enabled', true );
		$req->set_param( 'logs', [ 'firehose.log' ] );
		$resp = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'already_exists', $resp->get_error_code() );
		$this->assertSame( 409, $resp->data['status'] ?? 0 );
	}

	public function test_create_item_rejects_non_https_url(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke1' );
		$req->set_param( 'url', 'http://example.com' );
		$req->set_param( 'enabled', true );
		$req->set_param( 'logs', [ 'firehose.log' ] );
		$resp = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'create_failed', $resp->get_error_code() );
	}

	public function test_create_item_succeeds_with_valid_data(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'newspoke' );
		$req->set_param( 'url', 'https://newspoke.example/' );
		$req->set_param( 'enabled', true );
		$req->set_param( 'logs', [ 'firehose.log' ] );
		$req->set_param( 'auth_username', 'user' );
		$req->set_param( 'auth_password', 'pass' );
		$resp = $ctrl->create_item( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 201, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'newspoke', $body['id'] );

		// Verify the server is now in the registry.
		ServerRegistry::get_instance()->reset_cache();
		$server = ServerRegistry::get_instance()->get( 'newspoke' );
		$this->assertNotNull( $server );
		// validate_config rtrims trailing slashes.
		$this->assertSame( 'https://newspoke.example', $server['url'] );
	}

	public function test_update_item_returns_404_for_unknown_id(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'unknown' );
		$req->set_param( 'enabled', false );
		$resp = $ctrl->update_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_update_item_succeeds_with_partial_change(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke1' => [
					'url'     => 'https://spoke1.example/',
					'enabled' => true,
					'logs'    => [ 'firehose.log' ],
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke1' );
		$req->set_param( 'enabled', false );
		$resp = $ctrl->update_item( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'spoke1', $body['id'] );

		// Verify the change persisted.
		ServerRegistry::get_instance()->reset_cache();
		$updated = ServerRegistry::get_instance()->get( 'spoke1' );
		$this->assertFalse( $updated['enabled'] );
	}

	public function test_delete_item_returns_404_for_unknown_id(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'unknown' );
		$resp = $ctrl->delete_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_delete_item_removes_server(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke1' => [
					'url'     => 'https://spoke1.example/',
					'enabled' => true,
					'logs'    => [ 'firehose.log' ],
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke1' );
		$resp = $ctrl->delete_item( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertSame( 'spoke1', $body['id'] );

		// Verify the server is gone. Reset Config too — ServerRegistry's
		// get_all() calls Config::load_config() which caches the merged
		// `aggregator_servers` set; without this reset the cache resurrects
		// spoke1 even after the option store is cleared.
		Config::reset();
		ServerRegistry::get_instance()->reset_cache();
		$this->assertNull( ServerRegistry::get_instance()->get( 'spoke1' ) );
	}

	public function test_test_connection_passes_sslverify_true_by_default(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'svc' => [
					'url'     => 'https://svc.example/',
					'enabled' => true,
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$GLOBALS['_wp_test_remote_gets'] = [];
		$GLOBALS['_wp_test_remote_responses']['https://svc.example/wp-json/newspack-nodes/v1/discovery'] = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( [ 'registered_hooks' => [], 'custom_events' => [] ] ),
		];

		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'svc' );
		$ctrl->test_connection( $req );

		$this->assertNotEmpty( $GLOBALS['_wp_test_remote_gets'] );
		$call = \end( $GLOBALS['_wp_test_remote_gets'] );
		$this->assertSame( 'https://svc.example/wp-json/newspack-nodes/v1/discovery', $call['url'] );
		// File-overlay default is true; verify pass-through.
		$this->assertTrue( $call['args']['sslverify'] );
	}

	public function test_test_connection_passes_sslverify_false_when_config_says_so(): void {
		// LOCAL_NEWSPACK_NODES_CONF overlays the bundled sample. aggregator_verify_ssl
		// is config-file-only (not in the schema), so this is the canonical way
		// for an operator to disable peer/host verification.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . \dirname( __DIR__, 2 ) . '/configs/aggregator-ssl-off.php' );
		Config::reset();

		try {
			\update_option(
				ServerRegistry::OPTION_KEY,
				[
					'svc' => [
						'url'     => 'https://svc.example/',
						'enabled' => true,
					],
				]
			);
			ServerRegistry::get_instance()->reset_cache();

			$GLOBALS['_wp_test_remote_gets'] = [];
			$GLOBALS['_wp_test_remote_responses']['https://svc.example/wp-json/newspack-nodes/v1/discovery'] = [
				'response' => [ 'code' => 200 ],
				'body'     => \json_encode( [ 'registered_hooks' => [], 'custom_events' => [] ] ),
			];

			$ctrl = new ServersController();
			$req  = new \WP_REST_Request();
			$req->set_param( 'id', 'svc' );
			$ctrl->test_connection( $req );

			$call = \end( $GLOBALS['_wp_test_remote_gets'] );
			$this->assertFalse( $call['args']['sslverify'] );
		} finally {
			\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
			Config::reset();
		}
	}

	public function test_test_connection_returns_404_for_unknown_id(): void {
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'unknown' );
		$resp = $ctrl->test_connection( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_test_connection_returns_502_when_remote_unreachable(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'broken' => [
					'url'     => 'https://broken.example/',
					'enabled' => true,
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		// Stub wp_remote_get to return a WP_Error (network unreachable).
		$GLOBALS['_wp_test_remote_responses']['https://broken.example/wp-json/newspack-nodes/v1/discovery']
			= new \WP_Error( 'http_request_failed', 'Could not connect' );

		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'broken' );
		$resp = $ctrl->test_connection( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'connection_failed', $resp->get_error_code() );
		$this->assertSame( 502, $resp->data['status'] ?? 0 );
	}

	public function test_test_connection_returns_502_for_non_200_status(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'svc' => [
					'url'     => 'https://svc.example/',
					'enabled' => true,
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$GLOBALS['_wp_test_remote_responses']['https://svc.example/wp-json/newspack-nodes/v1/discovery'] = [
			'response' => [ 'code' => 500 ],
			'body'     => '',
		];
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'svc' );
		$resp = $ctrl->test_connection( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'connection_failed', $resp->get_error_code() );
		$this->assertStringContainsString( '500', $resp->get_error_message() );
	}

	public function test_test_connection_returns_502_for_non_json_response(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'svc' => [
					'url'     => 'https://svc.example/',
					'enabled' => true,
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$GLOBALS['_wp_test_remote_responses']['https://svc.example/wp-json/newspack-nodes/v1/discovery'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '<html>not json</html>',
		];
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'svc' );
		$resp = $ctrl->test_connection( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_response', $resp->get_error_code() );
	}

	public function test_test_connection_returns_filtered_payload_on_success(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke' => [
					'url'           => 'https://spoke.example/',
					'enabled'       => true,
					'auth_username' => 'u',
					'auth_password' => 'p',
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		$GLOBALS['_wp_test_remote_responses']['https://spoke.example/wp-json/newspack-nodes/v1/discovery'] = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( [
				'registered_hooks' => [ 'init', 'shutdown' ],
				'custom_events'    => [ 'my_event' ],
				'lag'              => 4567,
				'extra_dangerous'  => 'should-not-be-surfaced',
			] ),
		];
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke' );
		$resp = $ctrl->test_connection( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( 'spoke', $body['id'] );
		$this->assertSame( 'connected', $body['status'] );
		$this->assertSame( [ 'init', 'shutdown' ], $body['response']['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $body['response']['custom_events'] );
		$this->assertSame( 4567, $body['response']['lag'] );
		// Whitelisted only — extra_dangerous must NOT pass through.
		$this->assertArrayNotHasKey( 'extra_dangerous', $body['response'] );
	}

	public function test_test_connection_drops_non_string_hooks_in_response(): void {
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'spoke' => [
					'url'     => 'https://spoke.example/',
					'enabled' => true,
				],
			]
		);
		ServerRegistry::get_instance()->reset_cache();

		// Mixed types in the registered_hooks array — only strings should pass through.
		$GLOBALS['_wp_test_remote_responses']['https://spoke.example/wp-json/newspack-nodes/v1/discovery'] = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( [
				'registered_hooks' => [ 'init', 42, [ 'nested' ], null, 'shutdown' ],
				'custom_events'    => [],
			] ),
		];
		$ctrl = new ServersController();
		$req  = new \WP_REST_Request();
		$req->set_param( 'id', 'spoke' );
		$body = $ctrl->test_connection( $req )->get_data();
		// Only the strings should survive.
		$this->assertSame( [ 'init', 'shutdown' ], $body['response']['registered_hooks'] );
	}

	public function test_validate_logs_rejects_bad_filenames(): void {
		$ctrl = new ServersController();
		$this->assertTrue( $ctrl->validate_logs( [ 'firehose.log', 'jobs.log' ] ) );
		$this->assertFalse( $ctrl->validate_logs( [ '../etc/passwd' ] ) );
		$this->assertFalse( $ctrl->validate_logs( [ 'no-extension' ] ) );
		$this->assertFalse( $ctrl->validate_logs( [ 'has spaces.log' ] ) );
		$this->assertFalse( $ctrl->validate_logs( [ 42 ] ) ); // non-string
		$this->assertFalse( $ctrl->validate_logs( 'not-an-array' ) );
	}

	public function test_validate_url_required_rejects_empty(): void {
		$ctrl   = new ServersController();
		$result = $ctrl->validate_url_required( '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
	}

	public function test_validate_url_required_accepts_https(): void {
		$ctrl = new ServersController();
		$this->assertTrue( $ctrl->validate_url_required( 'https://valid.example/' ) );
	}

	public function test_validate_url_required_rejects_http(): void {
		$ctrl   = new ServersController();
		$result = $ctrl->validate_url_required( 'http://insecure.example/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_validate_url_optional_accepts_empty(): void {
		$ctrl = new ServersController();
		$this->assertTrue( $ctrl->validate_url_optional( '' ) );
	}

	public function test_validate_url_optional_rejects_invalid(): void {
		$ctrl   = new ServersController();
		$result = $ctrl->validate_url_optional( 'ftp://wrong.example/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_sanitize_password_caps_at_256(): void {
		$ctrl = new ServersController();
		$out  = $ctrl->sanitize_password( \str_repeat( 'a', 500 ) );
		$this->assertSame( 256, \strlen( $out ) );
	}

	public function test_sanitize_password_strips_control_chars(): void {
		$ctrl = new ServersController();
		// Control chars (0x00..0x1f, 0x7f) should be stripped.
		$out = $ctrl->sanitize_password( "valid\x00\x01\x02password\x7f" );
		$this->assertSame( 'validpassword', $out );
	}

	public function test_sanitize_password_returns_empty_for_non_string(): void {
		$ctrl = new ServersController();
		$this->assertSame( '', $ctrl->sanitize_password( 42 ) );
		$this->assertSame( '', $ctrl->sanitize_password( null ) );
		$this->assertSame( '', $ctrl->sanitize_password( [] ) );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new ServersController() )->admin_permissions_check( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] ?? 0 );
	}

	public function test_permission_check_accepts_capable(): void {
		$GLOBALS['_current_user_can'] = true;
		$result = ( new ServersController() )->admin_permissions_check( new \WP_REST_Request() );
		$this->assertTrue( $result );
	}
}
