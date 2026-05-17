<?php
/**
 * M2BootstrapTest: integration test for the rest_api_init priority-11 hook
 * that wires the nine M2 service CIs into the substrate's node registry.
 *
 * The substrate registers `_router`, `_command_interpreter`, `_http`, and
 * per-worker Partitions at `rest_api_init` priority 10. Application service
 * CIs mount at priority 11 (after `_command_interpreter` exists so they can
 * be sinked through it via Router lookups, and after the substrate's own
 * registrations have completed) so that a single `POST /newspack-nodes/v1/command`
 * round-trip can address any of them by name.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;

class M2BootstrapTest extends TestCase {

	/**
	 * Other tests in the suite wipe `$GLOBALS['_wp_actions']` for
	 * isolation, which strips the plugin-file `add_action` calls that
	 * fired at bootstrap. Re-attach the same named callback the plugin
	 * file uses so this integration test still exercises the production
	 * registration path — mirrors the pattern in TopologyRegistrationTest
	 * and ExpectedLogBasenamesTest.
	 */
	protected function setUp(): void {
		parent::setUp();
		\add_action( 'rest_api_init', 'newspack_event_logger_nodes_mount_service_cis', 11 );
	}

	/**
	 * Fire `rest_api_init` and confirm each service CI was registered under
	 * its canonical short name. `Core::node()` returns null for unknown
	 * names, so a non-null lookup is the contract.
	 */
	public function test_rest_api_init_registers_all_nine_service_cis(): void {
		\do_action( 'rest_api_init' );

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
				"CI '{$name}' must be registered under its short name after rest_api_init."
			);
		}
	}
}
