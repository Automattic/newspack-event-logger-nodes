<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Auto_Tuner_Node;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Auto_Tuner_Node::class )]
class AutoTunerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_actions']       = [];
		$GLOBALS['_current_user_can'] = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
		// Rule_Set::save()'s reconcile_orphans() no-ops when $wpdb isn't set;
		// pin that explicitly rather than relying on other suites' teardown.
		global $wpdb;
		$wpdb       = null;
		Core::$memd = null;
		if ( \class_exists( Config::class ) ) {
			Config::reset();
		}
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb       = null;
		Core::$memd = null;
		unset( $GLOBALS['_test_fire_option_actions'] );
		parent::tearDown();
	}

	/**
	 * Seed the durable rules option with raw (pre-Rule::from_array) shapes.
	 *
	 * @param array<int, array<string, mixed>> $rules
	 */
	private function set_rules_option( array $rules ): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = $rules;
	}

	/**
	 * Build a TM_STRUCT message routed at the AutoTuner. Returned by reference
	 * via output variable so the caller's `fill( array &$message )` doesn't
	 * trip "Only variables should be passed by reference" on a function-call
	 * result.
	 *
	 * @param array<string, mixed> $value
	 */
	private function struct_message( string $key, array $value ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::KEY ]   = $key;
		$message[ Message::VALUE ] = $value;
		return $message;
	}

	private function make_auto_tuner(): Auto_Tuner_Node {
		return new Auto_Tuner_Node();
	}

	private function worker_context(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
	}

	// --- Type / shape gates ---------------------------------------------------

	public function test_non_struct_message_ignored(): void {
		$this->worker_context();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ] ] ] );

		$tuner                     = $this->make_auto_tuner();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$message[ Message::KEY ]   = 'disable_hooks';
		$message[ Message::VALUE ] = [ 'rule_id' => 'shop', 'items' => [ 'noisy_hook' ] ];
		$tuner->fill( $message );

		$this->assertSame( [ 'keep_hook', 'noisy_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	public function test_non_array_value_ignored(): void {
		$this->worker_context();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ] ] ] );

		$tuner                     = $this->make_auto_tuner();
		$message                   = $this->struct_message( 'disable_hooks', [] );
		$message[ Message::VALUE ] = 'string-value';
		$tuner->fill( $message );

		$this->assertSame( [ 'keep_hook', 'noisy_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	public function test_unknown_key_ignored(): void {
		$this->worker_context();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ] ] ] );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'bogus_key', [ 'rule_id' => 'shop', 'items' => [ 'noisy_hook' ] ] );
		$tuner->fill( $message );

		$this->assertSame( [ 'keep_hook', 'noisy_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	public function test_empty_items_ignored(): void {
		$this->worker_context();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook' ], 'significant_events' => [ 'a' ] ] ] );

		$tuner = $this->make_auto_tuner();
		foreach ( [ 'disable_hooks', 'disable_custom_events', 'add_significant_events' ] as $key ) {
			$message = $this->struct_message( $key, [ 'rule_id' => 'shop', 'items' => [] ] );
			$tuner->fill( $message );
		}

		$rule = Rule_Set::load()->rule_by_id( 'shop' );
		$this->assertSame( [ 'keep_hook' ], $rule->hooks );
		$this->assertSame( [ 'a' ], $rule->significant_events );
	}

	public function test_missing_rule_id_ignored(): void {
		$this->worker_context();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ] ] ] );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'items' => [ 'noisy_hook' ] ] );
		$tuner->fill( $message );

		$this->assertSame( [ 'keep_hook', 'noisy_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	// --- Authorization gate ---------------------------------------------------

	public function test_skips_when_unauthorized(): void {
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ] ] ] );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'shop', 'items' => [ 'noisy_hook' ] ] );
		$tuner->fill( $message );

		$this->assertSame( [ 'keep_hook', 'noisy_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	public function test_runs_when_worker_env_set(): void {
		$this->worker_context();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ] ] ] );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'shop', 'items' => [ 'noisy_hook' ] ] );
		$tuner->fill( $message );

		$this->assertSame( [ 'keep_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	public function test_runs_when_manage_options_capability(): void {
		$GLOBALS['_current_user_can'] = true;
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ] ] ] );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'shop', 'items' => [ 'noisy_hook' ] ] );
		$tuner->fill( $message );

		$this->assertSame( [ 'keep_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	// --- disable_hooks --------------------------------------------------------

	public function test_disable_hooks_removes_them_from_the_identified_rule(): void {
		$this->worker_context();
		$this->set_rules_option( [
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook', 'noisy_hook' ], 'significant_events' => [] ],
		] );
		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'shop', 'items' => [ 'noisy_hook' ] ] );
		$tuner->fill( $message );

		$rule = Rule_Set::load()->rule_by_id( 'shop' );
		$this->assertSame( [ 'keep_hook' ], $rule->hooks );
	}

	public function test_disable_hooks_preserves_significant_hooks(): void {
		$this->worker_context();
		$this->set_rules_option( [
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'init', 'noisy', 'important' ], 'significant_events' => [ 'important' ] ],
		] );
		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'shop', 'items' => [ 'noisy', 'important' ] ] );
		$tuner->fill( $message );

		$rule = Rule_Set::load()->rule_by_id( 'shop' );
		$this->assertSame( [ 'init', 'important' ], $rule->hooks );
	}

	public function test_disable_hooks_only_mutates_the_identified_rule(): void {
		$this->worker_context();
		$this->set_rules_option( [
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'noisy_hook' ] ],
			[ 'id' => 'other', 'pattern' => '/other/', 'action' => 'log', 'hooks' => [ 'noisy_hook' ] ],
		] );
		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'shop', 'items' => [ 'noisy_hook' ] ] );
		$tuner->fill( $message );

		$set = Rule_Set::load();
		$this->assertSame( [], $set->rule_by_id( 'shop' )->hooks );
		$this->assertSame( [ 'noisy_hook' ], $set->rule_by_id( 'other' )->hooks );
	}

	public function test_unknown_rule_id_is_a_noop(): void {
		$this->worker_context();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'h' ] ] ] );
		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'ghost', 'items' => [ 'h' ] ] );
		$tuner->fill( $message );

		$this->assertSame( [ 'h' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}

	// --- disable_custom_events ------------------------------------------------

	public function test_disable_custom_events_removes_them_from_the_identified_rule(): void {
		$this->worker_context();
		$this->set_rules_option( [
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'custom_events' => [ 'event_a', 'event_b', 'event_c' ], 'significant_events' => [ 'event_b' ] ],
		] );
		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_custom_events', [ 'rule_id' => 'shop', 'items' => [ 'event_a', 'event_b' ] ] );
		$tuner->fill( $message );

		$rule = Rule_Set::load()->rule_by_id( 'shop' );
		$this->assertSame( [ 'event_b', 'event_c' ], $rule->custom_events );
	}

	// --- add_significant_events -----------------------------------------------

	public function test_add_significant_events_appends_to_the_identified_rule(): void {
		$this->worker_context();
		$this->set_rules_option( [
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'h' ], 'significant_events' => [ 'a' ] ],
		] );
		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'add_significant_events', [ 'rule_id' => 'shop', 'items' => [ 'b' ] ] );
		$tuner->fill( $message );

		$rule = Rule_Set::load()->rule_by_id( 'shop' );
		$this->assertSame( [ 'a', 'b' ], $rule->significant_events );
	}

	public function test_add_significant_events_merges_no_duplicates(): void {
		$this->worker_context();
		$this->set_rules_option( [
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'significant_events' => [ 'existing_a', 'existing_b' ] ],
		] );
		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'add_significant_events', [ 'rule_id' => 'shop', 'items' => [ 'new_one', 'existing_a', 'new_two' ] ] );
		$tuner->fill( $message );

		$rule = Rule_Set::load()->rule_by_id( 'shop' );
		$this->assertSame( [ 'existing_a', 'existing_b', 'new_one', 'new_two' ], $rule->significant_events );
	}

	// --- Pointer-tier (heavy rule) survival -----------------------------------

	/**
	 * A pointer-tier rule keeps its hooks in the durable option + mc mirror, not
	 * inline. Disabling ONE hook must resolve the REAL list and re-save — never
	 * see the pointer's null-hooks and let save() inline-collapse it to [],
	 * destroying the durable option (the BUG 1 data-loss path).
	 *
	 * @param int $i Range index → synthetic hook name.
	 */
	public function test_disable_hooks_on_pointer_rule_preserves_durable_hooks(): void {
		$this->worker_context();
		Core::$memd = new InMemoryMemcached();

		$big = \array_map( static fn( int $i ): string => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 2 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( 'heavy', '/heavy/', Rule::ACTION_LOG, hooks: $big ) ] );
		$this->assertArrayHasKey( Rule_Set::hooks_option_name( 'heavy' ), $GLOBALS['_wp_options'], 'precondition: pointer-tier durable option exists' );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'heavy', 'items' => [ 'hook_1' ] ] );
		$tuner->fill( $message );

		$this->assertArrayHasKey( Rule_Set::hooks_option_name( 'heavy' ), $GLOBALS['_wp_options'], 'durable hooks option must survive the auto-tune' );
		$resolved = Rule_Set::hooks_for( Rule_Set::load()->rule_by_id( 'heavy' ) );
		$this->assertNotSame( [], $resolved, 'hooks must not collapse to []' );
		$this->assertCount( \count( $big ) - 1, $resolved );
		$this->assertNotContains( 'hook_1', $resolved );
		$this->assertContains( 'hook_2', $resolved );
	}

	/**
	 * add_significant_events doesn't touch hooks, but reconstructing the Rule from
	 * a pointer's null-hooks would let save() inline-collapse it to []. The
	 * resolved hook set must survive untouched.
	 *
	 * @param int $i Range index → synthetic hook name.
	 */
	public function test_add_significant_events_on_pointer_rule_preserves_hooks(): void {
		$this->worker_context();
		Core::$memd = new InMemoryMemcached();

		$big = \array_map( static fn( int $i ): string => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 2 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( 'heavy', '/heavy/', Rule::ACTION_LOG, significant_events: [ 'a' ], hooks: $big ) ] );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'add_significant_events', [ 'rule_id' => 'heavy', 'items' => [ 'b' ] ] );
		$tuner->fill( $message );

		$loaded = Rule_Set::load()->rule_by_id( 'heavy' );
		$this->assertSame( [ 'a', 'b' ], $loaded->significant_events );
		$this->assertArrayHasKey( Rule_Set::hooks_option_name( 'heavy' ), $GLOBALS['_wp_options'], 'durable hooks option must survive' );
		$this->assertCount( \count( $big ), Rule_Set::hooks_for( $loaded ) );
	}

	// --- No-op short-circuit --------------------------------------------------

	public function test_disable_hooks_absent_hook_does_not_resave(): void {
		$this->worker_context();
		$this->set_rules_option( [
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'keep_hook' ], 'significant_events' => [] ],
		] );

		$writes                               = 0;
		$GLOBALS['_test_fire_option_actions'] = true;
		$counter                              = static function ( string $key ) use ( &$writes ): void {
			if ( Rule_Set::OPTION_RULES === $key ) {
				++$writes;
			}
		};
		\add_action( 'update_option', $counter );
		\add_action( 'add_option', $counter );

		$tuner   = $this->make_auto_tuner();
		$message = $this->struct_message( 'disable_hooks', [ 'rule_id' => 'shop', 'items' => [ 'already_gone_hook' ] ] );
		$tuner->fill( $message );

		$this->assertSame( 0, $writes, 'a no-op disable_hooks must not re-save the ruleset' );
		$this->assertSame( [ 'keep_hook' ], Rule_Set::load()->rule_by_id( 'shop' )->hooks );
	}
}
