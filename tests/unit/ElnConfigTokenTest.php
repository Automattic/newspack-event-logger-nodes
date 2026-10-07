<?php
/**
 * Tests for the `eln` topology-token namespace.
 *
 * The substrate resolves `<ns:key>` tokens via per-namespace resolvers
 * (Core::register_config_namespace / resolve_config_token). This plugin
 * registers an `eln` namespace for its app-specific tokens: is_hub, which an
 * active topology declares in its own frontmatter, and the stats Table TTLs.
 * Keys it does not own resolve to ''.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ElnConfigTokenTest extends TestCase {

	/** Snapshot of the process-lifetime resolver registry, restored in tearDown. */
	private array $saved_resolvers;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_resolvers  = Core::$config_resolvers;
		$GLOBALS['_wp_options'] = [];
		// Other classes reset the registry, so the stock dir is registered here
		// for the shipped `aggregator` and `hub` to resolve.
		Topology_Registry::register_stock_dir( \dirname( __DIR__, 2 ) . '/topologies' );
		\Newspack_Nodes\Config::reset();
		Config::reset();
	}

	protected function tearDown(): void {
		// A case may shadow the stock dir; the next setUp re-registers.
		Topology_Registry::reset();
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

	public function test_an_active_topology_declaring_is_hub_makes_a_hub(): void {
		$dir = $this->make_temp_dir( 'eln-hub-declared-' );
		\file_put_contents( "{$dir}/numbat-ledger.tsl", "make_node Echo numbat-echo-5521\n" );
		\file_put_contents( "{$dir}/wombat-relay.tsl", "var is_hub = 1\nmake_node Echo wombat-echo-3301\n" );
		$this->activate_user_topologies( $dir, [ 'numbat-ledger', 'wombat-relay' ] );

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_no_declaration_makes_a_spoke_whatever_its_readers_pull(): void {
		// A firehose reader is wiring, not a declaration: only the var counts.
		$dir = $this->make_temp_dir( 'eln-hub-undeclared-' );
		\file_put_contents( "{$dir}/numbat-ledger.tsl", "make_node Echo numbat-echo-5521\n" );
		\file_put_contents(
			"{$dir}/quokka-fanout.tsl",
			"var is_hub = 0\nmake_node Remote_Source firehose:quokka quokka /tmp/quokka-off /tmp/quokka-dl firehose.p{partition}:next-quokka\n"
		);
		$this->activate_user_topologies( $dir, [ 'numbat-ledger', 'quokka-fanout' ] );

		$this->assertSame( '0', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_a_declaration_in_an_included_file_only_makes_a_spoke(): void {
		// Frontmatter is the top-level file's alone, as num_partitions is.
		$dir = $this->make_temp_dir( 'eln-hub-included-' );
		\file_put_contents( "{$dir}/numbat-core.tsl", "var is_hub = 1\nmake_node Echo numbat-echo-7702\n" );
		\file_put_contents( "{$dir}/numbat-shell.tsl", "include numbat-core\n" );
		$this->activate_user_topologies( $dir, [ 'numbat-shell' ] );

		$this->assertSame( '0', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_a_declaration_other_than_one_or_zero_fails_loud_naming_its_topology(): void {
		$dir = $this->make_temp_dir( 'eln-hub-malformed-' );
		\file_put_contents( "{$dir}/wombat-relay.tsl", "var is_hub = 1\nmake_node Echo wombat-echo-3301\n" );
		\file_put_contents( "{$dir}/quokka-tally.tsl", "var is_hub = yes\nmake_node Echo quokka-echo-8814\n" );
		$this->activate_user_topologies( $dir, [ 'wombat-relay', 'quokka-tally' ] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/quokka-tally.*yes/' );
		Core::resolve_config_token( 'eln', 'is_hub' );
	}

	public function test_a_topology_named_aggregator_without_the_declaration_makes_a_spoke(): void {
		// The name is not a signal: a stock dir shadowing the shipped file
		// with one that declares nothing leaves the site a spoke.
		$dir = $this->make_temp_dir( 'eln-hub-named-' );
		\file_put_contents( "{$dir}/aggregator.tsl", "make_node Echo aggregator-echo-6630\n" );
		Topology_Registry::reset();
		Topology_Registry::register_stock_dir( $dir );
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'aggregator' ];
		\Newspack_Nodes\Config::reset();
		Config::reset();

		$this->assertSame( '0', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_the_stock_hub_topologies_declare_themselves(): void {
		$this->assertSame( '1', Topology_Analyzer::frontmatter( 'aggregator' )['is_hub'] ?? null );
		$this->assertSame( '1', Topology_Analyzer::frontmatter( 'hub' )['is_hub'] ?? null );
	}

	public function test_a_declaration_stands_though_the_topology_includes_a_file_that_is_absent(): void {
		$dir = $this->make_temp_dir( 'eln-hub-broken-include-' );
		\file_put_contents( "{$dir}/okapi-shard.tsl", "var is_hub = 1\ninclude absent-lab-7719\nmake_node Echo okapi-echo-7719\n" );
		$this->activate_user_topologies( $dir, [ 'okapi-shard' ] );

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	public function test_an_active_name_no_tsl_resolves_fails_loud_naming_it(): void {
		$dir = $this->make_temp_dir( 'eln-hub-missing-' );
		\file_put_contents( "{$dir}/wombat-relay.tsl", "var is_hub = 1\nmake_node Echo wombat-echo-3301\n" );
		$this->activate_user_topologies( $dir, [ 'wombat-relay', 'ghost-shard-8841' ] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'ghost-shard-8841' );
		Core::resolve_config_token( 'eln', 'is_hub' );
	}

	public function test_a_readable_declaration_makes_a_hub_beside_an_unreadable_topology(): void {
		$dir = $this->make_temp_dir( 'eln-hub-beside-broken-' );
		\file_put_contents( "{$dir}/okapi-shard.tsl", "make_node Echo okapi-twin-7719\nmake_node Null okapi-twin-7719\n" );
		\file_put_contents( "{$dir}/wombat-relay.tsl", "var is_hub = 1\nmake_node Echo wombat-echo-3301\n" );
		$this->activate_user_topologies( $dir, [ 'okapi-shard', 'wombat-relay' ] );

		$this->assertSame( '1', Core::resolve_config_token( 'eln', 'is_hub' ) );
	}

	/**
	 * Serve `$dir` as the user topology dir and activate `$names` from it.
	 *
	 * @param list<string> $names Active topology names.
	 */
	private function activate_user_topologies( string $dir, array $names ): void {
		Topology_Registry::register_user_dir( $dir );
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = $names;
		\Newspack_Nodes\Config::reset();
		Config::reset();
	}

	// --- schema-token / owned-empty guards ----------------------------------

	public function test_an_owned_but_empty_token_is_resolved_not_unresolvable(): void {
		// A spoke's is_hub is owned and false, NOT unresolvable — strict
		// resolution must return '0' and not throw.
		$dir = $this->make_temp_dir( 'eln-hub-owned-' );
		\file_put_contents( "{$dir}/numbat-ledger.tsl", "make_node Echo numbat-echo-5521\n" );
		$this->activate_user_topologies( $dir, [ 'numbat-ledger' ] );

		$this->assertSame( '0', Core::resolve_config_token( 'eln', 'is_hub', true ) );
	}

	public function test_flame_builder_schema_token_defaults_are_owned(): void {
		// Every <ns:key> token default in a node schema must be owned by a
		// registered namespace. A wrong-namespace token (the <config:is_hub>
		// footgun) resolves to '' silently in prod but THROWS under strict, which
		// is exactly what schema-arg resolution now uses. This walks Flame_Builder's
		// schema and fails loud if any token isn't owned.
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ 'aggregator' ];
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
