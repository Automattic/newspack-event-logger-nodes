<?php
/**
 * PerfHooksController:
 *   GET /performance/registered-hooks  — categorized list of registered hooks.
 *   GET /performance/hook-categories   — categories + colors + merged config.
 *
 * Models the legacy `Newspack_Performance_Logger\REST\HooksController`. Reads
 * categorization from the local `HookCategorizer` (port of the upstream).
 * Rate-limited 60/60s — both endpoints sweep $wp_filter, which can be costly
 * on plugin-rich sites.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\HookCategorizer;

class PerfHooksController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/registered-hooks',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_registered_hooks' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/performance/hook-categories',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_hook_categories' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_registered_hooks( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\HookCategorizer' ) ) {
			return new \WP_REST_Response(
				[
					'total_hooks'       => 0,
					'categories'        => [],
					'hooks_by_category' => [],
				],
				200
			);
		}

		$by_category = HookCategorizer::get_registered_hooks_by_category();
		$categories  = HookCategorizer::get_categories();
		$total       = 0;
		foreach ( $by_category as $list ) {
			$total += \is_array( $list ) ? \count( $list ) : 0;
		}

		return new \WP_REST_Response(
			[
				'total_hooks'       => $total,
				'categories'        => $categories,
				'hooks_by_category' => $by_category,
			],
			200
		);
	}

	public function get_hook_categories( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\HookCategorizer' ) ) {
			return new \WP_REST_Response(
				[
					'categories' => [],
					'config'     => [],
				],
				200
			);
		}

		return new \WP_REST_Response(
			[
				'categories' => HookCategorizer::get_categories(),
				'config'     => HookCategorizer::get_merged_config(),
			],
			200
		);
	}
}
