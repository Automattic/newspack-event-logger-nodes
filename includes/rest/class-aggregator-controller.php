<?php
/**
 * AggregatorController: status/servers/health for the hub-side aggregator.
 *
 * Models event-aggregator/v1/* from the legacy plugin under the new
 * newspack-nodes-aggregator/v1 namespace. Returns stub shapes so the
 * `event-aggregator` React tree can mount and load without 404s; real
 * data wiring lands when StreamMerger and ServerRegistry are integrated.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class AggregatorController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes-aggregator/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/servers',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_servers' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/health',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'health' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		return new \WP_REST_Response(
			[
				'data' => [],
				'meta' => [ 'stub' => true, 'namespace' => self::NAMESPACE ],
			],
			200
		);
	}

	public function list_servers( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		return new \WP_REST_Response(
			[
				'data' => [],
				'meta' => [ 'stub' => true, 'namespace' => self::NAMESPACE ],
			],
			200
		);
	}

	public function health( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'data' => [ 'healthy' => true ],
				'meta' => [ 'stub' => true ],
			],
			200
		);
	}
}
