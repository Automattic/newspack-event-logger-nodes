<?php
/**
 * PerfConfigController: GET /performance/config, POST /performance/config
 *
 * Bulk read and update of the 9 performance-tuning options used by the
 * `performance-logger` settings tree. The bulk POST is the path the
 * Settings UI uses on save (one round-trip vs. nine to /performance/settings).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Nodes\Config as RuntimeConfig;

class PerfConfigController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	/**
	 * Map response-param → option storage descriptor.
	 *
	 * @var array<string,array{option:string,type:string}>
	 */
	private const CONFIG_MAP = [
		'log_events'                  => [ 'option' => 'newspack_event_logger_nodes_log_events',                  'type' => 'array_assoc' ],
		'custom_events'               => [ 'option' => 'newspack_event_logger_nodes_custom_events',               'type' => 'array_bool' ],
		'log_urls'                    => [ 'option' => 'newspack_event_logger_nodes_log_urls',                    'type' => 'array_assoc' ],
		'skip_urls'                   => [ 'option' => 'newspack_event_logger_nodes_skip_urls',                   'type' => 'array_assoc' ],
		'auto_disable_threshold'      => [ 'option' => 'newspack_event_logger_nodes_auto_disable_threshold',      'type' => 'int' ],
		'auto_protect_time_threshold' => [ 'option' => 'newspack_event_logger_nodes_auto_protect_time_threshold', 'type' => 'float' ],
		'significant_events'          => [ 'option' => 'newspack_event_logger_nodes_significant_events',          'type' => 'array_assoc' ],
		'log_memory'                  => [ 'option' => 'newspack_event_logger_nodes_log_memory',                  'type' => 'bool' ],
		'flush_every_line'            => [ 'option' => 'newspack_event_logger_nodes_flush_every_line',            'type' => 'bool' ],
	];

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/config',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_config' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_config' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);
	}

	public function admin_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! \function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to access this resource.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_config( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$cfg = RuntimeConfig::load_config();

		$response_config = [
			'log_events'                  => $cfg['log_events'] ?? [],
			'custom_events'               => $cfg['custom_events'] ?? [],
			'log_urls'                    => $cfg['log_urls'] ?? [],
			'skip_urls'                   => $cfg['skip_urls'] ?? [],
			'auto_disable_threshold'      => (int) ( $cfg['auto_disable_threshold'] ?? 0 ),
			'auto_protect_time_threshold' => (float) ( $cfg['auto_protect_time_threshold'] ?? 0.0 ),
			'significant_events'          => $cfg['significant_events'] ?? [],
			'log_memory'                  => ! empty( $cfg['log_memory'] ),
			'flush_every_line'            => ! empty( $cfg['flush_every_line'] ),
		];

		return new \WP_REST_Response( [ 'config' => $response_config ], 200 );
	}

	public function update_config( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$updated = [];
		foreach ( self::CONFIG_MAP as $param => $cfg ) {
			$value = $request->get_param( $param );
			if ( null === $value ) {
				continue;
			}
			$option = $cfg['option'];

			switch ( $cfg['type'] ) {
				case 'array_assoc':
					if ( \is_array( $value ) ) {
						$flat = [];
						foreach ( $value as $k => $v ) {
							if ( \is_string( $v ) && '' !== $v ) {
								$flat[] = $v;
							} elseif ( \is_string( $k ) && '' !== $k ) {
								$flat[] = $k;
							}
						}
						$value = \array_values( \array_unique( $flat ) );
					}
					break;
				case 'array_bool':
					if ( \is_array( $value ) ) {
						$assoc = [];
						foreach ( $value as $k => $v ) {
							if ( \is_int( $k ) && \is_string( $v ) ) {
								$assoc[ $v ] = true;
							} elseif ( \is_string( $k ) && '' !== $k ) {
								$assoc[ $k ] = (bool) $v;
							}
						}
						$value = $assoc;
					}
					break;
				case 'int':
					$value = (int) $value;
					break;
				case 'float':
					$value = (float) $value;
					break;
				case 'bool':
					$value = (bool) $value;
					break;
			}

			\update_option( $option, $value );
			$updated[] = $param;
		}

		if ( ! empty( $updated ) ) {
			Config::reset();
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'updated' => $updated,
			],
			200
		);
	}
}
