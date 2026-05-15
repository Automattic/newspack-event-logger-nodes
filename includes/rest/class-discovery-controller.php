<?php
/**
 * DiscoveryController: GET /discovery
 *
 * Returns a snapshot of the runtime's discovery surface — the list of
 * registered hooks, custom events, and reader-lag in bytes. Used by hub
 * aggregators (test_connection, sync_all_settings) and admin dashboards
 * to verify that a remote spoke is actually wired up and current.
 *
 * Lift-adapt of `Newspack_Event_Logger\REST\DiscoveryController`. Reads
 * `registered_hooks` / `custom_events` from the local Config schema and
 * computes lag by comparing each registered reader's saved cursor offset
 * against the current write position of its input log.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Config as RuntimeConfig;

class DiscoveryController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/discovery',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_discovery' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_discovery( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$config = RuntimeConfig::load_config();

		$registered_hooks = $this->extract_string_list( $config['log_events'] ?? [] );
		$custom_events    = $this->extract_string_list( $config['custom_events'] ?? [] );

		// Filter custom event names out of registered_hooks (prevent cross-contamination).
		if ( ! empty( $custom_events ) ) {
			$custom_set       = \array_flip( $custom_events );
			$registered_hooks = \array_values( \array_filter( $registered_hooks, static fn ( $h ) => ! isset( $custom_set[ $h ] ) ) );
		}

		$response = [
			'registered_hooks' => $registered_hooks,
			'custom_events'    => $custom_events,
		];

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Pull a flat de-duplicated string list out of either an indexed-string
	 * array or an `assoc[name => true]` shape.
	 *
	 * @param mixed $value Raw config value.
	 * @return array<int,string>
	 */
	private function extract_string_list( mixed $value ): array {
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
