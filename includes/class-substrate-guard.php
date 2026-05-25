<?php
/**
 * Substrate Guard
 *
 * Runtime floor for the `newspack-nodes` substrate this plugin binds against.
 * The `Requires Plugins: newspack-nodes` header in the plugin entry covers
 * WP 6.5+, but on older cores that header isn't enforced — and it can't catch
 * a substrate that's PRESENT but too OLD to expose the APIs ELN calls. This
 * guard complements the header: the plugin entry checks `satisfied()` right
 * after the autoloader registers and, when unmet, bails with an admin notice
 * instead of fatal-ing at the first missing-symbol call.
 *
 * `satisfied()` is a pure predicate (version floor + capability flag) so it
 * unit-tests without a live substrate; `required_apis_present()` probes the
 * concrete substrate symbols the plugin entry's deferred loader invokes.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies the newspack-nodes substrate satisfies ELN's minimum floor.
 */
class Substrate_Guard {

	/**
	 * Lowest newspack-nodes version ELN was developed against. Raise this as
	 * ELN adopts newer substrate releases (the substrate's register_plugin /
	 * config-namespace / service-CI features land in its next release; until
	 * the substrate bumps, the capability check below is the operative floor).
	 */
	public const MINIMUM_NODES_VERSION = '0.4.0';

	/**
	 * Pure predicate: is the installed substrate new enough AND capable?
	 *
	 * @param string|null $installed_version Substrate version, or null if absent.
	 * @param string      $minimum           Minimum acceptable version.
	 * @param bool         $has_required_apis Whether every probed API is present.
	 * @return bool True only when present, at/above minimum, and API-complete.
	 */
	public static function satisfied( ?string $installed_version, string $minimum, bool $has_required_apis ): bool {
		return null !== $installed_version
			&& \version_compare( $installed_version, $minimum, '>=' )
			&& $has_required_apis;
	}

	/**
	 * Gate ELN's substrate-dependent wiring on the runtime floor.
	 *
	 * MUST be invoked once the substrate is loaded (on `plugins_loaded`, NOT at
	 * ELN's file-load time): ELN sorts alphabetically before `newspack-nodes`, so
	 * at file-load `NEWSPACK_NODES_VERSION` is undefined and every probed class is
	 * absent — checking then routes a healthy install to `on_unsatisfied` and
	 * disables the whole plugin. When `satisfied()`, runs `$on_ready`; otherwise
	 * hands the (possibly null) installed version to `$on_unsatisfied` so the
	 * caller can render the admin notice.
	 *
	 * @param string|null $installed_version Substrate version, or null if absent.
	 * @param bool         $has_required_apis Whether every probed API is present.
	 * @param callable     $on_ready          Runs the substrate-dependent wiring.
	 * @param callable     $on_unsatisfied    Receives the installed version (string|null).
	 */
	public static function boot( ?string $installed_version, bool $has_required_apis, callable $on_ready, callable $on_unsatisfied ): void {
		if ( self::satisfied( $installed_version, self::MINIMUM_NODES_VERSION, $has_required_apis ) ) {
			$on_ready();
			return;
		}
		$on_unsatisfied( $installed_version );
	}

	/**
	 * Does the loaded substrate expose every symbol ELN's bootstrap calls?
	 *
	 * Probes the load-bearing, newest-API subset the deferred loader invokes:
	 * `Topology_Registry::register_plugin` (the one-call registration API an
	 * old substrate most plausibly lacks), `Core::register_config_namespace`
	 * (the `<eln:…>` token resolver), `Service_CI_Node` (base class of the
	 * `App\*_CI_Node` service CIs mounted on `request_graph_ready`), and
	 * `Command_Interpreter_Node` (its `register_namespace` + `make_node`).
	 *
	 * @return bool True iff all probed substrate symbols are present.
	 */
	public static function required_apis_present(): bool {
		// Iterate const-array specs (not inline literals) so PHPStan can't constant-fold these runtime probes to always-true.
		foreach ( self::REQUIRED_CLASSES as $class ) {
			if ( ! \class_exists( $class ) ) {
				return false;
			}
		}
		foreach ( self::REQUIRED_METHODS as [ $class, $method ] ) {
			if ( ! \method_exists( $class, $method ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Substrate classes the deferred loader instantiates or extends.
	 * `Service_CI_Node` is the base of the `App\*_CI_Node` service CIs;
	 * `Command_Interpreter_Node` backs `register_namespace` + `make_node`.
	 */
	private const REQUIRED_CLASSES = [
		'\Newspack_Nodes\Topology_Registry',
		'\Newspack_Nodes\Core',
		'\Newspack_Nodes\Service_CI_Node',
		'\Newspack_Nodes\Command_Interpreter_Node',
	];

	/**
	 * Newest substrate methods the deferred loader calls — the ones an old
	 * substrate most plausibly lacks. `register_plugin` is the one-call
	 * topology registration API; `register_config_namespace` resolves the
	 * `<eln:…>` tokens.
	 */
	private const REQUIRED_METHODS = [
		[ '\Newspack_Nodes\Topology_Registry', 'register_plugin' ],
		[ '\Newspack_Nodes\Core', 'register_config_namespace' ],
	];
}
