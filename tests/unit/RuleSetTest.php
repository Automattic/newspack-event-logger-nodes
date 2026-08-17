<?php
/**
 * Tests for Rule_Set: durable storage, two-tier hooks, reconcile-on-save.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

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
		$rule = new Rule( 'a1', '/shop/', Rule::ACTION_LOG, hooks: [ 'init', 'wp' ] );
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

	public function test_reset_deletes_the_stored_option_rather_than_storing_an_empty_list(): void {
		// Storing `[]` would read as "log nothing"; only an ABSENT row reseeds.
		// What the seed then resolves to is RulesCITest's, over a real config file.
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'stored', 'pattern' => '/stored-only/', 'action' => 'skip' ],
		];

		Rule_Set::reset();

		$this->assertArrayNotHasKey( Rule_Set::OPTION_RULES, $GLOBALS['_wp_options'] );
	}

	public function test_reset_drops_every_pointer_rules_durable_hooks_option(): void {
		$big = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( 'heavy', '/heavy/', Rule::ACTION_LOG, hooks: $big ) ] );
		$this->assertArrayHasKey( Rule_Set::hooks_option_name( 'heavy' ), $GLOBALS['_wp_options'] );

		Rule_Set::reset();

		$this->assertArrayNotHasKey( Rule_Set::hooks_option_name( 'heavy' ), $GLOBALS['_wp_options'] );
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

	/** The stored (post-save) rule-map list, as settings-sync reads it off the option. */
	private function stored_rule_maps(): array {
		return \array_map( static fn ( Rule $r ): array => $r->to_array(), Rule_Set::load()->rules() );
	}

	public function test_hydrate_array_inlines_pointer_rule_hooks(): void {
		$big = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( 'big', '/heavy/', Rule::ACTION_LOG, hooks: $big ) ] );

		$sync = Rule_Set::hydrate_array( $this->stored_rule_maps() );

		$this->assertCount( 1, $sync );
		$this->assertSame( Rule::HOOKS_INLINE, $sync[0]['hooks_in'], 'pointer hooks must be inlined for transport' );
		$this->assertSame( $big, $sync[0]['hooks'] );
	}

	public function test_hydrate_array_leaves_an_unresolvable_pointer_as_a_pointer(): void {
		// Hub-side durable option missing (mc down too): hooks_for() → []. Inlining
		// [] would make the spoke re-tier to empty and delete its own good hooks, so
		// the entry must stay a pointer, NOT become inline-[].
		$pointer = ( new Rule( 'gone', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC ) )->to_array();

		$sync = Rule_Set::hydrate_array( [ $pointer ] );

		$this->assertSame( Rule::HOOKS_MC, $sync[0]['hooks_in'], 'an unresolvable pointer must not downgrade to inline-empty' );
		$this->assertNull( $sync[0]['hooks'] );
	}

	public function test_hydrate_array_passes_inline_and_skip_rules_through(): void {
		( new Rule_Set( [] ) )->save( [
			new Rule( 'log1', '/x/', Rule::ACTION_LOG, hooks: [ 'init' ] ),
			new Rule( 'skip1', '/y/', Rule::ACTION_SKIP ),
		] );

		$sync = Rule_Set::hydrate_array( $this->stored_rule_maps() );

		$this->assertSame( [ 'init' ], $sync[0]['hooks'] );
		$this->assertSame( Rule::HOOKS_INLINE, $sync[0]['hooks_in'] );
		$this->assertSame( 'skip', $sync[1]['action'] );
	}

	public function test_apply_synced_retiers_hydrated_large_rule_to_a_durable_option(): void {
		$big  = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$wire = [ ( new Rule( 'big', '/heavy/', Rule::ACTION_LOG, hooks: $big ) )->to_array() ];

		Rule_Set::apply_synced( $wire );

		// Keyed by the PATTERN-derived id, not the 'big' the wire supplied.
		$option = Rule_Set::hooks_option_name( Rule_Set::id_for( '/heavy/' ) );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ $option ], 'spoke must persist the hooks to its own durable option' );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 'mc', $stored['hooks_in'], 'a large synced rule must re-tier to a pointer, not bloat OPTION_RULES' );
		$this->assertNull( $stored['hooks'] );
	}

	public function test_sync_round_trip_preserves_pointer_hooks_across_the_wire(): void {
		// The bug: settings-sync ships OPTION_RULES verbatim, so a pointer rule's
		// hooks (a SEPARATE durable option) never reach the spoke and hooks_for()
		// reports "hooks missing". hydrate_array()+apply_synced() must close that gap.
		$big = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( 'big', '/heavy/', Rule::ACTION_LOG, hooks: $big ) ] );

		// Hub serializes for transport; the wire carries ONLY this (no durable option).
		$wire = Rule_Set::hydrate_array( $this->stored_rule_maps() );

		// Spoke starts clean — its durable option + rules list are absent.
		unset( $GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'big' ) ] );
		unset( $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );

		Rule_Set::apply_synced( $wire );

		$this->assertSame(
			$big,
			Rule_Set::hooks_for( Rule_Set::load()->rules()[0] ),
			'the spoke must resolve the full hook list the hub instrumented'
		);
	}

	/** The same warm-mirror table Rule_Set builds, reached without its private accessor. */
	private function hooks_table(): Table_Node {
		return Table_Node::table( Rule_Set::TABLE_HOOKS, Rule_Set::TABLE_TTL );
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
		Core::$memd = $mc;
		$this->hooks_table()->store( 'g7', [ 'from', 'mc' ] );
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
		$this->assertSame( [ 'from', 'durable' ], $this->hooks_table()->lookup( 'h8' ) );
	}

	public function test_pointer_hooks_ride_the_substrate_table(): void {
		Core::$memd = new InMemoryMemcached();
		$this->hooks_table()->store( 'j9', [ 'wp_loaded', 'shutdown' ] );

		$rule = new Rule( 'j9', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC );
		// No durable option seeded: only the table can have answered.
		$this->assertSame( [ 'wp_loaded', 'shutdown' ], Rule_Set::hooks_for( $rule ) );
	}

	public function test_a_co_tenant_install_does_not_read_our_hooks(): void {
		// A rule id is the url_hash of its pattern, so every install that logs
		// `/` derives the SAME id. Two installs sharing one memcached — which
		// the eve stack is — must still not share a hook list.
		Core::$memd = new InMemoryMemcached();
		$this->hooks_table()->store( 'k1', [ 'ours', 'only' ] );

		$rule = new Rule( 'k1', '/heavy/', Rule::ACTION_LOG, hooks: null, hooks_in: Rule::HOOKS_MC );
		$this->assertSame( [ 'ours', 'only' ], Rule_Set::hooks_for( $rule ) );

		$ours = Cache_Backend::$site;
		try {
			Cache_Backend::$site = 'a-different-install';
			$this->assertSame( [], Rule_Set::hooks_for( $rule ) );
		} finally {
			Cache_Backend::$site = $ours;
		}
	}

	public function test_saving_a_heavy_rule_mirrors_its_hooks_into_the_table(): void {
		Core::$memd = new InMemoryMemcached();
		$hooks      = \array_map( static fn ( int $i ): string => "heavy_hook_{$i}", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 7 ) );
		$id         = Rule_Set::id_for( '/heavy/' );

		( new Rule_Set( [] ) )->save( [ new Rule( $id, '/heavy/', Rule::ACTION_LOG, hooks: $hooks ) ] );

		$this->assertSame( $hooks, $this->hooks_table()->lookup( $id ) );
	}

	public function test_id_for_hashes_the_pattern_via_the_shared_url_hash(): void {
		$this->assertSame( \Newspack_Event_Logger_Nodes\Log_Manager::url_hash( '/shop/' ), Rule_Set::id_for( '/shop/' ) );
		$this->assertSame( '20e00bf7badc', Rule_Set::id_for( '/shop/' ) );
	}

	public function test_id_for_is_a_pure_function_of_the_pattern(): void {
		$this->assertSame( Rule_Set::id_for( '/a/' ), Rule_Set::id_for( '/a/' ) );
		$this->assertNotSame( Rule_Set::id_for( '/a/' ), Rule_Set::id_for( '/b/' ) );
	}

	// ── apply_synced: the pattern is the identity, off the wire too ─────────

	/**
	 * The hub→spoke sync is the one rule-write path that crosses a trust
	 * boundary, so it must re-derive ids exactly as the config and editor paths
	 * do — a wire-supplied id is not the rule's identity.
	 */
	public function test_apply_synced_derives_the_id_from_the_pattern(): void {
		Rule_Set::apply_synced( [
			[ 'id' => 'not-a-pattern-hash-4471', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'init' ] ],
		] );

		$rules = Rule_Set::load()->rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( Rule_Set::id_for( '/' ), $rules[0]->id );
	}

	public function test_apply_synced_collapses_two_rules_sharing_one_pattern(): void {
		Rule_Set::apply_synced( [
			[ 'id' => 'aaaaaaaaaaaa', 'pattern' => '/shop', 'action' => 'log',  'hooks' => [ 'init' ] ],
			[ 'id' => 'bbbbbbbbbbbb', 'pattern' => '/shop', 'action' => 'skip', 'hooks' => [] ],
		] );

		$rules = Rule_Set::load()->rules();
		$this->assertCount( 1, $rules, 'one pattern is one rule' );
		$this->assertTrue( $rules[0]->is_skip(), 'last entry wins, matching the config path' );
	}

	/**
	 * Two entries sharing an id used to alias one durable hooks option: the
	 * later inline rule's delete_option wiped the earlier pointer rule's list,
	 * losing every hook behind one rate-limited notice.
	 */
	public function test_apply_synced_keeps_a_pointer_rules_hooks_when_another_entry_shares_its_id(): void {
		$many = [];
		for ( $i = 0; $i < Rule_Set::INLINE_HOOK_LIMIT + 7; $i++ ) {
			$many[] = "hook_probe_{$i}";
		}
		Rule_Set::apply_synced( [
			[ 'id' => 'dupdupdupdup', 'pattern' => '/heavy', 'action' => 'log', 'hooks' => $many ],
			[ 'id' => 'dupdupdupdup', 'pattern' => '/light', 'action' => 'log', 'hooks' => [ 'init' ] ],
		] );

		$by_pattern = [];
		foreach ( Rule_Set::load()->rules() as $rule ) {
			$by_pattern[ $rule->pattern ] = \count( Rule_Set::hooks_for( $rule ) );
		}
		$this->assertSame( \count( $many ), $by_pattern['/heavy'] ?? -1 );
		$this->assertSame( 1, $by_pattern['/light'] ?? -1 );
	}

	/**
	 * `sanitize_settings_array()` drops the wire's null `hooks`, manufacturing a
	 * pointer entry with no hooks key. Re-tiering that used to inline it to []
	 * and delete the spoke's durable hooks option; the whole push must fail
	 * instead, leaving the spoke's last-good ruleset in place.
	 */
	public function test_apply_synced_refuses_a_pointer_entry_whose_hooks_key_was_dropped(): void {
		$big = \array_map( fn( $i ) => "hook_probe_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 3 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( Rule_Set::id_for( '/tarot/' ), '/tarot/', Rule::ACTION_LOG, hooks: $big ) ] );
		$option = Rule_Set::hooks_option_name( Rule_Set::id_for( '/tarot/' ) );

		try {
			Rule_Set::apply_synced( [
				[ 'id' => Rule_Set::id_for( '/tarot/' ), 'pattern' => '/tarot/', 'action' => 'log', 'hooks_in' => 'mc' ],
			] );
			$this->fail( 'apply_synced must refuse an unrepresentable rule' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'hooks', $e->getMessage() );
		}

		$this->assertSame( $big, $GLOBALS['_wp_options'][ $option ], 'the spoke keeps its durable hooks' );
		$this->assertSame( $big, Rule_Set::hooks_for( Rule_Set::load()->rules()[0] ) );
	}

	public function test_load_skips_an_unrepresentable_stored_rule(): void {
		// Read side stays tolerant: one hand-edited row must not fatal a request.
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'junk', 'action' => 'skip' ],
			[ 'id' => Rule_Set::id_for( '/tarot/' ), 'pattern' => '/tarot/', 'action' => 'log', 'hooks' => [ 'wp_loaded' ], 'hooks_in' => 'inline' ],
		];

		$rules = Rule_Set::load()->rules();

		$this->assertCount( 1, $rules, 'the pattern-less row is skipped, the good one survives' );
		$this->assertSame( '/tarot/', $rules[0]->pattern );
	}

	public function test_load_keeps_a_stored_rule_whose_hooks_are_a_scalar(): void {
		// A hand-edited (or empty-array-as-'') hooks value is a rule with no
		// hooks, not an unresolved pointer — reading it as one skipped the row
		// and stopped logging '/' entirely.
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => Rule_Set::id_for( '/' ), 'pattern' => '/', 'action' => 'log', 'hooks' => '', 'hooks_in' => 'inline' ],
		];

		$rules = Rule_Set::load()->rules();

		$this->assertCount( 1, $rules );
		$this->assertSame( '/', $rules[0]->pattern );
		$this->assertTrue( $rules[0]->is_log(), 'the log-all baseline survives' );
		$this->assertSame( [], $rules[0]->hooks );
		$this->assertSame( [], Rule_Set::hooks_for( $rules[0] ) );
	}

	public function test_seed_from_config_skips_an_unrepresentable_rule(): void {
		$this->set_config_rules( [
			[ 'action' => 'skip' ],
			[ 'pattern' => '/tarot/', 'action' => 'log' ],
		] );

		$rules = Rule_Set::load()->rules();

		$this->assertCount( 1, $rules, 'a config typo must not white-screen the site' );
		$this->assertSame( '/tarot/', $rules[0]->pattern );
	}
}
