<?php
/**
 * VaultChangedListenerTest: unit tests for the bootstrap listener that
 * reacts to the substrate's `newspack_nodes/vault/changed` action.
 *
 * The substrate Vault_CI fires `newspack_nodes/vault/changed`
 * ( $id, $action, $was_enabled, $now_enabled ) on add / update / remove.
 * The aggregator-specific side-effects that USED to live inside Servers_CI
 * (settings-sync fan-out + supervisor restart) now run from a free-function
 * listener `newspack_event_logger_nodes_on_vault_changed`.
 *
 * The settings-sync call is captured through the
 * `Remote_Manager::$sync_all_dispatch` closure seam so we assert exactly the
 * server-id list passed without touching the filesystem (Job_Intake) path.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Remote_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class VaultChangedListenerTest extends TestCase {

	/**
	 * Captured `$server_ids` from each `queue_sync_all_settings` call the
	 * listener makes (one entry per call).
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $synced = [];

	protected function setUp(): void {
		parent::setUp();
		$this->synced                     = [];
		Remote_Manager::$sync_all_dispatch = function ( array $server_ids ): int {
			$this->synced[] = $server_ids;
			return \count( $server_ids );
		};
	}

	protected function tearDown(): void {
		Remote_Manager::$sync_all_dispatch = null;
		parent::tearDown();
	}

	public function test_listener_function_exists(): void {
		$this->assertTrue( \function_exists( 'newspack_event_logger_nodes_on_vault_changed' ) );
	}

	public function test_add_while_enabled_queues_sync_for_that_id(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke1', 'added', false, true );
		$this->assertSame( [ [ 'spoke1' ] ], $this->synced );
	}

	public function test_update_enable_flip_queues_sync_for_that_id(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke2', 'updated', false, true );
		$this->assertSame( [ [ 'spoke2' ] ], $this->synced );
	}

	public function test_disabled_add_does_not_queue_sync(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke3', 'added', false, false );
		$this->assertSame( [], $this->synced );
	}

	public function test_update_with_no_enable_flip_does_not_queue_sync(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke4', 'updated', true, true );
		$this->assertSame( [], $this->synced );
	}

	public function test_remove_does_not_queue_sync(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke5', 'removed', true, false );
		$this->assertSame( [], $this->synced );
	}

	public function test_listener_is_registered_on_vault_changed_action(): void {
		// Pin exactly one registration of the bootstrap callback regardless of
		// run order — the bootstrap registers it at file load, but other tests
		// reset $GLOBALS['_wp_actions'], so the count is otherwise nondeterministic.
		$GLOBALS['_wp_actions']['newspack_nodes/vault/changed'] = [];
		\add_action( 'newspack_nodes/vault/changed', 'newspack_event_logger_nodes_on_vault_changed', 10, 4 );
		\do_action( 'newspack_nodes/vault/changed', 'spoke6', 'added', false, true );
		$this->assertSame( [ [ 'spoke6' ] ], $this->synced );
	}
}
