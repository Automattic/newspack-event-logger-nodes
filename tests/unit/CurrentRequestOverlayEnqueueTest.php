<?php
/**
 * CurrentRequestOverlayEnqueueTest: the WP-integration glue of the
 * Current-Request overlay tab — page-gated bundle enqueue
 * (enqueue_on_overlay_pages), per-request inline-data injection
 * (enqueue_inline_data), and hook wiring (init).
 *
 * These paths touch admin ENQUEUE functions that aren't in the shared
 * bootstrap, so those stubs are declared here (function_exists-guarded) and
 * record into $GLOBALS the assertions read back. Everything else (admin_url,
 * escaping, i18n) comes from tests/bootstrap.php — per-file copies forbidden.
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
}

namespace Newspack_Event_Logger_Nodes\Tests\Unit {

	use Newspack_Event_Logger_Nodes\Current_Request_Overlay;
	use Newspack_Event_Logger_Nodes\Hook_Categorizer;
	use Newspack_Event_Logger_Nodes\Log_Manager;
	use Newspack_Event_Logger_Nodes\Tests\TestCase;
	use PHPUnit\Framework\Attributes\CoversClass;

	#[CoversClass( Current_Request_Overlay::class )]
	class CurrentRequestOverlayEnqueueTest extends TestCase {

		private const HANDLE = 'newspack-eln-current-request';

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['_enqueued_scripts']        = [];
			$GLOBALS['_enqueued_styles']         = [];
			$GLOBALS['_inline_scripts']          = [];
			$GLOBALS['_wp_test_enqueued_handles'] = [];
			$GLOBALS['_wp_actions']              = [];
			$_GET                                = [];
		}

		protected function tearDown(): void {
			Log_Manager::reset();
			Hook_Categorizer::$read_file = null;
			Hook_Categorizer::clear_cache();
			$_GET = [];
			unset( $GLOBALS['_wp_test_enqueued_handles'] );
			parent::tearDown();
		}

		/** @return array<int,mixed>|null The recorded wp_enqueue_script args for $handle. */
		private function enqueued_script_for( string $handle ): ?array {
			foreach ( $GLOBALS['_enqueued_scripts'] as $rec ) {
				if ( ( $rec[0] ?? '' ) === $handle ) {
					return $rec;
				}
			}
			return null;
		}

		/** @return array<int,mixed>|null The recorded wp_enqueue_style args for $handle. */
		private function enqueued_style_for( string $handle ): ?array {
			foreach ( $GLOBALS['_enqueued_styles'] as $rec ) {
				if ( ( $rec[0] ?? '' ) === $handle ) {
					return $rec;
				}
			}
			return null;
		}

		// ── enqueue_on_overlay_pages ────────────────────────────────────────

		public function test_does_not_enqueue_when_page_is_not_an_overlay_page(): void {
			$_GET = [ 'page' => 'some-unrelated-page' ];

			Current_Request_Overlay::enqueue_on_overlay_pages();

			$this->assertNull(
				$this->enqueued_script_for( self::HANDLE ),
				'a non-overlay page must not enqueue the tab bundle'
			);
		}

		public function test_does_not_enqueue_when_no_page_param(): void {
			Current_Request_Overlay::enqueue_on_overlay_pages();

			$this->assertNull( $this->enqueued_script_for( self::HANDLE ) );
		}

		public function test_enqueues_bundle_and_style_on_an_overlay_page(): void {
			$_GET = [ 'page' => 'event-logger-overview' ];

			Current_Request_Overlay::enqueue_on_overlay_pages();

			$enq = $this->enqueued_script_for( self::HANDLE );
			$this->assertNotNull( $enq, 'overlay page must enqueue the tab bundle' );
			$this->assertStringEndsWith( 'build/current-request/index.js', (string) ( $enq[1] ?? '' ) );
			$this->assertFileExists(
				\NEWSPACK_EVENT_LOGGER_NODES_DIR . 'build/current-request/index.css',
				'Current Request CSS missing — run `npm run build`'
			);
			$style = $this->enqueued_style_for( self::HANDLE );
			$this->assertNotNull( $style, 'the direct Current Request stylesheet must enqueue' );
			$this->assertSame(
				[ 'wp-components', 'newspack-nodes-ui' ],
				$style[2] ?? null
			);
			$this->assertNotContains( 'newspack-nodes-graph', $style[2] ?? [] );
		}

		// ── enqueue_inline_data ─────────────────────────────────────────────

		public function test_skips_inline_data_when_handle_not_enqueued(): void {
			// wp_script_is returns false (handle absent) → no inline write.
			Current_Request_Overlay::enqueue_inline_data();

			$this->assertEmpty( $GLOBALS['_inline_scripts'], 'inline data must wait for the handle to be enqueued' );
		}

		public function test_injects_inline_data_once_handle_is_enqueued(): void {
			$GLOBALS['_wp_test_enqueued_handles'][ self::HANDLE ] = true;

			Current_Request_Overlay::enqueue_inline_data();

			$this->assertCount( 1, $GLOBALS['_inline_scripts'] );
			$rec = $GLOBALS['_inline_scripts'][0];
			$this->assertSame( self::HANDLE, $rec[0] ?? '' );
			$this->assertStringContainsString( 'NewspackEventLoggerNodes', (string) ( $rec[1] ?? '' ) );
			$this->assertStringContainsString( 'currentRequest', (string) ( $rec[1] ?? '' ) );
			// 'before' placement so the global exists before the bundle runs.
			$this->assertSame( 'before', $rec[2] ?? '' );
		}

		/**
		 * The tab draws `RequestProfile` on the station too, where no dashboard
		 * printed the colour map: it carries the dashboards' map itself, the
		 * platform spans beneath the configured colours.
		 */
		public function test_inline_data_carries_the_dashboards_span_colours(): void {
			$GLOBALS['_wp_test_enqueued_handles'][ self::HANDLE ] = true;
			\add_filter( 'newspack_event_logger_nodes_custom_colors', static fn ( array $colors ): array => [ 'url fold' => '#7A1F3D' ] + $colors );

			Current_Request_Overlay::enqueue_inline_data();

			$script = (string) ( $GLOBALS['_inline_scripts'][0][1] ?? '' );
			$this->assertMatchesRegularExpression( '/window\.eventLoggerCustomColors = (\{.*?\});/', $script );
			\preg_match( '/window\.eventLoggerCustomColors = (\{.*?\});/', $script, $m );
			$this->assertSame(
				\Newspack_Event_Logger_Nodes\Config::get_custom_colors() + \Newspack_Event_Logger_Nodes\Flame_Tree::platform_colors(),
				\json_decode( $m[1], true )
			);
			$this->assertSame( '#7A1F3D', \json_decode( $m[1], true )['url fold'], 'a configured colour wins' );
		}

		/**
		 * The station prints no dashboard bundle, so the tab carries the hook
		 * taxonomy the dashboards print, or its hook spans draw the default.
		 */
		public function test_inline_data_carries_the_dashboards_hook_categories_on_the_station(): void {
			// The platform spans' own categories ship with any taxonomy that is not stripped bare.
			$shipped  = Hook_Categorizer::get_base_config()['_colors'];
			$taxonomy = [
				'_colors'   => [ 'Kakapo' => '#5E2D79' ] + $shipped,
				'_patterns' => [ 'Kakapo' => [ '^kakapo_' ] ],
			];
			Hook_Categorizer::$read_file = static fn ( string $path ): string => (string) \wp_json_encode( $taxonomy );
			Hook_Categorizer::clear_cache();
			$_GET = [ 'page' => 'newspack-nodes-station' ];
			$GLOBALS['_wp_test_enqueued_handles'][ self::HANDLE ] = true;

			Current_Request_Overlay::enqueue_inline_data();

			$script = (string) ( $GLOBALS['_inline_scripts'][0][1] ?? '' );
			$this->assertSame( 1, \preg_match( '/window\.eventLoggerHookCategories = (\{.*\});window\.eventLoggerCustomColors/s', $script, $m ), $script );
			$this->assertEquals( $taxonomy, \json_decode( $m[1], true ), 'merged colours and base patterns, and nothing else' );
		}

		// ── init ────────────────────────────────────────────────────────────

		public function test_init_wires_the_tab_bundle_filter_and_both_enqueue_actions(): void {
			Current_Request_Overlay::init();

			$this->assertArrayHasKey(
				'newspack_nodes/station_tab_bundles',
				$GLOBALS['_wp_actions'],
				'init must register the substrate tab-bundle filter'
			);
			$this->assertCount(
				2,
				$GLOBALS['_wp_actions']['admin_enqueue_scripts'] ?? [],
				'init must hook both the bundle enqueue and the inline-data injection'
			);
		}
	}
}
