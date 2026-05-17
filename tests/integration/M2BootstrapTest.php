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

	/**
	 * M4 cutover #1 deletion: the legacy AggregatorStatusController is
	 * replaced by the `Aggregator_CI.status` verb on the unified
	 * `/newspack-nodes/v1/command` endpoint. The dashboard cutover landed
	 * in commit 1350303 and the schema-parity audit confirmed zero gaps,
	 * so the class itself must be gone — not just unreferenced.
	 *
	 * Asserting class non-existence (rather than route non-existence) is
	 * the strongest gate: a stale `register_routes()` call would crash
	 * before the route map could be inspected, and autoloader caching can
	 * resurrect a forgotten file across deploys.
	 */
	public function test_legacy_aggregator_status_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\AggregatorStatusController' ),
			'Legacy AggregatorStatusController class must be deleted; Aggregator_CI.status verb replaces it.'
		);
	}

	public function test_legacy_logger_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\LoggerController' ),
			'Legacy LoggerController class must be deleted; Logger_CI verbs replace it.'
		);
	}

	public function test_legacy_perf_hooks_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\PerfHooksController' ),
			'Legacy PerfHooksController class must be deleted; Performance_CI.hooks_registered + .hooks_categories verbs replace it.'
		);
	}

	public function test_legacy_gyroscope_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\GyroscopeController' ),
			'Legacy GyroscopeController (non-streaming /gyroscope/timeline) must be deleted; Performance_CI.gyroscope_timeline replaces it. GyroscopeStreamController stays for SSE.'
		);
	}

	public function test_legacy_request_log_controller_class_is_gone(): void {
		$this->assertFalse(
			\class_exists( '\\Newspack_Event_Logger_Nodes\\Rest\\RequestLogController' ),
			'Legacy RequestLogController must be deleted; Performance_CI.request_log_list + .request_log_detail replace it. SSE side is in RequestsStreamController which stays.'
		);
	}
}
