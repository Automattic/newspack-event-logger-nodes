<?php
/**
 * PerformanceController: GET /performance/dashboard, /performance/timing.
 *
 * Plumbing for the `performance-dashboards` React tree. Both endpoints
 * delegate to the dedicated dimension-specific controllers:
 *   - /performance/dashboard ⇒ PerfOverviewController body shape
 *     (overview + URL list).
 *   - /performance/timing    ⇒ time series merged across partitions.
 *
 * The dedicated overview/urls/requests controllers are independently
 * registered; this one keeps backward-compatible "data + meta" shape so
 * legacy clients still mount.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Config as RuntimeConfig;

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

		$overview_ctrl = new PerfOverviewController();
		$overview_resp = $overview_ctrl->get_overview( $request );
		if ( $overview_resp instanceof \WP_Error ) {
			return $overview_resp;
		}
		$overview = $overview_resp->get_data();

		$urls_ctrl = new PerfUrlsController();
		$urls_req  = new \WP_REST_Request();
		$urls_resp = $urls_ctrl->get_urls( $urls_req );
		$urls      = $urls_resp instanceof \WP_REST_Response ? $urls_resp->get_data() : [ 'data' => [] ];

		return new \WP_REST_Response(
			[
				'data' => [
					'overview' => $overview,
					'urls'     => $urls['data'] ?? [],
				],
				'meta' => [],
			],
			200
		);
	}

	public function get_timing( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$max_lifespan   = (int) ( $config['max_lifespan'] ?? 86400 );
		$cache          = $this->resolve_cache();

		$merged = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$store = new Stats_Store( $cache, $p, $max_lifespan );
			foreach ( $store->get_hourly() as $hour => $row ) {
				if ( ! isset( $merged[ $hour ] ) ) {
					$merged[ $hour ] = [
						'hour'        => $hour,
						'count'       => 0,
						'sum_ms'      => 0.0,
						'sum_peak_mb' => 0.0,
					];
				}
				$merged[ $hour ]['count']       += (int) ( $row['count'] ?? 0 );
				$merged[ $hour ]['sum_ms']      += (float) ( $row['sum_ms'] ?? 0 );
				$merged[ $hour ]['sum_peak_mb'] += (float) ( $row['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $merged );

		return new \WP_REST_Response(
			[
				'data' => [
					'time_series' => \array_values( $merged ),
				],
				'meta' => [],
			],
			200
		);
	}

	private function resolve_cache(): Cache_Interface {
		return self::cache();
	}
}
