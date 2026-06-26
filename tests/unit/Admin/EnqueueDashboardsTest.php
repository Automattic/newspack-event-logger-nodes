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

	use Newspack_Event_Logger_Nodes\Tests\TestCase;

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
			$this->assertArrayHasKey( 'version', $data );
		}

		public function test_settings_page_keeps_per_tree_extras(): void {
			$tree  = 'settings';
			$asset = \NEWSPACK_EVENT_LOGGER_NODES_DIR . "build/{$tree}/index.js";
			$this->assertFileExists( $asset, "ELN {$tree} build missing — run `npm run build`" );

			$_GET = [ 'page' => 'newspack-event-logger-nodes' ];
			$this->dispatch( 'nodes_page_newspack-event-logger-nodes' );

			$handle = "newspack-nodes-{$tree}";
			$this->assertNotNull( $this->enqueued_script_for( $handle ), 'settings dashboard script must enqueue' );

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
