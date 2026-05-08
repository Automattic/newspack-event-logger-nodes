<?php
/**
 * PerformanceController: GET /performance/dashboard, /performance/timing.
 *
 * Plumbing for the `performance-dashboards` React tree. Stub responses
 * preserve the shape that PerformanceDashboard.js expects (a `data` block
 * + `meta`) so the tree can mount without 404s.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class PerformanceController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/dashboard',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_dashboard' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/performance/timing',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_timing' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_dashboard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		return new \WP_REST_Response(
			[
				'data' => [
					'overview' => [],
					'urls'     => [],
				],
				'meta' => [ 'stub' => true ],
			],
			200
		);
	}

	public function get_timing( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
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
