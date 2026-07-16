<?php
/**
 * M2BootstrapTest: integration test for the
 * `newspack_nodes/request_graph_ready` hook that wires the nine M2
 * service CIs into the substrate's node registry.
 *
 * `HTTP_In::dispatch` lazy-builds the request-scope graph
 * (`_router` / `_command_interpreter` / `_http`) then fires
 * `newspack_nodes/request_graph_ready` so applications can mount their
 * service CIs through the base interpreter's `make_node()` — construct, name,
 * and sink in one atomic step. A single `POST /newspack-nodes/v1/command`
 * round-trip can then address each by short name.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Rest\HTTP_In_Node;
use Newspack_Nodes\Router_Node;

class M2BootstrapTest extends TestCase {

	/**
	 * Wipe and re-attach both mount callbacks (substrate + app) against the
	 * `newspack_nodes/request_graph_ready` hook so this integration test
	 * exercises the production registration path with no duplicates —
	 * each plugin's own `add_action` is still in `$GLOBALS['_wp_actions']`
	 * from bootstrap, and a second `do_action` would name-collide on
	 * the second mount.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_actions']['newspack_nodes/request_graph_ready'] = [];
		\add_action( 'newspack_nodes/request_graph_ready', 'newspack_nodes_mount_substrate_cis' );
		\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );
	}

	/**
	 * Build the request-scope graph (`_router` / `_command_interpreter` /
	 * `_http`) the way `HTTP_In::dispatch` does in production,
	 * then fire `newspack_nodes/request_graph_ready` and confirm each
	 * service CI was registered under its canonical short name. This is the
	 * INTEGRATED graph: `workers`/`aggregator`/`settings`/`status` are
	 * substrate-mounted (`newspack_nodes_mount_substrate_cis`); `discovery`/
	 * `performance`/`rules` are this plugin's app CIs. The assertion
	 * is that an ELN-installed environment yields the full set.
	 *
	 * `Core::node()` returns null for unknown names, so a non-null lookup
	 * is the contract.
	 */
	public function test_request_graph_ready_registers_all_service_cis(): void {
		( new Router_Node() )->name( Node_Names::ROUTER );
		$base = new Command_Interpreter_Node();
		$base->name( Node_Names::COMMAND_INTERPRETER );
		$base->sink( Core::node( Node_Names::ROUTER ) );
		( new HTTP_In_Node( static fn ( int $code ): null => null ) )->name( Node_Names::HTTP );

		\do_action( 'newspack_nodes/request_graph_ready', $base );

		// `servers` is no longer mounted by ELN — the substrate Vault_CI owns
		// the server registry surface now.
		$expected = [
			// Substrate-mounted (newspack_nodes_mount_substrate_cis):
			'workers',
			'aggregator',
			'settings',
			'status',
			// ELN app CIs (newspack_event_logger_nodes_mount_service_cis):
			'discovery',
			'performance',
			'rules',
		];
		foreach ( $expected as $name ) {
			$this->assertNotNull(
				Core::node( $name ),
				"interpreter '{$name}' must be registered under its short name after newspack_nodes/request_graph_ready."
			);
		}
	}

}
