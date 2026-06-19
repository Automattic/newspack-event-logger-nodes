<?php
/**
 * Tests for the `eln` topology-token namespace.
 *
 * The substrate resolves `<ns:key>` tokens via per-namespace resolvers
 * (Core::register_config_namespace / resolve_config_token). This plugin
 * registers an `eln` namespace for its four app-specific tokens
 * (is_hub, auto_disable_threshold, auto_protect_time_threshold,
 * significant_events_csv)
 * so `<eln:KEY>` resolves to the same value the old merged-config
 * `<config:KEY>` produced. Keys it does not own resolve to ''.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Topology_Registry;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ElnConfigTokenTest extends TestCase {

	/** Snapshot of the process-lifetime resolver registry, restored in tearDown. */
	private array $saved_resolvers;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_resolvers  = Core::$config_resolvers;
		$GLOBALS['_wp_options'] = [];
		// `is_hub` derives from active-topology membership; the active names are
		// resolved against the stock topology dir, so register it here (other
		// test classes reset the registry) so `aggregator` synthesizes.
		Topology_Registry::register_stock_dir( \dirname( __DIR__, 2 ) . '/topologies' );
		\Newspack_Nodes\Config::reset();
		Config::reset();
	}

	protected function tearDown(): void {
		Core::$config_resolvers = $this->saved_resolvers;
		$GLOBALS['_wp_options'] = [];
		\Newspack_Nodes\Config::reset();
		Config::reset();
		parent::tearDown();
	}

	public function test_eln_namespace_resolves_owned_schema_key_from_wp_option(): void {
		// auto_disable_threshold is a real load_config() schema key (int); the
		// WP option overlays the file default and the `eln` resolver returns it.
		\update_option( 'newspack_event_logger_nodes_auto_disable_threshold', '17' );
		Config::reset();
		$this->assertSame( '17', Core::resolve_config_token( 'eln', 'auto_disable_threshold' ) );
	}

	public function test_eln_namespace_does_not_own_substrate_key(): void {
		// logs_dir is substrate-owned (the `config` namespace), not ELN's —
		// resolving it through the `eln` namespace yields ''.
		$this->assertSame( '', Core::resolve_config_token( 'eln', 'logs_dir' ) );
	}

	// --- is_hub resolver ----------------------------------------------------

	public function test_is_hub_false_when_aggregator_topology_inactive(): void {
		// A site whose active topologies DON'T include `aggregator` is a spoke.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();
		$this->assertSame( '', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_is_hub_true_when_aggregator_topology_active(): void {
		// A hub is a site whose active topologies include `aggregator`.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'aggregator' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();
		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	// --- significant_events_csv resolver -----------------------------------

	public function test_significant_events_csv_imploded_from_array(): void {
		// The schema stores significant_events as a string array; the topology
		// token MUST expose the comma-joined CSV the flame builder expects.
		\update_option(
			'newspack_event_logger_nodes_significant_events',
			[ 'foo', 'bar', 'baz' ]
		);
		Config::reset();
		$this->assertSame(
			'foo,bar,baz',
			Core::resolve_config_token( 'eln', 'significant_events_csv' )
		);
	}

	public function test_significant_events_csv_empty_when_no_events(): void {
		// Empty array → empty string (NOT null — the substrate command
		// argument must be a string for the worker to receive it).
		\update_option( 'newspack_event_logger_nodes_significant_events', [] );
		Config::reset();
		$this->assertSame(
			'',
			Core::resolve_config_token( 'eln', 'significant_events_csv' )
		);
	}
}
