<?php
/**
 * AggregatorStatusController: GET /newspack-nodes-aggregator/v1/status
 *
 * Returns hub-side aggregator status keyed by server id. For each enabled
 * spoke, looks up `aggregator_status:{id}:p{N}` from memcache (one entry
 * per partition the StreamMerger pulls from). Used by the `event-aggregator`
 * React tree.
 *
 * Lift-adapt of `Newspack_Event_Aggregator\REST\StatusController`. The
 * existing 3-route stub `AggregatorController` keeps `/servers` and
 * `/health` shape parity; this controller is the real `/status` body.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\ServerRegistry;

class AggregatorStatusController extends PerformanceControllerBase {
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
	}

	public function get_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();
		$servers  = $registry->get_all();

		$config         = self::load_config();
		$num_partitions = \min( 16, \max( 1, (int) ( $config['num_partitions'] ?? 1 ) ) );

		$cache  = $this->resolve_cache();
		$result = [];

		foreach ( $servers as $id => $server ) {
			if ( ! \is_array( $server ) ) {
				continue;
			}
			$partitions = [];
			for ( $p = 0; $p < $num_partitions; $p++ ) {
				$key = "aggregator_status:{$id}:p{$p}";
				$val = $cache->get( $key );
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

	private function resolve_cache(): Cache_Interface {
		return self::cache();
	}
}
