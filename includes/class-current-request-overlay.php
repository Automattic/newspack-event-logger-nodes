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
	 * ELN admin pages that EMBED the debug overlay (so the tab must load there
	 * too). The hub page is handled separately by the substrate's
	 * `devtools_tab_bundles` filter; these are ELN's own pages where the overlay
	 * is mounted directly (RequestStreamPage / GyroscopePage / performance-dashboards).
	 *
	 * @var string[]
	 */
	private const OVERLAY_PAGES = [
		'newspack-nodes-performance',
		'newspack-nodes-errors',
		'newspack-nodes-gyroscope',
		'newspack-nodes-stream',
	];

	/**
	 * Hook the substrate's tab-bundle filter (hub) + our own enqueue on the ELN
	 * pages that embed the overlay + the per-request data injection.
	 */
	public static function init(): void {
		\add_filter( 'newspack_nodes/devtools_tab_bundles', [ self::class, 'register_bundle' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_on_overlay_pages' ] );
		// Priority 20: after both enqueue paths (the substrate filter on the hub,
		// our enqueue on the ELN pages) have run, so wp_add_inline_script binds.
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_inline_data' ], 20 );
	}

	/**
	 * Whether `$page` is a page that embeds the overlay — the UNION of ELN's own
	 * defaults and the substrate's `devtools_overlay_pages` registry (so any
	 * plugin's overlay page, e.g. the AI Newsletter's, gets the Request tab).
	 *
	 * @param string $page The `?page=` admin slug.
	 * @return bool
	 */
	public static function is_overlay_page( string $page ): bool {
		return \in_array( $page, self::overlay_pages(), true );
	}

	/**
	 * The overlay-page set: ELN's own {@see OVERLAY_PAGES} merged with the slugs
	 * other plugins contribute via the substrate's `devtools_overlay_pages`
	 * registry.
	 *
	 * @return string[]
	 */
	private static function overlay_pages(): array {
		$extra = \class_exists( '\Newspack_Nodes\Admin\Admin' ) ? \Newspack_Nodes\Admin\Admin::devtools_overlay_pages() : [];
		return \array_values( \array_unique( \array_merge( self::OVERLAY_PAGES, $extra ) ) );
	}

	/**
	 * Enqueue the tab bundle on the ELN pages that embed the overlay (the hub is
	 * the substrate filter's job). Same window-singleton tab registry, so the tab
	 * shows up beside the substrate's overlay tabs.
	 */
	public static function enqueue_on_overlay_pages(): void {
		if ( ! \function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin-page dispatch.
		$page = isset( $_GET['page'] ) && \is_string( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : '';
		if ( ! self::is_overlay_page( $page ) ) {
			return;
		}
		$dir   = \NEWSPACK_EVENT_LOGGER_NODES_DIR . 'build/current-request';
		$url   = \NEWSPACK_EVENT_LOGGER_NODES_URL . 'build/current-request';
		$asset = $dir . '/index.asset.php';
		// The asset file is a require'd PHP array of unknown shape — guard each
		// field rather than trust it.
		/** @var array<string,mixed> $meta */
		$meta = \file_exists( $asset ) ? (array) require $asset : [];
		$deps = isset( $meta['dependencies'] ) && \is_array( $meta['dependencies'] )
			? \array_values( \array_filter( $meta['dependencies'], '\is_string' ) )
			: [];
		$ver  = isset( $meta['version'] ) && \is_string( $meta['version'] ) ? $meta['version'] : \NEWSPACK_EVENT_LOGGER_NODES_VERSION;
		\wp_enqueue_script( self::HANDLE, "{$url}/index.js", $deps, $ver, true );
		if ( \file_exists( "{$dir}/index.css" ) ) {
			// Cache-bust on the stylesheet's own content hash, not $ver (the JS bundle
			// hash) — a SCSS-only rebuild leaves $ver unchanged, serving stale CSS.
			// Mirrors Admin::enqueue_react_page.
			$style_ver = \md5_file( "{$dir}/index.css" ) ?: $ver;
			\wp_enqueue_style( self::HANDLE, "{$url}/index.css", [], $style_ver );
		}
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
