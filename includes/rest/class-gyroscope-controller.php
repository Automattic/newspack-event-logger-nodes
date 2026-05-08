<?php
/**
 * GyroscopeController: GET /gyroscope/timeline?request_id=...
 *
 * Plumbing for the `performance-gyroscope` React tree. The legacy plugin
 * exposed an SSE stream here; this controller is the synchronous timeline
 * fetch (used when a client wants a snapshot for an explicit request id).
 * SSE infrastructure lands when SSEControllerBase is ported.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class GyroscopeController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/gyroscope/timeline',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_timeline' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'request_id' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function get_timeline( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		$rid = (string) ( $request->get_param( 'request_id' ) ?? '' );
		if ( $rid === '' ) {
			// Without a request id, the client just wants the empty initial shape.
			return new \WP_REST_Response(
				[
					'data' => [ 'events' => [] ],
					'meta' => [ 'stub' => true ],
				],
				200
			);
		}
		return new \WP_REST_Response(
			[
				'data' => [
					'request_id' => $rid,
					'events'     => [],
				],
				'meta' => [ 'stub' => true ],
			],
			200
		);
	}
}
