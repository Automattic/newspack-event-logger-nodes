<?php
/**
 * Tests for Rule_Set: durable storage, two-tier hooks, reconcile-on-save.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\TestCase;

final class RuleSetTest extends TestCase {

	/** Minimal wpdb double: get_col resolves a prefix LIKE against _wp_options. */
	private function wpdb_double(): object {
		return new class() {
			public string $options = 'wp_options';
			public function esc_like( string $text ): string {
				return $text;
			}
			public function get_col( string $sql ): array {
				\preg_match( "/LIKE '([^']+)%'/", $sql, $m );
				$prefix = $m[1] ?? '';
				return \array_values( \array_filter(
					\array_keys( $GLOBALS['_wp_options'] ),
					static fn ( string $name ): bool => \str_starts_with( $name, $prefix )
				) );
			}
		};
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options'] = [];
		global $wpdb;
		$wpdb       = $this->wpdb_double();
		Core::$memd = null; // pointer tests exercise the durable-option fallback by default.
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb       = null;
		Core::$memd = null;
		parent::tearDown();
	}

	public function test_load_missing_option_yields_synthetic_root_log_rule(): void {
		$set = Rule_Set::load();
		$this->assertCount( 1, $set->rules() );
		$this->assertSame( '/', $set->rules()[0]->pattern );
		$this->assertTrue( $set->rules()[0]->is_log() );
	}

	public function test_load_corrupt_option_falls_back_to_minimal(): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = 'not-an-array';
		$set = Rule_Set::load();
		$this->assertCount( 1, $set->rules() );
		$this->assertTrue( $set->rules()[0]->is_log() );
	}

	public function test_load_respects_a_valid_empty_ruleset(): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [];
		$set = Rule_Set::load();
		$this->assertSame( [], $set->rules() );
	}

	public function test_save_inlines_small_hook_lists(): void {
		$rule = new Rule( 'a1', '/shop/', Rule::ACTION_LOG, hooks: [ 'init', 'wp' ], hooks_in: Rule::HOOKS_MC );
		( new Rule_Set( [] ) )->save( [ $rule ] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 'inline', $stored['hooks_in'] );
		$this->assertSame( [ 'init', 'wp' ], $stored['hooks'] );
		$this->assertArrayNotHasKey( Rule_Set::hooks_option_name( 'a1' ), $GLOBALS['_wp_options'] );
	}

	public function test_save_points_large_hook_lists_to_a_durable_option(): void {
		$big  = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$rule = new Rule( 'b2', '/heavy/', Rule::ACTION_LOG, hooks: $big );
		( new Rule_Set( [] ) )->save( [ $rule ] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 'mc', $stored['hooks_in'] );
		$this->assertNull( $stored['hooks'] );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'b2' ) ] );
	}

	public function test_save_reconciles_orphaned_hook_options(): void {
		// Pre-seed a durable option for a rule id that will not survive the save.
		$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'gone' ) ] = [ 'stale' ];
		$rule = new Rule( 'keep', '/x/', Rule::ACTION_LOG, hooks: [ 'init' ] );
		( new Rule_Set( [] ) )->save( [ $rule ] );
		$this->assertArrayNotHasKey( Rule_Set::hooks_option_name( 'gone' ), $GLOBALS['_wp_options'] );
	}

	public function test_save_scrubs_durable_option_when_a_rule_shrinks_back_inline(): void {
		$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'c3' ) ] = \range( 1, 200 );
		$rule = new Rule( 'c3', '/x/', Rule::ACTION_LOG, hooks: [ 'init' ] ); // now small -> inline
		( new Rule_Set( [] ) )->save( [ $rule ] );
		$this->assertArrayNotHasKey( Rule_Set::hooks_option_name( 'c3' ), $GLOBALS['_wp_options'] );
	}

	public function test_save_leaves_in_memory_rules_tiered_to_match_disk(): void {
		$big  = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$rule = new Rule( 'p9', '/heavy/', Rule::ACTION_LOG, hooks: $big );
		$set  = new Rule_Set( [] );
		$set->save( [ $rule ] );

		$in_memory = $set->rules()[0];
		$this->assertSame( Rule::HOOKS_MC, $in_memory->hooks_in, 'in-memory rule must carry the re-tiered pointer marker' );
		$this->assertNull( $in_memory->hooks, 'in-memory pointer rule must not still hold the inline list' );
		$this->assertEquals( Rule_Set::load()->rules()[0], $in_memory, 'rules() after save() must match a fresh load from disk' );
	}

	public function test_hooks_for_inline_returns_inline_list(): void {
		$rule = new Rule( 'd4', '/x/', Rule::ACTION_LOG, hooks: [ 'a', 'b' ] );
		$this->assertSame( [ 'a', 'b' ], Rule_Set::hooks_for( $rule ) );
	}

	public function test_hooks_for_pointer_reads_durable_option_when_mc_down(): void {
		$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'e5' ) ] = [ 'x', 'y' ];
		$rule = new Rule( 'e5', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC );
		$this->assertSame( [ 'x', 'y' ], Rule_Set::hooks_for( $rule ) );
	}

	public function test_hooks_for_pointer_both_missing_returns_empty(): void {
		$rule = new Rule( 'f6', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC );
		$this->assertSame( [], Rule_Set::hooks_for( $rule ) );
	}

	public function test_hooks_for_pointer_returns_mc_value_without_touching_durable_option(): void {
		$mc = new InMemoryMemcached();
		$mc->set( Rule_Set::mc_key( 'g7' ), [ 'from', 'mc' ] );
		Core::$memd = $mc;
		// No durable option seeded at all: if this returned anything, it could
		// only have come from the mc mirror.
		$rule = new Rule( 'g7', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC );
		$this->assertSame( [ 'from', 'mc' ], Rule_Set::hooks_for( $rule ) );
		$this->assertArrayNotHasKey( Rule_Set::hooks_option_name( 'g7' ), $GLOBALS['_wp_options'] );
	}

	public function test_hooks_for_pointer_warms_mc_from_durable_option_on_miss(): void {
		$mc          = new InMemoryMemcached();
		Core::$memd  = $mc;
		$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'h8' ) ] = [ 'from', 'durable' ];
		$rule = new Rule( 'h8', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC );

		$result = Rule_Set::hooks_for( $rule );

		$this->assertSame( [ 'from', 'durable' ], $result );
		$this->assertSame( [ 'from', 'durable' ], $mc->get( Rule_Set::mc_key( 'h8' ) ) );
	}
}
