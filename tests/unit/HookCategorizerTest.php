<?php
/**
 * Tests for HookCategorizer.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\HookCategorizer;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( HookCategorizer::class )]
class HookCategorizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// HookCategorizer::get_user_customizations() requires wp_parse_args();
		// the current tests/bootstrap.php doesn't stub it. Skip the entire
		// suite when missing — see agent report for the requested addition.
		if ( ! \function_exists( 'wp_parse_args' ) ) {
			$this->markTestSkipped( 'wp_parse_args not stubbed in tests/bootstrap.php; see agent report.' );
		}
		HookCategorizer::clear_cache();
		// tests/bootstrap.php's get_option/update_option stubs back onto
		// $GLOBALS['_wp_options']; reset both option-storage globals so tests
		// from either bootstrap convention start clean.
		$GLOBALS['_wp_options']      = [];
		$GLOBALS['_wp_test_options'] = [];
	}

	protected function tearDown(): void {
		HookCategorizer::clear_cache();
		$GLOBALS['_wp_options']      = [];
		$GLOBALS['_wp_test_options'] = [];
		parent::tearDown();
	}

	// ── get_base_config() ───────────────────────────────────────────────────

	public function test_get_base_config_returns_array(): void {
		$config = HookCategorizer::get_base_config();
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( '_colors', $config );
		$this->assertArrayHasKey( '_patterns', $config );
	}

	public function test_get_base_config_is_cached(): void {
		$config1 = HookCategorizer::get_base_config();
		$config2 = HookCategorizer::get_base_config();
		$this->assertSame( $config1, $config2 );
	}

	public function test_clear_cache_resets_configs(): void {
		HookCategorizer::get_base_config();
		HookCategorizer::clear_cache();

		// After clearing, should reload from file.
		$config = HookCategorizer::get_base_config();
		$this->assertIsArray( $config );
	}

	// ── get_user_customizations() ───────────────────────────────────────────

	public function test_get_user_customizations_returns_defaults_when_empty(): void {
		$customs = HookCategorizer::get_user_customizations();
		$this->assertSame( [], $customs['patterns'] );
		$this->assertSame( [], $customs['overrides'] );
		$this->assertSame( [], $customs['colors'] );
	}

	public function test_get_user_customizations_reads_from_option(): void {
		\update_option( HookCategorizer::OPTION_NAME, [
			'overrides' => [ 'my_hook' => 'Custom' ],
		] );

		$customs = HookCategorizer::get_user_customizations();
		$this->assertSame( [ 'my_hook' => 'Custom' ], $customs['overrides'] );
		$this->assertSame( [], $customs['patterns'] );
		$this->assertSame( [], $customs['colors'] );
	}

	// ── get_merged_config() ─────────────────────────────────────────────────

	public function test_get_merged_config_has_colors_and_patterns(): void {
		$config = HookCategorizer::get_merged_config();
		$this->assertArrayHasKey( 'colors', $config );
		$this->assertArrayHasKey( 'patterns', $config );
		$this->assertArrayHasKey( 'overrides', $config );
	}

	public function test_get_merged_config_merges_user_colors(): void {
		\update_option( HookCategorizer::OPTION_NAME, [
			'colors' => [ 'MyPlugin' => '#FF0000' ],
		] );
		HookCategorizer::clear_cache();

		$config = HookCategorizer::get_merged_config();
		$this->assertSame( '#FF0000', $config['colors']['MyPlugin'] );
	}

	public function test_get_merged_config_merges_user_patterns(): void {
		\update_option( HookCategorizer::OPTION_NAME, [
			'patterns' => [ 'MyPlugin' => [ '^my_plugin_' ] ],
		] );
		HookCategorizer::clear_cache();

		$config = HookCategorizer::get_merged_config();
		$this->assertContains( '^my_plugin_', $config['patterns']['MyPlugin'] );
	}

	public function test_get_merged_config_user_patterns_append_to_base(): void {
		$base = HookCategorizer::get_base_config();
		// Pick a category that exists in base.
		$base_categories = \array_keys( $base['_patterns'] ?? [] );
		if ( empty( $base_categories ) ) {
			$this->markTestSkipped( 'No base patterns found' );
		}
		$category   = $base_categories[0];
		$base_count = \count( $base['_patterns'][ $category ] );

		\update_option( HookCategorizer::OPTION_NAME, [
			'patterns' => [ $category => [ '^test_extra_' ] ],
		] );
		HookCategorizer::clear_cache();

		$config = HookCategorizer::get_merged_config();
		$this->assertCount( $base_count + 1, $config['patterns'][ $category ] );
	}

	public function test_get_merged_config_is_cached(): void {
		$config1 = HookCategorizer::get_merged_config();
		$config2 = HookCategorizer::get_merged_config();
		$this->assertSame( $config1, $config2 );
	}

	// ── categorize() ────────────────────────────────────────────────────────

	public function test_categorize_returns_other_for_unknown(): void {
		$this->assertSame( 'Other', HookCategorizer::categorize( 'completely_unknown_hook' ) );
	}

	public function test_categorize_matches_ajax_hooks(): void {
		$this->assertSame( 'AJAX', HookCategorizer::categorize( 'wp_ajax_my_action' ) );
	}

	public function test_categorize_matches_admin_hooks(): void {
		$this->assertSame( 'Admin', HookCategorizer::categorize( 'admin_init' ) );
	}

	public function test_categorize_override_takes_precedence(): void {
		\update_option( HookCategorizer::OPTION_NAME, [
			'overrides' => [ 'wp_ajax_test' => 'Custom' ],
		] );
		HookCategorizer::clear_cache();

		$this->assertSame( 'Custom', HookCategorizer::categorize( 'wp_ajax_test' ) );
	}

	public function test_categorize_skips_invalid_regex_patterns(): void {
		\update_option( HookCategorizer::OPTION_NAME, [
			'patterns' => [ 'BadCategory' => [ '[invalid(' ] ],
		] );
		HookCategorizer::clear_cache();

		// Should not crash, should fall through to Other.
		$result = HookCategorizer::categorize( 'some_hook' );
		$this->assertIsString( $result );
	}

	public function test_categorize_skips_overly_long_patterns(): void {
		$long_pattern = \str_repeat( 'a', 101 );
		\update_option( HookCategorizer::OPTION_NAME, [
			'patterns' => [ 'LongCat' => [ $long_pattern ] ],
		] );
		HookCategorizer::clear_cache();

		$result = HookCategorizer::categorize( \str_repeat( 'a', 200 ) );
		$this->assertNotSame( 'LongCat', $result );
	}

	public function test_categorize_rejects_nested_quantifiers(): void {
		\update_option( HookCategorizer::OPTION_NAME, [
			'patterns' => [ 'NestedQuant' => [ '(a+)+' ] ],
		] );
		HookCategorizer::clear_cache();

		$result = HookCategorizer::categorize( 'aaaaaa' );
		$this->assertNotSame( 'NestedQuant', $result );
	}

	// ── categorize_many() ───────────────────────────────────────────────────

	public function test_categorize_many_returns_correct_structure(): void {
		$hooks  = [ 'wp_ajax_foo', 'admin_init', 'unknown_hook' ];
		$result = HookCategorizer::categorize_many( $hooks );

		$this->assertCount( 3, $result );
		$this->assertSame( 'AJAX', $result['wp_ajax_foo'] );
		$this->assertSame( 'Admin', $result['admin_init'] );
		$this->assertSame( 'Other', $result['unknown_hook'] );
	}

	public function test_categorize_many_empty_input(): void {
		$result = HookCategorizer::categorize_many( [] );
		$this->assertSame( [], $result );
	}

	// ── get_categories() ────────────────────────────────────────────────────

	public function test_get_categories_returns_colors(): void {
		$cats = HookCategorizer::get_categories();
		$this->assertIsArray( $cats );
		$this->assertArrayHasKey( 'AJAX', $cats );
	}

	// ── get_color() ─────────────────────────────────────────────────────────

	public function test_get_color_returns_color_for_known_category(): void {
		$color = HookCategorizer::get_color( 'AJAX' );
		$this->assertStringStartsWith( '#', $color );
	}

	public function test_get_color_returns_default_for_unknown_category(): void {
		$color = HookCategorizer::get_color( 'NonexistentCategory' );
		$this->assertSame( '#9E9E9E', $color );
	}

	// ── get_registered_hooks() ──────────────────────────────────────────────

	public function test_get_registered_hooks_returns_sorted_array(): void {
		global $wp_filter;
		$wp_filter = [
			'zebra_hook' => (object) [ 'callbacks' => [ 'something' ] ],
			'alpha_hook' => (object) [ 'callbacks' => [ 'something' ] ],
			'empty_hook' => (object) [ 'callbacks' => [] ],
		];

		$hooks = HookCategorizer::get_registered_hooks();
		$this->assertSame( [ 'alpha_hook', 'zebra_hook' ], $hooks );

		$wp_filter = [];
	}

	public function test_get_registered_hooks_includes_selected_hooks(): void {
		global $wp_filter;
		$wp_filter = [];

		\update_option( 'newspack_event_logger_nodes_log_events', [ 'my_custom_hook' ] );

		$hooks = HookCategorizer::get_registered_hooks();
		$this->assertContains( 'my_custom_hook', $hooks );
	}

	// ── get_registered_hooks_by_category — internal-prefix skip-list ────────

	public function test_get_registered_hooks_by_category_skips_internal_prefixes(): void {
		global $wp_filter;
		$wp_filter = [
			// Underscore-style (option schemas, etc).
			'newspack_event_logger_nodes_option_schema_core' => (object) [ 'callbacks' => [ 'cb' ] ],
			'newspack_nodes_option_schema_core'              => (object) [ 'callbacks' => [ 'cb' ] ],
			// Slash-style (actions, fanout filters).
			'newspack_event_logger_nodes/log_readers'        => (object) [ 'callbacks' => [ 'cb' ] ],
			'newspack_nodes/spawn_worker'                    => (object) [ 'callbacks' => [ 'cb' ] ],
			'newspack_nodes/supervisor_periodic'             => (object) [ 'callbacks' => [ 'cb' ] ],
			// Real WP hooks — must survive the filter.
			'wp_ajax_my_action'                              => (object) [ 'callbacks' => [ 'cb' ] ],
			'admin_init'                                     => (object) [ 'callbacks' => [ 'cb' ] ],
		];

		$grouped = HookCategorizer::get_registered_hooks_by_category();

		// Flatten the grouped hooks.
		$all_hooks = [];
		foreach ( $grouped as $hooks ) {
			$all_hooks = \array_merge( $all_hooks, $hooks );
		}

		$this->assertNotContains( 'newspack_event_logger_nodes_option_schema_core', $all_hooks );
		$this->assertNotContains( 'newspack_nodes_option_schema_core', $all_hooks );
		$this->assertNotContains( 'newspack_event_logger_nodes/log_readers', $all_hooks );
		$this->assertNotContains( 'newspack_nodes/spawn_worker', $all_hooks );
		$this->assertNotContains( 'newspack_nodes/supervisor_periodic', $all_hooks );
		$this->assertContains( 'wp_ajax_my_action', $all_hooks );
		$this->assertContains( 'admin_init', $all_hooks );

		$wp_filter = [];
	}

	// ── is_internal direct ──────────────────────────────────────────────────

	public function test_is_internal_detects_own_prefixes(): void {
		$this->assertTrue( HookCategorizer::is_internal( 'newspack_event_logger_nodes_option_schema_core' ) );
		$this->assertTrue( HookCategorizer::is_internal( 'newspack_event_logger_nodes/log_readers' ) );
		$this->assertTrue( HookCategorizer::is_internal( 'newspack_nodes_option_schema_core' ) );
		$this->assertTrue( HookCategorizer::is_internal( 'newspack_nodes/spawn_worker' ) );
		$this->assertTrue( HookCategorizer::is_internal( 'newspack_nodes/supervisor' ) );
		$this->assertTrue( HookCategorizer::is_internal( 'newspack_nodes/topologies' ) );

		// Real WP hooks — false.
		$this->assertFalse( HookCategorizer::is_internal( 'admin_init' ) );
		$this->assertFalse( HookCategorizer::is_internal( 'the_content' ) );
		$this->assertFalse( HookCategorizer::is_internal( 'wp_ajax_my_action' ) );

		// Activate/deactivate hooks for our plugins — these are WP standard
		// lifecycle hooks, not our re-entry-prone filters, and they fire
		// once at activation so operators can still pick them if they want
		// to time the activation handler. Out of scope for is_internal.
		$this->assertFalse( HookCategorizer::is_internal( 'activate_newspack-nodes/newspack-nodes.php' ) );
		$this->assertFalse( HookCategorizer::is_internal( 'deactivate_newspack-event-logger-nodes/newspack-event-logger-nodes.php' ) );

		// Names that look like they prefix-match but don't.
		$this->assertFalse( HookCategorizer::is_internal( 'newspack_nodessomething' ) );
		$this->assertFalse( HookCategorizer::is_internal( 'newspack_node_loaded' ) );
	}

	// ── Constants ───────────────────────────────────────────────────────────

	public function test_option_name_constant(): void {
		$this->assertSame( 'newspack_event_logger_nodes_hook_customizations', HookCategorizer::OPTION_NAME );
	}

	public function test_max_pattern_length_constant(): void {
		$this->assertSame( 100, HookCategorizer::MAX_PATTERN_LENGTH );
	}
}
