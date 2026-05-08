<?php
/**
 * LoggerController:
 *   GET /logger/config — runtime configuration snapshot.
 *   GET /logger/hooks  — registered hooks (categorized via HookCategorizer).
 *
 * Plumbing for the `performance-logger` settings tree. Returns
 * the same shape as the legacy /perf-logger/v1/{settings,hooks}
 * payloads. Settings persistence lives on `PerfSettingsController` /
 * `PerfConfigController` (POST endpoints).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\HookCategorizer;

class LoggerController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/logger/config',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_config' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/logger/hooks',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_hooks' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_config( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}
		// Echo the full filterable config so the React tree can populate the
		// settings UI from one endpoint. Sensitive values (memcache_servers
		// strings) are kept since they're already managed via WP options.
		return new \WP_REST_Response(
			[
				'data' => self::load_config(),
				'meta' => [],
			],
			200
		);
	}

	public function list_hooks( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$hooks      = [];
		$categories = [];

		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\HookCategorizer' ) ) {
			$by_category = HookCategorizer::get_registered_hooks_by_category();
			$categories  = HookCategorizer::get_categories();
			// Flatten by_category into a list of { name, category }.
			foreach ( $by_category as $cat => $list ) {
				if ( ! \is_array( $list ) ) {
					continue;
				}
				foreach ( $list as $name ) {
					if ( \is_string( $name ) ) {
						$hooks[] = [
							'name'     => $name,
							'category' => $cat,
						];
					}
				}
			}
		}

		return new \WP_REST_Response(
			[
				'data' => [
					'hooks'      => $hooks,
					'categories' => $categories,
				],
				'meta' => [],
			],
			200
		);
	}
}
