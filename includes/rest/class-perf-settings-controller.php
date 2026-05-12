<?php
/**
 * PerfSettingsController: POST /performance/settings
 *
 * Writes one of the 9 performance-tuning options. Mirrors the legacy
 * `Newspack_Performance_Logger\REST\SettingsController`. Suppresses
 * `SettingsSync` fan-out around `update_option()` so applying a remotely-
 * synced setting on a spoke doesn't bounce back as a re-sync — same
 * polarity guard the hub uses on inbound payloads.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\SettingsSync;

class PerfSettingsController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	private const MAX_EVENTS = 10000;

	/**
	 * Whitelist of perf-tuning options. Names match the
	 * `SettingsSync::PERF_TUNING_OPTIONS` set so a hub→spoke fan-out lands
	 * at exactly the same option keys.
	 *
	 * @var array<string,string> option name → type.
	 */
	private const ALLOWED_OPTIONS = [
		'newspack_event_logger_nodes_log_urls'                    => 'array',
		'newspack_event_logger_nodes_skip_urls'                   => 'array',
		'newspack_event_logger_nodes_log_events'                  => 'array',
		'newspack_event_logger_nodes_custom_events'               => 'array',
		'newspack_event_logger_nodes_auto_disable_threshold'      => 'int',
		'newspack_event_logger_nodes_auto_protect_time_threshold' => 'float',
		'newspack_event_logger_nodes_significant_events'          => 'array',
		'newspack_event_logger_nodes_log_memory'                  => 'bool',
		'newspack_event_logger_nodes_flush_every_line'            => 'bool',
	];

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/settings',
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
		$sanitized = $this->sanitize_value( $value, $type );
		if ( null === $sanitized ) {
			return new \WP_Error(
				'invalid_value',
				'Invalid value for this option type.',
				[ 'status' => 400 ]
			);
		}

		// Suppress the static-mode SettingsSync fan-out so applying a remotely-
		// synced setting doesn't re-queue a sync back to the sender. The
		// finally clause guarantees we restore the flag even on update failure.
		SettingsSync::suppress_sync( true );
		try {
			// Non-autoload (third arg false): matches legacy and keeps the
			// options-cache footprint bounded — log_events / significant_events
			// in particular can carry hundreds of strings.
			$updated = \update_option( $option, $sanitized, false );
		} finally {
			SettingsSync::suppress_sync( false );
		}

		Config::reset();

		return new \WP_REST_Response(
			[
				'option'  => $option,
				'updated' => (bool) $updated,
			],
			200
		);
	}

	private function sanitize_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case 'int':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$int = (int) $value;
				if ( $int < 0 || $int > 1073741824 ) {
					return null;
				}
				return $int;
			case 'float':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$f = (float) $value;
				if ( $f < 0 || $f > 86400 ) {
					return null;
				}
				return $f;
			case 'bool':
				return (bool) $value;
			case 'array':
				if ( ! \is_array( $value ) ) {
					return null;
				}
				return $this->sanitize_array( $value );
			default:
				return null;
		}
	}

	/**
	 * Recursive array sanitizer. Bounded recursion + element count.
	 *
	 * @param array<mixed,mixed> $arr   Input array.
	 * @param int                $depth Current depth.
	 * @return array<mixed,mixed>|null Sanitized array, or null if too deep / large.
	 */
	private function sanitize_array( array $arr, int $depth = 0 ): ?array {
		if ( $depth > 5 ) {
			return null;
		}
		if ( \count( $arr ) > self::MAX_EVENTS ) {
			return null;
		}
		$out = [];
		foreach ( $arr as $key => $value ) {
			$safe_key = \is_int( $key ) ? $key : \sanitize_text_field( (string) $key );
			if ( \is_string( $value ) ) {
				$out[ $safe_key ] = \sanitize_text_field( $value );
			} elseif ( \is_bool( $value ) || \is_int( $value ) || \is_float( $value ) ) {
				$out[ $safe_key ] = $value;
			} elseif ( \is_array( $value ) ) {
				$nested = $this->sanitize_array( $value, $depth + 1 );
				if ( null === $nested ) {
					return null;
				}
				$out[ $safe_key ] = $nested;
			}
		}
		return $out;
	}
}
