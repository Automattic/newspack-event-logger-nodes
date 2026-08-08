<?php
/**
 * Rules_CI: command-dispatch for the per-URL logging ruleset editor.
 *
 * Verbs:
 *   list   — every rule, with a pointer rule's hooks resolved to the full
 *            list via Rule_Set::hooks_for() and hooks_in normalized to
 *            'inline' — the editor never sees the storage tier.
 *   save   — whole-list replace. Arg `rules` is a JSON array of rule
 *            objects; each decodes via Rule::from_array(), the list is
 *            rekeyed by Rule_Set::rekey_by_pattern() — every entry takes the
 *            id its own pattern derives, and entries sharing a pattern
 *            collapse to the last one — and Rule_Set::save() persists it, so
 *            inline<->pointer tiering and orphan reconcile stay intact.
 *   upsert — single-rule add/replace. Arg `rule` is a JSON object. The rule
 *            carrying the incoming id, plus every rule sharing the incoming
 *            pattern, drops out; the incoming rule is appended under the id
 *            its pattern derives. A same-pattern edit therefore keeps its id,
 *            and an edit that changes the pattern (round-tripping the old id)
 *            rekeys the rule and drops the old-pattern entry. List position
 *            carries no meaning — Rule_Matcher ranks by specificity, not
 *            order. This is the performance-dashboard "log this URL" path —
 *            no need to ship the whole list for one change.
 *   delete — arg `id`. Drops the matching rule (if any), re-saves, and
 *            reports whether anything matched.
 *   reset  — no args. Discards the stored option so the file config seeds
 *            again; reports the seeded rule count.
 *
 * All five run through Rule_Set so the tiering/reconcile invariants in
 * Rule_Set::save() are never bypassed by a raw update_option().
 *
 * `save` and `upsert` take their JSON blob as the first raw token
 * (`self::arg_strings( $args )[0]`) instead of through Command_Args::parse(),
 * which would swallow a `--`-leading payload as an option. `delete` takes a
 * plain positional id and parses normally.
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

/**
 * The `rules` service CI. Verbs are declared once in node_schema(); the
 * inherited Service_CI_Node constructor builds the dispatch table from it,
 * so this class needs no constructor of its own.
 */
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
	 * @param string $raw Raw JSON token as it arrived on the command envelope.
	 * @return array<array-key, mixed>
	 * @throws \RuntimeException When the payload exceeds MAX_JSON_BYTES, or does not decode to an array.
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
	 * @param Rule $rule Persisted rule, either tier.
	 * @return array<string, mixed>
	 */
	private static function wire_shape( Rule $rule ): array {
		$shape             = $rule->to_array();
		$shape['hooks']    = Rule_Set::hooks_for( $rule );
		$shape['hooks_in'] = Rule::HOOKS_INLINE;
		return $shape;
	}

	/**
	 * Declare the `rules` CI: its category, description, and the five verbs
	 * with their argument lists and handlers.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string, mixed>
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Per-URL logging ruleset CRUD: list / save / upsert / delete / reset, backed by Rule_Set.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'list',
					'description' => 'All rules, with pointer-tier hooks resolved to the full list.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
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
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
						$decoded = self::decode_json_array( self::arg_strings( $args )[0] ?? '' );
						$rules   = [];
						foreach ( $decoded as $entry ) {
							if ( ! \is_array( $entry ) ) {
								throw new \RuntimeException( 'invalid rule entry: expected an object' );
							}
							/** @var array<string, mixed> $entry decoded rule object (Rule::to_array() shape). */
							$rules[] = Rule::from_array( $entry );
						}
						$rules = Rule_Set::rekey_by_pattern( $rules );
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
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
						$decoded = self::decode_json_array( self::arg_strings( $args )[0] ?? '' );
						/** @var array<string, mixed> $decoded decoded rule object (Rule::to_array() shape). */
						$incoming = Rule::from_array( $decoded );
						$new_id   = Rule_Set::id_for( $incoming->pattern );

						$set       = Rule_Set::load();
						$remaining = [];
						foreach ( $set->rules() as $r ) {
							// Drop the id match and any pattern match.
							if ( ( '' !== $incoming->id && $r->id === $incoming->id ) || $r->pattern === $incoming->pattern ) {
								continue;
							}
							$remaining[] = $r;
						}
						$remaining[] = $incoming->with_id( $new_id );
						$set->save( $remaining );

						// save() re-tiers in memory; the append is found.
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
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
						$id = Command_Args::parse( self::arg_strings( $args ) )['positional'][0] ?? '';
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
				[
					'name'        => 'reset',
					'description' => 'Discard the stored ruleset so the file config seeds again. Reports the seeded rule count.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
						return [ 'reset' => \count( Rule_Set::reset()->rules() ) ];
					},
				],
			],
		] );
	}
}
