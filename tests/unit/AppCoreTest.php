<?php
/**
 * Tests for Newspack_Event_Logger_Nodes\App\Core.
 *
 * Class is named AppCoreTest (not CoreTest) to avoid colliding with the
 * runtime substrate's existing CoreTest in the same monorepo, since both test
 * suites resolve from PHPUnit's classmap.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Core;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\LogManager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Core::class )]
class AppCoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}
		LogManager::reset();
		$GLOBALS['_wp_options']             = [];
		$GLOBALS['_wp_test_options']        = [];
		$GLOBALS['_wp_test_current_filter'] = '';
		$GLOBALS['_wp_test_filters']        = [];
		$GLOBALS['wp_filter']               = [];
		// Reset filter registry that Config's apply_filters() walks.
		$GLOBALS['_wp_actions']             = [];
		// Add the option-schema entries the legacy bootstrap registered via
		// the `newspack_event_logger_option_schema_core` filter — Config keeps
		// the same filter shape under the new namespace
		// (`newspack_event_logger_nodes_option_schema_core`), so the test must
		// add them to make `log_events`/`significant_events`/etc. round-trip
		// through `Config::load_config()`.
		$GLOBALS['_wp_actions']['newspack_event_logger_nodes_option_schema_core'][] = function ( $schema ) {
			return \array_merge(
				$schema,
				[
					'log_urls'            => 'array_strings',
					'skip_urls'           => 'array_strings',
					'log_events'          => 'array_strings',
					'custom_events'       => 'array_strings',
					'log_memory'          => 'bool',
					'flush_every_line'    => 'bool',
					'significant_events'  => 'array_strings',
					'hook_start_priority' => 'int',
				]
			);
		};
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_options']             = [];
		$GLOBALS['_wp_test_options']        = [];
		$GLOBALS['_wp_test_current_filter'] = '';
		$GLOBALS['_wp_test_filters']        = [];
		$GLOBALS['_wp_actions']             = [];
		$GLOBALS['wp_filter']               = [];
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}
		LogManager::reset();
		parent::tearDown();
	}

	private function require_config_or_skip(): void {
		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			$this->markTestSkipped( 'Config class not yet available (parallel agent porting).' );
		}
		// LogManager uses wp_json_encode; Core uses current_filter. Both are
		// not currently stubbed (REPORTED in agent report).
		foreach ( [ 'wp_json_encode', 'current_filter' ] as $fn ) {
			if ( ! \function_exists( $fn ) ) {
				$this->markTestSkipped( "WP function stub `{$fn}` not yet in tests/bootstrap.php; see agent report." );
			}
		}
	}

	/**
	 * App\Core::__construct calls `add_filter($hook, $cb, $priority)` with three
	 * arguments. The current tests/bootstrap.php only declares `add_filter`
	 * with the two-arg signature `(string $hook, callable $cb)` — passing a
	 * third argument blows up. Skip the filter-registration tests until the
	 * bootstrap is widened (REPORTED in the agent report); the short_name and
	 * pass-through tests still run unmodified.
	 */
	private function require_priority_aware_add_filter_or_skip(): void {
		try {
			$ref = new \ReflectionFunction( 'add_filter' );
			if ( $ref->getNumberOfParameters() < 3 ) {
				$this->markTestSkipped( 'tests/bootstrap.php add_filter() stub has no priority arg yet — see agent report for needed widening.' );
			}
		} catch ( \ReflectionException $e ) {
			$this->markTestSkipped( 'add_filter not declared.' );
		}
	}

	/**
	 * Set config via WP option stubs (overrides file config). Writes to BOTH
	 * `_wp_options` (newspack-event-logger-nodes/tests/bootstrap.php convention)
	 * and `_wp_test_options` (legacy convention) so the test is robust whichever
	 * stub the bootstrap declares.
	 *
	 * @param array $config Config key/value pairs.
	 */
	private function use_config( array $config ): void {
		$this->require_config_or_skip();
		foreach ( $config as $key => $value ) {
			if ( false === $value ) {
				$value = '0';
			}
			$GLOBALS['_wp_options'][ "newspack_event_logger_nodes_{$key}" ]      = $value;
			$GLOBALS['_wp_test_options'][ "newspack_event_logger_nodes_{$key}" ] = $value;
		}
		Config::reset();
		LogManager::reset();
	}

	// ── short_name tests via reflection ─────────────────────────────────

	public function test_short_name_string_function(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame( 'do_blocks', $ref->invoke( null, 'do_blocks' ) );
	}

	public function test_short_name_namespaced_string(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame( 'do_stuff', $ref->invoke( null, 'Some\\Namespace\\do_stuff' ) );
	}

	public function test_short_name_array_class_method_string(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame(
			'Image_CDN::filter_the_content',
			$ref->invoke( null, [ 'My\\Namespace\\Image_CDN', 'filter_the_content' ] )
		);
	}

	public function test_short_name_array_object_method(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame(
			'stdClass::some_method',
			$ref->invoke( null, [ new \stdClass(), 'some_method' ] )
		);
	}

	public function test_short_name_closure(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$closure = function () { return 42; };
		$result  = $ref->invoke( null, $closure );
		$this->assertStringContainsString( '{closure}', $result );
		$this->assertStringContainsString( 'AppCoreTest.php', $result );
	}

	public function test_short_name_invokable_object(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$invokable = new class() {
			public function __invoke() {}
		};
		$this->assertStringContainsString( '::__invoke', $ref->invoke( null, $invokable ) );
	}

	public function test_short_name_unknown_type(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame( '{unknown}', $ref->invoke( null, 42 ) );
	}

	public function test_short_name_single_element_array(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );
		$ref->setAccessible( true );

		$this->assertSame( '{unknown}', $ref->invoke( null, [ 'only_one' ] ) );
	}

	// ── Constructor tests ───────────────────────────────────────────────

	public function test_constructor_disabled_logging(): void {
		$this->require_config_or_skip();
		// With logging disabled (default), constructor returns early BEFORE
		// any add_filter() call — so this test is safe even with the 2-arg stub.
		$core = new Core();
		$this->assertInstanceOf( Core::class, $core );
	}

	public function test_constructor_registers_hook_filters(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'      => true,
			'log_events'          => [ 'the_content', 'wp_head' ],
			'significant_events'  => [ 'the_content hook' ],
			'hook_start_priority' => 1,
		] );

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayHasKey( 'the_content', $filters );
		$this->assertArrayHasKey( 'wp_head', $filters );
		// Two priorities: start (from config) and complete (PHP_INT_MAX-1).
		$priorities = \array_keys( $filters['the_content'] );
		$this->assertCount( 2, $priorities );
		$this->assertContains( PHP_INT_MAX - 1, $priorities );
	}

	public function test_constructor_skips_plugin_loaded(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging' => true,
			'log_events'     => [ 'plugin_loaded', 'init' ],
		] );

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayNotHasKey( 'plugin_loaded', $filters );
		$this->assertArrayHasKey( 'init', $filters );
	}

	public function test_constructor_skips_empty_hook_names(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging' => true,
			'log_events'     => [ '', 'init', 42 ],
		] );

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayNotHasKey( '', $filters );
		$this->assertArrayHasKey( 'init', $filters );
	}

	public function test_constructor_skips_internal_namespace_hooks(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging' => true,
			'log_events'     => [
				'newspack_event_logger_nodes_anything',
				'newspack_nodes_anything',
				'newspack_event_logger_anything',
				'newspack_performance_logger_anything',
				'init',
			],
		] );

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_anything', $filters );
		$this->assertArrayNotHasKey( 'newspack_nodes_anything', $filters );
		$this->assertArrayNotHasKey( 'newspack_event_logger_anything', $filters );
		$this->assertArrayNotHasKey( 'newspack_performance_logger_anything', $filters );
		$this->assertArrayHasKey( 'init', $filters );
	}

	// ── hook_start / hook_complete pass-through tests ───────────────────

	public function test_hook_start_passes_through_value(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$this->assertSame( 'hello world', $core->hook_start( 'hello world' ) );
	}

	public function test_hook_start_passes_through_null(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'wp_head';

		$this->assertNull( $core->hook_start() );
	}

	public function test_hook_complete_passes_through_value(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$this->assertSame( 'test output', $core->hook_complete( 'test output' ) );
	}

	public function test_hook_complete_passes_through_null(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'wp_head';

		$this->assertNull( $core->hook_complete() );
	}

	public function test_hook_start_with_integer(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'test_hook';

		$this->assertSame( 42, $core->hook_start( 42 ) );
	}

	public function test_hook_start_with_array(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'test_hook';

		$input = [ 'key' => 'value' ];
		$this->assertSame( $input, $core->hook_start( $input ) );
	}

	public function test_hook_start_with_long_string(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$long_string = \str_repeat( 'x', 2000 );
		$this->assertSame( $long_string, $core->hook_start( $long_string ) );
	}

	public function test_hook_start_with_boolean(): void {
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'test_hook';

		$this->assertTrue( $core->hook_start( true ) );
	}

	// ── significant_events parsing ──────────────────────────────────────

	public function test_constructor_parses_significant_events(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook', 'wp_head' ],
		] );

		$core = new Core();

		$ref = new \ReflectionProperty( Core::class, 'significant' );
		$ref->setAccessible( true );
		$sig = $ref->getValue( $core );

		$this->assertArrayHasKey( 'the_content', $sig );
		$this->assertArrayHasKey( 'wp_head', $sig );
	}

	// ── wrap_callbacks tests ────────────────────────────────────────────

	public function test_wrap_callbacks_skips_when_no_wp_filter(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$this->assertSame( 'test', $core->hook_start( 'test' ) );
	}

	public function test_wrap_callbacks_wraps_eligible_callbacks(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v . ' modified'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'test_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$wrapped = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];
		$this->assertNotSame( $original, $wrapped, 'Callback should be wrapped' );
		$this->assertSame( 99, $wp_filter['the_content']->callbacks[10]['test_cb']['accepted_args'] );
	}

	public function test_wrap_callbacks_skips_start_priority(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$ref      = new \ReflectionProperty( Core::class, 'start_priority' );
		$ref->setAccessible( true );
		$priority = $ref->getValue( $core );

		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			$priority => [
				'our_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$this->assertSame( $original, $wp_filter['the_content']->callbacks[ $priority ]['our_cb']['function'] );
	}

	public function test_wrap_callbacks_prevents_double_wrap(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'test_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );
		$first_wrap = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];

		$core->hook_start( 'test' );
		$second_wrap = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];

		$this->assertSame( $first_wrap, $second_wrap, 'Should not double-wrap' );
	}

	public function test_wrap_callbacks_skips_max_int_priority(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			PHP_INT_MAX - 1 => [
				'our_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$this->assertSame( $original, $wp_filter['the_content']->callbacks[ PHP_INT_MAX - 1 ]['our_cb']['function'] );
	}

	public function test_wrapped_callback_executes_and_returns_value(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function ( $v ) { return $v . ' MODIFIED'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'test_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$wrapped = $wp_filter['the_content']->callbacks[10]['test_cb']['function'];
		$result  = \call_user_func( $wrapped, 'hello' );
		$this->assertSame( 'hello MODIFIED', $result, 'Wrapped callback should return original callback result' );
	}

	public function test_accepted_args_zero_callback_works(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$original = function () { return 'no-args-result'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'zero_cb' => [
					'function'      => $original,
					'accepted_args' => 0,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$wrapped = $wp_filter['the_content']->callbacks[10]['zero_cb']['function'];
		$result  = \call_user_func( $wrapped, 'whatever' );
		$this->assertSame( 'no-args-result', $result );
	}
}
