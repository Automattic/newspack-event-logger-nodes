<?php
/**
 * Discovery_CI: command-dispatch for the discovery surface.
 *
 * Replaces legacy class-discovery-controller.php with a CommandInterpreter
 * that mounts at priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   get — return registered_hooks + custom_events for this spoke.
 *
 * Reads `log_events` / `custom_events` from the substrate Config and
 * filters custom event names out of registered_hooks to match the legacy
 * payload exactly. No service dependencies — the substrate Config is a
 * global accessed directly, matching the legacy controller.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Config as RuntimeConfig;

\defined( 'ABSPATH' ) || exit;

class Discovery_CI extends CommandInterpreter {

	public function __construct() {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors Workers_CI / RequestBuilder
		// / FlameBuilder, which extend Node and also skip the parent call.
		$this->commands( [
			'get' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): array {
				$config           = RuntimeConfig::load_config();
				$registered_hooks = self::extract_string_list( $config['log_events']    ?? [] );
				$custom_events    = self::extract_string_list( $config['custom_events'] ?? [] );

				// Filter custom event names out of registered_hooks to prevent
				// cross-contamination (matches legacy DiscoveryController behavior).
				if ( ! empty( $custom_events ) ) {
					$custom_set       = \array_flip( $custom_events );
					$registered_hooks = \array_values( \array_filter( $registered_hooks, static fn ( $h ) => ! isset( $custom_set[ $h ] ) ) );
				}

				return [
					'registered_hooks' => $registered_hooks,
					'custom_events'    => $custom_events,
				];
			},
		] );
	}

	/**
	 * Pull a flat de-duplicated string list out of either an indexed-string
	 * array or an `assoc[name => true]` shape. Lift of the legacy
	 * `DiscoveryController::extract_string_list` private helper.
	 *
	 * @param mixed $value Raw config value.
	 * @return array<int,string>
	 */
	private static function extract_string_list( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $key => $entry ) {
			if ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) {
				$out[] = $key;
			} elseif ( \is_string( $entry ) && '' !== $entry ) {
				$out[] = $entry;
			}
		}
		return \array_values( \array_unique( $out ) );
	}
}
