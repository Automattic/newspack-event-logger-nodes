<?php
/**
 * PerformancePageTest: the Performance dashboard as a composed page — its
 * context, the default layout `tests/fixtures/performance-layout.json` holds
 * for the browser's tests, the five blocks this plugin owns and where they
 * stand, and what a Performance load sets before its bundle.
 *
 * `Composition` reads WordPress's block parser and schema validator, so this
 * suite runs with `WP_ABSPATH` naming an install.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin;

use Newspack_Event_Logger_Nodes\Admin\Performance_Page;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Tests\Helpers\Wp_Includes;
use PHPUnit\Framework\Attributes\CoversClass;

require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/WpIncludes.php';

#[CoversClass( Performance_Page::class )]
final class PerformancePageTest extends TestCase {

	/** The Performance page's context. */
	private const CONTEXT = 'newspack-event-logger-nodes/performance';

	/** What the filter map held before each test. */
	private array $filters = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		Wp_Includes::load( [ 'parse_blocks', 'serialize_blocks', 'rest_validate_value_from_schema', 'rest_sanitize_value_from_schema', 'wp_kses', 'wp_sprintf_l' ] );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->filters = $GLOBALS['_wp_actions'][ Composition::FILTER ] ?? [];
		$GLOBALS['_wp_actions'][ Composition::FILTER ] = [
			[ Composition::class, 'declare_substrate_blocks' ],
			[ Performance_Page::class, 'declare' ],
		];
		$this->forget_declarations();
		$GLOBALS['_inline_scripts']   = [];
		$GLOBALS['_enqueued_scripts'] = [];
		$GLOBALS['_wp_options']       = [];
		$_GET                         = [];
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_actions'][ Composition::FILTER ] = $this->filters;
		$this->forget_declarations();
		$_GET = [];
		parent::tearDown();
	}

	/** Forget the memo, so the next `declarations()` applies the filter. */
	private function forget_declarations(): void {
		( new \ReflectionProperty( Composition::class, 'declarations' ) )->setValue( null, null );
	}

	public function test_the_fixture_holds_the_default_tree_the_browser_tests_read(): void {
		$fixture = \json_decode( (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/fixtures/performance-layout.json' ), true );
		$this->assertSame(
			[ self::CONTEXT => Composition::validate( self::CONTEXT, Composition::default_markup( self::CONTEXT ) ) ],
			$fixture
		);
	}

	public function test_the_default_opens_on_the_headline_and_closes_on_the_url_table(): void {
		$tree = Composition::validate( self::CONTEXT, Composition::default_markup( self::CONTEXT ) );
		$this->assertSame( 'newspack-nodes/stats', $tree[0]['name'] );
		$this->assertSame( 'eln/urls', $tree[0]['attributes']['source'] );
		$this->assertSame( 'newspack-event-logger-nodes/url-table', \end( $tree )['name'] );
	}

	public function test_the_context_admits_the_shared_blocks_beside_its_own(): void {
		$context = Composition::context_of( self::CONTEXT );
		$this->assertSame(
			[
				'newspack-nodes/stats',
				'newspack-nodes/time-chart',
				'newspack-nodes/table',
				'newspack-nodes/log-stream',
				'newspack-nodes/sparkline',
				'newspack-nodes/section',
			],
			$context['admits']
		);
		$this->assertSame( 64, $context['max_blocks'] );
	}

	public function test_each_block_of_its_own_stands_once_and_only_on_the_page(): void {
		$blocks = Composition::declarations()['blocks'];
		foreach ( [ 'breakdown-bar', 'aggregate-chart', 'category-chart', 'profile', 'url-table' ] as $name ) {
			$block = $blocks[ "newspack-event-logger-nodes/{$name}" ];
			$this->assertSame( [ self::CONTEXT ], $block['contexts'], $name );
			$this->assertFalse( $block['multiple'], $name );
		}
	}

	public function test_a_second_url_table_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-event-logger-nodes/url-table' );
		Composition::validate( self::CONTEXT, '<!-- wp:newspack-event-logger-nodes/url-table /--><!-- wp:newspack-event-logger-nodes/url-table /-->' );
	}

	public function test_page_data_says_who_may_tune_and_holds_the_layout(): void {
		$GLOBALS['_current_user_can'] = true;
		$data                         = Performance_Page::page_data();
		$this->assertTrue( $data['canTune'] );
		$this->assertTrue( $data['canManage'] );
		$this->assertSame( [ self::CONTEXT => Composition_Store::read( self::CONTEXT ) ], $data['compositions'] );
	}

	public function test_page_data_names_the_stats_window_in_buckets(): void {
		$this->use_min_lifetime( 5400 );
		$this->assertSame( 18, Performance_Page::page_data()['window'] );
	}

	public function test_page_data_carries_the_window_refusal_when_min_lifetime_is_under_a_bucket(): void {
		$this->use_min_lifetime( 120 );
		$this->assertSame(
			[ 'error' => 'min_lifetime 120 is below the 300-second floor of one stats bucket' ],
			Performance_Page::page_data()['window']
		);
	}

	public function test_page_data_withholds_tune_from_a_reader(): void {
		$GLOBALS['_current_user_can'] = false;
		$data                         = Performance_Page::page_data();
		$this->assertFalse( $data['canTune'] );
		$this->assertFalse( $data['canManage'] );
	}

	public function test_enqueue_sets_the_page_data_before_the_bundle_and_loads_no_editor(): void {
		$GLOBALS['_current_user_can'] = true;
		$dir                          = \sys_get_temp_dir() . '/eln-performance-unbuilt-' . \uniqid();
		Performance_Page::enqueue( 'newspack-nodes-overview-7940', $dir, 'https://kea-7940.test/build' );
		[ [ $handle, $script, $position ] ] = $GLOBALS['_inline_scripts'];
		$this->assertSame( [ 'newspack-nodes-overview-7940', 'before' ], [ $handle, $position ] );
		$prefix = 'window.' . Performance_Page::GLOBAL . ' = ';
		$this->assertStringStartsWith( $prefix, $script );
		$this->assertSame( Performance_Page::page_data(), \json_decode( \substr( $script, \strlen( $prefix ), -1 ), true ) );
		$this->assertArrayNotHasKey( Performance_Page::EDITOR, $GLOBALS['_enqueued_scripts'] );
	}

	public function test_the_page_data_cannot_close_its_script_element(): void {
		$GLOBALS['_current_user_can'] = true;
		\update_option( 'newspack_nodes_composition:' . self::CONTEXT, [ 'markup' => '<!-- wp:paragraph --><p><strong>ruru 7941</strong></p><!-- /wp:paragraph -->', 'actor' => 'kea', 'ts' => 1.5 ] );
		Performance_Page::enqueue( 'newspack-nodes-overview-7941', \sys_get_temp_dir(), 'https://kea-7941.test/build' );
		$this->assertStringNotContainsString( '</strong>', $GLOBALS['_inline_scripts'][0][1] );
		$this->assertStringContainsString( 'ruru 7941', $GLOBALS['_inline_scripts'][0][1] );
	}
}
