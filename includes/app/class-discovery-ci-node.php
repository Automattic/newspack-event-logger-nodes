<?php
/**
 * Discovery_CI: the spoke's answer to "which hooks does this site register?".
 *
 * `newspack-event-logger-nodes.php` mounts this as the `discovery` node on the
 * substrate's `newspack_nodes/request_graph_ready` action, beside the
 * `performance` and `rules` service CIs. One caller asks its one verb: the
 * hub's `Discovery_Collector_Node` fans `discovery.get` at every connected
 * spoke and union-merges the replies into the `discovered_hooks` staging
 * option behind the ruleset editor's hook picker.
 *
 * The payload is every hook this spoke's `$wp_filter` holds a callback on,
 * read through `Hook_Categorizer::hooks_with_callbacks()`, less the plugin's
 * and the substrate's own hooks. It never reads the ruleset: a spoke's rules
 * are the ones the hub pushed, so reporting them would tell the hub nothing.
 * The command arrives as a `/command` REST request, so `$wp_filter` holds
 * what the active plugins and theme registered before the route ran; a hook
 * registered only on a front-end or admin request goes unreported.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Hook_Categorizer;
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
	 * Declare the `discovery` CI: its category, description, and the single
	 * `get` verb with its capability role and handler. The inherited
	 * Service_CI_Node ctor turns this into the dispatch table, and the topology
	 * console reads the same array for the palette entry.
	 *
	 * `get` declares READ, the lowest of the substrate's three roles, because it
	 * only reads `$wp_filter`. Service_CI_Node applies whatever role the verb
	 * declares, so a handler gating itself would override this declaration
	 * silently.
	 *
	 * The internal hooks are dropped here because the picker drops them too
	 * (`Hook_Categorizer::get_registered_hooks_by_category()`) and
	 * `App\Core` never binds one, so each would spend a slot of the hub's
	 * staging cap on a name nobody can select.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Discovery surface: list the hooks this spoke registers callbacks on.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'get',
					'capability'  => Capabilities::READ,
					'description' => 'Return registered_hooks: every non-internal hook holding a callback on this spoke.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
						$hooks = \array_filter( Hook_Categorizer::hooks_with_callbacks(), static fn ( string $hook ): bool => ! Hook_Categorizer::is_internal( $hook ) );
						return [ 'registered_hooks' => \array_values( $hooks ) ];
					},
				],
			],
		] );
	}
}
