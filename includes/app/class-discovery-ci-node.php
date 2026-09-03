<?php
/**
 * Discovery_CI: the spoke's answer to "which hooks and events do you
 * instrument?".
 *
 * `newspack-event-logger-nodes.php` mounts this as the `discovery` node on the
 * substrate's `newspack_nodes/request_graph_ready` action, beside the
 * `performance` and `rules` service CIs. Two callers ask its one verb: the
 * hub's `Discovery_Collector_Node` fans `discovery.get` at every connected
 * spoke and union-merges the replies into the `discovered_hooks` /
 * `discovered_events` staging options behind the ruleset editor's hook picker,
 * and the substrate's `vault` CI probes the same verb to test one spoke's
 * connection.
 *
 * The payload is the union of the hooks and custom events every LOG rule in the
 * durable ruleset instruments (`Rule_Set::instrumented_union()`), with
 * custom-event names filtered out of registered_hooks so the picker's two
 * catalogs stay disjoint. It reports the ruleset and never writes it, because
 * the editor is the only rules writer.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

/**
 * The `discovery` service CI. Its one verb is declared in node_schema(), and
 * the inherited Service_CI_Node constructor builds the dispatch table from
 * that declaration, wrapping the handler in the capability role the verb
 * names — so this class needs neither a constructor nor a gate of its own,
 * and the catalog scan picks the verb up with no registration.
 */
class Discovery_CI_Node extends Service_CI_Node {

	/**
	 * One of the instrumented union's two lists, reduced to a list of non-empty
	 * strings. The union de-duplicates already, by keying a hash map on each
	 * name and returning its `array_keys()`.
	 *
	 * That map is what leaves two shapes to clean up. A numeric hook name keys
	 * it as an integer, so `array_keys()` hands the name back as one, and a
	 * blank name would stage on the hub as an empty catalog entry. The union
	 * declares `string[]`, but a pointer rule's hooks come back from a stored
	 * option, so that declaration is an assertion about data an operator can
	 * edit. `array_values()` reindexes because `array_filter()` preserves keys,
	 * and a gapped array encodes as a JSON object rather than the list the hub
	 * parses.
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
	 * Declare the `discovery` CI: its category, description, and the single
	 * `get` verb with its capability role and handler. The inherited
	 * Service_CI_Node ctor turns this into the dispatch table, and the topology
	 * console reads the same array for the palette entry.
	 *
	 * `get` declares READ, the lowest of the substrate's three roles, because it
	 * only reads the ruleset. Service_CI_Node applies whatever role the verb
	 * declares, so a handler gating itself would override this declaration
	 * silently.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
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
