<?php
/**
 * SettingsController: POST /settings — write one of the 4 core options.
 *
 * Whitelisted writes only: `num_partitions`, `num_segments`, `segment_size`,
 * `max_lifespan`. Used by hub-side aggregator fan-out (RemoteManager) when
 * pushing core settings down to spokes. Performance-tuning options are
 * handled by the sister `LoggerSettingsController` under
 * `/performance/settings`.
 *
 * Triggers `Config::reset()` after a successful update so the next request
 * (workers, dashboards, supervisor) sees the new value without restart.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Config;

class SettingsController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	/**
	 * Whitelist of WP option names this endpoint can write. Each entry
	 * carries its sanitization type. Sensitive infra options like
	 * `base_directory` are intentionally NOT writable here.
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED_OPTIONS = [
		'newspack_event_logger_nodes_num_partitions' => 'int',
		'newspack_event_logger_nodes_num_segments'   => 'int',
		'newspack_event_logger_nodes_segment_size'   => 'int',
		'newspack_event_logger_nodes_max_lifespan'   => 'int',
	];

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_setting' ],
				'permission_callback' => [ $this, 'update_permissions_check' ],
				'args'                => [
					'option' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ $this, 'validate_option_name' ],
					],
					'value'  => [
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Manage-options + valid REST nonce required. Mirrors the legacy
	 * permission posture; we don't allow anonymous writes.
	 */
	public function update_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! \function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to update settings.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function validate_option_name( mixed $option ): bool {
		return \is_string( $option ) && isset( self::ALLOWED_OPTIONS[ $option ] );
	}

	public function update_setting( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$option = (string) $request->get_param( 'option' );
		$value  = $request->get_param( 'value' );

		if ( ! isset( self::ALLOWED_OPTIONS[ $option ] ) ) {
			return new \WP_Error( 'invalid_option', "Unknown option: {$option}", [ 'status' => 400 ] );
		}

		$type      = self::ALLOWED_OPTIONS[ $option ];
		$sanitized = $this->sanitize_value( $value, $type, $option );
		if ( null === $sanitized ) {
			return new \WP_Error(
				'invalid_value',
				'Invalid value for this option type.',
				[ 'status' => 400 ]
			);
		}

		$updated = \update_option( $option, $sanitized );

		// Reset Config cache so the new value is visible on the next read.
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}

		return new \WP_REST_Response(
			[
				'option'  => $option,
				'updated' => (bool) $updated,
			],
			200
		);
	}

	/**
	 * Type-coerce + bounds-check.
	 *
	 * @param mixed  $value  Raw input.
	 * @param string $type   Type hint from ALLOWED_OPTIONS.
	 * @param string $option Option name (used for per-option min override).
	 * @return mixed|null Sanitized value, or null if rejected.
	 */
	private function sanitize_value( mixed $value, string $type, string $option ): mixed {
		switch ( $type ) {
			case 'int':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$int = (int) $value;
				$min = ( 'newspack_event_logger_nodes_max_lifespan' === $option ) ? 0 : 1;
				if ( $int < $min || $int > 1073741824 ) {
					return null;
				}
				return $int;
			default:
				return null;
		}
	}
}
