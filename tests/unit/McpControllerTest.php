<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\MCP_Controller;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Auth;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

/**
 * The MCP surface: a thin wrapper over verbs that already exist, authenticated
 * by a scoped session and acting AS the user who minted it.
 *
 * The load-bearing property is that a scope is a CEILING. A read-scoped session
 * minted by an administrator can call `overview` and cannot touch the ruleset,
 * and a manage-scoped session minted by a nobody can do nothing at all.
 */
#[CoversClass( MCP_Controller::class )]
class McpControllerTest extends TestCase {

	private ?\Memcached $prev_memd = null;

	protected function setUp(): void {
		parent::setUp();
		$this->prev_memd = Core::$memd;
		Core::$memd      = new InMemoryMemcached();
		$GLOBALS['_wp_test_current_user_id'] = 42;
		$GLOBALS['_current_user_can']        = true;
	}

	protected function tearDown(): void {
		Cache_Backend::$apcu_usable           = static fn (): bool => false;
		Capabilities::$session_scope          = null;
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_wp_test_current_user_id']  = 0;
		Core::$memd                           = $this->prev_memd;
		parent::tearDown();
	}

	private function request( array $body, ?string $bearer = null ): \WP_REST_Request {
		$req = new \WP_REST_Request();
		$req->set_body( (string) \wp_json_encode( $body ) );
		if ( null !== $bearer ) {
			$req->set_header( 'authorization', "Bearer {$bearer}" );
		}
		return $req;
	}

	private function session( string $scope ): array {
		$minted = Command_Auth::mint_session( $scope, 900 );
		return [ $minted, $minted['handle'] . '.' . $minted['key'] ];
	}

	public function test_a_request_with_no_credential_is_refused(): void {
		$result = ( new MCP_Controller() )->check_permission( $this->request( [] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_unauthorized', $result->get_error_code() );
	}

	public function test_a_wrong_key_is_refused(): void {
		[ $minted ] = $this->session( Capabilities::READ );

		$result = ( new MCP_Controller() )->check_permission(
			$this->request( [], $minted['handle'] . '.' . \str_repeat( 'f', 64 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_a_valid_session_installs_its_ceiling(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );

		$this->assertTrue( ( new MCP_Controller() )->check_permission( $this->request( [], $bearer ) ) );
		$this->assertSame( Capabilities::READ, Capabilities::$session_scope );
	}

	public function test_initialize_names_the_server_and_its_protocol(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$reply = $controller->dispatch(
			$this->request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize' ], $bearer )
		);

		$this->assertSame( '2.0', $reply['jsonrpc'] );
		$this->assertSame( 1, $reply['id'] );
		$this->assertSame( MCP_Controller::PROTOCOL_VERSION, $reply['result']['protocolVersion'] );
		$this->assertSame( 'newspack-event-logger-nodes', $reply['result']['serverInfo']['name'] );
	}

	public function test_tools_list_offers_only_what_the_scope_covers(): void {
		[ , $read_bearer ] = $this->session( Capabilities::READ );
		$controller        = new MCP_Controller();
		$controller->check_permission( $this->request( [], $read_bearer ) );

		$names = \array_column(
			$controller->dispatch(
				$this->request( [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ], $read_bearer )
			)['result']['tools'],
			'name'
		);

		$this->assertContains( 'performance_overview', $names );
		$this->assertContains( 'performance_ask', $names );
		$this->assertNotContains( 'rules_upsert', $names, 'a read scope may not edit the ruleset' );
	}

	public function test_a_tune_scope_is_offered_the_ruleset(): void {
		[ , $bearer ] = $this->session( Capabilities::TUNE );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$names = \array_column(
			$controller->dispatch(
				$this->request( [ 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list' ], $bearer )
			)['result']['tools'],
			'name'
		);

		$this->assertContains( 'rules_upsert', $names );
	}

	/** Every tool description carries the caveat; a bare ratio invites invention. */
	public function test_every_tool_says_what_is_not_measured(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$tools = $controller->dispatch(
			$this->request( [ 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list' ], $bearer )
		)['result']['tools'];

		foreach ( $tools as $tool ) {
			$this->assertStringContainsString( 'SQL', $tool['description'] );
		}
	}

	public function test_calling_a_tool_the_scope_does_not_cover_is_an_error(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$reply = $controller->dispatch(
			$this->request(
				[
					'jsonrpc' => '2.0',
					'id'      => 5,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'rules_upsert', 'arguments' => [ 'rule' => '{}' ] ],
				],
				$bearer
			)
		);

		$this->assertArrayHasKey( 'error', $reply );
		$this->assertStringContainsString( 'unknown tool', \strtolower( $reply['error']['message'] ) );
	}

	public function test_calling_a_read_tool_returns_its_payload_as_content(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$reply = $controller->dispatch(
			$this->request(
				[
					'jsonrpc' => '2.0',
					'id'      => 6,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'performance_overview', 'arguments' => [] ],
				],
				$bearer
			)
		);

		$this->assertArrayNotHasKey( 'error', $reply );
		$this->assertSame( 'text', $reply['result']['content'][0]['type'] );
		$decoded = \json_decode( $reply['result']['content'][0]['text'], true );
		$this->assertArrayHasKey( 'total_urls', $decoded );
	}

	/** The one guard a new fleet-fronting route must not quietly omit. */
	public function test_a_subsite_is_refused_at_the_door(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$GLOBALS['_wp_test_is_multisite'] = true;
		$GLOBALS['_wp_test_is_main_site'] = false;

		try {
			$result = ( new MCP_Controller() )->check_permission( $this->request( [], $bearer ) );
			$this->assertInstanceOf( \WP_Error::class, $result );
		} finally {
			$GLOBALS['_wp_test_is_multisite'] = false;
			$GLOBALS['_wp_test_is_main_site'] = true;
		}
	}

	/**
	 * The scope is a ceiling over the MINTING USER's authority, so listing has
	 * to ask what that user can actually do — a session minted by someone who
	 * holds nothing would otherwise list every tool and refuse all of them.
	 */
	public function test_a_session_whose_user_holds_nothing_lists_no_tools(): void {
		[ , $bearer ] = $this->session( Capabilities::MANAGE );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );
		$GLOBALS['_current_user_can'] = false;

		$tools = $controller->dispatch(
			$this->request( [ 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list' ], $bearer )
		)['result']['tools'];

		$this->assertSame( [], $tools );
	}

	/** JSON-RPC forbids a response to a notification (no `id`). */
	public function test_a_notification_gets_no_response(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$this->assertNull(
			$controller->dispatch(
				$this->request( [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ], $bearer )
			)
		);
	}

	public function test_the_door_rate_limits_a_looping_agent(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();

		$last = true;
		for ( $i = 0; $i <= MCP_Controller::RATE_LIMIT_BURST; $i++ ) {
			$last = $controller->check_permission( $this->request( [], $bearer ) );
		}

		$this->assertInstanceOf( \WP_Error::class, $last );
		$this->assertSame( 'rate_limited', $last->get_error_code() );
	}

	public function test_an_unknown_method_is_a_jsonrpc_error(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$reply = $controller->dispatch(
			$this->request( [ 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'wizard/summon' ], $bearer )
		);

		$this->assertSame( -32601, $reply['error']['code'] );
	}

	public function test_a_verb_refusal_comes_back_as_a_tool_error_not_a_crash(): void {
		[ , $bearer ] = $this->session( Capabilities::READ );
		$controller   = new MCP_Controller();
		$controller->check_permission( $this->request( [], $bearer ) );

		$reply = $controller->dispatch(
			$this->request(
				[
					'jsonrpc' => '2.0',
					'id'      => 8,
					'method'  => 'tools/call',
					'params'  => [ 'name' => 'performance_url_detail', 'arguments' => [ 'hash' => 'ffffffff' ] ],
				],
				$bearer
			)
		);

		$this->assertTrue( $reply['result']['isError'] );
		$this->assertStringContainsString( 'URL not found', $reply['result']['content'][0]['text'] );
	}
}
