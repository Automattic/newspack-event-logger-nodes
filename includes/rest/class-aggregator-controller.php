<?php
/**
 * AggregatorController: status/servers/health for the hub-side aggregator.
 *
 * Models event-aggregator/v1/* from the legacy plugin under the new
 * newspack-nodes-aggregator/v1 namespace. `/status` returns the real
 * per-server memcache-backed partition snapshot; `/servers` lists the
 * registered servers (via ServerRegistry); `/health` reports a simple
 * healthy flag.
 *
 * Note: the M4 dashboard cutover (commit 1350303) migrated
 * `AggregatorStatus.js` to dispatch `aggregator.status` via the unified
 * `/newspack-nodes/v1/command` endpoint — `Aggregator_CI.status` is the
 * canonical implementation. This controller's `/status` route is the
 * stand-alone REST shim preserved for any non-dashboard callers; the
 * body is held in parity with the CI verb (same memcache keys, same
 * 1–16 partition clamp, same per-server shape).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Nodes\Config as RuntimeConfig;

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
	 * GET /status — per-server partition snapshot from memcache.
	 *
	 * For each enabled spoke, looks up `aggregator_status:{id}:p{N}` from
	 * the shared cache (one entry per partition the StreamMerger pulls
	 * from). Cache misses default to an empty array, never null. The
	 * partition count is clamped to 1..16 to bound the cache fan-out
	 * regardless of how `num_partitions` is configured.
	 *
	 * Held in parity with `Aggregator_CI::status` (the dashboard's
	 * current path) so any non-dashboard caller still hitting this REST
	 * shim sees the same shape.
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();
		$servers = $registry->get_all();

		$config         = RuntimeConfig::load_config();
		$num_partitions = \min( 16, \max( 1, (int) ( $config['num_partitions'] ?? 1 ) ) );

		$cache  = self::cache();
		$result = [];

		foreach ( $servers as $id => $server ) {
			if ( ! \is_array( $server ) ) {
				continue;
			}
			$partitions = [];
			for ( $p = 0; $p < $num_partitions; $p++ ) {
				$val              = $cache->get( "aggregator_status:{$id}:p{$p}" );
				$partitions[ $p ] = \is_array( $val ) ? $val : [];
			}

			$result[ $id ] = [
				'id'         => $id,
				'url'        => isset( $server['url'] ) ? \esc_url_raw( (string) $server['url'] ) : '',
				'enabled'    => $server['enabled'] ?? true,
				'partitions' => $partitions,
			];
		}

		return new \WP_REST_Response( $result, 200 );
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
