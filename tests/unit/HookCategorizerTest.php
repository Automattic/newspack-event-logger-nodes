<?php
/**
 * Tests for HookCategorizer.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Hook_Categorizer::class )]
class HookCategorizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// HookCategorizer::get_user_customizations() requires wp_parse_args();
		// the current tests/bootstrap.php doesn't stub it. Skip the entire
		// suite when missing — see agent report for the requested addition.
		if ( ! \function_exists( 'wp_parse_args' ) ) {
			$this->markTestSkipped( 'wp_parse_args not stubbed in tests/bootstrap.php; see agent report.' );
		}
		Hook_Categorizer::clear_cache();
		// tests/bootstrap.php's get_option/update_option stubs back onto
		// $GLOBALS['_wp_options']; reset both option-storage globals so tests
		// from either bootstrap convention start clean.
		$GLOBALS['_wp_options']      = [];
		$GLOBALS['_wp_test_options'] = [];
	}

	protected function tearDown(): void {
		Hook_Categorizer::clear_cache();
		$GLOBALS['_wp_options']      = [];
		$GLOBALS['_wp_test_options'] = [];
		parent::tearDown();
	}

	// ── get_base_config() ───────────────────────────────────────────────────

	public function test_get_base_config_returns_array(): void {
		$config = Hook_Categorizer::get_base_config();
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( '_colors', $config );
		$this->assertArrayHasKey( '_patterns', $config );
	}

	public function test_get_base_config_is_cached(): void {
		$config1 = Hook_Categorizer::get_base_config();
		$config2 = Hook_Categorizer::get_base_config();
		$this->assertSame( $config1, $config2 );
	}

	public function test_clear_cache_resets_configs(): void {
		Hook_Categorizer::get_base_config();
		Hook_Categorizer::clear_cache();

		// After clearing, should reload from file.
		$config = Hook_Categorizer::get_base_config();
		$this->assertIsArray( $config );
	}

	public function test_get_base_config_returns_default_when_file_unreadable(): void {
		Hook_Categorizer::clear_cache();
		// file-read seam: simulate file_get_contents() failing (read error /
		// race after the file_exists check) by returning false.
		Hook_Categorizer::$read_file = static fn( string $path ) => false;
		try {
			$config = Hook_Categorizer::get_base_config();
		} finally {
			Hook_Categorizer::$read_file = null;
			Hook_Categorizer::clear_cache();
		}

		$this->assertSame( [ '_colors' => [], '_patterns' => [] ], $config );
	}

	// ── get_user_customizations() ───────────────────────────────────────────

	public function test_get_user_customizations_returns_defaults_when_empty(): void {
		$customs = Hook_Categorizer::get_user_customizations();
		$this->assertSame( [], $customs['patterns'] );
		$this->assertSame( [], $customs['overrides'] );
		$this->assertSame( [], $customs['colors'] );
	}

	public function test_get_user_customizations_reads_from_option(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'overrides' => [ 'my_hook' => 'Custom' ],
		] );

		$customs = Hook_Categorizer::get_user_customizations();
		$this->assertSame( [ 'my_hook' => 'Custom' ], $customs['overrides'] );
		$this->assertSame( [], $customs['patterns'] );
		$this->assertSame( [], $customs['colors'] );
	}

	// ── get_merged_config() ─────────────────────────────────────────────────

	public function test_get_merged_config_has_colors_and_patterns(): void {
		$config = Hook_Categorizer::get_merged_config();
		$this->assertArrayHasKey( 'colors', $config );
		$this->assertArrayHasKey( 'patterns', $config );
		$this->assertArrayHasKey( 'overrides', $config );
	}

	public function test_get_merged_config_merges_user_colors(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'colors' => [ 'MyPlugin' => '#FF0000' ],
		] );
		Hook_Categorizer::clear_cache();

		$config = Hook_Categorizer::get_merged_config();
		$this->assertSame( '#FF0000', $config['colors']['MyPlugin'] );
	}

	public function test_get_merged_config_merges_user_patterns(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'patterns' => [ 'MyPlugin' => [ '^my_plugin_' ] ],
		] );
		Hook_Categorizer::clear_cache();

		$config = Hook_Categorizer::get_merged_config();
		$this->assertContains( '^my_plugin_', $config['patterns']['MyPlugin'] );
	}

	public function test_get_merged_config_user_patterns_append_to_base(): void {
		$base = Hook_Categorizer::get_base_config();
		// Pick a category that exists in base.
		$base_categories = \array_keys( $base['_patterns'] ?? [] );
		if ( empty( $base_categories ) ) {
			$this->markTestSkipped( 'No base patterns found' );
		}
		$category   = $base_categories[0];
		$base_count = \count( $base['_patterns'][ $category ] );

		\update_option( Hook_Categorizer::OPTION_NAME, [
			'patterns' => [ $category => [ '^test_extra_' ] ],
		] );
		Hook_Categorizer::clear_cache();

		$config = Hook_Categorizer::get_merged_config();
		$this->assertCount( $base_count + 1, $config['patterns'][ $category ] );
	}

	public function test_get_merged_config_is_cached(): void {
		$config1 = Hook_Categorizer::get_merged_config();
		$config2 = Hook_Categorizer::get_merged_config();
		$this->assertSame( $config1, $config2 );
	}

	// ── categorize() ────────────────────────────────────────────────────────

	public function test_categorize_returns_other_for_unknown(): void {
		$this->assertSame( 'Other', Hook_Categorizer::categorize( 'completely_unknown_hook' ) );
	}

	public function test_categorize_matches_ajax_hooks(): void {
		$this->assertSame( 'AJAX', Hook_Categorizer::categorize( 'wp_ajax_my_action' ) );
	}

	public function test_categorize_matches_admin_hooks(): void {
		$this->assertSame( 'Admin', Hook_Categorizer::categorize( 'admin_init' ) );
	}

	public function test_categorize_override_takes_precedence(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'overrides' => [ 'wp_ajax_test' => 'Custom' ],
		] );
		Hook_Categorizer::clear_cache();

		$this->assertSame( 'Custom', Hook_Categorizer::categorize( 'wp_ajax_test' ) );
	}

	public function test_categorize_skips_invalid_regex_patterns(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'patterns' => [ 'BadCategory' => [ '[invalid(' ] ],
		] );
		Hook_Categorizer::clear_cache();

		// Should not crash, should fall through to Other.
		$result = Hook_Categorizer::categorize( 'some_hook' );
		$this->assertIsString( $result );
	}

	public function test_categorize_skips_overly_long_patterns(): void {
		$long_pattern = \str_repeat( 'a', 101 );
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'patterns' => [ 'LongCat' => [ $long_pattern ] ],
		] );
		Hook_Categorizer::clear_cache();

		$result = Hook_Categorizer::categorize( \str_repeat( 'a', 200 ) );
		$this->assertNotSame( 'LongCat', $result );
	}

	public function test_categorize_rejects_nested_quantifiers(): void {
		\update_option( Hook_Categorizer::OPTION_NAME, [
			'patterns' => [ 'NestedQuant' => [ '(a+)+' ] ],
		] );
		Hook_Categorizer::clear_cache();

		$result = Hook_Categorizer::categorize( 'aaaaaa' );
		$this->assertNotSame( 'NestedQuant', $result );
	}

	// ── get_categories() ────────────────────────────────────────────────────

	public function test_get_categories_returns_colors(): void {
		$cats = Hook_Categorizer::get_categories();
		$this->assertIsArray( $cats );
		$this->assertArrayHasKey( 'AJAX', $cats );
	}

	// ── get_registered_hooks() ──────────────────────────────────────────────

	public function test_get_registered_hooks_returns_sorted_array(): void {
		global $wp_filter;
		$wp_filter = [
			'zebra_hook' => (object) [ 'callbacks' => [ 'something' ] ],
			'alpha_hook' => (object) [ 'callbacks' => [ 'something' ] ],
			'empty_hook' => (object) [ 'callbacks' => [] ],
		];

		$hooks = Hook_Categorizer::get_registered_hooks();
		$this->assertSame( [ 'alpha_hook', 'zebra_hook' ], $hooks );

		$wp_filter = [];
	}

	public function test_get_registered_hooks_includes_selected_hooks(): void {
		global $wp_filter;
		$wp_filter = [];

		$this->set_rules_option( [
			[ 'id' => 'a', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'my_custom_hook' ] ],
		] );

		$hooks = Hook_Categorizer::get_registered_hooks();
		$this->assertContains( 'my_custom_hook', $hooks );
	}

	public function test_get_registered_hooks_includes_discovered_hooks(): void {
		global $wp_filter;
		$wp_filter = [];

		// Spoke-discovered hooks (staged by Discovery_Collector_Node) surface in
		// the picker even though they aren't registered locally — symmetric with
		// discovered_events feeding get_custom_colors().
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_hooks'] = [ 'spoke_only_hook' => true ];

		$hooks = Hook_Categorizer::get_registered_hooks();
		$this->assertContains( 'spoke_only_hook', $hooks );

		$wp_filter = [];
	}

	public function test_selected_hooks_come_from_the_rule_union(): void {
		$this->set_rules_option( [
			[ 'id' => 'a', 'pattern' => '/a/', 'action' => 'log', 'hooks' => [ 'init' ] ],
			[ 'id' => 'b', 'pattern' => '/b/', 'action' => 'log', 'hooks' => [ 'wp', 'init' ] ],
		] );

		$selected = Hook_Categorizer::selected_hooks(); // union, deduped.
		\sort( $selected );
		$this->assertSame( [ 'init', 'wp' ], $selected );
	}

	public function test_selected_hooks_ignores_skip_rules(): void {
		$this->set_rules_option( [
			[ 'id' => 'a', 'pattern' => '/a/', 'action' => 'log', 'hooks' => [ 'init' ] ],
			[ 'id' => 'b', 'pattern' => '/b/', 'action' => 'skip', 'hooks' => [ 'wp_head' ] ],
		] );

		$this->assertSame( [ 'init' ], Hook_Categorizer::selected_hooks() );
	}

	/**
	 * Seed the durable ruleset option the union reads (inline hooks only).
	 *
	 * @param array<int,array<string,mixed>> $rules Stored rule shapes.
	 */
	private function set_rules_option( array $rules ): void {
		\update_option( \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES, $rules );
	}

	// ── get_registered_hooks_by_category — internal-prefix skip-list ────────

	public function test_get_registered_hooks_by_category_skips_internal_prefixes(): void {
		global $wp_filter;
		$wp_filter = [
			// Underscore-style (option schemas, etc).
			'newspack_event_logger_nodes_option_schema_core' => (object) [ 'callbacks' => [ 'cb' ] ],
			'newspack_nodes_option_schema_core'              => (object) [ 'callbacks' => [ 'cb' ] ],
			// Slash-style (actions, fanout filters).
			'newspack_event_logger_nodes/sse_connected'        => (object) [ 'callbacks' => [ 'cb' ] ],
			'newspack_nodes/spawn_worker'                    => (object) [ 'callbacks' => [ 'cb' ] ],
			'newspack_nodes/periodic'                        => (object) [ 'callbacks' => [ 'cb' ] ],
			// Real WP hooks — must survive the filter.
			'wp_ajax_my_action'                              => (object) [ 'callbacks' => [ 'cb' ] ],
			'admin_init'                                     => (object) [ 'callbacks' => [ 'cb' ] ],
		];

		$grouped = Hook_Categorizer::get_registered_hooks_by_category();

		// Flatten the grouped hooks.
		$all_hooks = [];
		foreach ( $grouped as $hooks ) {
			$all_hooks = \array_merge( $all_hooks, $hooks );
		}

		$this->assertNotContains( 'newspack_event_logger_nodes_option_schema_core', $all_hooks );
		$this->assertNotContains( 'newspack_nodes_option_schema_core', $all_hooks );
		$this->assertNotContains( 'newspack_event_logger_nodes/sse_connected', $all_hooks );
		$this->assertNotContains( 'newspack_nodes/spawn_worker', $all_hooks );
		$this->assertNotContains( 'newspack_nodes/periodic', $all_hooks );
		$this->assertContains( 'wp_ajax_my_action', $all_hooks );
		$this->assertContains( 'admin_init', $all_hooks );

		$wp_filter = [];
	}

	// ── is_internal direct ──────────────────────────────────────────────────

	public function test_is_internal_detects_own_prefixes(): void {
		$this->assertTrue( Hook_Categorizer::is_internal( 'newspack_event_logger_nodes_option_schema_core' ) );
		$this->assertTrue( Hook_Categorizer::is_internal( 'newspack_event_logger_nodes/sse_connected' ) );
		$this->assertTrue( Hook_Categorizer::is_internal( 'newspack_nodes_option_schema_core' ) );
		$this->assertTrue( Hook_Categorizer::is_internal( 'newspack_nodes/spawn_worker' ) );
		$this->assertTrue( Hook_Categorizer::is_internal( 'newspack_nodes/reconcile' ) );
		$this->assertTrue( Hook_Categorizer::is_internal( 'newspack_nodes/topologies' ) );

		// Real WP hooks — false.
		$this->assertFalse( Hook_Categorizer::is_internal( 'admin_init' ) );
		$this->assertFalse( Hook_Categorizer::is_internal( 'the_content' ) );
		$this->assertFalse( Hook_Categorizer::is_internal( 'wp_ajax_my_action' ) );

		// Activate/deactivate hooks for our plugins — these are WP standard
		// lifecycle hooks, not our re-entry-prone filters, and they fire
		// once at activation so operators can still pick them if they want
		// to time the activation handler. Out of scope for is_internal.
		$this->assertFalse( Hook_Categorizer::is_internal( 'activate_newspack-nodes/newspack-nodes.php' ) );
		$this->assertFalse( Hook_Categorizer::is_internal( 'deactivate_newspack-event-logger-nodes/newspack-event-logger-nodes.php' ) );

		// Names that look like they prefix-match but don't.
		$this->assertFalse( Hook_Categorizer::is_internal( 'newspack_nodessomething' ) );
		$this->assertFalse( Hook_Categorizer::is_internal( 'newspack_node_loaded' ) );
	}

	// ── Constants ───────────────────────────────────────────────────────────

	public function test_option_name_constant(): void {
		$this->assertSame( 'newspack_event_logger_nodes_hook_customizations', Hook_Categorizer::OPTION_NAME );
	}

	public function test_max_pattern_length_constant(): void {
		$this->assertSame( 100, Hook_Categorizer::MAX_PATTERN_LENGTH );
	}
}
