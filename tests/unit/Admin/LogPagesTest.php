<?php
/**
 * LogPagesTest: the Request Log, Error Log and Gyroscope as composed pages —
 * their three contexts beside the Performance page's, the default layouts
 * `tests/fixtures/log-page-layouts.json` holds for the browser's tests, each
 * one log-stream block, and what each page's load sets before its bundle.
 *
 * `Composition` reads WordPress's block parser and schema validator, so this
 * suite runs with `WP_ABSPATH` naming an install.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin;

use Newspack_Event_Logger_Nodes\Admin\Compositions;
use Newspack_Event_Logger_Nodes\Admin\Log_Pages;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Tests\Helpers\Wp_Includes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/WpIncludes.php';

#[CoversClass( Log_Pages::class )]
#[CoversClass( Compositions::class )]
final class LogPagesTest extends TestCase {

	/** Each page's admin slug => its context. */
	private const CONTEXTS = [
		'event-logger-requests'  => 'newspack-event-logger-nodes/request-log',
		'event-logger-errors'    => 'newspack-event-logger-nodes/error-log',
		'event-logger-gyroscope' => 'newspack-event-logger-nodes/gyroscope',
	];

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
			[ Compositions::class, 'declare' ],
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

	/** @return array<string, array{string, string}> Each page's admin slug and context. */
	public static function pages(): array {
		$pages = [];
		foreach ( self::CONTEXTS as $slug => $context ) {
			$pages[ $slug ] = [ $slug, $context ];
		}
		return $pages;
	}

	public function test_the_fixture_holds_each_default_tree_the_browser_tests_read(): void {
		$fixture = \json_decode( (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/fixtures/log-page-layouts.json' ), true );
		$trees   = [];
		foreach ( self::CONTEXTS as $context ) {
			$trees[ $context ] = Composition::validate( $context, Composition::default_markup( $context ) );
		}
		$this->assertSame( $trees, $fixture );
	}

	#[DataProvider( 'pages' )]
	public function test_each_default_is_one_log_stream_block( string $slug, string $context ): void {
		$tree = Composition::validate( $context, Composition::default_markup( $context ) );
		$this->assertCount( 1, $tree, $slug );
		$this->assertSame( 'newspack-nodes/log-stream', $tree[0]['name'], $slug );
	}

	public function test_each_default_reads_its_own_stream_names_its_columns_and_heads_its_toolbar(): void {
		$read = [];
		foreach ( self::CONTEXTS as $context ) {
			$attributes        = Composition::validate( $context, Composition::default_markup( $context ) )[0]['attributes'];
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

	#[DataProvider( 'pages' )]
	public function test_each_context_admits_what_the_performance_page_admits( string $slug, string $context ): void {
		$this->assertSame(
			Composition::context_of( 'newspack-event-logger-nodes/performance' )['admits'],
			Composition::context_of( $context )['admits'],
			$slug
		);
	}

	public function test_one_callback_declares_the_four_pages_and_the_blocks_performance_owns(): void {
		$declared = Composition::declarations();
		$this->assertSame(
			[
				'newspack-event-logger-nodes/performance',
				'newspack-event-logger-nodes/request-log',
				'newspack-event-logger-nodes/error-log',
				'newspack-event-logger-nodes/gyroscope',
			],
			\array_values( \array_filter( \array_keys( $declared['contexts'] ), static fn ( string $name ): bool => \str_starts_with( $name, 'newspack-event-logger-nodes/' ) ) )
		);
		$this->assertArrayHasKey( 'newspack-event-logger-nodes/url-table', $declared['blocks'] );
	}

	#[DataProvider( 'pages' )]
	public function test_a_log_page_holds_sixteen_blocks( string $slug, string $context ): void {
		$this->assertSame( 16, Composition::context_of( $context )['max_blocks'], $slug );
	}

	public function test_the_menu_names_each_page_by_its_slug(): void {
		$this->assertSame(
			[
				'event-logger-requests'  => [ 'Request Log', 'Request Log' ],
				'event-logger-errors'    => [ 'Error Log', 'Errors' ],
				'event-logger-gyroscope' => [ 'Gyroscope', 'Gyroscope' ],
			],
			Log_Pages::menu()
		);
	}

	#[DataProvider( 'pages' )]
	public function test_page_data_names_the_page_and_holds_its_layout( string $slug, string $context ): void {
		$GLOBALS['_current_user_can'] = true;
		$page                         = \substr( $context, \strlen( 'newspack-event-logger-nodes/' ) );
		$data                         = Log_Pages::page_data( $page );
		$this->assertSame( $page, $data['page'], $slug );
		$this->assertTrue( $data['canTune'] );
		$this->assertTrue( $data['canManage'] );
		$this->assertSame( [ $context => Composition_Store::read( $context ) ], $data['compositions'] );
	}

	public function test_page_data_withholds_tune_from_a_reader(): void {
		$GLOBALS['_current_user_can'] = false;
		$data                         = Log_Pages::page_data( 'error-log' );
		$this->assertFalse( $data['canTune'] );
		$this->assertFalse( $data['canManage'] );
	}

	public function test_enqueue_refuses_a_slug_it_lays_out_no_page_at(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'event-logger-overview' );
		Log_Pages::enqueue( 'event-logger-overview', 'newspack-nodes-log-pages-7982', \sys_get_temp_dir(), 'https://kea-7982.test/build' );
	}

	public function test_enqueue_sets_the_page_data_before_the_bundle_and_loads_no_editor(): void {
		$GLOBALS['_current_user_can'] = true;
		$dir                          = \sys_get_temp_dir() . '/eln-log-pages-unbuilt-' . \uniqid();
		Log_Pages::enqueue( 'event-logger-gyroscope', 'newspack-nodes-log-pages-7980', $dir, 'https://kea-7980.test/build' );
		[ [ $handle, $script, $position ] ] = $GLOBALS['_inline_scripts'];
		$this->assertSame( [ 'newspack-nodes-log-pages-7980', 'before' ], [ $handle, $position ] );
		$prefix = 'window.' . Log_Pages::GLOBAL . ' = ';
		$this->assertStringStartsWith( $prefix, $script );
		$this->assertSame( Log_Pages::page_data( 'gyroscope' ), \json_decode( \substr( $script, \strlen( $prefix ), -1 ), true ) );
		$this->assertArrayNotHasKey( Log_Pages::EDITOR, $GLOBALS['_enqueued_scripts'] );
	}

	public function test_the_page_data_cannot_close_its_script_element(): void {
		$GLOBALS['_current_user_can'] = true;
		\update_option( 'newspack_nodes_composition:newspack-event-logger-nodes/error-log', [ 'markup' => '<!-- wp:paragraph --><p><strong>ruru 7981</strong></p><!-- /wp:paragraph -->', 'actor' => 'kea', 'ts' => 1.5 ] );
		Log_Pages::enqueue( 'event-logger-errors', 'newspack-nodes-log-pages-7981', \sys_get_temp_dir(), 'https://kea-7981.test/build' );
		$this->assertStringNotContainsString( '</strong>', $GLOBALS['_inline_scripts'][0][1] );
		$this->assertStringContainsString( 'ruru 7981', $GLOBALS['_inline_scripts'][0][1] );
	}
}
