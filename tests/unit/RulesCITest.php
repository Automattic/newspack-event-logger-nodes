<?php
/**
 * RulesCITest: unit tests for Rules_CI, the service CI backing the rules-editor
 * UI (list/save/upsert/delete over Rule_Set).
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Rules_CI_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

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
		parent::tearDown();
	}

	private function fire( string $verb, string $args = '' ): mixed {
		return VerbHarness::fire( new Rules_CI_Node(), 'rules', $verb, $args );
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
		$this->assertSame( $big, $GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'a2' ) ] );
	}

	public function test_save_mints_ids_for_blank_id_rules(): void {
		$payload = \wp_json_encode( [
			[ 'pattern' => '/new/', 'action' => Rule::ACTION_LOG ],
		] );

		$this->fire( 'save', $payload );

		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertNotSame( '', $stored[0]['id'] );
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

	public function test_upsert_replaces_a_rule_with_the_same_pattern_preserving_its_id(): void {
		$existing = new Rule( 'keep-1', '/exact/', Rule::ACTION_SKIP );
		( new Rule_Set( [] ) )->save( [ $existing ] );

		$payload = \wp_json_encode( [ 'pattern' => '/exact/', 'action' => Rule::ACTION_LOG, 'hooks' => [ 'init' ] ] );
		$result  = $this->fire( 'upsert', $payload );

		$this->assertSame( 'keep-1', $result['rule']['id'], 'upsert must preserve the id of the same-pattern rule it replaces' );
		$this->assertSame( Rule::ACTION_LOG, $result['rule']['action'] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 1, $stored, 'a same-pattern upsert replaces in place, not append' );
	}

	public function test_upsert_appends_a_new_rule_with_a_fresh_unique_id(): void {
		$existing = new Rule( 'only-1', '/existing/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $existing ] );

		$payload = \wp_json_encode( [ 'pattern' => '/brand-new/', 'action' => Rule::ACTION_LOG ] );
		$result  = $this->fire( 'upsert', $payload );

		$this->assertNotSame( '', $result['rule']['id'] );
		$this->assertNotSame( 'only-1', $result['rule']['id'] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 2, $stored, 'a new-pattern upsert appends' );
	}

	public function test_upsert_edit_with_a_changed_pattern_replaces_by_id_not_orphan(): void {
		$existing = new Rule( 'edit-1', '/old/', Rule::ACTION_LOG );
		( new Rule_Set( [] ) )->save( [ $existing ] );

		// Editing that rule (its id round-trips from the modal) and changing the
		// pattern must REPLACE it — not append a new-id rule and orphan '/old/'.
		$payload = \wp_json_encode( [ 'id' => 'edit-1', 'pattern' => '/new/', 'action' => Rule::ACTION_LOG ] );
		$result  = $this->fire( 'upsert', $payload );

		$this->assertSame( 'edit-1', $result['rule']['id'], 'an edit keeps the rule id' );
		$this->assertSame( '/new/', $result['rule']['pattern'] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$this->assertCount( 1, $stored, 'editing a pattern replaces in place — no orphaned old-pattern rule' );
		$this->assertSame( '/new/', $stored[0]['pattern'], 'the surviving rule carries the new pattern' );
	}

	public function test_upsert_preserves_a_sibling_pointer_rules_durable_hooks(): void {
		$big     = \array_map( static fn ( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$sibling = new Rule( 'sib-1', '/heavy/', Rule::ACTION_LOG, hooks: $big );
		( new Rule_Set( [] ) )->save( [ $sibling ] );

		$payload = \wp_json_encode( [ 'pattern' => '/brand-new/', 'action' => Rule::ACTION_LOG ] );
		$this->fire( 'upsert', $payload );

		$this->assertSame(
			$big,
			$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( 'sib-1' ) ],
			'an unrelated upsert must not wipe a sibling pointer rule\'s durable hooks option'
		);
		VerbHarness::reset(); // fresh request-scope graph: fire() registers '_router' once per call.
		$list = $this->fire( 'list' );
		$sib  = \array_values( \array_filter( $list['rules'], static fn ( array $r ): bool => 'sib-1' === $r['id'] ) )[0];
		$this->assertSame( $big, $sib['hooks'], 'list must still resolve the sibling pointer rule to its full hook list' );
	}

	public function test_upsert_rejects_invalid_json(): void {
		$result = $this->fire( 'upsert', 'not json' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
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
		$this->assertEqualsCanonicalizing( [ 'list', 'save', 'upsert', 'delete' ], $names );
	}
}
