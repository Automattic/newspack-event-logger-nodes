<?php
/**
 * M2BootstrapTest: integration test for the
 * `newspack_nodes/request_graph_ready` hook that wires the nine M2
 * service CIs into the substrate's node registry.
 *
 * `Command_Controller::dispatch` lazy-builds the request-scope graph
 * (`_router` / `_command_interpreter` / `_http`) then fires
 * `newspack_nodes/request_graph_ready` so applications can mount their
 * service CIs through the base CI's `make_node()` — construct, name,
 * and sink in one atomic step. A single `POST /newspack-nodes/v1/command`
 * round-trip can then address each by short name.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Core;
use Newspack_Nodes\HTTP_Out;
use Newspack_Nodes\Router;

class M2BootstrapTest extends TestCase {

	/**
	 * Wipe and re-attach exactly one mount callback against the
	 * `newspack_nodes/request_graph_ready` hook so this integration test
	 * exercises the production registration path with no duplicates —
	 * the plugin file's own `add_action` is still in `$GLOBALS['_wp_actions']`
	 * from bootstrap, and a second `do_action` would name-collide on
	 * the second mount.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_actions']['newspack_nodes/request_graph_ready'] = [];
		\add_action( 'newspack_nodes/request_graph_ready', 'newspack_event_logger_nodes_mount_service_cis' );
	}

	/**
	 * Build the request-scope graph (`_router` / `_command_interpreter` /
	 * `_http`) the way `Command_Controller::dispatch` does in production,
	 * then fire `newspack_nodes/request_graph_ready` and confirm each
	 * service CI was registered under its canonical short name.
	 * `Core::node()` returns null for unknown names, so a non-null lookup
	 * is the contract.
	 */
	public function test_request_graph_ready_registers_all_nine_service_cis(): void {
		( new Router() )->name( '_router' );
		$base = new CommandInterpreter();
		$base->name( '_command_interpreter' );
		$base->sink( Core::node( '_router' ) );
		( new HTTP_Out( static fn ( int $code ): null => null ) )->name( '_http' );

		\do_action( 'newspack_nodes/request_graph_ready', $base );

		$expected = [
			'workers',
			'discovery',
			'status',
			'settings',
			'logger',
			'events',
			'servers',
			'aggregator',
			'performance',
		];
		foreach ( $expected as $name ) {
			$this->assertNotNull(
				Core::node( $name ),
				"CI '{$name}' must be registered under its short name after newspack_nodes/request_graph_ready."
			);
		}
	}
}
