<?php
/**
 * StatusController: GET /newspack-nodes/v1/status
 *
 * Health/version probe. Returns plugin version, runtime version, partition
 * count, hub-vs-spoke flag, and cache reachability — enough for an admin
 * dashboard to render a "is this thing alive?" surface without making a
 * dozen separate calls.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;

class StatusController extends PerformanceControllerBase {
	public function register_routes(): void {
		\register_rest_route(
			'newspack-nodes/v1',
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
		$config = self::load_config();

		$cache_available = false;
		try {
			$cache_available = $this->resolve_cache()->is_available();
		} catch ( \Throwable $e ) {
			// Leave cache_available=false; status endpoint never fails.
		}

		return new \WP_REST_Response(
			[
				'status'           => 'ok',
				'version'          => \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ? \NEWSPACK_EVENT_LOGGER_NODES_VERSION : 'unknown',
				'runtime_version'  => \defined( 'NEWSPACK_NODES_VERSION' ) ? \NEWSPACK_NODES_VERSION : 'unknown',
				'num_partitions'   => (int) ( $config['num_partitions'] ?? 1 ),
				'enable_workers'   => true === ( $config['enable_workers'] ?? false ), // Strict — same polarity as SettingsSync.
				'cache_available'  => $cache_available,
				'timestamp'        => \time(),
			],
			200
		);
	}

	private function resolve_cache(): Cache_Interface {
		return self::cache();
	}
}
