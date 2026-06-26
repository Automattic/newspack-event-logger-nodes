<?php
declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Current_Request_Overlay;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The Current-Request overlay tab's enqueue glue: it registers an ELN bundle on
 * the substrate's `newspack_nodes/devtools_tab_bundles` filter and injects the
 * page's request id into a DISTINCT JS global (not the shared, clobber-prone
 * `NewspackNodesData`).
 */
#[CoversClass( Current_Request_Overlay::class )]
class CurrentRequestOverlayTest extends TestCase {

	public function test_register_bundle_appends_the_overlay_tab_bundle(): void {
		$bundles = Current_Request_Overlay::register_bundle( [] );

		$this->assertCount( 1, $bundles );
		$this->assertSame(
			'newspack-eln-current-request',
			$bundles[0]['handle']
		);
		$this->assertStringContainsString(
			'build/current-request',
			$bundles[0]['dir']
		);
		$this->assertStringContainsString(
			'build/current-request',
			$bundles[0]['url']
		);
	}

	public function test_register_bundle_preserves_existing_bundles(): void {
		$existing = [ [ 'handle' => 'other', 'dir' => '/x', 'url' => '/x' ] ];
		$bundles  = Current_Request_Overlay::register_bundle( $existing );

		$this->assertCount( 2, $bundles );
		$this->assertSame( 'other', $bundles[0]['handle'] );
	}

	public function test_inline_data_js_sets_a_distinct_global_with_rid_and_perf_url(): void {
		$js = Current_Request_Overlay::inline_data_js(
			'abc123',
			2,
			'http://example.test/wp-admin/admin.php?page=event-logger-overview'
		);

		$this->assertStringContainsString( 'NewspackEventLoggerNodes', $js );
		$this->assertStringContainsString( 'currentRequest', $js );
		$this->assertStringContainsString( '"rid":"abc123"', $js );
		$this->assertStringContainsString( '"partition":2', $js );
		$this->assertStringContainsString(
			'event-logger-overview',
			$js
		);
		// Distinct global — NOT the shared NewspackNodesData that other bundles
		// localize and would overwrite at render time.
		$this->assertStringNotContainsString( 'NewspackNodesData', $js );
	}

	public function test_is_overlay_page_matches_the_overlay_embedding_perf_pages(): void {
		$this->assertTrue( Current_Request_Overlay::is_overlay_page( 'event-logger-requests' ) );
		$this->assertTrue( Current_Request_Overlay::is_overlay_page( 'event-logger-overview' ) );
		$this->assertTrue( Current_Request_Overlay::is_overlay_page( 'event-logger-gyroscope' ) );
		// Hub is the substrate filter's job, not ours; unrelated pages never match.
		$this->assertFalse( Current_Request_Overlay::is_overlay_page( 'newspack-nodes-hub' ) );
		$this->assertFalse( Current_Request_Overlay::is_overlay_page( 'edit.php' ) );
		$this->assertFalse( Current_Request_Overlay::is_overlay_page( '' ) );
	}

	public function test_is_overlay_page_includes_substrate_registry_pages(): void {
		\add_filter(
			'newspack_nodes/devtools_overlay_pages',
			static fn ( array $pages ): array => \array_merge( $pages, [ 'some-consumer-page' ] )
		);
		// finally so a failed assertion can't leak the filter into later tests.
		try {
			// A page contributed via the substrate registry is now an overlay
			// page, while ELN's own defaults still match and unrelated don't.
			$this->assertTrue( Current_Request_Overlay::is_overlay_page( 'some-consumer-page' ) );
			$this->assertTrue( Current_Request_Overlay::is_overlay_page( 'event-logger-overview' ) );
			$this->assertFalse( Current_Request_Overlay::is_overlay_page( 'unrelated-page' ) );
		} finally {
			unset( $GLOBALS['_wp_actions']['newspack_nodes/devtools_overlay_pages'] );
		}
	}

	public function test_inline_data_js_with_no_rid_still_emits_a_safe_global(): void {
		$js = Current_Request_Overlay::inline_data_js( '', 0, '' );

		$this->assertStringContainsString( 'NewspackEventLoggerNodes', $js );
		$this->assertStringContainsString( '"rid":""', $js );
	}
}
