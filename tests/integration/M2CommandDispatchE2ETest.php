<?php
/**
 * M2CommandDispatchE2ETest: M2 acceptance test for the whole stack.
 *
 * Proves that every service CI mounted by the plugin's
 * `newspack_nodes/request_graph_ready` listener actually responds
 * end-to-end to a representative verb when driven through the
 * substrate's production `HTTP_In` endpoint. The path under
 * test:
 *
 *   POST /newspack-nodes/v1/command  →  HTTP_In::dispatch
 *                                    →  ensure_request_graph (lazy-builds
 *                                       _router / _command_interpreter / _http)
 *                                    →  do_action newspack_nodes/request_graph_ready
 *                                       (mount hook installs every service CI
 *                                       via $base_interpreter->make_node())
 *                                    →  Router (peels TO head)
 *                                    →  Service CI (interpret + run verb)
 *                                    →  interpreter sink → base interpreter → Router
 *                                    →  HTTP_In (writes packed Message)
 *                                    →  ob_get_clean captures the body
 *
 * Auth-gated CIs (aggregator, performance) get `_current_user_can = true` in
 * setUp so their `manage_options` check passes.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Rest\HTTP_In_Node;

class M2CommandDispatchE2ETest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Auth-gated CIs (aggregator, performance) check manage_options.
		$GLOBALS['_current_user_can'] = true;
		// Wipe the hook store and re-attach both mount callbacks (substrate
		// + app) so dataProvider iterations don't double-register the same
		// hook (which would collide on names on the second tick).
		$GLOBALS['_wp_actions'] = [];
		\add_action( 'newspack_nodes/request_graph_ready', 'newspack_nodes_mount_substrate_cis' );
		\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );
	}

	/**
	 * @dataProvider verb_provider
	 */
	public function test_each_ci_responds_to_a_representative_verb( string $to, string $verb, string $args ): void {
		// HTTP_In::dispatch lazy-builds the request-scope graph
		// (`_router` / `_command_interpreter` / `_http`) and fires
		// `newspack_nodes/request_graph_ready`, which the mount hook in
		// setUp uses to construct and sink each service CI via the
		// base interpreter's make_node() — same path production runs.

		$ctrl = new HTTP_In_Node();
		$ctrl->set_test_mode( true );
		\ob_start();
		$ctrl->dispatch( $this->build_request( $to, $verb, $args ) );
		$body = (string) \ob_get_clean();

		$this->assertNotEmpty( $body, "verb '{$verb}' on '{$to}' produced no response" );
		$msg            = self::response_for( $body, $verb );
		$response_flags = Message::TM_COMMAND | Message::TM_RESPONSE;
		$this->assertSame(
			"e2e-{$verb}",
			$msg[ Message::ID ],
			"verb '{$verb}' returned wrong correlation id"
		);
		$this->assertSame(
			$response_flags,
			$msg[ Message::TYPE ] & ( $response_flags | Message::TM_ERROR ),
			"verb '{$verb}' returned TM_ERROR or wrong type. VALUE was: " . (string) \wp_json_encode( $msg[ Message::VALUE ] )
		);
		// Per the command protocol the response VALUE is a live PHP array
		// `['name'=>'<verb>','payload'=><structure>]` — it rode through
		// packed()/unpacked() as a nested object, never a re-encoded string.
		$this->assertIsArray(
			$msg[ Message::VALUE ],
			"verb '{$verb}' response VALUE should be a structured array, not an encoded string"
		);
		$this->assertSame(
			$verb,
			$msg[ Message::VALUE ]['name'] ?? null,
			"verb '{$verb}' response VALUE.name mismatch"
		);
		$this->assertArrayHasKey(
			'payload',
			$msg[ Message::VALUE ],
			"verb '{$verb}' response VALUE missing payload"
		);
	}

	/**
	 * Fidelity guard for the data-provider's JSON-string args: the provider
	 * passes `'{"limit":1}'` for events.recent, and build_request() must decode
	 * it into the array the verb consumes (verbs do
	 * `is_array($payload) ? $payload : []`, so a raw string collapses to `[]`
	 * and the limit silently reverts to the 100 default). Asserting the verb
	 * actually honoured `limit:1` proves the payload arrived decoded.
	 */
	public function test_events_recent_honours_json_string_limit_from_provider(): void {
		$ctrl = new HTTP_In_Node();
		$ctrl->set_test_mode( true );
		\ob_start();
		$ctrl->dispatch( $this->build_request( 'events', 'recent', '{"limit":1}' ) );
		$body = (string) \ob_get_clean();

		$msg     = self::response_for( $body, 'recent' );
		$payload = $msg[ Message::VALUE ]['payload'] ?? null;
		$this->assertIsArray( $payload, 'events.recent must return a structured payload' );
		$this->assertSame(
			1,
			$payload['meta']['limit'] ?? null,
			'limit:1 from the JSON-string args must reach the verb (a string payload would default to 100)'
		);
	}

	/**
	 * The event-logger-nodes test bootstrap's WP_REST_Request stub only knows
	 * get_param/set_param. HTTP_In reads the body via get_body(),
	 * so we subclass to add that. set_header is a no-op — the controller
	 * doesn't read headers but real callers always set Content-Type.
	 */
	/**
	 * Extract a verb's response Message from a dispatch body. The `/command`
	 * body is JSONL — one packed Message per line — because a verb may emit
	 * stderr/log lines alongside its response. Pick the line carrying THIS
	 * command's correlation id (`e2e-{verb}`) rather than `Message::unpacked()`
	 * on the whole body (which would choke on the multi-line JSONL).
	 *
	 * @param string $body Raw JSONL dispatch body.
	 * @param string $verb The dispatched verb (its reply echoes ID `e2e-{verb}`).
	 * @return array The 7-element response Message.
	 */
	private static function response_for( string $body, string $verb ): array {
		foreach ( \explode( "\n", $body ) as $line ) {
			$line = \trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$msg = Message::unpacked( $line );
			if ( "e2e-{$verb}" === ( $msg[ Message::ID ] ?? '' ) ) {
				return $msg;
			}
		}
		throw new \RuntimeException( "no response with id e2e-{$verb} in JSONL body: {$body}" );
	}

	private function build_request( string $to, string $verb, string $args ): \WP_REST_Request {
		$req = new class() extends \WP_REST_Request {
			private string $body = '';
			public function set_body( string $body ): void { $this->body = $body; }
			public function get_body(): string { return $this->body; }
			public function set_header( string $name, string $value ): void { /* no-op */ }
		};
		// The controller requires a packed 7-element positional Message
		// (`Message::unpacked()`), so build one rather than a keyed object.
		// VALUE is the command struct as a live PHP array — never separately
		// json-encoded; only the whole-message envelope (Message::packed) is
		// JSON. The packed body is one JSONL line, which the substrate's
		// messages_from_body() parses as a single command. `$args` is a
		// JSON-string from the provider; decode it into the array the verbs
		// expect (they do `is_array($payload) ? $payload : []`, so a raw
		// string collapses to `[]` and the verb loses its parameters).
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_COMMAND;
		$msg[ Message::FROM ]  = '_http';
		$msg[ Message::TO ]    = $to;
		$msg[ Message::ID ]    = "e2e-{$verb}";
		$msg[ Message::VALUE ] = [ 'name' => $verb, 'arguments' => '', 'payload' => \json_decode( $args, true ) ];

		$req->set_body( Message::packed( $msg ) );
		// text/plain matches the JSONL/text-plain wire contract (the controller
		// ignores the header, but real callers post JSONL as text/plain).
		$req->set_header( 'content-type', 'text/plain; charset=UTF-8' );
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
