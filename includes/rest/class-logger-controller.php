<?php
/**
 * LoggerController: GET /logger/config, GET /logger/hooks.
 *
 * Plumbing for the `performance-logger` settings tree. Stub responses
 * mirror the legacy /perf-logger/v1/{settings,hooks} payloads so the
 * settings UI mounts without 404s. Settings persistence lands once
 * SettingsSync is ported into the application.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class LoggerController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/logger/config',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_config' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/logger/hooks',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_hooks' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_config( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		// Echo runtime config + stub flag — the React tree just needs a
		// well-shaped payload to mount.
		return new \WP_REST_Response(
			[
				'data' => self::load_config(),
				'meta' => [ 'stub' => true ],
			],
			200
		);
	}

	public function list_hooks( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		return new \WP_REST_Response(
			[
				'data' => [
					'hooks'     => [],
					'categories' => [],
				],
				'meta' => [ 'stub' => true ],
			],
			200
		);
	}
}
