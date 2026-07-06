<?php
/**
 * Auto Tuner Node
 *
 * Receives FlameBuilder's auto-tune decisions as messages and applies them by
 * mutating the rule identified by `rule_id` and persisting via `Rule_Set::save()`,
 * which handles the inline<->pointer tiering + orphan reconciliation.
 *
 * Replaces the legacy AutoTuneHandlers static action listeners. FlameBuilder
 * used to fire three `do_action()` calls and AutoTuneHandlers wired six
 * priority-5/priority-10 listeners (hub + standalone for each event); since
 * both sides live in the same request-workers process anyway, the WP-action
 * indirection was just intra-process message-passing dressed up as hooks.
 * Now expressed as a Node fill() with dispatch by Message::KEY.
 *
 * Message shape:
 *   KEY   = 'disable_hooks' | 'disable_custom_events' | 'add_significant_events'
 *   VALUE = [ 'rule_id' => string, 'items' => string[] ]
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Auto_Tuner_Node extends Node {

	public function fill( array &$message ): void {
		/** @var int $type_flags */
		$type_flags = $message[ Message::TYPE ];
		if ( ( $type_flags & Message::TM_STRUCT ) === 0 ) {
			return;
		}
		$value = $message[ Message::VALUE ];
		if ( ! \is_array( $value ) ) {
			return;
		}

		$rule_id = \is_string( $value['rule_id'] ?? null ) ? $value['rule_id'] : '';
		/** @var string[] $items dynamic message VALUE['items']. */
		$items = \is_array( $value['items'] ?? null ) ? $value['items'] : [];

		if ( empty( $items ) || '' === $rule_id || ! $this->authorized() ) {
			return;
		}

		switch ( $message[ Message::KEY ] ?? '' ) {
			case 'disable_hooks':
				$this->apply_disable_hooks( $items, $rule_id );
				break;
			case 'disable_custom_events':
				$this->apply_disable_custom_events( $items, $rule_id );
				break;
			case 'add_significant_events':
				$this->apply_add_significant_events( $items, $rule_id );
				break;
		}
	}

	/**
	 * Auto-tune only fires from inside a request-workers worker (FlameBuilder).
	 * Workers populate `NEWSPACK_NODES_WORKER_TYPE` post-auth (substrate
	 * SpawnController + Supervisor::run); admin requests have manage_options.
	 * Tests can unset both to exercise the early-return path.
	 */
	private function authorized(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only env check.
		if ( isset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] ) ) {
			return true;
		}
		if ( \function_exists( 'current_user_can' ) ) {
			return \current_user_can( 'manage_options' );
		}
		return false;
	}

	/**
	 * @param string[] $items Hooks to disable, unless protected by the rule's significant_events.
	 */
	private function apply_disable_hooks( array $items, string $rule_id ): void {
		$this->mutate_rule(
			$rule_id,
			static function ( Rule $rule ) use ( $items ): Rule {
				$significant = $rule->significant_events;
				// Resolve the REAL hook list: a pointer rule's ->hooks is null.
				$hooks = Rule_Set::hooks_for( $rule );
				$kept  = \array_values( \array_filter(
					$hooks,
					static fn( $hook ) => \in_array( $hook, $significant, true ) || ! \in_array( $hook, $items, true )
				) );
				// Hand save() the resolved list + inline marker; it re-tiers by count.
				return new Rule( $rule->id, $rule->pattern, $rule->action, $rule->auto_disable_threshold, $rule->auto_protect_time_threshold, $significant, $rule->custom_events, $kept, Rule::HOOKS_INLINE );
			}
		);
	}

	/**
	 * @param string[] $items Custom-event categories to disable, unless protected by the rule's significant_events.
	 */
	private function apply_disable_custom_events( array $items, string $rule_id ): void {
		$this->mutate_rule(
			$rule_id,
			static function ( Rule $rule ) use ( $items ): Rule {
				$significant = $rule->significant_events;
				$disable     = \array_flip( \array_filter( $items, static fn( $event ) => ! \in_array( $event, $significant, true ) ) );
				$kept        = \array_values( \array_filter( $rule->custom_events, static fn( $event ) => ! isset( $disable[ $event ] ) ) );
				// Hooks are untouched but MUST be the resolved list, not the pointer's
				// null — otherwise save() re-inlines the rule to hooks=[] and drops it.
				return new Rule( $rule->id, $rule->pattern, $rule->action, $rule->auto_disable_threshold, $rule->auto_protect_time_threshold, $significant, $kept, Rule_Set::hooks_for( $rule ), Rule::HOOKS_INLINE );
			}
		);
	}

	/**
	 * @param string[] $items Newly-promoted significant-event tags to append.
	 */
	private function apply_add_significant_events( array $items, string $rule_id ): void {
		$this->mutate_rule(
			$rule_id,
			static function ( Rule $rule ) use ( $items ): Rule {
				$merged = \array_values( \array_unique( \array_merge( $rule->significant_events, $items ) ) );
				// Resolve hooks (pointer ->hooks is null) so save() re-tiers instead of dropping.
				return new Rule( $rule->id, $rule->pattern, $rule->action, $rule->auto_disable_threshold, $rule->auto_protect_time_threshold, $merged, $rule->custom_events, Rule_Set::hooks_for( $rule ), Rule::HOOKS_INLINE );
			}
		);
	}

	// --- Apply -----------------------------------------------------------------

	/**
	 * Load the rule set, mutate the rule identified by `$rule_id`, and save it
	 * back. A `null` from `$mutate` (rule missing, or the mutation itself
	 * opting out) is a no-op — nothing is written.
	 *
	 * @param callable(Rule): ?Rule $mutate
	 */
	private function mutate_rule( string $rule_id, callable $mutate ): void {
		// AutoTuner runs inside a long-lived request-workers process, and
		// mutate_rule below does read-modify-write on the rules option.
		// Without invalidating the alloptions snapshot, the RMW would
		// clobber writes made by other workers / SettingsSync fanouts /
		// admin edits since this worker spawned.
		\Newspack_Nodes\Config::invalidate_options_cache();

		$set  = Rule_Set::load();
		$rule = $set->rule_by_id( $rule_id );
		if ( null === $rule ) {
			return;
		}
		$updated = $mutate( $rule );
		if ( null === $updated || self::unchanged( $rule, $updated ) ) {
			return;
		}
		$rules = \array_map(
			static fn( Rule $r ) => $r->id === $rule_id ? $updated : $r,
			$set->rules()
		);
		$set->save( $rules );
	}

	/**
	 * True when $updated equals $original once both hook lists are resolved to
	 * concrete form. A pointer-tier rule stores hooks=null, so a raw to_array()
	 * comparison would always differ — this lets a no-op action (e.g. a hook a
	 * concurrent worker already removed) skip the write entirely.
	 */
	private static function unchanged( Rule $original, Rule $updated ): bool {
		return self::resolved_shape( $original ) === self::resolved_shape( $updated );
	}

	/**
	 * The rule's to_array() with hooks resolved to their concrete list (a pointer
	 * rule's null replaced by Rule_Set::hooks_for) so two rules are comparable
	 * regardless of storage tier.
	 *
	 * @return array<string, mixed>
	 */
	private static function resolved_shape( Rule $rule ): array {
		$shape             = $rule->to_array();
		$shape['hooks']    = Rule_Set::hooks_for( $rule );
		$shape['hooks_in'] = Rule::HOOKS_INLINE;
		return $shape;
	}

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		// Hidden: AutoTuner is instantiated as a sibling/patron of
		// FlameBuilder (handled via $interpreter->patron()), not built directly
		// in TSL. Keeping it out of the palette prevents operators from
		// wiring up a second instance that nothing routes messages to.
		return [
			'category'    => 'Hidden',
			'description' => 'Receives FlameBuilder auto-tune decisions and applies them to the identified rule.',
			'arguments'        => [],
			'commands'       => [],
		];
	}
}
