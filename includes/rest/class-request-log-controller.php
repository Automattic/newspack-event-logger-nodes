<?php
/**
 * RequestLogController: GET /request-log/list, GET /request-log/detail/{id}.
 *
 * Plumbing for the `performance-request-log` React tree. The legacy
 * /perf-logger/v1/requests/* endpoints map onto these. Detail lookup
 * returns 404 via the shared not_found_error() helper when the id is
 * unknown — keeps the empty-state shape consistent.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class RequestLogController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/request-log/list',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_list' ],
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
			'/request-log/detail/(?P<id>[A-Za-z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_detail' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function get_list( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
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

	public function get_detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		$rid = (string) ( $request->get_param( 'id' ) ?? '' );
		if ( $rid === '' ) {
			return $this->not_found_error( 'request id missing' );
		}
		// Stub: real implementation queries the firehose index per partition.
		return new \WP_REST_Response(
			[
				'data' => [
					'request_id' => $rid,
					'entries'    => [],
				],
				'meta' => [ 'stub' => true ],
			],
			200
		);
	}
}
