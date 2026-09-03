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
	 * switch. Every rejection is silent: `Flame_Builder_Node::emit_auto_tune()`
	 * satisfies the shape gates by construction, so a shape rejection means a
	 * hand-built or replayed message, and the authorization gate is the one a
	 * real decision can lose — `authorized()` says when.
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
		/** @var string[] $items Hook or event names from the message VALUE. */
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
	 * Auto-tune fires from inside a worker running FlameBuilder, and
	 * `NEWSPACK_NODES_WORKER_TYPE` marks that context. Only the substrate writes
	 * it — `Spawn_Controller::spawn()` past the endpoint's permission check, and
	 * `Bootstrap::reconcile_fleet()` on the cron pass. A visitor cannot forge it:
	 * PHP exposes request headers under an `HTTP_` prefix, so nothing a client
	 * sends lands on this key. An ordinary admin request qualifies on
	 * `manage_options` instead, and a process with no WordPress loaded, where
	 * `current_user_can()` is undefined, qualifies on neither.
	 *
	 * A worker started by `wp nodes run` has neither — that path sets no worker
	 * env, and WP-CLI has no current user — so its auto-tune decisions are
	 * dropped unless the command is passed a `--user` holding `manage_options`.
	 *
	 * @return bool
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
	 * All three appliers rebuild the Rule the same way, and the reason is worth
	 * stating once: each hands `Rule::with()` the RESOLVED hook list together
	 * with the `HOOKS_INLINE` marker. The two travel as a pair because the
	 * constructor refuses a list under `HOOKS_MC` and a null under
	 * `HOOKS_INLINE`, and a pointer-tier rule carries `hooks = null` — so
	 * overriding one half of the pair on such a rule throws. Passing the pair
	 * even where the hooks are untouched costs a heavy rule nothing:
	 * `Rule_Set::save()` re-tiers by count and writes the pointer form back.
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
				// The pair the constructor demands; save() re-tiers by count.
				return $rule->with( [ 'significant_events' => $significant, 'hooks' => $kept, 'hooks_in' => Rule::HOOKS_INLINE ] );
			}
		);
	}

	/**
	 * Drop the named custom-event categories from the rule.
	 *
	 * As with hooks, a category listed in `significant_events` is protected.
	 * The rule's hooks are untouched and travel resolved all the same, for the
	 * reason `apply_disable_hooks()` gives.
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
				return $rule->with( [ 'significant_events' => $significant, 'custom_events' => $kept, 'hooks' => Rule_Set::hooks_for( $rule ), 'hooks_in' => Rule::HOOKS_INLINE ] );
			}
		);
	}

	/**
	 * Append newly-promoted significant-event tags to the rule, deduped.
	 *
	 * Hooks and custom events are untouched; the hooks travel resolved all the
	 * same, for the reason `apply_disable_hooks()` gives.
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
				return $rule->with( [ 'significant_events' => $merged, 'hooks' => Rule_Set::hooks_for( $rule ), 'hooks_in' => Rule::HOOKS_INLINE ] );
			}
		);
	}

	/**
	 * Load the rule set, mutate the rule identified by `$rule_id`, and save it
	 * back. Three outcomes write nothing: no rule carries that id, `$mutate`
	 * returns null (the mutation opting out), or the result is `unchanged()`.
	 *
	 * The read-modify-write takes no lock of its own, because
	 * `Flame_Builder_Node::apply_auto_tune()` holds a five-second memcache lock
	 * across the synchronous emit and already serialises the fleet. What this
	 * method owns is staleness: it opens by dropping WordPress's per-process
	 * option caches, which keeps a long-lived worker from saving a ruleset it
	 * read minutes ago and clobbering an admin edit. Writers that collide
	 * anyway — the lock expired, or the host runs no memcache — resolve
	 * last-writer-wins.
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
	 * True when $updated equals $original once `Rule_Set::resolved_map()` has
	 * resolved both hook lists and neutralised both tier markers. A pointer-tier
	 * rule stores `hooks = null` under `HOOKS_MC` while every applier returns a
	 * resolved list under `HOOKS_INLINE`, so a raw `to_array()` comparison would
	 * report a change on every heavy rule forever. Normalising both lets a no-op
	 * action — a hook a concurrent worker already removed — skip the write
	 * entirely.
	 *
	 * @param Rule $original Rule as loaded.
	 * @param Rule $updated  Rule as returned by the mutation.
	 * @return bool
	 */
	private static function unchanged( Rule $original, Rule $updated ): bool {
		return Rule_Set::resolved_map( $original ) === Rule_Set::resolved_map( $updated );
	}

	/**
	 * Topology-console manifest. `Hidden` keeps this node out of the palette:
	 * FlameBuilder constructs it, so no topology ever names it in TSL. It takes
	 * no constructor arguments and exposes no verbs.
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
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
