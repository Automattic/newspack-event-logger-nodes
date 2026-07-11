<?php
/**
 * Rules_CI: command-dispatch for the per-URL logging ruleset editor.
 *
 * Verbs:
 *   list   — every rule, with a pointer rule's hooks resolved to the full
 *            list via Rule_Set::hooks_for() and hooks_in normalized to
 *            'inline' — the editor never sees the storage tier.
 *   save   — whole-list replace. Arg `rules` is a JSON array of rule
 *            objects; each decodes via Rule::from_array() and the list goes
 *            through Rule_Set::save() so inline<->pointer tiering and orphan
 *            reconcile stay intact. Blank-id entries get a freshly minted id.
 *   upsert — single-rule add/replace. Arg `rule` is a JSON object. A rule
 *            with the same pattern is replaced IN PLACE, preserving its id;
 *            otherwise the rule is appended with a fresh id. This is the
 *            performance-dashboard "log this URL" path — no need to ship the
 *            whole list for one change.
 *   delete — arg `id`. Drops the matching rule (if any) and re-saves.
 *
 * All four run through Rule_Set so the tiering/reconcile invariants in
 * Rule_Set::save() are never bypassed by a raw update_option().
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Rules_CI_Node extends Service_CI_Node {

	/** Hard cap on JSON payload size for `save`/`upsert` — a whole ruleset is small; this just bounds a runaway request. */
	private const MAX_JSON_BYTES = 65536;

	/** Bounded decode depth — plenty for a rule object's nested hooks/events lists. */
	private const MAX_JSON_DEPTH = 12;

	/**
	 * Decode + guard a JSON arg into an array. Rejects oversized payloads and
	 * anything that doesn't decode to a PHP array (malformed JSON, or valid
	 * JSON that isn't an object/array at the top level).
	 *
	 * @return array<array-key, mixed>
	 */
	private static function decode_json_array( string $raw ): array {
		if ( \strlen( $raw ) > self::MAX_JSON_BYTES ) {
			throw new \RuntimeException( 'payload too large' );
		}
		$decoded = \json_decode( $raw, true, self::MAX_JSON_DEPTH );
		if ( ! \is_array( $decoded ) ) {
			throw new \RuntimeException( 'invalid JSON: expected an object or array' );
		}
		return $decoded;
	}

	/**
	 * Project a Rule into the wire shape the editor consumes: hooks always
	 * resolved to the full list (pointer tier included) and hooks_in always
	 * 'inline' — the storage tier is a Rule_Set implementation detail.
	 *
	 * @return array<string, mixed>
	 */
	private static function wire_shape( Rule $rule ): array {
		$shape             = $rule->to_array();
		$shape['hooks']    = Rule_Set::hooks_for( $rule );
		$shape['hooks_in'] = Rule::HOOKS_INLINE;
		return $shape;
	}

	/**
	 * Rekey every rule to its pattern-derived id (Rule_Set::id_for), ignoring any
	 * client-supplied id, and collapse duplicate patterns to one rule (last wins).
	 * The pattern is the identity — a save can never persist two rules for one URL.
	 *
	 * @param Rule[] $rules
	 * @return Rule[]
	 */
	private static function ensure_ids( array $rules ): array {
		$by_id = [];
		foreach ( $rules as $rule ) {
			$id            = Rule_Set::id_for( $rule->pattern );
			$by_id[ $id ]  = $rule->with_id( $id );
		}
		return \array_values( $by_id );
	}

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Per-URL logging ruleset CRUD: list / save / upsert / delete, backed by Rule_Set.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'list',
					'description' => 'All rules, with pointer-tier hooks resolved to the full list.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						$rules = \array_map(
							static fn ( Rule $r ): array => self::wire_shape( $r ),
							Rule_Set::load()->rules()
						);
						return [ 'rules' => \array_values( $rules ) ];
					},
				],
				[
					'name'        => 'save',
					'description' => 'Whole-list replace. Arg is a JSON array of rule objects.',
					'args'        => [
						[ 'name' => 'rules', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						$decoded = self::decode_json_array( $args );
						$rules   = [];
						foreach ( $decoded as $entry ) {
							if ( ! \is_array( $entry ) ) {
								throw new \RuntimeException( 'invalid rule entry: expected an object' );
							}
							/** @var array<string, mixed> $entry decoded rule object (Rule::to_array() shape). */
							$rules[] = Rule::from_array( $entry );
						}
						$rules = self::ensure_ids( $rules );
						Rule_Set::load()->save( $rules );
						return [ 'saved' => \count( $rules ) ];
					},
				],
				[
					'name'        => 'upsert',
					'description' => 'Add/replace a single rule, keyed by pattern hash. Arg is a JSON rule object; a same-pattern add replaces in place, and an edit that changes the pattern (its old-pattern id round-trips) rekeys the rule and drops the old-pattern entry.',
					'args'        => [
						[ 'name' => 'rule', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						$decoded = self::decode_json_array( $args );
						/** @var array<string, mixed> $decoded decoded rule object (Rule::to_array() shape). */
						$incoming = Rule::from_array( $decoded );
						$new_id   = Rule_Set::id_for( $incoming->pattern );

						$set       = Rule_Set::load();
						$remaining = [];
						foreach ( $set->rules() as $r ) {
							// Drop rule + pattern-mates (pattern = id).
							if ( ( '' !== $incoming->id && $r->id === $incoming->id ) || $r->pattern === $incoming->pattern ) {
								continue;
							}
							$remaining[] = $r;
						}
						$remaining[] = $incoming->with_id( $new_id );
						$set->save( $remaining );

						$persisted = $set->rule_by_id( $new_id );
						\assert( null !== $persisted );
						return [ 'rule' => self::wire_shape( $persisted ) ];
					},
				],
				[
					'name'        => 'delete',
					'description' => 'Remove a rule by id.',
					'args'        => [
						[ 'name' => 'id', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						$id = Command_Args::parse( $args )['positional'][0] ?? '';
						if ( '' === $id ) {
							throw new \RuntimeException( 'id required' );
						}

						$set       = Rule_Set::load();
						$remaining = [];
						$found     = false;
						foreach ( $set->rules() as $r ) {
							if ( $r->id === $id ) {
								$found = true;
								continue;
							}
							$remaining[] = $r;
						}
						$set->save( $remaining );
						return [ 'deleted' => $found ];
					},
				],
			],
		] );
	}
}
