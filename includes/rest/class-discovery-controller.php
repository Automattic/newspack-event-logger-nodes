<?php
/**
 * DiscoveryController: GET /discovery
 *
 * Returns a snapshot of the runtime's discovery surface — the list of
 * registered hooks, custom events, and reader-lag in bytes. Used by hub
 * aggregators (test_connection, sync_all_settings) and admin dashboards
 * to verify that a remote spoke is actually wired up and current.
 *
 * Lift-adapt of `Newspack_Event_Logger\REST\DiscoveryController`. Reads
 * `registered_hooks` / `custom_events` from the local Config schema and
 * computes lag by comparing each registered reader's saved cursor offset
 * against the current write position of its input log.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Partition;

class DiscoveryController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/discovery',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_discovery' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_discovery( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$config = self::load_config();

		$registered_hooks = $this->extract_string_list( $config['log_events'] ?? [] );
		$custom_events    = $this->extract_string_list( $config['custom_events'] ?? [] );

		// Filter custom event names out of registered_hooks (prevent cross-contamination).
		if ( ! empty( $custom_events ) ) {
			$custom_set       = \array_flip( $custom_events );
			$registered_hooks = \array_values( \array_filter( $registered_hooks, static fn ( $h ) => ! isset( $custom_set[ $h ] ) ) );
		}

		$response = [
			'registered_hooks' => $registered_hooks,
			'custom_events'    => $custom_events,
		];

		$lag = $this->calculate_lag( $config );
		if ( null !== $lag ) {
			$response['lag'] = $lag;
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Pull a flat de-duplicated string list out of either an indexed-string
	 * array or an `assoc[name => true]` shape.
	 *
	 * @param mixed $value Raw config value.
	 * @return array<int,string>
	 */
	private function extract_string_list( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $key => $entry ) {
			if ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) {
				$out[] = $key;
			} elseif ( \is_string( $entry ) && '' !== $entry ) {
				$out[] = $entry;
			}
		}
		return \array_values( \array_unique( $out ) );
	}

	/**
	 * Compute max lag (bytes) across registered log readers. Readers expose
	 * `inputs` and a saved-position lookup via the
	 * `newspack_event_logger_nodes/log_reader_positions` filter (return shape:
	 * `[ name => [ input_log => [ 'seg' => int, 'off' => int ] ] ]`).
	 *
	 * Returns null if we have no information (no readers registered or position
	 * filter unavailable) — caller omits the `lag` key from the response in
	 * that case rather than reporting a misleading zero.
	 *
	 * @param array<string,mixed> $config Loaded config (avoids the second load).
	 */
	private function calculate_lag( array $config ): ?int {
		try {
			$readers = [];
			if ( \function_exists( 'apply_filters' ) ) {
				$readers = (array) \apply_filters( 'newspack_event_logger_nodes/log_readers', [] );
			}
			if ( empty( $readers ) ) {
				return null;
			}

			$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
			$segment_size   = (int) ( $config['segment_size'] ?? ( 64 * 1024 * 1024 ) );
			$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
			$log_base       = $base_dir . '/logs';

			$positions = [];
			if ( \function_exists( 'apply_filters' ) ) {
				$positions = (array) \apply_filters( 'newspack_event_logger_nodes/log_reader_positions', [] );
			}

			$max_lag = 0;
			foreach ( $readers as $name => $reader_config ) {
				$inputs = $reader_config['inputs'] ?? [];
				if ( ! \is_array( $inputs ) || empty( $inputs ) ) {
					continue;
				}
				$input_log = (string) $inputs[0];
				if ( '' === $input_log ) {
					continue;
				}

				for ( $p = 0; $p < $num_partitions; $p++ ) {
					$partition = new Partition( "{$log_base}/{$input_log}", $p );
					$segments  = $partition->get_segments();
					if ( empty( $segments ) ) {
						continue;
					}
					$write_pos = $partition->get_current_position();

					$pos        = $positions[ $name ][ $p ][ $input_log ] ?? null;
					$reader_seg = (int) ( $pos['seg'] ?? 0 );
					$reader_off = (int) ( $pos['off'] ?? 0 );

					$lag = $this->calculate_position_difference(
						$write_pos['segment_id'] ?? 0,
						$write_pos['offset'] ?? 0,
						$reader_seg,
						$reader_off,
						$segment_size
					);
					if ( $lag > $max_lag ) {
						$max_lag = $lag;
					}
				}
			}
			return $max_lag;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Bytes-behind helper: 0 if reader is at/beyond writer; otherwise the sum
	 * of (remaining-in-reader-segment + full-segments-between + bytes-in-write-segment).
	 */
	private function calculate_position_difference(
		int $write_seg,
		int $write_off,
		int $reader_seg,
		int $reader_off,
		int $segment_size
	): int {
		if ( $write_seg === $reader_seg ) {
			return \max( 0, $write_off - $reader_off );
		}
		if ( $write_seg < $reader_seg ) {
			return 0;
		}
		$remaining_in_reader = $segment_size - $reader_off;
		$full_between        = \max( 0, $write_seg - $reader_seg - 1 );
		return $remaining_in_reader + ( $full_between * $segment_size ) + $write_off;
	}
}
