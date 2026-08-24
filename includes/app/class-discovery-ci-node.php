<?php
/**
 * Discovery_CI: command-dispatch for the spoke-side discovery surface.
 *
 * `newspack-event-logger-nodes.php` mounts this as the `discovery` node on the
 * substrate's `newspack_nodes/request_graph_ready` action, beside the
 * `performance` and `rules` service CIs. The hub's `Discovery_Collector_Node`
 * fans a `discovery.get` command at every connected spoke and union-merges the
 * replies into the hub's `discovered_*` staging options, which feed the ruleset
 * editor's hook picker.
 *
 * The class declares no constructor: the inherited Service_CI_Node ctor builds
 * the commands table from node_schema() and gates every verb behind
 * manage_options, so the catalog scan picks the verb up automatically.
 *
 * Verbs:
 *   get — return registered_hooks + custom_events for this spoke.
 *
 * The payload is the union of instrumented hooks and custom events across every
 * LOG rule in the durable ruleset (`Rule_Set::instrumented_union()`), with
 * custom-event names filtered out of registered_hooks so the two lists stay
 * disjoint.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Discovery_CI_Node extends Service_CI_Node {

	/**
	 * The instrumented union's list of names, with empty strings dropped.
	 *
	 * `Rule_Set::instrumented_union()` returns `array_keys()` of two hash maps,
	 * so what arrives is already a de-duplicated list of strings.
	 *
	 * @param mixed $value Hook or custom-event list from the instrumented union.
	 * @return array<int,string>
	 */
	private static function extract_string_list( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		return \array_values( \array_filter( $value, static fn ( $v ): bool => \is_string( $v ) && '' !== $v ) );
	}

	/**
	 * Verb declaration for the `discovery` service CI. The inherited
	 * Service_CI_Node ctor turns this into the dispatch table, wrapping each
	 * handler in the manage_options gate; the topology console reads it for the
	 * palette entry.
	 *
	 * @api Used by the substrate to provide UI etc.
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Discovery surface: list registered + custom event hooks this spoke instruments.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'get',
					'capability'  => Capabilities::READ,
					'description' => 'Return registered_hooks + custom_events for this spoke.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
						$union            = Rule_Set::load()->instrumented_union();
						$registered_hooks = self::extract_string_list( $union['hooks'] );
						$custom_events    = self::extract_string_list( $union['custom_events'] );

						// Filter custom-event names out of registered_hooks.
						if ( ! empty( $custom_events ) ) {
							$custom_set       = \array_flip( $custom_events );
							$registered_hooks = \array_values( \array_filter( $registered_hooks, static fn ( $h ) => ! isset( $custom_set[ $h ] ) ) );
						}

						return [
							'registered_hooks' => $registered_hooks,
							'custom_events'    => $custom_events,
						];
					},
				],
			],
		] );
	}
}
