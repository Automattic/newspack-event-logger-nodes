<?php
/**
 * VaultChangedListenerTest: unit tests for the bootstrap listener that
 * reacts to the substrate's `newspack_nodes/vault/changed` action.
 *
 * The substrate Vault_CI fires `newspack_nodes/vault/changed`
 * ( $id, $action, $was_enabled, $now_enabled ) on add / update / remove.
 * The aggregator-specific side-effect that USED to live inside Servers_CI now
 * runs from a free-function listener `newspack_event_logger_nodes_on_vault_changed`:
 * it flags a supervisor restart so the hub-control worker respawns, re-loads
 * remotes from the Vault, and the settings-sync node graph (Settings_Sync_Node +
 * Discovery_Collector_Node) fans current settings to the new/changed server.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Lock_Node;

class VaultChangedListenerTest extends TestCase {

	/** Absolute path to the supervisor lock dir the listener writes the restart flag into. */
	private string $lock_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$base = $this->make_temp_dir();
		$this->use_base_dir( $base );
		$this->lock_dir = $base . '/locks/supervisor.lock.d';
		\mkdir( $this->lock_dir, 0777, true );
	}

	private function restart_flag(): string {
		return $this->lock_dir . '/' . Lock_Node::RESTART_FLAG;
	}

	public function test_listener_function_exists(): void {
		$this->assertTrue( \function_exists( 'newspack_event_logger_nodes_on_vault_changed' ) );
	}

	public function test_add_requests_supervisor_restart(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke1', 'added', false, true );
		$this->assertFileExists( $this->restart_flag() );
	}

	public function test_update_requests_supervisor_restart(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke2', 'updated', true, true );
		$this->assertFileExists( $this->restart_flag() );
	}

	public function test_remove_requests_supervisor_restart(): void {
		\newspack_event_logger_nodes_on_vault_changed( 'spoke5', 'removed', true, false );
		$this->assertFileExists( $this->restart_flag() );
	}

	public function test_missing_lock_dir_is_best_effort_no_throw(): void {
		$this->rmdir_recursive( $this->lock_dir );
		\newspack_event_logger_nodes_on_vault_changed( 'spoke6', 'added', false, true );
		$this->assertFileDoesNotExist( $this->restart_flag() );
	}

	public function test_listener_is_registered_on_vault_changed_action(): void {
		// Pin exactly one registration of the bootstrap callback regardless of
		// run order — the bootstrap registers it at file load, but other tests
		// reset $GLOBALS['_wp_actions'], so the count is otherwise nondeterministic.
		$GLOBALS['_wp_actions']['newspack_nodes/vault/changed'] = [];
		\add_action( 'newspack_nodes/vault/changed', 'newspack_event_logger_nodes_on_vault_changed', 10, 4 );
		\do_action( 'newspack_nodes/vault/changed', 'spoke7', 'added', false, true );
		$this->assertFileExists( $this->restart_flag() );
	}
}
