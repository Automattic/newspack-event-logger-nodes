<?php
/**
 * EventsController: GET /events/recent, /events/stats.
 *
 * Plumbing for the `event-dashboards` React tree. Stub bodies — real reads
 * land once Consumer-fed RawLogs and Worker dashboards are wired through
 * application nodes.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class EventsController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/events/recent',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_recent' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'limit' => [
						'default'           => 100,
						'sanitize_callback' => static fn ( $v ) => \max( 1, \min( 1000, (int) $v ) ),
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/events/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_recent( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		return new \WP_REST_Response(
			[
				'data' => [],
				'meta' => [ 'stub' => true, 'limit' => $request->get_param( 'limit' ) ],
			],
			200
		);
	}

	public function get_stats( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		return new \WP_REST_Response(
			[
				'data' => [ 'time_series' => [] ],
				'meta' => [ 'stub' => true ],
			],
			200
		);
	}
}
