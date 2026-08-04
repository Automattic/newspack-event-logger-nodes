<?php
/**
 * Current-Request overlay tab — server glue.
 *
 * The tab itself is JS (`src/current-request`), which registers into the
 * substrate's window-singleton devtools registry. This class does the three
 * server-side jobs that bundle needs: it contributes the bundle descriptor to
 * the substrate's `newspack_nodes/devtools_tab_bundles` filter (which loads it
 * on the Nodes hub page), enqueues it directly on the admin pages that mount
 * `<DebugOverlay>` themselves, and injects THIS request's id and partition into
 * a JS global the tab reads. ELN owns all of it because ELN owns the request
 * lifecycle — `Log_Manager` is what mints the id.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

/**
 * Wires the Current-Request overlay tab's bundle + per-request data exposure.
 *
 * Every member is static: this is hook glue with no state of its own, so
 * {@see init()} registers the callbacks and nothing is ever instantiated.
 */
class Current_Request_Overlay {

	/** Script handle for the tab bundle; also its style and inline-data anchor. */
	private const HANDLE = 'newspack-eln-current-request';

	/**
	 * ELN admin pages that mount `<DebugOverlay>` themselves, and so must load
	 * the tab bundle themselves. The substrate's `devtools_tab_bundles` filter
	 * covers the Nodes hub page and nothing else. All four ELN dashboard trees
	 * render the overlay — overview, error-log, gyroscope, requests; the settings
	 * page does not, and is absent.
	 *
	 * @var string[]
	 */
	private const OVERLAY_PAGES = [
		'event-logger-overview',
		'event-logger-errors',
		'event-logger-gyroscope',
		'event-logger-requests',
	];

	/**
	 * Enqueue the tab bundle on an overlay page this plugin is responsible for —
	 * the hub is the substrate filter's job. Every bundle registers into the same
	 * window-singleton tab registry, so the Request tab lands beside the
	 * substrate's overlay tabs rather than in a second tab bar.
	 *
	 * Dependencies and version come from the build's `index.asset.php` manifest
	 * (`{ dependencies, version }`), falling back to the plugin version when the
	 * bundle was never built. The stylesheet cache-busts on its OWN content hash,
	 * so a SCSS-only rebuild still lands behind a fresh `?ver=`.
	 *
	 * Hooked to `admin_enqueue_scripts`; returns silently off an overlay page.
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
		// require'd array of unknown shape — guard fields, don't trust it.
		/** @var array<string,mixed> $meta */
		$meta = \file_exists( $asset ) ? (array) require $asset : [];
		$deps = isset( $meta['dependencies'] ) && \is_array( $meta['dependencies'] )
			? \array_values( \array_filter( $meta['dependencies'], '\is_string' ) )
			: [];
		$ver  = isset( $meta['version'] ) && \is_string( $meta['version'] ) ? $meta['version'] : \NEWSPACK_EVENT_LOGGER_NODES_VERSION;
		\wp_enqueue_script( self::HANDLE, "{$url}/index.js", $deps, $ver, true );
		if ( \file_exists( "{$dir}/index.css" ) ) {
			$style_ver = \Newspack_Nodes\Admin\Admin::css_cache_version( "{$dir}/index.css", $ver );
			\wp_enqueue_style(
				self::HANDLE,
				"{$url}/index.css",
				[ 'wp-components', 'newspack-nodes-ui' ],
				$style_ver
			);
		}
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
	 * Inject this request's id, partition, and performance-dashboard URL into the
	 * tab's global — but only once the handle is actually enqueued on this page,
	 * by either path (the substrate's filter on the hub, ours everywhere else).
	 * A page that never loaded the bundle gets no global and no inline script.
	 *
	 * `Log_Manager` leaves the request id empty when the request went unlogged —
	 * logging disabled, running as root, or no matching `log` rule — and the tab
	 * renders its empty state from that.
	 *
	 * Hooked to `admin_enqueue_scripts` at priority 20.
	 */
	public static function enqueue_inline_data(): void {
		if ( ! \function_exists( 'wp_script_is' ) || ! \wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$log      = Log_Manager::instance();
		$rid      = $log->get_request_id();
		$perf_url = \admin_url( 'admin.php?page=event-logger-overview' );
		\wp_add_inline_script(
			self::HANDLE,
			self::inline_data_js( $rid, $log->get_partition(), $perf_url ),
			'before'
		);
	}

	/**
	 * Build the inline script that sets the tab's data on a DISTINCT global —
	 * `window.NewspackEventLoggerNodes.currentRequest`, not the shared
	 * `NewspackNodesData`, which every bundle localizes and would overwrite at
	 * render time. The `Object.assign` merge preserves any sibling key another
	 * ELN bundle already put on that global.
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

	/**
	 * Hook the substrate's tab-bundle filter (hub) + our own enqueue on the ELN
	 * pages that embed the overlay + the per-request data injection.
	 *
	 * Called from the plugin's deferred bootstrap, admin requests only.
	 */
	public static function init(): void {
		\add_filter( 'newspack_nodes/devtools_tab_bundles', [ self::class, 'register_bundle' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_on_overlay_pages' ] );
		// Priority 20: after both enqueue paths, so wp_add_inline_script binds.
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_inline_data' ], 20 );
	}

	/**
	 * Append our bundle descriptor for the substrate to enqueue on the hub page.
	 *
	 * The descriptor carries no `lazy` flag, so the hub ships the tab up front
	 * rather than on tab-click — {@see enqueue_inline_data()} depends on the
	 * handle being enqueued by the time it runs at priority 20.
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
}
