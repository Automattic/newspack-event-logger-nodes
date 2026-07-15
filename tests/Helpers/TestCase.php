<?php
namespace Newspack_Event_Logger_Nodes\Tests;

use Newspack_Nodes\Tests\TestCase as RuntimeTestCase;

abstract class TestCase extends RuntimeTestCase {

	/**
	 * Re-assert the topology registration bootstrap.php makes. Sibling tests call
	 * Topology_Registry::reset(), which strands every later test that reads a
	 * topology — and ELN topologies `include` ACROSS the plugin boundary
	 * (job-router -> job-intake, which the substrate ships), so BOTH dirs must
	 * resolve. An unresolvable include now throws by design: an empty write set
	 * would read as "no conflict" to the gate and "these logs are orphans" to the
	 * GC. register_plugin()/register_builtin_dir() are idempotent.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
		);
		\Newspack_Nodes\Topology_Registry::register_builtin_dir(
			\dirname( __DIR__, 3 ) . '/newspack-nodes/topologies'
		);
	}

	/**
	 * ELN-specific default prefix so app temp dirs live in their OWN namespace,
	 * not the substrate's `newspack-nodes-test-`. Under parallel run-coverage the
	 * nodes and ELN suites each `rm -rf` their prefix; sharing one prefix had each
	 * suite deleting the other's LIVE temp dirs mid-run. Inherits the parent's
	 * PID + more-entropy uniqueness and auto-cleanup.
	 */
	protected function make_temp_dir( string $prefix = 'newspack-event-logger-nodes-test-' ): string {
		return parent::make_temp_dir( $prefix );
	}

	/**
	 * Same as the substrate helper but also resets the application Config
	 * cache so its merged result picks up the new file.
	 */
	protected function use_base_dir( string $dir, array $extras = [] ): void {
		parent::use_base_dir( $dir, $extras );
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			\Newspack_Event_Logger_Nodes\Config::reset();
		}
	}
}
