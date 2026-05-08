<?php
/**
 * AggregatorController: status/servers/health for the hub-side aggregator.
 *
 * Models event-aggregator/v1/* from the legacy plugin under the new
 * newspack-nodes-aggregator/v1 namespace. The `/status` route delegates
 * to the dedicated `AggregatorStatusController` for the real per-server
 * memcache-backed status; `/servers` lists the registered servers (via
 * ServerRegistry); `/health` reports a simple healthy flag.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\ServerRegistry;

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

	/**
	 * GET /status — delegates to AggregatorStatusController for the real
	 * memcache-backed per-server partition status.
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		return ( new AggregatorStatusController() )->get_status( $request );
	}

	/**
	 * GET /servers — list registered remote-spoke ids + URL + enabled flag.
	 * Credentials are masked; the React tree only needs id/url/enabled to render.
	 */
	public function list_servers( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();

		$servers = [];
		foreach ( $registry->get_all() as $id => $cfg ) {
			$servers[] = [
				'id'              => $id,
				'url'             => (string) ( $cfg['url'] ?? '' ),
				'enabled'         => (bool) ( $cfg['enabled'] ?? false ),
				'logs'            => $cfg['logs'] ?? [],
				'has_credentials' => ! empty( $cfg['auth_username'] ) && ! empty( $cfg['auth_password'] ),
				'is_config'       => $registry->is_config_server( (string) $id ),
			];
		}

		return new \WP_REST_Response( $servers, 200 );
	}

	/**
	 * GET /health — simple liveness probe. Reports cache reachability so the
	 * dashboard can flag a memcache-down state without a separate endpoint.
	 */
	public function health( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'healthy'   => true,
				'cache'     => self::cache()->is_available(),
				'timestamp' => \time(),
			],
			200
		);
	}

}
