<?php
/**
 * PerformanceControllerBase: shared base for REST controllers.
 *
 * Skeleton — full check_rate_limit / Config::load_config / not_found_error
 * deferred. Establishes the abstract pattern.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

abstract class PerformanceControllerBase {
	abstract public function register_routes(): void;

	public function read_permissions_check(): bool|\WP_Error {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', 'Insufficient permissions', [ 'status' => 403 ] );
		}
		return true;
	}

	public function validate_partition( int $partition, int $num_partitions ): int|\WP_Error {
		if ( $partition < 0 || $partition >= $num_partitions ) {
			return new \WP_Error(
				'invalid_partition',
				"Partition $partition out of range [0, $num_partitions)",
				[ 'status' => 400 ]
			);
		}
		return $partition;
	}
}
