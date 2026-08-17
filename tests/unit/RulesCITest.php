<?php
/**
 * RulesCITest: unit tests for Rules_CI, the service CI backing the rules-editor
 * UI (list/save/upsert/delete over Rule_Set).
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Rules_CI_Node;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

#[CoversClass( Rules_CI_Node::class )]
class RulesCITest extends TestCase {

	/** Minimal wpdb double: get_col resolves a prefix LIKE against _wp_options (Rule_Set::save's orphan reconcile). */
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
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		Core::$memd = new InMemoryMemcached();
		global $wpdb;
		$wpdb = $this->wpdb_double();
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_current_user_can'] = false;
		$GLOBALS['_wp_options']       = [];
		Core::$memd = null;
		global $wpdb;
		$wpdb = null;
		\Newspack_Event_Logger_Nodes\Config::reset();
		parent::tearDown();
	}

	/**
	 * Put a `rules` seed in the config FILE layer, the way production has one.
	 *
	 * Deliberately not a reflection pin on the memoized array: `reset()`
	 * invalidates that memo, so a pinned one both hides the staleness the memo
	 * causes and evaporates the moment the fix lands.
	 *
	 * @param array<int, array<string, mixed>> $rules Config `rules` entries.
	 */
	private function set_config_rules( array $rules ): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'rules' => $rules ] );
	}

	private function fire( string $verb, string $args = '' ): mixed {
		return VerbHarness::fire( new Rules_CI_Node(), 'rules', $verb, '' === $args ? [] : [ $args ] );
	}

	// -------------------------------------------------------------------------
	// list
	// -------------------------------------------------------------------------

	public function test_list_resolves_pointer_rule_hooks_and_normalizes_tier(): void {
		$big  = \array_map( static fn ( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$rule = new Rule( 'p1', '/heavy/', Rule::ACTION_LOG, hooks: $big );
		( new Rule_Set( [] ) )->save( [ $rule ] );

		$result = $this->fire( 'list' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rules', $result );
		$this->assertCount( 1, $result['rules'] );
		$wire = $result['rules'][0];
		$this->assertSame( $big, $wire['hooks'], 'list must resolve the pointer tier to the full hook list' );
		$this->assertSame( 'inline', $wire['hooks_in'], 'the editor never sees the tier — always normalized to inline' );
	}

	public function test_list_returns_empty_rules_for_an_empty_set(): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [];

		$result = $this->fire( 'list' );

		$this->assertSame( [], $result['rules'] );
	}

	// -------------------------------------------------------------------------
	// save
	// -------------------------------------------------------------------------

	public function test_save_round_trips_a_whole_list_and_retiers_a_big_rule(): void {
		$big = \array_map( static fn ( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$payload = \wp_json_encode( [
			[
				'id'      => 'a1',
				'pattern' => '/small/',
				'action'  => Rule::ACTION_LOG,
				'hooks'   => [ 'init' ],
			],
			[
				'id'      => 'a2',
				'pattern' => '/heavy/',
				'action'  => Rule::ACTION_LOG,
				'hooks'   => $big,
			],
		] );

		$result = $this->fire( 'save', $payload );

		$this->assertSame( [ 'saved' => 2 ], $result );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 2, $stored );
		$heavy = $stored[1];
		$this->assertSame( 'mc', $heavy['hooks_in'], 'save must go through Rule_Set::save so the tiering threshold applies' );
		// Supplied ids ('a1'/'a2') are ignored — the durable option keys off the pattern hash.
		$this->assertSame( $big, $GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( Log_Manager::url_hash( '/heavy/' ) ) ] );
	}

	public function test_save_derives_ids_from_the_pattern(): void {
		$payload = \wp_json_encode( [
			[ 'id' => 'ignored', 'pattern' => '/new/', 'action' => Rule::ACTION_LOG ],
		] );

		$this->fire( 'save', $payload );

		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertSame( Log_Manager::url_hash( '/new/' ), $stored[0]['id'] );
	}

	public function test_save_collapses_duplicate_patterns_to_one_rule(): void {
		$payload = \wp_json_encode( [
			[ 'pattern' => '/dup/', 'action' => Rule::ACTION_SKIP ],
			[ 'pattern' => '/dup/', 'action' => Rule::ACTION_LOG ],
		] );

		$result = $this->fire( 'save', $payload );

		$this->assertSame( [ 'saved' => 1 ], $result );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 1, $stored );
		$this->assertSame( Rule::ACTION_LOG, $stored[0]['action'], 'last entry for a pattern wins' );
	}

	public function test_save_rejects_invalid_json(): void {
		$result = $this->fire( 'save', 'not json' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_save_rejects_a_non_array_json_payload(): void {
		$rule = new Rule( 'untouched', '/x/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $rule ] );

		$result = $this->fire( 'save', '"just a string"' );

		$this->assertIsString( $result );
		$this->assertCount( 1, $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ], 'a rejected payload must not touch the durable option' );
	}

	// -------------------------------------------------------------------------
	// upsert
	// -------------------------------------------------------------------------

	public function test_upsert_replaces_a_rule_with_the_same_pattern(): void {
		$existing = new Rule( Log_Manager::url_hash( '/exact/' ), '/exact/', Rule::ACTION_SKIP );
		( new Rule_Set( [] ) )->save( [ $existing ] );

		$payload = \wp_json_encode( [ 'pattern' => '/exact/', 'action' => Rule::ACTION_LOG, 'hooks' => [ 'init' ] ] );
		$result  = $this->fire( 'upsert', $payload );

		$this->assertSame( Log_Manager::url_hash( '/exact/' ), $result['rule']['id'], 'same pattern -> same id' );
		$this->assertSame( Rule::ACTION_LOG, $result['rule']['action'] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 1, $stored, 'a same-pattern upsert replaces in place, not append' );
	}

	public function test_upsert_appends_a_new_rule_keyed_by_its_pattern(): void {
		$existing = new Rule( Log_Manager::url_hash( '/existing/' ), '/existing/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $existing ] );

		$payload = \wp_json_encode( [ 'pattern' => '/brand-new/', 'action' => Rule::ACTION_LOG ] );
		$result  = $this->fire( 'upsert', $payload );

		$this->assertSame( Log_Manager::url_hash( '/brand-new/' ), $result['rule']['id'] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 2, $stored, 'a new-pattern upsert appends' );
	}

	public function test_upsert_add_does_not_drop_the_log_all_baseline(): void {
		// An id-less ADD must match only by pattern, never by empty id — else it
		// would delete an id-less baseline ('/') and silently disable logging.
		$baseline = new Rule( Log_Manager::url_hash( '/' ), '/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $baseline ] );

		$payload = \wp_json_encode( [ 'pattern' => '/foo/', 'action' => Rule::ACTION_SKIP ] );
		$this->fire( 'upsert', $payload );

		$stored   = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$patterns = \array_column( $stored, 'pattern' );
		$this->assertContains( '/', $patterns, 'the log-all baseline must survive an unrelated add' );
		$this->assertContains( '/foo/', $patterns );
	}

	public function test_upsert_replaces_a_legacy_positional_id_rule_for_the_same_pattern(): void {
		// A rule persisted by v0.26 carries a positional id, not id_for(pattern).
		// An id-less add for that same pattern must still replace it, not duplicate.
		$legacy = new Rule( 'faed26b5', '/exact/', Rule::ACTION_SKIP );
		( new Rule_Set( [] ) )->save( [ $legacy ] );

		$payload = \wp_json_encode( [ 'pattern' => '/exact/', 'action' => Rule::ACTION_LOG ] );
		$this->fire( 'upsert', $payload );

		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 1, $stored, 'a same-pattern add must replace the legacy-id rule, not duplicate it' );
		$this->assertSame( Log_Manager::url_hash( '/exact/' ), $stored[0]['id'], 'the survivor is rekeyed to the pattern hash' );
	}

	public function test_upsert_edit_rekeys_by_new_pattern_and_deletes_the_old(): void {
		$existing = new Rule( Log_Manager::url_hash( '/old/' ), '/old/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $existing ] );

		// Editing that rule (its old-pattern id round-trips from the modal) and
		// changing the pattern rekeys it to the new pattern's id and drops '/old/'.
		$payload = \wp_json_encode( [ 'id' => Log_Manager::url_hash( '/old/' ), 'pattern' => '/new/', 'action' => Rule::ACTION_LOG ] );
		$result  = $this->fire( 'upsert', $payload );

		$this->assertSame( Log_Manager::url_hash( '/new/' ), $result['rule']['id'], 'the rekeyed rule is identified by its new pattern' );
		$this->assertSame( '/new/', $result['rule']['pattern'] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 1, $stored, 'the old-pattern rule is deleted, not orphaned' );
		$this->assertSame( '/new/', $stored[0]['pattern'], 'the surviving rule carries the new pattern' );
	}

	public function test_upsert_preserves_a_sibling_pointer_rules_durable_hooks(): void {
		$big     = \array_map( static fn ( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$sib_id  = Log_Manager::url_hash( '/heavy/' );
		$sibling = new Rule( $sib_id, '/heavy/', Rule::ACTION_LOG, hooks: $big );
		( new Rule_Set( [] ) )->save( [ $sibling ] );

		$payload = \wp_json_encode( [ 'pattern' => '/brand-new/', 'action' => Rule::ACTION_LOG ] );
		$this->fire( 'upsert', $payload );

		$this->assertSame(
			$big,
			$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( $sib_id ) ],
			'an unrelated upsert must not wipe a sibling pointer rule\'s durable hooks option'
		);
		VerbHarness::reset(); // fresh request-scope graph: fire() registers '_router' once per call.
		$list = $this->fire( 'list' );
		$sib  = \array_values( \array_filter( $list['rules'], static fn ( array $r ): bool => $sib_id === $r['id'] ) )[0];
		$this->assertSame( $big, $sib['hooks'], 'list must still resolve the sibling pointer rule to its full hook list' );
	}

	public function test_upsert_rejects_invalid_json(): void {
		$result = $this->fire( 'upsert', 'not json' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_upsert_refuses_a_pattern_less_rule_and_keeps_the_baseline(): void {
		// A pattern-less rule used to coerce to '/', taking the log-all
		// baseline's id and replacing it with a skip — logging off site-wide.
		$baseline = new Rule( Log_Manager::url_hash( '/' ), '/', Rule::ACTION_LOG, hooks: [ 'wp_loaded' ] );
		( new Rule_Set( [] ) )->save( [ $baseline ] );

		$result = $this->fire( 'upsert', \wp_json_encode( [ 'action' => Rule::ACTION_SKIP ] ) );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'pattern', \strtolower( $result ) );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 1, $stored );
		$this->assertSame( '/', $stored[0]['pattern'] );
		$this->assertSame( Rule::ACTION_LOG, $stored[0]['action'], 'the log-all baseline survives a pattern-less upsert' );
		$this->assertSame( [ 'wp_loaded' ], $stored[0]['hooks'] );
	}

	// -------------------------------------------------------------------------
	// delete
	// -------------------------------------------------------------------------

	public function test_delete_removes_a_rule_by_id(): void {
		$rule = new Rule( 'del-1', '/gone/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $rule ] );

		$result = $this->fire( 'delete', 'del-1' );

		$this->assertSame( [ 'deleted' => true ], $result );
		$this->assertSame( [], $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );
	}

	public function test_delete_preserves_a_sibling_pointer_rules_durable_hooks(): void {
		$big     = \array_map( static fn ( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$sibling = new Rule( 'sib-2', '/heavy/', Rule::ACTION_LOG, hooks: $big );
		$victim  = new Rule( 'gone-2', '/gone/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $sibling, $victim ] );

		$result = $this->fire( 'delete', 'gone-2' );

		$this->assertSame( [ 'deleted' => true ], $result );
		$this->assertSame(
			$big,
			$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'sib-2' ) ],
			'an unrelated delete must not wipe a sibling pointer rule\'s durable hooks option'
		);
	}

	public function test_delete_unknown_id_reports_false_and_does_not_touch_the_set(): void {
		$rule = new Rule( 'stays', '/here/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $rule ] );

		$result = $this->fire( 'delete', 'does-not-exist' );

		$this->assertSame( [ 'deleted' => false ], $result );
		$this->assertCount( 1, $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );
	}

	// -------------------------------------------------------------------------
	// schema-driven dispatch
	// -------------------------------------------------------------------------

	public function test_extends_service_ci_node(): void {
		$this->assertTrue(
			\is_subclass_of( Rules_CI_Node::class, \Newspack_Nodes\Service_CI_Node::class ),
			'Rules_CI_Node must extend Service_CI_Node so its node_schema is auto-wired by the catalog scan.'
		);
	}

	public function test_node_schema_declares_the_crud_verbs(): void {
		$schema = Rules_CI_Node::node_schema();

		$this->assertSame( 'Service', $schema['category'] );
		$names = \array_column( $schema['commands'], 'name' );
		$this->assertEqualsCanonicalizing( [ 'list', 'save', 'upsert', 'delete', 'reset' ], $names );
	}

	// -------------------------------------------------------------------------
	// reset
	// -------------------------------------------------------------------------

	/**
	 * Config is memoized per process WITH the stored option folded in by the
	 * presence overlay, and `App\Core` warms it at `plugins_loaded:11` — so by
	 * the time a verb runs, `Config::value('rules')` already answers with the
	 * ruleset we are about to discard. Every reset test reproduces that.
	 */
	private function warm_stale_config(): void {
		\Newspack_Event_Logger_Nodes\Config::value( 'rules' );
	}

	public function test_reset_drops_the_stored_ruleset_and_reports_the_seeded_count(): void {
		$this->set_config_rules( [
			[ 'pattern' => '/seeded-a/', 'action' => 'log' ],
			[ 'pattern' => '/seeded-b/', 'action' => 'skip' ],
		] );
		( new Rule_Set( [] ) )->save( [ new Rule( 'stored', '/stored-only/', Rule::ACTION_SKIP ) ] );
		$this->warm_stale_config();

		$result = $this->fire( 'reset' );

		$this->assertSame( [ 'reset' => 2 ], $result, 'the count is the FILE seed, not the discarded ruleset' );
		$this->assertArrayNotHasKey( Rule_Set::OPTION_RULES, $GLOBALS['_wp_options'] );
	}

	public function test_reset_answers_with_the_config_seed_not_the_discarded_ruleset(): void {
		$this->set_config_rules( [ [ 'pattern' => '/seeded-only/', 'action' => 'log' ] ] );
		( new Rule_Set( [] ) )->save( [ new Rule( 'stored', '/stored-only/', Rule::ACTION_SKIP ) ] );
		$this->warm_stale_config();

		$seeded = Rule_Set::reset();

		$this->assertSame(
			[ '/seeded-only/' ],
			\array_map( static fn ( Rule $r ): string => $r->pattern, $seeded->rules() )
		);
	}

	public function test_reset_then_list_answers_with_the_config_seed(): void {
		$this->set_config_rules( [ [ 'pattern' => '/seeded-only/', 'action' => 'log' ] ] );
		( new Rule_Set( [] ) )->save( [ new Rule( 'stored', '/stored-only/', Rule::ACTION_SKIP ) ] );
		$this->warm_stale_config();

		$this->fire( 'reset' );
		VerbHarness::reset();
		$listed = $this->fire( 'list' );

		$this->assertIsArray( $listed );
		$this->assertSame(
			[ '/seeded-only/' ],
			\array_column( $listed['rules'], 'pattern' )
		);
	}
}
