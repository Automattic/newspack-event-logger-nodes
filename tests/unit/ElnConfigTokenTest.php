<?php
/**
 * Tests for the `eln` topology-token namespace.
 *
 * The substrate resolves `<ns:key>` tokens via per-namespace resolvers
 * (Core::register_config_namespace / resolve_config_token). This plugin
 * registers an `eln` namespace for its app-specific tokens (is_hub,
 * stats_mirror_node) so `<eln:KEY>` resolves to the same value the old
 * merged-config `<config:KEY>` produced. The auto_disable_threshold /
 * auto_protect_time_threshold / significant_events_csv tokens were retired
 * with the seven global settings the per-URL ruleset absorbed (Task 10).
 * Keys it does not own resolve to ''.
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

	// --- schema-token / owned-empty guards ----------------------------------

	public function test_stats_mirror_node_unset_is_owned_empty_under_strict(): void {
		// Unset stats_mirror_node ('stats mirror off') is owned-empty, NOT
		// unresolvable — strict resolution must return '' and not throw.
		$this->assertSame( '', Core::resolve_config_token( 'eln', 'stats_mirror_node', true ) );
	}

	public function test_flame_builder_schema_token_defaults_are_owned(): void {
		// Every <ns:key> token default in a node schema must be owned by a
		// registered namespace. A wrong-namespace token (the <config:is_hub>
		// footgun) resolves to '' silently in prod but THROWS under strict, which
		// is exactly what schema-arg resolution now uses. This walks Flame_Builder's
		// schema and fails loud if any token isn't owned.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'aggregator' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$schema = \Newspack_Event_Logger_Nodes\Flame_Builder_Node::node_schema();
		$tokens = [];
		foreach ( $schema['arguments'] ?? [] as $arg ) {
			$this->collect_schema_token( $arg, $tokens );
		}
		foreach ( $schema['commands'] ?? [] as $cmd ) {
			foreach ( $cmd['args'] ?? [] as $arg ) {
				$this->collect_schema_token( $arg, $tokens );
			}
		}
		$this->assertNotEmpty( $tokens, 'expected at least one <ns:key> token default to exercise' );
		foreach ( $tokens as $token ) {
			// Throws (RuntimeException) if the namespace doesn't own the key.
			Core::resolve_config_tokens( $token, true );
		}
		$this->addToAssertionCount( 1 );
	}

	/** @param array<string,mixed> $arg */
	private function collect_schema_token( array $arg, array &$tokens ): void {
		$default = $arg['default'] ?? null;
		if ( \is_string( $default ) && \preg_match( '/<[a-zA-Z_]\w*:[a-zA-Z_]\w*>/', $default ) ) {
			$tokens[] = $default;
		}
	}
}
