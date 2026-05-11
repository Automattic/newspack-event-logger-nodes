<?php
/**
 * PerfHooksAvailableController:
 *   GET  /performance/hooks/available  — discover hooks via $wp_actions/$wp_filter.
 *   POST /performance/hooks/configure  — persist log_events + custom_events.
 *
 * Used by the performance-logger Settings UI to enumerate all hooks the WP
 * runtime has fired or registered, then toggle them on/off via the bulk
 * configure endpoint. Models the legacy
 * `Newspack_Performance_Logger\REST\DashboardController` /hooks routes.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\HookCategorizer;

class PerfHooksAvailableController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/hooks/available',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_available_hooks' ],
				'permission_callback' => [ $this, 'admin_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/performance/hooks/configure',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'configure_hooks' ],
				'permission_callback' => [ $this, 'admin_permissions_check' ],
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

	public function get_available_hooks( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;

		$hooks = [];

		if ( isset( $wp_actions ) && \is_array( $wp_actions ) ) {
			foreach ( $wp_actions as $hook_name => $count ) {
				if ( HookCategorizer::is_internal( (string) $hook_name ) ) {
					continue;
				}
				$hooks[ $hook_name ] = [
					'name'     => $hook_name,
					'category' => $this->categorize_hook( (string) $hook_name ),
					'count'    => (int) $count,
				];
			}
		}

		if ( isset( $wp_filter ) && ( \is_array( $wp_filter ) || $wp_filter instanceof \Traversable ) ) {
			foreach ( $wp_filter as $hook_name => $callbacks ) {
				if ( HookCategorizer::is_internal( (string) $hook_name ) ) {
					continue;
				}
				if ( ! isset( $hooks[ $hook_name ] ) ) {
					$hooks[ $hook_name ] = [
						'name'     => $hook_name,
						'category' => $this->categorize_hook( (string) $hook_name ),
						'count'    => 0,
					];
				}
			}
		}

		// Filter out custom events — they're managed via the custom-events tab.
		$cfg            = self::load_config();
		$custom_events  = $cfg['custom_events'] ?? [];
		if ( \is_array( $custom_events ) ) {
			foreach ( $custom_events as $key => $value ) {
				$name = ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) ? $key : $value;
				if ( \is_string( $name ) ) {
					unset( $hooks[ $name ] );
				}
			}
		}

		\ksort( $hooks );

		return new \WP_REST_Response(
			[
				'hooks' => \array_values( $hooks ),
			],
			200
		);
	}

	public function configure_hooks( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$hooks         = $request->get_param( 'hooks' );
		$custom_events = $request->get_param( 'custom_events' );
		$configured    = 0;

		if ( ! empty( $hooks ) && \is_array( $hooks ) ) {
			$flat = [];
			foreach ( $hooks as $h ) {
				if ( \is_string( $h ) && '' !== $h ) {
					$flat[] = \sanitize_text_field( $h );
				}
			}
			\update_option( 'newspack_event_logger_nodes_log_events', $flat );
			$configured += \count( $flat );
		}

		if ( ! empty( $custom_events ) && \is_array( $custom_events ) ) {
			$assoc = [];
			foreach ( $custom_events as $event ) {
				if ( \is_string( $event ) && '' !== $event ) {
					$assoc[ \sanitize_text_field( $event ) ] = true;
				}
			}
			\update_option( 'newspack_event_logger_nodes_custom_events', $assoc );
			$configured += \count( $assoc );
		}

		Config::reset();

		return new \WP_REST_Response(
			[
				'success'          => true,
				'hooks_configured' => $configured,
			],
			200
		);
	}

	private function categorize_hook( string $hook_name ): string {
		return HookCategorizer::categorize( $hook_name );
	}
}
