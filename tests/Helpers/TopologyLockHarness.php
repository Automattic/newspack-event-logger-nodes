<?php
/**
 * TopologyLockHarness: per-partition lock-dir fixtures for the tests that
 * assert a config write actually reaches the live fleet.
 *
 * Every worker holds a WP option cache frozen at boot, so a write only lands
 * within the 15s scan window if the writer touches a restart or reload flag in
 * each active topology's partition lock dirs. Three suites assert that —
 * AdminTest (settings form), RuleSetTest (ruleset writes) and PerformanceCITest
 * (the hub->spoke settings-sync receive path) — and they share this rather than
 * each copying the fixture dance.
 *
 * Lock dirs resolve through `Config::get_locks_directory()`, the same accessor
 * production uses, so a test cannot assert against a path the code under test
 * never writes to.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Lock_Node;
use Newspack_Nodes\Topology_Registry;

trait TopologyLockHarness {

	/** Temp dir holding the per-test topology `.tsl` fixtures; null until registered. */
	protected ?string $tsl_dir = null;

	/**
	 * Register a stock-topology dir built from `$fixtures` (name => `.tsl` body)
	 * and mark `$active` the live set.
	 *
	 * The fixture bodies matter because restart classification is by consumer
	 * node type: `resolve_class('Flame_Builder')` is what maps a save to the
	 * lock dirs of the topologies whose graph instantiates that node.
	 *
	 * @param array<string,string> $fixtures Topology name => `.tsl` source.
	 * @param array<int,string>    $active   Active topology names.
	 * @param int|null             $num_partitions Global partition count, when it must not be the default.
	 */
	protected function register_topology_fixtures( array $fixtures, array $active, ?int $num_partitions = null ): void {
		Topology_Registry::reset();
		$this->tsl_dir = $this->make_temp_dir( 'eln-topology-locks-' );
		Topology_Registry::register_stock_dir( $this->tsl_dir );
		foreach ( $fixtures as $name => $source ) {
			\file_put_contents( "{$this->tsl_dir}/{$name}.tsl", $source );
		}
		\update_option( 'newspack_nodes_topologies', $active );
		if ( null !== $num_partitions ) {
			\update_option( 'newspack_nodes_num_partitions', $num_partitions );
		}
		Config::reset();
		RuntimeConfig::reset();
	}

	/**
	 * The stock ELN-shaped fixture set: combined/flame-builder carry a
	 * Flame_Builder, hub-control a Discovery_Collector, aggregator neither, and
	 * job-router nothing an event-list save reaches — so only an 'all'
	 * classification touches it.
	 *
	 * @param array<int,string> $active Active topology names (default: all fixtures).
	 */
	protected function register_topologies( array $active = [ 'combined', 'aggregator', 'hub-control', 'flame-builder', 'job-router' ] ): void {
		$this->register_topology_fixtures(
			[
				'combined'      => "make_node Flame_Builder flame-builder\n",
				'flame-builder' => "make_node Flame_Builder flame-builder\n",
				'hub-control'   => "make_node Discovery_Collector discovery-collector 300\n",
				'aggregator'    => "make_node Remote_Job_Rewrite remote-job-rewrite\n",
				'job-router'    => "make_node Job_Router job-router\n",
			],
			$active
		);
	}

	/** Drop the registered fixtures and the options they rode in on. */
	protected function reset_topology_fixtures(): void {
		if ( null === $this->tsl_dir ) {
			return;
		}
		\delete_option( 'newspack_nodes_topologies' );
		\delete_option( 'newspack_nodes_num_partitions' );
		RuntimeConfig::reset();
		Topology_Registry::reset();
		$this->rmdir_recursive( $this->tsl_dir );
		$this->tsl_dir = null;
	}

	/** The configured locks directory, resolved exactly as production resolves it. */
	protected function locks_dir(): string {
		return Config::get_locks_directory();
	}

	/**
	 * Create one partition lock dir. Both flag channels require the dir to
	 * already exist; neither requires a live holder, so no heartbeat is written.
	 *
	 * @param string $group     Topology name, or a bare worker-group name for phantom-dir tests.
	 * @param int    $partition Partition index.
	 */
	protected function prepare_lock_dir( string $group, int $partition ): string {
		$dir = $this->lock_dir_for( $group, $partition );
		\mkdir( $dir, 0755, true );
		return $dir;
	}

	protected function lock_dir_for( string $topology, int $partition ): string {
		return $this->locks_dir() . "/{$topology}.p{$partition}.lock.d";
	}

	protected function assertRestartFlagged( string $topology, int $partition ): void {
		$this->assertFileExists( $this->lock_dir_for( $topology, $partition ) . '/' . Lock_Node::RESTART_FLAG );
	}

	protected function assertRestartNotFlagged( string $topology, int $partition ): void {
		$this->assertFileDoesNotExist( $this->lock_dir_for( $topology, $partition ) . '/' . Lock_Node::RESTART_FLAG );
	}

	protected function assertReloadFlagged( string $topology, int $partition ): void {
		$this->assertFileExists( $this->lock_dir_for( $topology, $partition ) . '/' . Lock_Node::RELOAD_FLAG );
	}

	protected function assertReloadNotFlagged( string $topology, int $partition ): void {
		$this->assertFileDoesNotExist( $this->lock_dir_for( $topology, $partition ) . '/' . Lock_Node::RELOAD_FLAG );
	}
}
