<?php
/**
 * Current-Request overlay tab — server glue.
 *
 * Registers ELN's `current-request` bundle on the substrate's
 * `newspack_nodes/devtools_tab_bundles` filter (so it loads wherever the debug
 * overlay does) and injects THIS request's id into a distinct JS global the tab
 * reads. ELN owns this because it owns the request lifecycle (`Log_Manager`).
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes;

/**
 * Wires the Current-Request overlay tab's bundle + per-request data exposure.
 */
class Current_Request_Overlay {

	/** Script handle for the overlay-tab bundle. */
	private const HANDLE = 'newspack-eln-current-request';

	/**
	 * Hook the substrate's tab-bundle filter + the per-request data injection.
	 */
	public static function init(): void {
		\add_filter( 'newspack_nodes/devtools_tab_bundles', [ self::class, 'register_bundle' ] );
		// Priority 20: after the substrate's enqueue_devtools_tab_bundles (default
		// 10) has enqueued our handle, so wp_add_inline_script has something to bind.
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_inline_data' ], 20 );
	}

	/**
	 * Append our bundle descriptor for the substrate to enqueue.
	 *
	 * @param array<int,array<string,mixed>> $bundles Existing descriptors.
	 * @return array<int,array<string,mixed>>
	 */
	public static function register_bundle( array $bundles ): array {
		$bundles[] = [
			'handle' => self::HANDLE,
			'dir'    => \NEWSPACK_EVENT_LOGGER_NODES_DIR . 'build/current-request',
			'url'    => \NEWSPACK_EVENT_LOGGER_NODES_URL . 'build/current-request',
		];
		return $bundles;
	}

	/**
	 * Inject the page's request id into the tab's global — but only once the
	 * substrate has actually enqueued our handle on this page.
	 */
	public static function enqueue_inline_data(): void {
		if ( ! \function_exists( 'wp_script_is' ) || ! \wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$log      = Log_Manager::instance();
		$rid      = $log->get_request_id();
		$perf_url = \admin_url( 'admin.php?page=newspack-nodes-performance' );
		\wp_add_inline_script(
			self::HANDLE,
			self::inline_data_js( $rid, $log->get_partition(), $perf_url ),
			'before'
		);
	}

	/**
	 * Build the inline script that sets the tab's data on a DISTINCT global —
	 * not the shared `NewspackNodesData`, which every bundle localizes and would
	 * overwrite at render time.
	 *
	 * @param string $rid       Current request id (empty when unlogged).
	 * @param int    $partition The partition this request's lines hash to (request_detail needs it).
	 * @param string $perf_url  Performance-dashboard base URL for the deep link.
	 * @return string Inline JS.
	 */
	public static function inline_data_js( string $rid, int $partition, string $perf_url ): string {
		$data = [
			'rid'       => $rid,
			'partition' => $partition,
			'perfUrl'   => $perf_url,
		];
		return 'window.NewspackEventLoggerNodes=Object.assign(window.NewspackEventLoggerNodes||{},{currentRequest:'
			. \wp_json_encode( $data )
			. '});';
	}
}
