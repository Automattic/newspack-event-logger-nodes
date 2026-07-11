<?php
/**
 * Logger_CI: command-dispatch for the settings configuration
 * surface.
 *
 * Mounts at priority 11 alongside the rest of the M2 service CIs and
 * declares its verbs via the v0.6.0 schema-driven pattern — the inherited
 * Service_CI_Node ctor builds the commands table from node_schema(), so
 * there's no per-class ctor and the catalog scan picks the verbs up
 * automatically.
 *
 * Verbs:
 *   config — return the full filterable substrate config; the `{data, meta}`
 *            REST envelope is added by the REST shim, not the interpreter.
 *            The React tree populates the settings UI from this single
 *            endpoint.
 *   hooks  — return a flattened `{ hooks: [{name, category}, ...],
 *            categories: {...} }` view derived from
 *            HookCategorizer::get_registered_hooks_by_category() and
 *            HookCategorizer::get_categories(). Internal Event Logger /
 *            Nodes hooks are filtered out by the categorizer itself.
 *
 * Both verbs are read-only with no auth check — rate-limiting is a transport
 * concern handled outside the interpreter. No service
 * dependencies — substrate Config and HookCategorizer are both globals
 * accessed directly, matching Discovery_CI / Settings_CI.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Logger_CI_Node extends Service_CI_Node {

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Performance-logger configuration: substrate config snapshot + categorized hook list for the settings UI.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'config',
					'description' => 'Return the full filterable substrate config.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						// Full filterable config; settings UI needs this shape.
						return RuntimeConfig::load_config();
					},
				],
				[
					'name'        => 'hooks',
					'description' => 'Return a flattened { hooks: [{name, category}, ...], categories: {...} } view.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						$hooks       = [];
						$by_category = Hook_Categorizer::get_registered_hooks_by_category();
						$categories  = Hook_Categorizer::get_categories();
						// Flatten by_category to name+category list for picker.
						foreach ( $by_category as $cat => $list ) {
							if ( ! \is_array( $list ) ) {
								continue;
							}
							foreach ( $list as $name ) {
								if ( \is_string( $name ) ) {
									$hooks[] = [
										'name'     => $name,
										'category' => $cat,
									];
								}
							}
						}
						return [
							'hooks'      => $hooks,
							'categories' => $categories,
						];
					},
				],
			],
		] );
	}
}
