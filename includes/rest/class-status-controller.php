<?php
/**
 * StatusController: GET /newspack-nodes/v1/status
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

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
		return new \WP_REST_Response(
			[
				'status'  => 'ok',
				'version' => \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ? \NEWSPACK_EVENT_LOGGER_NODES_VERSION : 'unknown',
			],
			200
		);
	}
}
