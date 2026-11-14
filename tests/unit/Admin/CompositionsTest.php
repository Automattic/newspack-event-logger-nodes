<?php
/**
 * CompositionsTest: the plugin's four composed pages — Performance, the
 * Request Log, the Error Log and the Gyroscope — off the one page table:
 * their contexts and the blocks Performance owns, the default layouts the
 * browser's tests read from `tests/fixtures/`, the menu each page registers,
 * and what each page's load sets before its bundle, under one global.
 *
 * `Composition` reads WordPress's block parser and schema validator, so this
 * suite runs with `WP_ABSPATH` naming an install.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin;

use Newspack_Event_Logger_Nodes\Admin\Compositions;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Block_Editor_Bootstrap;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Tests\Helpers\Wp_Includes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/WpIncludes.php';

#[CoversClass( Compositions::class )]
final class CompositionsTest extends TestCase {

	/** The Performance page's context. */
	private const PERFORMANCE = 'newspack-event-logger-nodes/performance';

	/** Each log page's admin slug => its context. */
	private const LOG_CONTEXTS = [
		'event-logger-requests'  => 'newspack-event-logger-nodes/request-log',
		'event-logger-errors'    => 'newspack-event-logger-nodes/error-log',
		'event-logger-gyroscope' => 'newspack-event-logger-nodes/gyroscope',
	];

	/** What the filter map held before each test. */
	private array $filters = [];

	/** A build directory a test made, removed after it. */
	private ?string $build = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		Wp_Includes::load( [ 'parse_blocks', 'serialize_blocks', 'rest_validate_value_from_schema', 'rest_sanitize_value_from_schema', 'wp_kses', 'wp_sprintf_l' ] );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->filters = $GLOBALS['_wp_actions'][ Composition::FILTER ] ?? [];
		$GLOBALS['_wp_actions'][ Composition::FILTER ] = [
			[ Composition::class, 'declare_substrate_blocks' ],
			[ Compositions::class, 'declare' ],
		];
		$this->forget_declarations();
		$GLOBALS['_inline_scripts']     = [];
		$GLOBALS['_enqueued_scripts']   = [];
		$GLOBALS['_registered_scripts'] = [];
		$GLOBALS['_localized_scripts']  = [];
		$GLOBALS['_wp_options']         = [];
		$_GET                           = [];
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_actions'][ Composition::FILTER ] = $this->filters;
		$this->forget_declarations();
		$_GET = [];
		if ( null !== $this->build ) {
			$this->rmdir_recursive( $this->build );
		}
		parent::tearDown();
	}

	/** Forget the memos: the declarations and the one canvas a page boots. */
	private function forget_declarations(): void {
		( new \ReflectionProperty( Composition::class, 'declarations' ) )->setValue( null, null );
		( new \ReflectionProperty( Block_Editor_Bootstrap::class, 'canvas_context' ) )->setValue( null, null );
		( new \ReflectionProperty( Block_Editor_Bootstrap::class, 'content_blocks_loaded' ) )->setValue( null, false );
	}

	/** @return array<string, array{string, string}> Each log page's admin slug and context. */
	public static function log_pages(): array {
		$pages = [];
		foreach ( self::LOG_CONTEXTS as $slug => $context ) {
			$pages[ $slug ] = [ $slug, $context ];
		}
		return $pages;
	}

	/** The JSON `enqueue()` set on `window.<GLOBAL>`, decoded. */
	private function printed_page_data( string $handle ): array {
		[ [ $printed_on, $script, $position ] ] = $GLOBALS['_inline_scripts'];
		$this->assertSame( [ $handle, 'before' ], [ $printed_on, $position ] );
		$prefix = 'window.' . Compositions::GLOBAL . ' = ';
		$this->assertStringStartsWith( $prefix, $script );
		return (array) \json_decode( \substr( $script, \strlen( $prefix ), -1 ), true );
	}

	public function test_one_callback_declares_the_four_pages_and_the_blocks_performance_owns(): void {
		$declared = Composition::declarations();
		$this->assertSame(
			[ self::PERFORMANCE, ...\array_values( self::LOG_CONTEXTS ) ],
			\array_values( \array_filter( \array_keys( $declared['contexts'] ), static fn ( string $name ): bool => \str_starts_with( $name, 'newspack-event-logger-nodes/' ) ) )
		);
		$this->assertArrayHasKey( 'newspack-event-logger-nodes/url-table', $declared['blocks'] );
	}

	public function test_every_page_admits_the_shared_blocks_but_the_table(): void {
		foreach ( [ self::PERFORMANCE, ...\array_values( self::LOG_CONTEXTS ) ] as $context ) {
			$this->assertSame(
				[
					'newspack-nodes/stats',
					'newspack-nodes/time-chart',
					'newspack-nodes/log-stream',
					'newspack-nodes/sparkline',
					'newspack-nodes/section',
				],
				Composition::context_of( $context )['admits'],
				$context
			);
		}
	}

	public function test_performance_holds_sixty_four_blocks_and_a_log_page_sixteen(): void {
		$this->assertSame( 64, Composition::context_of( self::PERFORMANCE )['max_blocks'] );
		foreach ( self::LOG_CONTEXTS as $context ) {
			$this->assertSame( 16, Composition::context_of( $context )['max_blocks'], $context );
		}
	}

	public function test_the_fixtures_hold_each_default_tree_the_browser_tests_read(): void {
		$fixtures = \dirname( __DIR__, 2 ) . '/fixtures';
		$trees    = [];
		foreach ( self::LOG_CONTEXTS as $context ) {
			$trees[ $context ] = Composition::validate( $context, Composition::default_markup( $context ) );
		}
		$this->assertSame( $trees, \json_decode( (string) \file_get_contents( "{$fixtures}/log-page-layouts.json" ), true ) );
		$this->assertSame(
			[ self::PERFORMANCE => Composition::validate( self::PERFORMANCE, Composition::default_markup( self::PERFORMANCE ) ) ],
			\json_decode( (string) \file_get_contents( "{$fixtures}/performance-layout.json" ), true )
		);
	}

	public function test_the_performance_default_opens_on_the_headline_and_closes_on_the_url_table(): void {
		$tree = Composition::validate( self::PERFORMANCE, Composition::default_markup( self::PERFORMANCE ) );
		$this->assertSame( 'newspack-nodes/stats', $tree[0]['name'] );
		$this->assertSame( 'eln/urls', $tree[0]['attributes']['source'] );
		$this->assertSame( 'newspack-event-logger-nodes/url-table', \end( $tree )['name'] );
	}

	public function test_each_performance_block_stands_once_and_only_on_its_page(): void {
		$blocks = Composition::declarations()['blocks'];
		foreach ( [ 'breakdown-bar', 'aggregate-chart', 'category-chart', 'profile', 'url-table' ] as $name ) {
			$block = $blocks[ "newspack-event-logger-nodes/{$name}" ];
			$this->assertSame( [ self::PERFORMANCE ], $block['contexts'], $name );
			$this->assertFalse( $block['multiple'], $name );
		}
	}

	public function test_a_second_url_table_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-event-logger-nodes/url-table' );
		Composition::validate( self::PERFORMANCE, '<!-- wp:newspack-event-logger-nodes/url-table /--><!-- wp:newspack-event-logger-nodes/url-table /-->' );
	}

	#[DataProvider( 'log_pages' )]
	public function test_each_log_default_is_one_log_stream_block( string $slug, string $context ): void {
		$tree = Composition::validate( $context, Composition::default_markup( $context ) );
		$this->assertCount( 1, $tree, $slug );
		$this->assertSame( 'newspack-nodes/log-stream', $tree[0]['name'], $slug );
	}

	public function test_each_log_default_reads_its_own_stream_names_its_columns_and_heads_its_toolbar(): void {
		$read = [];
		foreach ( self::LOG_CONTEXTS as $context ) {
			$attributes       = Composition::validate( $context, Composition::default_markup( $context ) )[0]['attributes'];
			$read[ $context ] = [ $attributes['source'], $attributes['columns'], $attributes['toolbar'] ];
		}
		$this->assertSame(
			[
				'newspack-event-logger-nodes/request-log' => [ 'eln/requests', [ 'time', 'rid', 'url', 'status_code', 'remote_addr', 'duration' ], 'header' ],
				'newspack-event-logger-nodes/error-log'   => [ 'eln/errors', [], 'header' ],
				'newspack-event-logger-nodes/gyroscope'   => [ 'eln/gyroscope', [ 'rid', 'url', 'status_code', 'state', 'what', 'est' ], 'header' ],
			],
			$read
		);
	}

	public function test_the_menu_names_each_page_by_its_slug_and_its_bundle(): void {
		$this->assertSame(
			[
				'event-logger-overview'  => [ 'Performance Dashboard', 'Performance', 'overview' ],
				'event-logger-requests'  => [ 'Request Log', 'Request Log', 'log-pages' ],
				'event-logger-errors'    => [ 'Error Log', 'Errors', 'log-pages' ],
				'event-logger-gyroscope' => [ 'Gyroscope', 'Gyroscope', 'log-pages' ],
			],
			Compositions::menu()
		);
	}

	#[DataProvider( 'log_pages' )]
	public function test_log_page_data_names_the_page_and_holds_its_layout_alone( string $slug, string $context ): void {
		$GLOBALS['_current_user_can'] = true;
		$page                         = \substr( $context, \strlen( 'newspack-event-logger-nodes/' ) );
		$data                         = Compositions::page_data( $page );
		$this->assertSame( [ 'page', 'canTune', 'canManage', 'compositions' ], \array_keys( $data ), $slug );
		$this->assertSame( $page, $data['page'], $slug );
		$this->assertTrue( $data['canTune'] );
		$this->assertTrue( $data['canManage'] );
		$this->assertSame( [ $context => Composition_Store::read( $context ) ], $data['compositions'] );
	}

	public function test_performance_page_data_adds_the_stats_window_in_buckets(): void {
		$GLOBALS['_current_user_can'] = true;
		$this->use_min_lifetime( 5400 );
		$data = Compositions::page_data( 'performance' );
		$this->assertSame( [ 'page', 'canTune', 'canManage', 'compositions', 'window' ], \array_keys( $data ) );
		$this->assertSame( 'performance', $data['page'] );
		$this->assertSame( [ self::PERFORMANCE => Composition_Store::read( self::PERFORMANCE ) ], $data['compositions'] );
		$this->assertSame( 18, $data['window'] );
	}

	public function test_performance_page_data_carries_the_window_refusal_when_min_lifetime_is_under_a_bucket(): void {
		$this->use_min_lifetime( 120 );
		$this->assertSame(
			[ 'error' => 'min_lifetime 120 is below the 300-second floor of one stats bucket' ],
			Compositions::page_data( 'performance' )['window']
		);
	}

	public function test_page_data_withholds_tune_from_a_reader(): void {
		$GLOBALS['_current_user_can'] = false;
		$data                         = Compositions::page_data( 'error-log' );
		$this->assertFalse( $data['canTune'] );
		$this->assertFalse( $data['canManage'] );
	}

	public function test_enqueue_refuses_a_slug_it_lays_out_no_page_at(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'newspack-event-logger-nodes' );
		Compositions::enqueue( 'newspack-event-logger-nodes', 'newspack-nodes-settings-7982', \sys_get_temp_dir(), 'https://kea-7982.test/build' );
	}

	public function test_enqueue_sets_the_page_data_its_slug_names_and_loads_no_editor(): void {
		$GLOBALS['_current_user_can'] = true;
		$this->use_min_lifetime( 5400 );
		Compositions::enqueue( 'event-logger-overview', 'newspack-nodes-overview-7940', \sys_get_temp_dir() . '/eln-unbuilt-' . \uniqid(), 'https://kea-7940.test/build' );
		$this->assertSame( Compositions::page_data( 'performance' ), $this->printed_page_data( 'newspack-nodes-overview-7940' ) );
		$this->assertSame( [], $GLOBALS['_enqueued_scripts'] );
	}

	/** @return array<string, array{string, string, string, string}> Slug, page, editor bundle and handle. */
	public static function editors(): array {
		return [
			'performance' => [ 'event-logger-overview', 'performance', 'overview-editor', 'newspack-event-logger-nodes-overview-editor' ],
			'gyroscope'   => [ 'event-logger-gyroscope', 'gyroscope', 'log-pages-editor', 'newspack-event-logger-nodes-log-pages-editor' ],
		];
	}

	#[DataProvider( 'editors' )]
	public function test_an_edit_load_mounts_the_editor_of_the_page_its_slug_names( string $slug, string $page, string $bundle, string $handle ): void {
		$GLOBALS['_current_user_can'] = true;
		$this->use_min_lifetime( 5400 );
		$this->build = \sys_get_temp_dir() . '/eln-editors-' . \uniqid();
		\mkdir( "{$this->build}/{$bundle}", 0777, true );
		\file_put_contents( "{$this->build}/{$bundle}/layout-editor.js", 'x' );
		\file_put_contents( "{$this->build}/{$bundle}/layout-editor.asset.php", "<?php return [ 'dependencies' => [ 'wp-element' ], 'version' => 'kea-7983' ];" );
		$_GET['edit'] = $page;
		Compositions::enqueue( $slug, 'newspack-nodes-page-7983', $this->build, 'https://kea-7983.test/build' );
		$this->assertContains( $handle, \array_column( $GLOBALS['_enqueued_scripts'], 0 ) );
		$this->assertSame( "https://kea-7983.test/build/{$bundle}/layout-editor.js", $GLOBALS['_registered_scripts'][ $handle ]['src'] );
		$this->assertSame( "newspack-event-logger-nodes/{$page}", $GLOBALS['_localized_scripts'][ $handle ]['data']['context'] );
	}

	public function test_an_edit_naming_another_page_mounts_no_editor(): void {
		$GLOBALS['_current_user_can'] = true;
		$this->build = \sys_get_temp_dir() . '/eln-editors-' . \uniqid();
		\mkdir( "{$this->build}/log-pages-editor", 0777, true );
		\file_put_contents( "{$this->build}/log-pages-editor/layout-editor.js", 'x' );
		\file_put_contents( "{$this->build}/log-pages-editor/layout-editor.asset.php", "<?php return [ 'dependencies' => [ 'wp-element' ], 'version' => 'kea-7984' ];" );
		$_GET['edit'] = 'gyroscope';
		Compositions::enqueue( 'event-logger-errors', 'newspack-nodes-log-pages-7984', $this->build, 'https://kea-7984.test/build' );
		$this->assertSame( 'error-log', $this->printed_page_data( 'newspack-nodes-log-pages-7984' )['page'] );
		$this->assertSame( [], $GLOBALS['_enqueued_scripts'] );
	}

	public function test_the_page_data_cannot_close_its_script_element(): void {
		$GLOBALS['_current_user_can'] = true;
		\update_option( 'newspack_nodes_composition:newspack-event-logger-nodes/error-log', [ 'markup' => '<!-- wp:paragraph --><p><strong>ruru 7981</strong></p><!-- /wp:paragraph -->', 'actor' => 'kea', 'ts' => 1.5 ] );
		Compositions::enqueue( 'event-logger-errors', 'newspack-nodes-log-pages-7981', \sys_get_temp_dir(), 'https://kea-7981.test/build' );
		$this->assertStringNotContainsString( '</strong>', $GLOBALS['_inline_scripts'][0][1] );
		$this->assertStringContainsString( 'ruru 7981', $GLOBALS['_inline_scripts'][0][1] );
	}
}
