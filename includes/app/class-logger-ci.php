<?php
/**
 * Logger_CI: command-dispatch for the performance-logger configuration
 * surface.
 *
 * Replaces legacy class-logger-controller.php with a CommandInterpreter
 * that mounts at priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   config — return the full filterable substrate config (matches the
 *            legacy `/logger/config` payload sans the `{data, meta}` REST
 *            envelope). The React tree populates the settings UI from
 *            this single endpoint.
 *   hooks  — return a flattened `{ hooks: [{name, category}, ...],
 *            categories: {...} }` view derived from
 *            HookCategorizer::get_registered_hooks_by_category() and
 *            HookCategorizer::get_categories(). Internal Event Logger /
 *            Nodes hooks are filtered out by the categorizer itself.
 *
 * Both verbs are read-only with no auth check — the legacy controller
 * gated them on `read_permissions_check` for rate-limiting; rate-limiting
 * is now a transport concern handled outside the CI. No service
 * dependencies — substrate Config and HookCategorizer are both globals
 * accessed directly, matching Discovery_CI / Settings_CI.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;

\defined( 'ABSPATH' ) || exit;

class Logger_CI_Node extends Command_Interpreter_Node {

	public function __construct() {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors Status_CI / Discovery_CI /
		// Settings_CI / Workers_CI, which extend CommandInterpreter and
		// also skip the parent call.
		$this->commands( [
			'config' => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				// Echo the full filterable config; sensitive values (memcache
				// server strings) stay since they're already managed via WP
				// options. Value-equivalent with the legacy `get_config`
				// response body — minus the `{data, meta}` REST envelope,
				// which is reconstructed by the REST shim, not the CI.
				return RuntimeConfig::load_config();
			},
			'hooks'  => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				$hooks       = [];
				$by_category = Hook_Categorizer::get_registered_hooks_by_category();
				$categories  = Hook_Categorizer::get_categories();
				// Flatten by_category into a list of { name, category } so
				// the React picker doesn't need to know about the grouping
				// shape. Within each category the order follows the
				// categorizer's per-category sort.
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
		] );
	}
}
