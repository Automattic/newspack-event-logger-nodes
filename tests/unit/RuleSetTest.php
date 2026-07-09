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
		\Newspack_Event_Logger_Nodes\Config::reset();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb       = null;
		Core::$memd = null;
		\Newspack_Event_Logger_Nodes\Config::reset();
		parent::tearDown();
	}

	/** Pin the memoized Config so an absent rules option seeds from these entries. */
	private function set_config_rules( array $rules ): void {
		$ref = new \ReflectionProperty( \Newspack_Event_Logger_Nodes\Config::class, 'config' );
		$ref->setValue( null, [ 'rules' => $rules ] );
	}

	public function test_load_missing_option_with_empty_config_yields_empty_ruleset(): void {
		// Empty means empty everywhere: config `rules => []` (like a stored `[]`)
		// is an explicit "log nothing", not an implicit log-all baseline.
		$this->set_config_rules( [] );
		$this->assertCount( 0, Rule_Set::load()->rules() );
	}

	public function test_load_missing_option_seeds_from_config_rules_with_pattern_hashed_ids(): void {
		$this->set_config_rules( [
			[ 'pattern' => '/api/', 'action' => 'skip' ],
			[ 'pattern' => '/',     'action' => 'log' ],
		] );

		$rules = Rule_Set::load()->rules();

		$this->assertCount( 2, $rules );
		// id == Log_Manager::url_hash( pattern ).
		$this->assertSame( '4247c63fd79e', $rules[0]->id );
		$this->assertSame( '/api/', $rules[0]->pattern );
		$this->assertTrue( $rules[0]->is_skip() );
		$this->assertSame( '2a0c975ed95c', $rules[1]->id );
		$this->assertTrue( $rules[1]->is_log() );
	}

	public function test_seed_from_config_derives_id_from_pattern_ignoring_any_supplied_id(): void {
		$this->set_config_rules( [ [ 'id' => 'home', 'pattern' => '/', 'action' => 'log' ] ] );
		// The pattern IS the identity — a config-supplied id is ignored.
		$this->assertSame( '2a0c975ed95c', Rule_Set::load()->rules()[0]->id );
	}

	public function test_seed_from_config_collapses_duplicate_patterns_to_one_rule(): void {
		$this->set_config_rules( [
			[ 'pattern' => '/api/', 'action' => 'skip' ],
			[ 'pattern' => '/api/', 'action' => 'log' ],
		] );

		$rules = Rule_Set::load()->rules();

		$this->assertCount( 1, $rules, 'one id per pattern — the last entry wins' );
		$this->assertSame( '4247c63fd79e', $rules[0]->id );
		$this->assertTrue( $rules[0]->is_log() );
	}

	public function test_seed_from_config_carries_per_rule_fields(): void {
		$this->set_config_rules( [
			[ 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'init', 'wp' ] ],
		] );

		$rule = Rule_Set::load()->rules()[0];

		$this->assertSame( [ 'init', 'wp' ], $rule->hooks );
	}

	public function test_load_corrupt_option_with_empty_config_yields_empty_ruleset(): void {
		// Corrupt option → seed_from_config; with no config rules that is empty
		// (log nothing), not the old minimal log-all fallback.
		$this->set_config_rules( [] );
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = 'not-an-array';
		$this->assertCount( 0, Rule_Set::load()->rules() );
	}

	public function test_load_mints_ids_for_stored_rules_that_lack_one(): void {
		// A settings-synced config-default ruleset can be stored idless; load()
		// must mint each id from the pattern so nothing collides on the '' key.
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'pattern' => '/api/', 'action' => 'skip' ],
			[ 'pattern' => '/',     'action' => 'log' ],
		];

		$rules = Rule_Set::load()->rules();

		$this->assertSame( \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/api/' ), $rules[0]->id );
		$this->assertSame( \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/' ), $rules[1]->id );
	}

	public function test_load_preserves_a_nonempty_legacy_id(): void {
		// A non-empty (legacy positional) id is trusted — its durable hooks option
		// is keyed by it, so load() must NOT rekey it out from under that option.
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'faed26b5', 'pattern' => '/', 'action' => 'log' ],
		];
		$this->assertSame( 'faed26b5', Rule_Set::load()->rules()[0]->id );
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

	public function test_save_rehydrates_an_unchanged_pointer_rule_instead_of_wiping_it(): void {
		$big = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'ptr' ) ] = $big;
		// Loaded-from-storage shape: hooks=null, hooks_in=mc (what Rule::from_array yields for a pointer rule).
		$pointer = new Rule( 'ptr', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC );

		( new Rule_Set( [ $pointer ] ) )->save( [ $pointer ] );

		$this->assertSame( $big, $GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'ptr' ) ], 'save() must not wipe the durable option for an unchanged pointer rule' );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 'mc', $stored['hooks_in'], 'the rule must stay pointer-tier, not get re-inlined to []' );
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

	public function test_id_for_hashes_the_pattern_via_the_shared_url_hash(): void {
		$this->assertSame( \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/shop/' ), Rule_Set::id_for( '/shop/' ) );
		$this->assertSame( '20e00bf7badc', Rule_Set::id_for( '/shop/' ) );
	}

	public function test_id_for_is_a_pure_function_of_the_pattern(): void {
		$this->assertSame( Rule_Set::id_for( '/a/' ), Rule_Set::id_for( '/a/' ) );
		$this->assertNotSame( Rule_Set::id_for( '/a/' ), Rule_Set::id_for( '/b/' ) );
	}
}
