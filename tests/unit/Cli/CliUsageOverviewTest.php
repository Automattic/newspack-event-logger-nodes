<?php
/**
 * Every command this plugin registers lists in the `wp nodes` overview it
 * shares with the substrate.
 *
 * @package Newspack_Event_Logger_Nodes\Tests
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Cli;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Tests\ListsEveryCliCommand;

require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/WPCLIStub.php';
require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/ListsEveryCliCommand.php';

final class CliUsageOverviewTest extends TestCase {
	use ListsEveryCliCommand;

	public function test_every_command_lists_in_its_usage_overview(): void {
		$GLOBALS['_test_wp_cli_commands'] = [];

		\newspack_nodes_register_cli_commands();
		\newspack_event_logger_nodes_register_cli_commands();

		$this->assert_every_cli_command_listed( $GLOBALS['_test_wp_cli_commands'] );
	}
}
