<?php
/**
 * Rules_CI: command dispatch for the per-URL logging ruleset editor.
 *
 * `newspack-event-logger-nodes.php` mounts this as the `rules` node on the
 * substrate's `newspack_nodes/request_graph_ready` action, beside the
 * `discovery` and `performance` service CIs. The `src/rules` editor, mounted
 * into the settings page's Logging Rules section, drives all five verbs —
 * list, save, upsert, delete, reset.
 *
 * Every verb goes through `Rule_Set`, so the inline/pointer tiering and the
 * orphan reconcile in `Rule_Set::save()` hold for an editor write exactly as
 * they do for the config seed. A raw `update_option()` here would store the
 * list without writing a heavy rule's hooks option or sweeping the options a
 * removed rule left behind. An id is a pure function of the pattern, so `save`
 * and `upsert` rekey what they are handed rather than trust an incoming id,
 * and list POSITION carries no meaning: `Rule_Matcher` ranks by specificity.
 *
 * `save` and `upsert` read their JSON blob as the raw first token
 * (`self::arg_strings( $args )[0]`); a blob carries its own structure, so
 * there is nothing for `Command_Args::parse()` to classify. `delete` takes a
 * plain positional id and parses normally. One unrepresentable entry throws
 * out of `Rule::from_array()` before `Rule_Set::save()` is reached, so a
 * whole-list replace is all or nothing.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

/**
 * The `rules` service CI: five verbs over one `Rule_Set`.
 *
 * Verbs are declared once in `node_schema()`, and the inherited
 * `Service_CI_Node` constructor builds the dispatch table from that
 * declaration, so this class needs no constructor of its own.
 */
class Rules_CI_Node extends Service_CI_Node {

	/**
	 * Byte cap on a `save` or `upsert` payload. A whole ruleset is small, so
	 * this bounds a runaway request rather than any edit an operator makes.
	 */
	private const MAX_JSON_BYTES = 65536;

	/**
	 * Decode depth ceiling. A rule list nests three levels — the list, a rule
	 * object, its hooks or events list — so 12 clears any real payload while
	 * `json_decode()` refuses a deeper one instead of building it.
	 */
	private const MAX_JSON_DEPTH = 12;

	/**
	 * Decode and guard a JSON argument into an array.
	 *
	 * Refuses an oversized payload before decoding, and refuses anything that
	 * does not come back a PHP array: malformed JSON, JSON nested past
	 * MAX_JSON_DEPTH, and valid JSON carrying a scalar at the top level all
	 * land in the same refusal.
	 *
	 * @param string $raw Raw JSON token as it arrived on the command envelope.
	 * @return array<array-key,mixed>
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
	 * Project a Rule into the wire shape the editor consumes: hooks resolved to
	 * the full list whichever tier holds them, and `hooks_in` always inline.
	 *
	 * A pointer rule stores its hooks under a separate option and carries null
	 * in the rule itself, so an unresolved projection would show the editor a
	 * rule with no hooks. Saying `inline` beside those resolved hooks is what
	 * keeps the shape representable on the way back: `Rule` refuses a hooks
	 * list that claims the pointer tier. The tier is `Rule_Set`'s own decision,
	 * re-derived from the hook count on every save, so the editor never picks
	 * it.
	 *
	 * @param Rule $rule Persisted rule, either tier.
	 * @return array<string,mixed>
	 */
	private static function wire_shape( Rule $rule ): array {
		$shape             = $rule->to_array();
		$shape['hooks']    = Rule_Set::hooks_for( $rule );
		$shape['hooks_in'] = Rule::HOOKS_INLINE;
		return $shape;
	}

	/**
	 * Declare the `rules` CI: its category, description, and the five verbs
	 * with their capability roles, argument lists and handlers.
	 *
	 * The `capability` key is the whole gate. `Service_CI_Node` wraps each
	 * handler in `Capabilities::require()` for the role declared here — READ
	 * for `list`, TUNE for the four writes — so no handler checks again; one
	 * that did would outrank its own declaration without saying so.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Per-URL logging ruleset CRUD: list / save / upsert / delete / reset, backed by Rule_Set.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'list',
					'capability'  => Capabilities::READ,
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
					'capability'  => Capabilities::TUNE,
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
							/** @var array<string,mixed> $entry decoded rule object (Rule::to_array() shape). */
							$rules[] = Rule::from_array( $entry );
						}
						$rules = Rule_Set::rekey_by_pattern( $rules );
						Rule_Set::load()->save( $rules );
						return [ 'saved' => \count( $rules ) ];
					},
				],
				[
					'name'        => 'upsert',
					'capability'  => Capabilities::TUNE,
					'description' => 'Add/replace a single rule, keyed by pattern hash. Arg is a JSON rule object; a same-pattern add replaces in place, and an edit that changes the pattern (its old-pattern id round-trips) rekeys the rule and drops the old-pattern entry.',
					'args'        => [
						[ 'name' => 'rule', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
						$decoded = self::decode_json_array( self::arg_strings( $args )[0] ?? '' );
						/** @var array<string,mixed> $decoded decoded rule object (Rule::to_array() shape). */
						$incoming = Rule::from_array( $decoded );
						$new_id   = Rule_Set::id_for( $incoming->pattern );

						$set       = Rule_Set::load();
						$remaining = [];
						foreach ( $set->rules() as $r ) {
							// An edit that moved the pattern keeps its old id.
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
					'capability'  => Capabilities::TUNE,
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
					'capability'  => Capabilities::TUNE,
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
