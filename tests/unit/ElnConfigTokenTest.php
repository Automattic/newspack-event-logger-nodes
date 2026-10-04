<?php
/**
 * Tests for the `eln` topology-token namespace.
 *
 * The substrate resolves `<ns:key>` tokens via per-namespace resolvers
 * (Core::register_config_namespace / resolve_config_token). This plugin
 * registers an `eln` namespace for its app-specific tokens (is_hub and the
 * stats Table TTLs) so `<eln:KEY>` resolves to the same value the old
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
		// A site whose active topologies DON'T include `aggregator` is a spoke,
		// and a bool token renders as `0`, which a `bool` arg binds as false.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();
		$this->assertSame( '0', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_is_hub_true_when_aggregator_topology_active(): void {
		// A hub is a site whose active topologies include `aggregator`.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'aggregator' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();
		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_is_hub_true_when_an_active_topology_wraps_aggregator(): void {
		// Deployments run the stock aggregator through a locally-named wrapper,
		// so the ACTIVE name is never `aggregator` and a name match sees a spoke.
		$dir = $this->make_temp_dir( 'eln-hub-wrapper-' );
		\file_put_contents( "{$dir}/okapi-hub.tsl", "include aggregator\n" );
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'okapi-hub' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_is_hub_true_when_an_active_topology_wires_remote_sources(): void {
		// A deployment that FORKS the stock aggregator to change one argument
		// renames it, so no name in the include chain is `aggregator` — but the
		// graph still reads from spokes, which is what makes a site a hub.
		$dir = $this->make_temp_dir( 'eln-hub-fork-' );
		\file_put_contents(
			"{$dir}/okapi-fanout.tsl",
			"make_node Remote_Source firehose:okapi okapi firehose.p<partition>\n"
		);
		\file_put_contents( "{$dir}/okapi-hub.tsl", "include okapi-fanout\n" );
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'okapi-hub' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_is_hub_true_when_a_remote_source_subclass_pulls_the_firehose(): void {
		require_once \dirname( __DIR__ ) . '/fixtures/class-tapir-pull-node.php';
		\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\Tests\\Fixtures\\' );
		$dir = $this->make_temp_dir( 'eln-hub-subclass-' );
		\file_put_contents( "{$dir}/okapi-tapir.tsl", "make_node Tapir_Pull firehose:okapi okapi firehose.p<partition>\n" );
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'okapi-tapir' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_is_hub_true_when_a_remote_source_pulls_one_fixed_firehose_partition(): void {
		$dir = $this->make_temp_dir( 'eln-hub-fixed-' );
		\file_put_contents( "{$dir}/okapi-p3.tsl", "make_node Remote_Source firehose:okapi okapi firehose.p3\n" );
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'okapi-p3' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_an_unresolvable_token_in_a_pulled_partition_fails_the_hub_derivation_loud(): void {
		// Unresolved, it might name the firehose: answering "spoke" would turn
		// that hub's per-server stats off in silence.
		$dir = $this->make_temp_dir( 'eln-hub-token-' );
		\file_put_contents( "{$dir}/okapi-tok.tsl", "make_node Remote_Source firehose:okapi okapi firehose.p<wombat9:shard>\n" );
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'okapi-tok' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->expectExceptionMessage( 'wombat9:shard' );
		Core::resolve_config_token( 'eln', 'is_hub' );
	}

	public function test_an_aggregator_by_name_is_a_hub_whatever_an_earlier_reader_holds(): void {
		$dir = $this->make_temp_dir( 'eln-hub-name-first-' );
		\file_put_contents( "{$dir}/peer-ledger.tsl", "make_node Remote_Source ledger:okapi okapi ledger.p<wombat9:shard>\n" );
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'peer-ledger', 'aggregator' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_is_hub_false_when_the_wired_remote_sources_read_another_log(): void {
		// A spoke may pull some other log from a peer; only a firehose reader
		// aggregates requests, so only one makes the site a hub.
		$dir = $this->make_temp_dir( 'eln-hub-other-log-' );
		\file_put_contents(
			"{$dir}/okapi-ledger.tsl",
			"make_node Remote_Source ledger:okapi okapi ledger.p<partition>\n"
			. "make_node Remote_Source hosefire:okapi okapi hosefire.p<partition>\n"
		);
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined', 'okapi-ledger' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '0', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_an_unreadable_active_topology_fails_the_hub_derivation_loud(): void {
		// Unread, the broken one might be the hub: answering "spoke" would turn
		// its per-server stats off in silence.
		$dir = $this->make_temp_dir( 'eln-hub-broken-' );
		\file_put_contents( "{$dir}/okapi-shard.tsl", "make_node Echo okapi-twin-7719\nmake_node Null okapi-twin-7719\n" );
		Topology_Registry::register_user_dir( $dir );

		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'okapi-shard', 'combined' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->expectException( \RuntimeException::class );
		Core::resolve_config_token( 'eln', 'is_hub' );
	}

	public function test_a_failed_hub_derivation_is_memoized_until_the_local_cache_resets(): void {
		// Every <eln:is_hub> resolution would otherwise re-walk every active
		// topology: the failure is derived once and raised again as it was.
		$dir = $this->make_temp_dir( 'eln-hub-memo-' );
		\file_put_contents( "{$dir}/quokka-shard.tsl", "make_node Echo quokka-twin-4417\nmake_node Null quokka-twin-4417\n" );
		Topology_Registry::register_user_dir( $dir );
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'quokka-shard', 'aggregator' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$first = $this->resolution_failure();
		// Repaired on disk: only a fresh derivation could see it.
		\file_put_contents( "{$dir}/quokka-shard.tsl", "make_node Echo quokka-twin-4417\n" );
		\Newspack_Nodes\Topology_Analyzer::reset_caches();
		$second = $this->resolution_failure();

		$this->assertSame( $first, $second, 'the second resolution re-raises the first failure' );
		Config::reset_local_cache();
		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ), 'a reset derives afresh' );
	}

	/** What resolving `<eln:is_hub>` threw; fails the test when it threw nothing. */
	private function resolution_failure(): \Throwable {
		try {
			Core::resolve_config_token( 'eln', 'is_hub' );
		} catch ( \Throwable $e ) {
			return $e;
		}
		$this->fail( 'expected the hub derivation to throw' );
	}

	// --- schema-token / owned-empty guards ----------------------------------

	public function test_an_owned_but_empty_token_is_resolved_not_unresolvable(): void {
		// A spoke's is_hub is owned and false, NOT unresolvable — strict
		// resolution must return '0' and not throw.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'combined' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '0', Core::resolve_config_token( 'eln', 'is_hub', true ) );
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

	// --- the stats Tables' TTLs ---------------------------------------------

	public function test_the_stats_table_ttls_derive_from_the_retention_window(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 259200 ] );
		$this->assertSame( [ '259200', '10800', '7200' ], [ Config::resolve_eln_token( 'stats_ttl' ), Config::resolve_eln_token( 'stats_url_ttl' ), Config::resolve_eln_token( 'stats_url_fine_ttl' ) ] );
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 5400 ] );
		$this->assertSame( [ '90000', '3600', '5400' ], [ Config::resolve_eln_token( 'stats_ttl' ), Config::resolve_eln_token( 'stats_url_ttl' ), Config::resolve_eln_token( 'stats_url_fine_ttl' ) ], 'the aggregate Table outlives the 25 hours a chart reads' );
	}
}
