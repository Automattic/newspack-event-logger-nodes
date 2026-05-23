<?php
/**
 * ServersCITest: unit tests for Servers_CI, the M2 service-CI that replaces
 * the legacy ServersController.
 *
 * Six verbs proxy the hub-side server registry: list, get, add, update,
 * delete, test. Asserts value-equivalence with the legacy controller's
 * JSON shape per verb and exercises the auth gate on the four mutating
 * verbs (add/update/delete/test). The HTTP probe in `test` is exercised
 * via the static closure seam (Servers_CI::$http_call) so the surrounding
 * URL construction + response-classification code stays covered.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Servers_CI_Node;
use Newspack_Event_Logger_Nodes\Remote_Manager;
use Newspack_Event_Logger_Nodes\Server_Registry;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Servers_CI_Node::class )]
class ServersCITest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching SettingsCITest / DiscoveryCITest.
		$this->tmp = '/tmp/servers-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp );
		$GLOBALS['_wp_options']              = [];
		$GLOBALS['_current_user_can']        = true;
		$GLOBALS['_wp_test_remote_gets']     = [];
		$GLOBALS['_wp_test_remote_responses'] = [];
		Servers_CI_Node::$http_call               = null;
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_wp_options']              = [];
		$GLOBALS['_current_user_can']        = false;
		$GLOBALS['_wp_test_remote_gets']     = [];
		$GLOBALS['_wp_test_remote_responses'] = [];
		Servers_CI_Node::$http_call               = null;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// list verb
	// ---------------------------------------------------------------------

	public function test_list_verb_returns_empty_map_when_no_servers_registered(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'list' );

		$this->assertSame( [], $result );
	}

	public function test_list_verb_returns_registered_servers_with_public_shape(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [
			'url'           => 'https://a.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'secret-pw-1',
			'logs'          => [ 'firehose.log', 'errors.log' ],
		] );
		$registry->reset_cache();

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'list' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'site-a', $result );
		$this->assertSame( 'site-a', $result['site-a']['id'] );
		$this->assertSame( 'https://a.example.com', $result['site-a']['url'] );
		$this->assertTrue( $result['site-a']['enabled'] );
		$this->assertSame( [ 'firehose.log', 'errors.log' ], $result['site-a']['logs'] );
		$this->assertTrue( $result['site-a']['has_credentials'] );
		$this->assertArrayNotHasKey( 'auth_password', $result['site-a'] );
		$this->assertArrayNotHasKey( 'auth_username', $result['site-a'] );
	}

	// ---------------------------------------------------------------------
	// get verb
	// ---------------------------------------------------------------------

	public function test_get_verb_returns_single_server(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [
			'url' => 'https://a.example.com',
		] );

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'get', [ 'id' => 'site-a' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'site-a', $result['id'] );
		$this->assertSame( 'https://a.example.com', $result['url'] );
		$this->assertFalse( $result['has_credentials'] );
	}

	public function test_get_verb_returns_error_for_unknown_id(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'get', [ 'id' => 'nope' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', $result );
	}

	public function test_get_verb_requires_id(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'get', [] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'id required', $result );
	}

	// ---------------------------------------------------------------------
	// add verb
	// ---------------------------------------------------------------------

	public function test_add_verb_persists_server_in_registry(): void {
		$registry = new Server_Registry();
		$ci       = new Servers_CI_Node( $registry );

		$result = VerbHarness::fire( $ci, 'servers', 'add', [
			'id'            => 'new-site',
			'url'           => 'https://new.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'pw',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'new-site', $result['id'] );

		// Verify the registry actually wrote the entry.
		$registry->reset_cache();
		$entry = $registry->get( 'new-site' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'https://new.example.com', $entry['url'] );
	}

	public function test_add_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$registry = new Server_Registry();
		$ci       = new Servers_CI_Node( $registry );

		$result = VerbHarness::fire( $ci, 'servers', 'add', [
			'id'  => 'site-a',
			'url' => 'https://a.example.com',
		] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );

		// Verify the registry was not written.
		$registry->reset_cache();
		$this->assertNull( $registry->get( 'site-a' ) );
	}

	public function test_add_verb_rejects_invalid_id(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'add', [
			'id'  => '!!bad-id!!',
			'url' => 'https://a.example.com',
		] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', $result );
	}

	public function test_add_verb_rejects_http_url(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'add', [
			'id'  => 'plain',
			'url' => 'http://insecure.example.com',
		] );

		// Registry's validate_config rejects non-HTTPS URLs → add returns false → CI error.
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'failed', $result );
	}

	// ---------------------------------------------------------------------
	// update verb
	// ---------------------------------------------------------------------

	public function test_update_verb_applies_partial_update(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [
			'url'     => 'https://a.example.com',
			'enabled' => false,
		] );

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'update', [
			'id'      => 'site-a',
			'enabled' => true,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'site-a', $result['id'] );

		$registry->reset_cache();
		$entry = $registry->get( 'site-a' );
		$this->assertTrue( $entry['enabled'] );
	}

	public function test_update_verb_rejects_unauthorized(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [
			'url'     => 'https://a.example.com',
			'enabled' => false,
		] );
		$GLOBALS['_current_user_can'] = false;

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'update', [
			'id'      => 'site-a',
			'enabled' => true,
		] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );

		$registry->reset_cache();
		$entry = $registry->get( 'site-a' );
		$this->assertFalse( $entry['enabled'] );
	}

	public function test_update_verb_returns_error_for_unknown_id(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'update', [
			'id'      => 'never-existed',
			'enabled' => true,
		] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', $result );
	}

	// ---------------------------------------------------------------------
	// delete verb
	// ---------------------------------------------------------------------

	public function test_delete_verb_removes_server(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [ 'url' => 'https://a.example.com' ] );

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'delete', [ 'id' => 'site-a' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'site-a', $result['id'] );

		$registry->reset_cache();
		$this->assertNull( $registry->get( 'site-a' ) );
	}

	public function test_delete_verb_rejects_unauthorized(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [ 'url' => 'https://a.example.com' ] );
		$GLOBALS['_current_user_can'] = false;

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'delete', [ 'id' => 'site-a' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );

		$registry->reset_cache();
		$this->assertNotNull( $registry->get( 'site-a' ) );
	}

	public function test_delete_verb_returns_error_for_unknown_id(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'delete', [ 'id' => 'never-existed' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', $result );
	}

	// ---------------------------------------------------------------------
	// test verb
	// ---------------------------------------------------------------------

	/**
	 * Wrap a verb-response payload (associative array) in the same Message
	 * envelope a real CommandInterpreter would produce on a TM_COMMAND
	 * dispatch — used by the test_test_verb_* tests so the closure seam can
	 * return realistic spoke responses now that probe_remote() goes through
	 * `/command` instead of the legacy `/discovery` route.
	 */
	private static function wrap_discovery_response( array $payload ): string {
		$msg                                   = \Newspack_Nodes\Message::new_message();
		$msg[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_COMMAND | \Newspack_Nodes\Message::TM_RESPONSE;
		$msg[ \Newspack_Nodes\Message::FROM ]  = 'discovery';
		$msg[ \Newspack_Nodes\Message::TO ]    = '_http';
		// VALUE is the structured `{name, payload}` LIVE array; `payload` is
		// the verb's structured return — NOT a nested JSON string. The
		// whole-Message JSON is the only serialization boundary. Mirrors
		// CommandInterpreter::interpret()'s response shape.
		$msg[ \Newspack_Nodes\Message::VALUE ] = [
			'name'    => 'get',
			'payload' => $payload,
		];
		return \Newspack_Nodes\Message::packed( $msg );
	}

	public function test_test_verb_returns_connected_on_200_discovery_response(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [
			'url'           => 'https://a.example.com',
			'auth_username' => 'admin',
			'auth_password' => 'pw',
		] );

		// Closure seam: capture outbound args + return a 200 discovery body
		// wrapped in the same command envelope a real spoke would emit.
		$captured = null;
		Servers_CI_Node::$http_call = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured = [ 'url' => $url, 'args' => $args ];
			return [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( [
					'registered_hooks' => [ 'init', 'wp_loaded' ],
					'custom_events'    => [ 'my_event' ],
					'lag'              => 42,
				] ),
			];
		};

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'test', [ 'id' => 'site-a' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'site-a', $result['id'] );
		$this->assertSame( 'connected', $result['status'] );
		$this->assertSame( [ 'init', 'wp_loaded' ], $result['response']['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $result['response']['custom_events'] );
		$this->assertSame( 42, $result['response']['lag'] );

		// URL composition: stored URL + /wp-json/newspack-nodes/v1/command
		// (legacy /discovery was deleted in M5).
		$this->assertSame( 'https://a.example.com/wp-json/newspack-nodes/v1/command', $captured['url'] );
		// Basic-Auth header populated when credentials are present.
		$this->assertArrayHasKey( 'headers', $captured['args'] );
		$this->assertStringStartsWith( 'Basic ', $captured['args']['headers']['Authorization'] );

		// Wire shape: the body is a single packed Message (positional 7-field
		// array), NOT the legacy keyed `{type,to,from,value:"<json>"}` object.
		// VALUE is the structured command LIVE array. Content-Type is
		// text/plain to match the browser client (JSONL body).
		$this->assertSame( 'text/plain; charset=UTF-8', $captured['args']['headers']['Content-Type'] ?? '' );
		$message = \Newspack_Nodes\Message::unpacked( $captured['args']['body'] );
		$this->assertSame( \Newspack_Nodes\Message::TM_COMMAND, $message[ \Newspack_Nodes\Message::TYPE ] );
		$this->assertSame( '_http', $message[ \Newspack_Nodes\Message::FROM ] );
		$this->assertSame( 'discovery', $message[ \Newspack_Nodes\Message::TO ] );
		$value = $message[ \Newspack_Nodes\Message::VALUE ];
		$this->assertIsArray( $value, 'VALUE must be the structured command array, not a JSON string' );
		$this->assertSame( 'get', $value['name'] );
	}

	public function test_test_verb_builds_command_body_via_shared_remote_manager_builder(): void {
		// probe_remote() and RemoteManager's periodic health-check probe must
		// emit the SAME `discovery.get` command wire — both build it through
		// RemoteManager::command_message_body() so the two surfaces can't drift.
		$registry = new Server_Registry();
		$registry->add( 'site-a', [ 'url' => 'https://a.example.com' ] );

		$captured = null;
		Servers_CI_Node::$http_call = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured = $args;
			return [
				'response' => [ 'code' => 200 ],
				'body'     => self::wrap_discovery_response( [ 'registered_hooks' => [ 'init' ] ] ),
			];
		};

		$ci = new Servers_CI_Node( $registry );
		VerbHarness::fire( $ci, 'servers', 'test', [ 'id' => 'site-a' ] );

		// Compare the captured body to the shared builder's output field-by-field,
		// zeroing the per-message microtime TIMESTAMP (the only field that can't
		// match across two separate new_message() calls). Equality on every other
		// field proves probe_remote builds through command_message_body().
		$actual   = \Newspack_Nodes\Message::unpacked( $captured['body'] );
		$expected = \Newspack_Nodes\Message::unpacked( Remote_Manager::command_message_body( 'discovery', 'get', '' ) );
		$actual[ \Newspack_Nodes\Message::TIMESTAMP ]   = 0;
		$expected[ \Newspack_Nodes\Message::TIMESTAMP ] = 0;
		$this->assertSame(
			$expected,
			$actual,
			'probe_remote must build its body through the shared command_message_body() builder'
		);
		// Spell out the verb/arguments/payload the builder produces for this
		// call so the de-dup can't silently regress them (the array equality
		// above already covers TYPE/FROM/TO).
		$value = $actual[ \Newspack_Nodes\Message::VALUE ];
		$this->assertSame( 'get', $value['name'] );
		$this->assertSame( '', $value['arguments'] );
		$this->assertSame( '', $value['payload'] );
	}

	public function test_test_verb_returns_error_on_non_200_response(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [ 'url' => 'https://a.example.com' ] );

		Servers_CI_Node::$http_call = static fn( string $url, array $args ): array =>
			[ 'response' => [ 'code' => 503 ], 'body' => '' ];

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'test', [ 'id' => 'site-a' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( '503', $result );
	}

	public function test_test_verb_returns_error_on_wp_error_response(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [ 'url' => 'https://a.example.com' ] );

		Servers_CI_Node::$http_call = static fn( string $url, array $args ): \WP_Error =>
			new \WP_Error( 'http_timeout', 'request timed out' );

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'test', [ 'id' => 'site-a' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'connect', $result );
	}

	public function test_test_verb_rejects_unauthorized(): void {
		$registry = new Server_Registry();
		$registry->add( 'site-a', [ 'url' => 'https://a.example.com' ] );
		$GLOBALS['_current_user_can'] = false;

		$ci     = new Servers_CI_Node( $registry );
		$result = VerbHarness::fire( $ci, 'servers', 'test', [ 'id' => 'site-a' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_test_verb_returns_error_for_unknown_id(): void {
		$ci     = new Servers_CI_Node( new Server_Registry() );
		$result = VerbHarness::fire( $ci, 'servers', 'test', [ 'id' => 'never-existed' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', $result );
	}
}
