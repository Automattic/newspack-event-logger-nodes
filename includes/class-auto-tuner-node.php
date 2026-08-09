<?php
/**
 * Auto Tuner Node
 *
 * The write half of auto-tune. `Flame_Builder_Node` decides which hooks and
 * custom events have grown too noisy to keep instrumenting, and which events
 * deserve promotion to significant; this node turns those decisions into a
 * durable ruleset edit. It mutates the single rule named by `rule_id` and
 * persists the whole list through `Rule_Set::save()`, which owns the
 * inline<->pointer hook tiering and the orphan reconciliation.
 *
 * FlameBuilder constructs and owns one of these as a sibling named
 * `{flame-builder}:auto-tuner`, and addresses it by TO; the message therefore
 * arrives through the Router like any other. `fill()` is terminal — nothing is
 * forwarded to the sink.
 *
 * Message shape:
 *   TYPE  = TM_STRUCT (an array VALUE; a non-struct message is dropped)
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

/**
 * Applies auto-tune decisions to the per-URL logging ruleset.
 */
class Auto_Tuner_Node extends Node {

	/**
	 * Apply one auto-tune decision, or drop the message.
	 *
	 * Gates precede any write: the message must carry TM_STRUCT, its VALUE must
	 * be an array holding a non-empty `rule_id` and a non-empty `items` list,
	 * and the process must be authorized. An unrecognized KEY falls through the
	 * switch. Every rejection is silent — a malformed decision costs the caller
	 * nothing, and FlameBuilder emits these from its periodic flush.
	 *
	 * @param array<int,mixed> $message Positional Message array.
	 */
	public function fill( array $message ): void {
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
	 * May this process rewrite the ruleset?
	 *
	 * Auto-tune fires from inside a worker running FlameBuilder. Workers
	 * populate `NEWSPACK_NODES_WORKER_TYPE` only after the substrate has
	 * authorized the spawn (`Spawn_Controller::spawn()`),
	 * so its presence stands in for that check; an ordinary admin request
	 * qualifies on `manage_options` instead. Tests clear both to exercise the
	 * early return.
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
	 * Drop the named hooks from the rule's instrumented list.
	 *
	 * A hook that also appears in the rule's `significant_events` is protected
	 * and survives, however noisy it got.
	 *
	 * All three appliers rebuild the Rule the same way, and the reason is a
	 * footgun worth stating once: each hands the constructor the RESOLVED hook
	 * list together with the `HOOKS_INLINE` marker. A pointer-tier rule carries
	 * `hooks = null`, and `Rule_Set::save()` reads a null under an inline marker
	 * as "no hooks" — it would collapse the rule to an empty list and delete the
	 * durable option, losing every hook the rule instruments. Resolving first
	 * also gives save() a true count to re-tier by.
	 *
	 * @param string[] $items   Hooks to disable, unless protected by the rule's significant_events.
	 * @param string   $rule_id Id of the rule to mutate.
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
				// Give save() resolved list + inline marker; re-tiers by count.
				return new Rule( $rule->id, $rule->pattern, $rule->action, $rule->auto_disable_threshold, $rule->auto_protect_time_threshold, $significant, $rule->custom_events, $kept, Rule::HOOKS_INLINE );
			}
		);
	}

	/**
	 * Drop the named custom-event categories from the rule.
	 *
	 * As with hooks, a category listed in `significant_events` is protected.
	 * The rule's hooks are untouched but must still be passed in resolved form
	 * — see `apply_disable_hooks()`.
	 *
	 * @param string[] $items   Custom-event categories to disable, unless protected by the rule's significant_events.
	 * @param string   $rule_id Id of the rule to mutate.
	 */
	private function apply_disable_custom_events( array $items, string $rule_id ): void {
		$this->mutate_rule(
			$rule_id,
			static function ( Rule $rule ) use ( $items ): Rule {
				$significant = $rule->significant_events;
				$disable     = \array_flip( \array_filter( $items, static fn( $event ) => ! \in_array( $event, $significant, true ) ) );
				$kept        = \array_values( \array_filter( $rule->custom_events, static fn( $event ) => ! isset( $disable[ $event ] ) ) );
				// Resolved hooks, never the pointer rule's null.
				return new Rule( $rule->id, $rule->pattern, $rule->action, $rule->auto_disable_threshold, $rule->auto_protect_time_threshold, $significant, $kept, Rule_Set::hooks_for( $rule ), Rule::HOOKS_INLINE );
			}
		);
	}

	/**
	 * Append newly-promoted significant-event tags to the rule, deduped.
	 *
	 * Hooks and custom events are untouched, but the hooks must still be passed
	 * in resolved form — see `apply_disable_hooks()`.
	 *
	 * @param string[] $items   Newly-promoted significant-event tags to append.
	 * @param string   $rule_id Id of the rule to mutate.
	 */
	private function apply_add_significant_events( array $items, string $rule_id ): void {
		$this->mutate_rule(
			$rule_id,
			static function ( Rule $rule ) use ( $items ): Rule {
				$merged = \array_values( \array_unique( \array_merge( $rule->significant_events, $items ) ) );
				// Resolved hooks, never the pointer rule's null.
				return new Rule( $rule->id, $rule->pattern, $rule->action, $rule->auto_disable_threshold, $rule->auto_protect_time_threshold, $merged, $rule->custom_events, Rule_Set::hooks_for( $rule ), Rule::HOOKS_INLINE );
			}
		);
	}

	/**
	 * Load the rule set, mutate the rule identified by `$rule_id`, and save it
	 * back. Three outcomes write nothing: no rule carries that id, `$mutate`
	 * returns null (the mutation opting out), or the result is `unchanged()`.
	 *
	 * The read-modify-write is not locked. It opens by dropping WordPress's
	 * per-process option caches, which is what keeps a long-lived worker from
	 * saving a ruleset it read minutes ago and clobbering an admin edit; two
	 * workers racing the same rule still resolve last-writer-wins.
	 *
	 * @param string                $rule_id Id of the rule to mutate.
	 * @param callable(Rule): ?Rule $mutate  Returns the replacement Rule, or null to abort.
	 */
	private function mutate_rule( string $rule_id, callable $mutate ): void {
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
	 *
	 * @param Rule $original Rule as loaded.
	 * @param Rule $updated  Rule as returned by the mutation.
	 */
	private static function unchanged( Rule $original, Rule $updated ): bool {
		return self::resolved_shape( $original ) === self::resolved_shape( $updated );
	}

	/**
	 * The rule's to_array() with hooks resolved to their concrete list (a pointer
	 * rule's null replaced by Rule_Set::hooks_for) so two rules are comparable
	 * regardless of storage tier.
	 *
	 * @param Rule $rule Rule to flatten.
	 * @return array<string,mixed>
	 */
	private static function resolved_shape( Rule $rule ): array {
		$shape             = $rule->to_array();
		$shape['hooks']    = Rule_Set::hooks_for( $rule );
		$shape['hooks_in'] = Rule::HOOKS_INLINE;
		return $shape;
	}

	/**
	 * Topology-console manifest. `Hidden` keeps this node out of the palette:
	 * FlameBuilder constructs it, so no topology ever names it in TSL. It takes
	 * no constructor arguments and exposes no verbs.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Hidden',
			'description' => 'Receives FlameBuilder auto-tune decisions and applies them to the identified rule.',
			'arguments'        => [],
			'commands'       => [],
		];
	}
}
