<?php
/**
 * EnqueueDashboardsTest: the admin_enqueue_scripts dispatch closure now routes
 * its script + index.css + NewspackNodesData localize through the substrate's
 * shared Admin::enqueue_react_page() registrar, keeping every per-tree extra.
 *
 * Drives the captured admin_enqueue_scripts dispatch closure with a page slug
 * in $_GET, then asserts on the recording stubs. (The closure is captured at
 * file-load time because sibling tests clear $GLOBALS['_wp_actions'].)
 */

namespace {
	if ( ! \function_exists( 'wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ): void {
			$GLOBALS['_enqueued_styles'][] = $args;
		}
	}
	if ( ! \function_exists( 'wp_add_inline_script' ) ) {
		function wp_add_inline_script( ...$args ): bool {
			$GLOBALS['_inline_scripts'][] = $args;
			return true;
		}
	}
	if ( ! \function_exists( 'wp_script_is' ) ) {
		// Status-controllable stub: a test marks a handle "enqueued" by adding it
		// to $GLOBALS['_wp_test_enqueued_handles'].
		function wp_script_is( string $handle, string $list = 'enqueued' ): bool {
			return ! empty( $GLOBALS['_wp_test_enqueued_handles'][ $handle ] );
		}
	}
	if ( ! \function_exists( 'wp_style_add_data' ) ) {
		function wp_style_add_data( string $handle, string $key, $value ): bool {
			$GLOBALS['_style_data'][ $handle ][ $key ] = $value;
			return true;
		}
	}

	// Capture the dispatch closure at test-file-load time (after the plugin file
	// registered it at bootstrap, before any test clears $GLOBALS['_wp_actions']).
	// Other ELN tests reset that global, which would otherwise unregister the
	// closure and make `do_action` a no-op for our case.
	$GLOBALS['_eln_enqueue_dispatch'] = $GLOBALS['_wp_actions']['admin_enqueue_scripts'][0] ?? null;
}

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin {

	use Newspack_Event_Logger_Nodes\Current_Request_Overlay;
	use Newspack_Event_Logger_Nodes\Log_Manager;
	use Newspack_Event_Logger_Nodes\Tests\TestCase;
	use PHPUnit\Framework\Attributes\DataProvider;

	class EnqueueDashboardsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['_enqueued_scripts']  = [];
			$GLOBALS['_enqueued_styles']   = [];
			$GLOBALS['_localized_scripts'] = [];
			$GLOBALS['_inline_scripts']    = [];
			$GLOBALS['_style_data']        = [];
			$_GET = [];
		}

		/** Invoke the captured admin_enqueue_scripts dispatch closure. */
		private function dispatch( string $hook ): void {
			$cb = $GLOBALS['_eln_enqueue_dispatch'] ?? null;
			$this->assertIsCallable( $cb, 'admin_enqueue_scripts dispatch closure not captured at load time' );
			$cb( $hook );
		}

		/** Find the NewspackNodesData localize record for $handle. */
		private function localized_for( string $handle ): ?array {
			foreach ( $GLOBALS['_localized_scripts'] as $rec ) {
				if ( ( $rec[0] ?? '' ) === $handle && 'NewspackNodesData' === ( $rec[1] ?? '' ) ) {
					return \is_array( $rec[2] ?? null ) ? $rec[2] : [];
				}
			}
			return null;
		}

		/** Find the wp_enqueue_script record (positional stub) for $handle. */
		private function enqueued_script_for( string $handle ): ?array {
			foreach ( $GLOBALS['_enqueued_scripts'] as $rec ) {
				if ( ( $rec[0] ?? '' ) === $handle ) {
					return $rec;
				}
			}
			return null;
		}

		public function test_settings_page_enqueues_the_shared_field_reset_bundle(): void {
			$_GET['page'] = 'newspack-event-logger-nodes';
			$this->dispatch( 'settings_page_newspack-event-logger-nodes' );

			// The toggle and the sheet that paints it ride this head-time hook,
			// so the sheet prints in <head> rather than as a late style.
			$this->assertNotNull( $this->enqueued_script_for( 'newspack-nodes-field-reset' ) );
			$styles = \array_map( static fn ( $rec ) => $rec[0] ?? '', $GLOBALS['_enqueued_styles'] );
			$this->assertContains( 'newspack-nodes-ui', $styles );
		}

		public function test_dashboard_pages_carry_no_field_reset_bundle(): void {
			$_GET['page'] = 'event-logger-overview';
			$this->dispatch( 'toplevel_page_event-logger-overview' );

			$this->assertNull( $this->enqueued_script_for( 'newspack-nodes-field-reset' ) );
		}

		/** Find the wp_enqueue_style record (positional stub) for $handle. */
		private function enqueued_style_for( string $handle ): ?array {
			foreach ( $GLOBALS['_enqueued_styles'] as $rec ) {
				if ( ( $rec[0] ?? '' ) === $handle ) {
					return $rec;
				}
			}
			return null;
		}

		/** @return array<string,array{string,string}> Dashboard page and tree pairs. */
		public static function graph_dashboard_pages(): array {
			return [
				'overview'  => [ 'event-logger-overview', 'overview' ],
				'error log' => [ 'event-logger-errors', 'error-log' ],
				'gyroscope' => [ 'event-logger-gyroscope', 'gyroscope' ],
				'requests'  => [ 'event-logger-requests', 'requests' ],
			];
		}

		public function test_skips_unmapped_page(): void {
			$_GET = [ 'page' => 'totally-unrelated' ];
			$this->dispatch( 'toplevel_page_x' );
			$this->assertEmpty( $GLOBALS['_enqueued_scripts'] );
		}

		public function test_performance_page_routes_through_registrar(): void {
			$tree  = 'overview';
			$asset = \NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.js";
			$this->assertFileExists( $asset, "ELN {$tree} build missing — run `npm run build`" );

			$_GET = [ 'page' => 'event-logger-overview' ];
			$this->dispatch( 'nodes_page_event-logger-overview' );

			$handle = "newspack-nodes-{$tree}";
			$enq    = $this->enqueued_script_for( $handle );
			$this->assertNotNull( $enq, 'registrar must enqueue the dashboard script' );
			$this->assertStringEndsWith( "build/{$tree}/index.js", (string) ( $enq[1] ?? '' ) );

			// Deps now come from the wp-scripts manifest the registrar reads,
			// not the old hardcoded [wp-element, wp-components, ...] fallback.
			$manifest = require \NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.asset.php";
			$this->assertSame( \array_values( $manifest['dependencies'] ), $enq[2] ?? null );

			// ELN's COMPLETE localize payload survives byte-for-byte.
			$data = $this->localized_for( $handle );
			$this->assertNotNull( $data, 'NewspackNodesData must be localized on the handle' );
			$this->assertArrayHasKey( 'restUrl', $data );
			// aggregatorRestUrl was a dead localize (no JS consumer) for the pre-command-path aggregator dashboard; removed with src/event-aggregator.
			$this->assertArrayNotHasKey( 'aggregatorRestUrl', $data );
			$this->assertArrayHasKey( 'nonce', $data );
			$this->assertArrayHasKey( 'restartNonce', $data );
			$this->assertSame( $tree, $data['tree'] );
			// The header stamps the plugin that OWNS the surface, so this is
			// ELN's own release and never the substrate it is built on.
			$this->assertSame( \NEWSPACK_EVENT_LOGGER_NODES_VERSION, $data['version'] );
		}

		#[DataProvider( 'graph_dashboard_pages' )]
		public function test_graph_dashboard_style_has_exact_graph_dependency( string $page, string $tree ): void {
			$asset = \NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.css";
			$this->assertFileExists( $asset, "ELN {$tree} CSS missing — run `npm run build`" );

			$_GET = [ 'page' => $page ];
			$this->dispatch( "nodes_page_{$page}" );

			$style = $this->enqueued_style_for( "newspack-nodes-{$tree}" );
			$this->assertNotNull( $style, "{$tree} dashboard style must enqueue" );
			$this->assertSame(
				[ 'wp-components', 'newspack-nodes-graph' ],
				$style[2] ?? null
			);
		}

		/**
		 * The JSON one inline script assigned to `window.<name>` on `$handle`.
		 *
		 * @return array<string,mixed>
		 */
		private function window_json( string $handle, string $name ): array {
			foreach ( $GLOBALS['_inline_scripts'] as $rec ) {
				if ( ( $rec[0] ?? '' ) === $handle && \preg_match( '/window\.' . $name . ' = (\{.*?\}|\[\]);/', (string) ( $rec[1] ?? '' ), $m ) ) {
					return (array) \json_decode( $m[1], true );
				}
			}
			$this->fail( "no window.{$name} on {$handle}" );
		}

		/**
		 * The dashboards' colour map carries the platform's own span names
		 * beneath the configured colours; the rule editor's picker does not.
		 */
		public function test_dashboards_color_platform_spans_beneath_the_configured_colors(): void {
			\add_filter( 'newspack_event_logger_nodes_custom_colors', static fn ( array $colors ): array => [ 'url fold' => '#7A1F3D' ] + $colors );
			$_GET = [ 'page' => 'event-logger-overview' ];
			try {
				$this->dispatch( 'nodes_page_event-logger-overview' );
			} finally {
				unset( $GLOBALS['_wp_actions']['newspack_event_logger_nodes_custom_colors'] );
			}

			$colors = $this->window_json( 'newspack-nodes-overview', 'eventLoggerCustomColors' );
			$this->assertSame( '#7A1F3D', $colors['url fold'] ?? null, 'a configured colour wins' );
			$this->assertSame(
				[ '#003DA5', '#BD8600', '#117644', '#2055B0' ],
				[ $colors['url page cache'] ?? null, $colors['url header cache'] ?? null, $colors['url scope read'] ?? null, $colors['requests checkpoint'] ?? null ]
			);
			$picker = $this->window_json( 'newspack-nodes-overview', 'newspackNodesCustomColors' );
			$this->assertArrayNotHasKey( 'url page cache', $picker, 'the picker offers only the operator\'s events' );
			$this->assertSame( '#7A1F3D', $picker['url fold'] ?? null );
		}

		/**
		 * A dashboard page loads two bundles that both draw spans — its own tree
		 * and the Request tab — and each span-palette global lands on it once.
		 */
		#[DataProvider( 'graph_dashboard_pages' )]
		public function test_a_dashboard_page_carries_each_palette_global_once( string $page ): void {
			$_GET = [ 'page' => $page ];
			$this->dispatch( "nodes_page_{$page}" );
			Current_Request_Overlay::enqueue_on_overlay_pages();
			$tab = 'newspack-eln-current-request';
			$GLOBALS['_wp_test_enqueued_handles'] = [ $tab => null !== $this->enqueued_script_for( $tab ) ];
			try {
				Current_Request_Overlay::enqueue_inline_data();
			} finally {
				Log_Manager::reset();
				unset( $GLOBALS['_wp_test_enqueued_handles'] );
			}

			$printed = \implode( "\n", \array_map( static fn ( $rec ) => (string) ( $rec[1] ?? '' ), $GLOBALS['_inline_scripts'] ) );
			$this->assertStringContainsString( 'currentRequest', $printed, 'the Request tab loaded here' );
			$this->assertSame( 1, \substr_count( $printed, 'window.eventLoggerHookCategories =' ) );
			$this->assertSame( 1, \substr_count( $printed, 'window.eventLoggerCustomColors =' ) );
		}

		public function test_settings_page_keeps_per_tree_extras(): void {
			$tree  = 'settings';
			$asset = \NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.js";
			$this->assertFileExists( $asset, "ELN {$tree} build missing — run `npm run build`" );

			$_GET = [ 'page' => 'newspack-event-logger-nodes' ];
			$this->dispatch( 'nodes_page_newspack-event-logger-nodes' );

			$handle = "newspack-nodes-{$tree}";
			$this->assertNotNull( $this->enqueued_script_for( $handle ), 'settings dashboard script must enqueue' );
			$style = $this->enqueued_style_for( $handle );
			$this->assertNotNull( $style, 'settings dashboard style must enqueue' );
			$this->assertSame(
				[ 'wp-components', 'newspack-nodes-ui' ],
				$style[2] ?? null
			);
			$this->assertNotContains( 'newspack-nodes-graph', $style[2] ?? [] );

			// Per-tree inline-script extras are preserved (eventLoggerDashboards
			// + newspackNodesRecommendedHooks blocks anchored on the handle).
			$inline_payloads = [];
			foreach ( $GLOBALS['_inline_scripts'] as $rec ) {
				if ( ( $rec[0] ?? '' ) === $handle ) {
					$inline_payloads[] = (string) ( $rec[1] ?? '' );
				}
			}
			$joined = \implode( "\n", $inline_payloads );
			$this->assertStringContainsString( 'window.eventLoggerDashboards', $joined );
			$this->assertStringContainsString( 'window.newspackNodesRecommendedHooks', $joined );
		}
	}
}
