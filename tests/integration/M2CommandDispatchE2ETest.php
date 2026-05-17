<?php
/**
 * M2CommandDispatchE2ETest: M2 acceptance test for the whole stack.
 *
 * Proves that every service CI mounted at `rest_api_init` priority 11 actually
 * responds end-to-end to a representative verb when driven through the
 * substrate's production `Command_Controller` endpoint. The path under test:
 *
 *   POST /newspack-nodes/v1/command  →  Command_Controller::dispatch
 *                                    →  Router (peels TO head)
 *                                    →  Service CI (interpret + run verb)
 *                                    →  CI sink → base CI → Router
 *                                    →  HTTP_Out (writes packed Message)
 *                                    →  ob_get_clean captures the body
 *
 * The substrate request-scope graph (`_router` / `_command_interpreter` /
 * `_http`) is built in setUp the same way `Bootstrap` would build it in a real
 * worker / cli / HTTP request. The plugin's `rest_api_init` priority-11 hook
 * fires the service-CI mount so the dispatch can address each by short name.
 *
 * Auth-gated CIs (aggregator, performance) get `_current_user_can = true` in
 * setUp so their `manage_options` check passes.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Core;
use Newspack_Nodes\HTTP_Out;
use Newspack_Nodes\Message;
use Newspack_Nodes\Rest\Command_Controller;
use Newspack_Nodes\Router;

class M2CommandDispatchE2ETest extends TestCase {

	/** @var list<string> Short names the M2 mount hook registers; kept here so the test owns its own contract. */
	private const SERVICE_CI_NAMES = [ 'workers', 'discovery', 'status', 'settings', 'logger', 'events', 'servers', 'aggregator', 'performance' ];

	protected function setUp(): void {
		parent::setUp();
		// Auth-gated CIs (aggregator, performance) check manage_options.
		$GLOBALS['_current_user_can'] = true;
		// Wipe action store so dataProvider iterations don't re-attach the
		// mount hook 9× — that would double-register and collide on names.
		$GLOBALS['_wp_actions'] = [];
		\add_action( 'rest_api_init', 'newspack_event_logger_nodes_mount_service_cis', 11 );
	}

	/**
	 * @dataProvider verb_provider
	 */
	public function test_each_ci_responds_to_a_representative_verb( string $to, string $verb, string $args ): void {
		// Build the request-scope graph (`_router` / `_command_interpreter` /
		// `_http`) the way a real Bootstrap would before any Command_Controller
		// dispatch runs. Same pattern as VerbHarness / CommandControllerTest.
		( new Router() )->name( '_router' );
		$base = new CommandInterpreter();
		$base->name( '_command_interpreter' );
		$base->sink( Core::node( '_router' ) );
		( new HTTP_Out( static fn ( int $code ): null => null ) )->name( '_http' );

		// Fire the M2 priority-11 hook, then wire each registered CI's sink so
		// verb responses (TO=FROM) walk back through Router → HTTP_Out.
		\do_action( 'rest_api_init' );
		foreach ( self::SERVICE_CI_NAMES as $name ) {
			$ci = Core::node( $name );
			if ( $ci instanceof CommandInterpreter ) {
				$ci->sink( Core::node( '_command_interpreter' ) );
			}
		}

		$ctrl = new Command_Controller();
		$ctrl->set_test_mode( true );
		\ob_start();
		$ctrl->dispatch( $this->build_request( $to, $verb, $args ) );
		$body = (string) \ob_get_clean();

		$this->assertNotEmpty( $body, "verb '{$verb}' on '{$to}' produced no response" );
		$msg            = Message::unpacked( $body );
		$response_flags = Message::TM_COMMAND | Message::TM_RESPONSE;
		$this->assertSame(
			"e2e-{$verb}",
			$msg[ Message::ID ],
			"verb '{$verb}' returned wrong correlation id"
		);
		$this->assertSame(
			$response_flags,
			$msg[ Message::TYPE ] & ( $response_flags | Message::TM_ERROR ),
			"verb '{$verb}' returned TM_ERROR or wrong type. VALUE was: " . (string) $msg[ Message::VALUE ]
		);
	}

	/**
	 * The event-logger-nodes test bootstrap's WP_REST_Request stub only knows
	 * get_param/set_param. Command_Controller reads the body via get_body(),
	 * so we subclass to add that. set_header is a no-op — the controller
	 * doesn't read headers but real callers always set Content-Type.
	 */
	private function build_request( string $to, string $verb, string $args ): \WP_REST_Request {
		$req = new class() extends \WP_REST_Request {
			private string $body = '';
			public function set_body( string $body ): void { $this->body = $body; }
			public function get_body(): string { return $this->body; }
			public function set_header( string $name, string $value ): void { /* no-op */ }
		};
		$req->set_body(
			(string) \wp_json_encode(
				[
					'type'  => Message::TM_COMMAND,
					'to'    => $to,
					'from'  => '_http',
					'id'    => "e2e-{$verb}",
					'value' => \wp_json_encode( [ 'name' => $verb, 'arguments' => $args, 'payload' => '' ] ),
				]
			)
		);
		$req->set_header( 'content-type', 'application/json' );
		return $req;
	}

	/**
	 * Representative verb per CI. Each verb either takes no args or accepts a
	 * minimal safe arg blob. Verbs that mutate / require complex setup are
	 * avoided — this test proves the dispatch path works, not that every verb
	 * is implemented correctly (per-verb tests cover that).
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public static function verb_provider(): array {
		return [
			'workers.list'       => [ 'workers',     'list',   '{}' ],
			'discovery.get'      => [ 'discovery',   'get',    '{}' ],
			'status.get'         => [ 'status',      'get',    '{}' ],
			'settings.get'       => [ 'settings',    'get',    '{}' ],
			'logger.config'      => [ 'logger',      'config', '{}' ],
			'events.recent'      => [ 'events',      'recent', '{"limit":1}' ],
			'servers.list'       => [ 'servers',     'list',   '{}' ],
			'aggregator.health'  => [ 'aggregator',  'health', '{}' ],
			'performance.timing' => [ 'performance', 'timing', '{}' ],
		];
	}
}
