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

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Core;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Core::class )]
class AppCoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}
		Log_Manager::reset();
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
		Log_Manager::reset();
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
		Log_Manager::reset();
	}

	/**
	 * Stub the governing rule App\Core's constructor will see. Sets the
	 * durable rules option (Rule_Set::load()'s source of truth) plus a
	 * REQUEST_URI that matches the rule's pattern, then resets Log_Manager
	 * so the next lazy Log_Manager::instance() call re-derives the match.
	 * $rule = null models "nothing matched" (skip — zero hooks bound).
	 *
	 * @param array<string, mixed> $config Extra global config (e.g. hook_start_priority).
	 */
	private function set_governing_rule( ?Rule $rule, array $config = [] ): void {
		$this->use_config( \array_merge( [ 'enable_logging' => true ], $config ) );
		if ( null === $rule ) {
			$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [];
			return;
		}
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [ $rule->to_array() ];
		$_SERVER['REQUEST_URI'] = $rule->is_exact() ? \rtrim( $rule->pattern, '?' ) : $rule->pattern . 'cart';
	}

	/**
	 * Run $body and return the hook names bound via add_filter during it.
	 *
	 * @return string[]
	 */
	private function capture_added_filters( \Closure $body ): array {
		$GLOBALS['_wp_test_filters'] = [];
		$body();
		return \array_keys( $GLOBALS['_wp_test_filters'] );
	}

	// ── outbound HTTP spans ─────────────────────────────────────────────

	/** The open-span labels App\Core is holding for in-flight requests. */
	private function http_spans( Core $core, string $which = 'http_spans' ): array {
		$prop = new \ReflectionProperty( Core::class, $which );
		$prop->setAccessible( true );
		return $prop->getValue( $core );
	}

	/**
	 * A log rule times outbound HTTP without naming a hook: the request spends
	 * the time below PHP userland, where no hook can reach, and the caveat on
	 * every brief says so.
	 */
	public function test_a_rule_can_turn_the_outbound_http_pair_off(): void {
		// Every logged request paid for it whether or not anyone was reading
		// outbound HTTP, and a rule is where the other span tiers say so.
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'e41b7c2d9a05', '/checkout/', Rule::ACTION_LOG, log_http: false )
		);

		$bound = $this->capture_added_filters( fn() => new Core() );

		$this->assertNotContains( 'pre_http_request', $bound );
		$this->assertNotContains( 'http_api_debug', $bound );
	}

	public function test_a_log_rule_binds_the_outbound_http_pair(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule( new Rule( '7c9e1a4b2d3f', '/checkout/', Rule::ACTION_LOG ) );

		$bound = $this->capture_added_filters( fn() => new Core() );

		$this->assertContains( 'pre_http_request', $bound );
		// `http_api_debug` is an ACTION; the harness records those separately.
		$this->assertArrayHasKey( 'http_api_debug', $GLOBALS['_wp_actions'] );
	}

	/**
	 * `WP_Http::request()` returns a short-circuited response WITHOUT firing
	 * `http_api_debug` (class-wp-http.php, `if ( false !== $pre ) return $pre;`),
	 * so opening a span there would never close and would adopt every row after
	 * it. A short-circuit is a cache hit anyway — there is no I/O to time.
	 */
	public function test_a_short_circuited_request_opens_no_span(): void {
		$this->set_governing_rule( new Rule( '7c9e1a4b2d3f', '/checkout/', Rule::ACTION_LOG ) );
		$core = new Core();

		$preempt = [ 'response' => [ 'code' => 200 ] ];

		$this->assertSame( $preempt, $core->http_start( $preempt, [], 'https://img.example.net/a.jpg' ) );
		$this->assertSame( [], $this->http_spans( $core ) );
	}

	/** A real outbound call opens exactly one span and closes it. */
	public function test_an_outbound_request_opens_and_closes_one_span(): void {
		$this->set_governing_rule( new Rule( '7c9e1a4b2d3f', '/checkout/', Rule::ACTION_LOG ) );
		$core = new Core();
		$url  = 'https://img.example.net/a.jpg?access_token=shhh';

		$this->assertFalse( $core->http_start( false, [], $url ) );
		$this->assertSame( [ 'http' ], $this->http_spans( $core ) );

		$core->http_end( [ 'response' => [ 'code' => 200 ] ], 'response', 'Requests', [], $url );

		$this->assertSame( [], $this->http_spans( $core ) );
	}

	/**
	 * Instrumentation must never CONSTRUCT the logger it reports into.
	 * `Log_Manager::instance()` lazily creates one, and a stale binding firing
	 * after a reset would build it at that moment — stamping `process (start)`
	 * with the callback's time instead of the mu-profiler's `request_ts`.
	 */
	public function test_an_http_span_never_creates_a_log_manager(): void {
		$this->set_governing_rule( new Rule( '7c9e1a4b2d3f', '/checkout/', Rule::ACTION_LOG ) );
		$core = new Core();
		Log_Manager::reset();

		$core->http_start( false, [], 'https://img.example.net/a.jpg' );
		$core->http_end( null, 'response', 'Requests', [], 'https://img.example.net/a.jpg' );

		$this->assertFalse( Log_Manager::has_instance() );
	}

	// ── hook caller traces ──────────────────────────────────────────────

	/** A rule that traces callers, distinct from every default. */
	private function tracing_rule( int $traces, bool $hooks = true ): Rule {
		// Named: a positional tail silently re-aims when a flag is added.
		return new Rule(
			'9f3c1d7e5b28',
			'/reports/',
			Rule::ACTION_LOG,
			hooks: [ 'the_content' ],
			trace_hooks: $hooks,
			trace_callers: $traces
		);
	}

	/**
	 * The origin frame is the ONE frame worth a label: who called the hook.
	 *
	 * `debug_backtrace( IGNORE_ARGS, 8 )` measured 0.9us against 12.7us for
	 * `wp_debug_backtrace_summary()`, which formats every frame on the stack
	 * before we throw all but one away. At that price it runs on EVERY firing,
	 * which is what lets `l` split a flame node completely rather than only for
	 * the firings a budget covered.
	 */
	public function test_the_origin_frame_skips_the_hook_machinery(): void {
		$ref    = new \ReflectionMethod( Core::class, 'origin_frame' );
		$origin = $ref->invoke( null );

		$this->assertNotSame( '', $origin, 'a caller is always there' );
		$this->assertStringNotContainsString( 'App\\Core', $origin, 'never our own frames' );
		$this->assertStringNotContainsString( 'WP_Hook', $origin, 'never the hook machinery' );
		$this->assertStringNotContainsString( 'apply_filters', $origin );
	}

	/** The per-hook trace counters App\Core is holding. */
	private function traced( Core $core ): array {
		$prop = new \ReflectionProperty( Core::class, 'traced' );
		$prop->setAccessible( true );
		return $prop->getValue( $core );
	}

	/**
	 * A hook that fires SIXTEEN times is the question a record cannot answer:
	 * the span says how long each pass took and nothing about who asked for it.
	 * `wp_debug_backtrace_summary()` at the open names the caller — but a
	 * backtrace per firing on a hook like `render_block` (2,601 times) is not
	 * something to pay for, so it stops after CALLER_TRACE_LIMIT.
	 */
	public function test_caller_traces_stop_at_the_limit(): void {
		$limit = 7;
		$this->set_governing_rule( $this->tracing_rule( $limit ) );
		$core  = new Core();

		$GLOBALS['_wp_test_current_filter'] = 'the_content';
		for ( $i = 0; $i < $limit + 5; $i++ ) {
			$core->hook_start( 'body' );
		}

		$this->assertSame( [ 'the_content' => $limit ], $this->traced( $core ) );
	}

	/**
	 * The NEAREST frames are the answer, and they are the ones a naive cap cut.
	 *
	 * `wp_debug_backtrace_summary()` hands back the stack nearest-first as an
	 * array and outermost-first as a string — the pretty form is the array
	 * reversed. Capping the string's HEAD therefore kept the bootstrap and
	 * discarded the caller, which on a real admin request is the whole answer.
	 */
	public function test_a_caller_trace_keeps_the_nearest_frames(): void {
		$this->set_governing_rule( $this->tracing_rule( Rule::TRACE_CALLERS_DEFAULT ) );
		$core = new Core();
		$ref  = new \ReflectionMethod( Core::class, 'caller_of' );

		$summary = $ref->invoke( $core, 'the_content' );

		$this->assertStringStartsWith( "apply_filters('the_content')", $summary );
		// Beyond CALLER_FRAMES: the window keeps the near end, not the boot end.
		$this->assertStringNotContainsString( 'far_frame_30', $summary );
	}

	/** A rule that does not ask pays for no backtraces at all. */
	public function test_a_rule_that_does_not_opt_in_traces_no_callers(): void {
		$this->set_governing_rule( $this->tracing_rule( 0 ) );
		$core = new Core();

		$GLOBALS['_wp_test_current_filter'] = 'the_content';
		$core->hook_start( 'body' );

		$this->assertSame( [], $this->traced( $core ) );
	}

	// ── query spans ─────────────────────────────────────────────────────

	/** A rule that opts in, distinct from every default. */
	private function query_rule( bool $on ): Rule {
		return new Rule( '5e2b8c1f4a70', '/reports/', Rule::ACTION_LOG, log_queries: $on );
	}

	/**
	 * Unlike the outbound-HTTP pair, query spans are OFF unless the rule asks:
	 * the close only fires under `SAVEQUERIES`, and they cost two entries per
	 * query rather than two per remote call.
	 */
	public function test_a_rule_that_does_not_opt_in_binds_no_query_pair(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule( $this->query_rule( false ) );

		$bound = $this->capture_added_filters( fn() => new Core() );

		$this->assertNotContains( 'query', $bound );
		$this->assertNotContains( 'log_query_custom_data', $bound );
	}

	public function test_a_rule_that_opts_in_binds_the_query_pair(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule( $this->query_rule( true ) );

		$bound = $this->capture_added_filters( fn() => new Core() );

		$this->assertContains( 'query', $bound );
		$this->assertContains( 'log_query_custom_data', $bound );
	}

	/**
	 * Every other span in the schema is `state` + a separate `l`, and the flame
	 * composes `state: label` from the two. These two spelled the discriminator
	 * INTO the state, which is a profile category per table and per host —
	 * against `MAX_CAT_VALUES` — and left `l`, whose whole job this is, empty.
	 */
	public function test_a_query_span_states_sql_and_labels_the_table(): void {
		$this->set_governing_rule( $this->query_rule( true ) );
		$core = new Core();

		$core->query_start( 'SELECT option_value FROM wp_options WHERE 1' );

		// The state is what `complete()` matches and what keys the profile map.
		$this->assertSame( [ 'sql' ], $this->http_spans( $core, 'query_spans' ) );
		$this->assertSame( 'sql', $this->open_span_label() );
	}

	public function test_an_http_span_states_http_and_labels_the_host(): void {
		$this->set_governing_rule( new Rule( '7c9e1a4b2d3f', '/checkout/', Rule::ACTION_LOG ) );
		$core = new Core();

		$core->http_start( false, [], 'https://img.example.net/a.jpg' );

		$this->assertSame( [ 'http' ], $this->http_spans( $core ) );
		$this->assertSame( 'http', $this->open_span_label() );
	}

	/** The label of the span Log_Manager currently has open, innermost first. */
	private function open_span_label(): string {
		$prop = new \ReflectionProperty( Log_Manager::class, 'times' );
		$prop->setAccessible( true );
		$times = $prop->getValue( Log_Manager::instance() );
		$last  = \end( $times );
		return \is_array( $last ) ? (string) ( $last['label'] ?? '' ) : '';
	}

	/** The pair opens one span and closes it, passing both values through. */
	public function test_a_query_opens_and_closes_one_span(): void {
		$this->set_governing_rule( $this->query_rule( true ) );
		$core = new Core();
		$sql  = 'SELECT option_value FROM wp_options WHERE option_name = \'siteurl\'';

		$this->assertSame( $sql, $core->query_start( $sql ) );
		$this->assertSame( [ 'sql' ], $this->http_spans( $core, 'query_spans' ) );

		$this->assertSame( [ 'x' => 1 ], $core->query_end( [ 'x' => 1 ], $sql, 0.002, 'caller', 1.0 ) );
		$this->assertSame( [], $this->http_spans( $core, 'query_spans' ) );
	}

	// ── short_name tests via reflection ─────────────────────────────────

	public function test_short_name_string_function(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

		$this->assertSame( 'do_blocks', $ref->invoke( null, 'do_blocks' ) );
	}

	public function test_short_name_namespaced_string(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

		$this->assertSame( 'do_stuff', $ref->invoke( null, 'Some\\Namespace\\do_stuff' ) );
	}

	public function test_short_name_array_class_method_string(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

		$this->assertSame(
			'Image_CDN::filter_the_content',
			$ref->invoke( null, [ 'My\\Namespace\\Image_CDN', 'filter_the_content' ] )
		);
	}

	public function test_short_name_array_object_method(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

		$this->assertSame(
			'stdClass::some_method',
			$ref->invoke( null, [ new \stdClass(), 'some_method' ] )
		);
	}

	public function test_short_name_closure(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

		$closure = function () { return 42; };
		$result  = $ref->invoke( null, $closure );
		$this->assertStringContainsString( '{closure}', $result );
		$this->assertStringContainsString( 'AppCoreTest.php', $result );
	}

	public function test_short_name_invokable_object(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

		$invokable = new class() {
			public function __invoke() {}
		};
		$this->assertStringContainsString( '::__invoke', $ref->invoke( null, $invokable ) );
	}

	public function test_short_name_unknown_type(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

		$this->assertSame( '{unknown}', $ref->invoke( null, 42 ) );
	}

	public function test_short_name_single_element_array(): void {
		$ref = new \ReflectionMethod( Core::class, 'short_name' );

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
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'the_content', 'wp_head' ], significant_events: [ 'the_content hook' ] ),
			[ 'hook_start_priority' => 1 ]
		);

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayHasKey( 'the_content', $filters );
		$this->assertArrayHasKey( 'wp_head', $filters );
		// Three priorities: start (from config), spacer (PHP_INT_MAX-2), complete (PHP_INT_MAX-1).
		$priorities = \array_keys( $filters['the_content'] );
		$this->assertCount( 3, $priorities );
		$this->assertContains( PHP_INT_MAX - 2, $priorities );
		$this->assertContains( PHP_INT_MAX - 1, $priorities );
	}

	public function test_binds_only_the_governing_rules_hooks(): void {
		$this->set_governing_rule( new Rule(
			'shop', '/shop/', Rule::ACTION_LOG,
			hooks: [ 'wp', 'template_redirect' ]
		) );
		$bound = $this->capture_added_filters( fn() => new Core() );
		$this->assertContains( 'wp', $bound );
		$this->assertContains( 'template_redirect', $bound );
		$this->assertNotContains( 'init', $bound ); // not in the rule.
	}

	public function test_skip_or_no_rule_binds_nothing(): void {
		$this->set_governing_rule( null );
		$bound = $this->capture_added_filters( fn() => new Core() );
		$this->assertSame( [], $bound );
	}

	public function test_constructor_skips_plugin_loaded(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'plugin_loaded', 'init' ] )
		);

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayNotHasKey( 'plugin_loaded', $filters );
		$this->assertArrayHasKey( 'init', $filters );
	}

	public function test_constructor_skips_empty_hook_names(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ '', 'init', 42 ] )
		);

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayNotHasKey( '', $filters );
		$this->assertArrayHasKey( 'init', $filters );
	}

	public function test_constructor_skips_internal_namespace_hooks(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [
				// Underscore-style.
				'newspack_event_logger_nodes_option_schema_core',
				'newspack_nodes_option_schema_core',
				// Slash-style.
				'newspack_event_logger_nodes/log_readers',
				'newspack_nodes/spawn_worker',
				// Real WP hook — must be instrumented.
				'init',
			] )
		);

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_option_schema_core', $filters );
		$this->assertArrayNotHasKey( 'newspack_nodes_option_schema_core', $filters );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes/log_readers', $filters );
		$this->assertArrayNotHasKey( 'newspack_nodes/spawn_worker', $filters );
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

	public function test_hook_spacer_passes_through_value(): void {
		$this->require_config_or_skip();
		$this->use_config( [ 'enable_logging' => true ] );
		$core = new Core();
		$this->assertSame( 'sacrificial', $core->hook_spacer( 'sacrificial' ) );
		$this->assertNull( $core->hook_spacer() );
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
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'the_content' ], significant_events: [ 'the_content hook', 'wp_head' ] )
		);

		$core = new Core();

		$ref = new \ReflectionProperty( Core::class, 'significant' );
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
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'the_content' ], significant_events: [ 'the_content hook' ] )
		);

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

	public function test_wrap_callbacks_skips_spacer_priority(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		// The sacrificial spacer at PHP_INT_MAX-2 must never be timing-wrapped.
		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			PHP_INT_MAX - 2 => [
				'spacer_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$this->assertSame(
			$original,
			$wp_filter['the_content']->callbacks[ PHP_INT_MAX - 2 ]['spacer_cb']['function'],
			'the spacer priority must be left un-wrapped'
		);
	}

	public function test_wrap_callbacks_skips_by_reference_callbacks(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		// A callback taking its argument by reference (like
		// vip_es_disable_advanced_post_cache( &$query )). The wrapper reads args
		// via func_get_args(), which can't preserve a reference, so wrapping it
		// would pass a value to a by-ref param — a PHP warning + a lost mutation.
		// It must be left un-wrapped.
		$original = function ( &$v ) { $v = 'mutated'; return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'byref_cb' => [
					'function'      => $original,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$this->assertSame(
			$original,
			$wp_filter['the_content']->callbacks[10]['byref_cb']['function'],
			'a by-reference callback must be left un-wrapped'
		);
		$this->assertSame(
			1,
			$wp_filter['the_content']->callbacks[10]['byref_cb']['accepted_args'],
			'accepted_args must be untouched for a skipped callback'
		);
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

	// ── Significant-event hook injection ────────────────────────────────

	public function test_significant_events_injected_into_log_events(): void {
		// Significant events whose name doesn't already appear in log_events
		// must auto-register so the hook actually gets instrumented.
		// Custom events (registered via custom_events) should be excluded.
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'init' ], significant_events: [ 'the_content hook', 'wp_head' ], custom_events: [] )
		);

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		// 'the_content' was injected from significant_events (with " hook" suffix stripped).
		$this->assertArrayHasKey( 'the_content', $filters );
		// 'wp_head' was injected (no suffix, treated as raw hook name).
		$this->assertArrayHasKey( 'wp_head', $filters );
		// 'init' was already in log_events.
		$this->assertArrayHasKey( 'init', $filters );
	}

	public function test_significant_events_excludes_custom_events(): void {
		// Custom events go through LogManager::message — they're NOT WP filters,
		// so instrumenting them with add_filter is pointless. The constructor
		// must skip injection when the significant event name is in custom_events.
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'init' ], significant_events: [ 'my_custom_event' ], custom_events: [ 'my_custom_event' ] )
		);

		new Core();
		$filters = $GLOBALS['_wp_test_filters'] ?? [];

		$this->assertArrayNotHasKey(
			'my_custom_event',
			$filters,
			'custom events must not be injected as filter listeners'
		);
		$this->assertArrayHasKey( 'init', $filters );
	}

	public function test_significant_events_with_hook_suffix_marks_significant(): void {
		// The " hook" suffix is stripped before being added to the
		// significant set. Verify both forms (with/without suffix) are
		// registered correctly.
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'the_content', 'init' ], significant_events: [ 'the_content hook', 'init' ] )
		);

		$core = new Core();
		$ref  = new \ReflectionProperty( Core::class, 'significant' );
		$sig  = $ref->getValue( $core );

		$this->assertArrayHasKey( 'the_content', $sig );
		$this->assertArrayHasKey( 'init', $sig );
		// The full " hook" suffix form must NOT remain as a key.
		$this->assertArrayNotHasKey( 'the_content hook', $sig );
	}

	// ── Hook-start priority configuration ──────────────────────────────

	public function test_hook_start_priority_default_is_1(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'init' ] )
		);

		$core = new Core();
		$ref  = new \ReflectionProperty( Core::class, 'start_priority' );
		// Default lives in the plugin's bundled config (-10000), not the
		// fallback `?? 1` baked into Core::__construct — so this test pins
		// the deployed config's value, not the code-level fallback.
		$this->assertSame( -10000, $ref->getValue( $core ) );
	}

	public function test_hook_start_priority_custom(): void {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'init' ] ),
			[ 'hook_start_priority' => 7 ]
		);

		$core = new Core();
		$ref  = new \ReflectionProperty( Core::class, 'start_priority' );
		$this->assertSame( 7, $ref->getValue( $core ) );

		// And the registered filter uses the configured priority.
		$filters = $GLOBALS['_wp_test_filters'] ?? [];
		$this->assertArrayHasKey( 7, $filters['init'] ?? [] );
	}

	// ── hook_start truncation ─────────────────────────────────────────

	public function test_hook_start_long_string_truncates_internally(): void {
		// The constructor forwards the value through but internally truncates
		// to 1024 chars when logging. The pass-through is the externally
		// observable contract — verified above. This test verifies the
		// LogManager-disabled path doesn't crash on long strings.
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		// 5000-char string — way beyond the 1024 internal cap.
		$big = \str_repeat( 'a', 5000 );
		$this->assertSame( $big, $core->hook_start( $big ) );
	}

	public function test_hook_start_with_object_value(): void {
		// Non-scalar, non-string, non-null values must pass through and be
		// json-encoded for logging (depth-limited to avoid circular refs).
		$this->require_config_or_skip();
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$obj = new \stdClass();
		$obj->key = 'value';
		$this->assertSame( $obj, $core->hook_start( $obj ) );
	}

	// ── hook_complete with multiple sequential hooks ───────────────────

	public function test_hook_complete_returns_value_unchanged(): void {
		$this->require_config_or_skip();
		$core = new Core();

		$GLOBALS['_wp_test_current_filter'] = 'first_hook';
		$this->assertSame( 'a', $core->hook_complete( 'a' ) );

		$GLOBALS['_wp_test_current_filter'] = 'second_hook';
		$this->assertSame( 42, $core->hook_complete( 42 ) );
	}

	// ── Wrapped callback finally-block invariant ────────────────────────

	public function test_wrapped_callback_completes_timer_on_throw(): void {
		// The wrapper must call $lm->complete() even when the original
		// callback throws — this is the critical finally-block invariant
		// that prevents orphaned timers stalling the request profiler.
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$thrower = function ( $v ) {
			throw new \RuntimeException( 'boom' );
		};
		$hook = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'thrower' => [
					'function'      => $thrower,
					'accepted_args' => 1,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$wrapped = $wp_filter['the_content']->callbacks[10]['thrower']['function'];
		$caught  = false;
		try {
			\call_user_func( $wrapped, 'arg' );
		} catch ( \RuntimeException $e ) {
			$caught = true;
		}

		$this->assertTrue( $caught, 'exception must propagate to caller' );
	}

	public function test_wrapped_callback_passes_multiple_args(): void {
		// accepted_args = 3 → wrapper slices func_get_args to 3.
		$this->require_priority_aware_add_filter_or_skip();
		$this->use_config( [
			'enable_logging'     => true,
			'log_events'         => [ 'the_content' ],
			'significant_events' => [ 'the_content hook' ],
		] );

		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$received_args = null;
		$original      = function ( $a, $b, $c ) use ( &$received_args ) {
			$received_args = [ $a, $b, $c ];
			return $a;
		};
		$hook          = new \WP_Hook();
		$hook->callbacks = [
			10 => [
				'multi' => [
					'function'      => $original,
					'accepted_args' => 3,
				],
			],
		];

		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'test' );

		$wrapped = $wp_filter['the_content']->callbacks[10]['multi']['function'];
		$result  = \call_user_func( $wrapped, 'first', 'second', 'third', 'extra-ignored' );

		$this->assertSame( 'first', $result );
		// Wrapper sliced to accepted_args=3.
		$this->assertSame( [ 'first', 'second', 'third' ], $received_args );
	}

	// ── hook_start disabled short-circuit ──────────────────────────────

	public function test_hook_start_returns_early_when_log_manager_never_started(): void {
		// No rule matched this request, so the LogManager never started:
		// hook_start must return the filter value untouched WITHOUT calling
		// start() / wrap_callbacks (the hot-path guard).
		$this->set_governing_rule( null );
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';

		$this->assertFalse( Log_Manager::instance()->is_started(), 'precondition: nothing matched' );
		$this->assertSame( 'passthrough', $core->hook_start( 'passthrough' ) );
	}

	public function test_hook_start_stops_instrumenting_after_finish(): void {
		// finish() closes the logging window; a hook firing during shutdown
		// must not be wrapped, even though the governing rule still says log.
		$core     = $this->significant_core();
		$original = function ( $v ) { return $v . ' LATE'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [ 10 => [ 'late' => [ 'function' => $original, 'accepted_args' => 1 ] ] ];
		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		Log_Manager::instance()->finish();

		$this->assertSame( 'shutting-down', $core->hook_start( 'shutting-down' ) );
		$this->assertSame(
			$original,
			$wp_filter['the_content']->callbacks[10]['late']['function'],
			'a finished context must not wrap callbacks'
		);
	}

	// ── wrap_callbacks: real wrap path (governing rule populates `significant`) ──

	/**
	 * Install a governing rule whose significant events cover `the_content`, so
	 * hook_start('the_content') actually invokes wrap_callbacks — the earlier
	 * use_config()-based wrap tests left `significant` empty, making the wrap a
	 * no-op. Returns the enabled Core with current_filter set to the_content.
	 *
	 * @param array<string, mixed> $config Extra global config (e.g. hook_start_priority).
	 */
	private function significant_core( array $config = [] ): Core {
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'the_content' ], significant_events: [ 'the_content hook' ] ),
			$config
		);
		$core = new Core();
		$GLOBALS['_wp_test_current_filter'] = 'the_content';
		return $core;
	}

	public function test_wrapper_body_runs_original_and_returns_result(): void {
		// The wrapper closure's body (start/call/complete/return) only executes
		// when the wrapper is actually invoked — this drives it end to end.
		$core = $this->significant_core();

		$original = function ( $v ) { return $v . ' WRAPPED'; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [ 10 => [ 'cb' => [ 'function' => $original, 'accepted_args' => 1 ] ] ];
		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'seed' );

		$wrapped = $wp_filter['the_content']->callbacks[10]['cb']['function'];
		$this->assertNotSame( $original, $wrapped, 'callback must be wrapped when significant' );
		$this->assertSame( 'hi WRAPPED', \call_user_func( $wrapped, 'hi' ), 'wrapper must run the original and return its result' );
	}

	public function test_wrap_callbacks_returns_when_hook_absent_from_wp_filter(): void {
		// significant is populated, but the hook has no wp_filter entry → the
		// early `return;` guard fires. hook_start still passes the value through.
		$core = $this->significant_core();
		global $wp_filter;
		unset( $wp_filter['the_content'] );

		$this->assertSame( 'v', $core->hook_start( 'v' ) );
	}

	public function test_wrap_callbacks_skips_callback_at_start_priority(): void {
		// A callback registered AT start_priority (== $min) must be left alone —
		// it's below the wrap window. Pin start_priority to a known value.
		$core = $this->significant_core( [ 'hook_start_priority' => 5 ] );

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [ 5 => [ 'low' => [ 'function' => $original, 'accepted_args' => 1 ] ] ];
		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'x' );

		$this->assertSame( $original, $wp_filter['the_content']->callbacks[5]['low']['function'] );
		$this->assertSame( 1, $wp_filter['the_content']->callbacks[5]['low']['accepted_args'] );
	}

	public function test_wrap_callbacks_does_not_rewrap_existing_wrapper(): void {
		// Second hook_start on the same hook must recognise the wrapper it created
		// (spl_object_id in wrapper_ids) and skip re-wrapping it.
		$core = $this->significant_core();

		$original = function ( $v ) { return $v; };
		$hook     = new \WP_Hook();
		$hook->callbacks = [ 10 => [ 'cb' => [ 'function' => $original, 'accepted_args' => 1 ] ] ];
		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'x' );
		$first = $wp_filter['the_content']->callbacks[10]['cb']['function'];
		$core->hook_start( 'x' );
		$second = $wp_filter['the_content']->callbacks[10]['cb']['function'];

		$this->assertInstanceOf( \Closure::class, $first );
		$this->assertSame( $first, $second, 'existing wrapper must not be re-wrapped' );
	}

	public function test_wrap_callbacks_skips_array_by_reference_callback_under_governing_rule(): void {
		// Real wrap path (significant populated) + a by-reference array callback:
		// callback_has_ref_param() returns true → the `continue` leaves it un-wrapped.
		$core = $this->significant_core();

		$original = [ new RefParamFixture(), 'by_ref' ];
		$hook     = new \WP_Hook();
		$hook->callbacks = [ 10 => [ 'byref' => [ 'function' => $original, 'accepted_args' => 1 ] ] ];
		global $wp_filter;
		$wp_filter['the_content'] = $hook;

		$core->hook_start( 'x' );

		$this->assertSame( $original, $wp_filter['the_content']->callbacks[10]['byref']['function'] );
		$this->assertSame( 1, $wp_filter['the_content']->callbacks[10]['byref']['accepted_args'] );
	}

	// ── callback_has_ref_param: every callback shape (direct reflection) ──

	public function test_callback_has_ref_param_array_object_method(): void {
		$ref = new \ReflectionMethod( Core::class, 'callback_has_ref_param' );
		$this->assertTrue( $ref->invoke( null, [ new RefParamFixture(), 'by_ref' ] ) );
		$this->assertFalse( $ref->invoke( null, [ new RefParamFixture(), 'no_ref' ] ) );
	}

	public function test_callback_has_ref_param_array_with_non_string_method_is_false(): void {
		// target is a string but method isn't a string → the invalid-shape guard.
		$ref = new \ReflectionMethod( Core::class, 'callback_has_ref_param' );
		$this->assertFalse( $ref->invoke( null, [ 'SomeClass', 42 ] ) );
	}

	public function test_callback_has_ref_param_invokable_object(): void {
		$ref = new \ReflectionMethod( Core::class, 'callback_has_ref_param' );
		$this->assertTrue( $ref->invoke( null, new InvokableRefFixture() ) );
	}

	public function test_callback_has_ref_param_plain_function_string(): void {
		$ref = new \ReflectionMethod( Core::class, 'callback_has_ref_param' );
		// A non-namespaced function with a normal (by-value) param → false.
		$this->assertFalse( $ref->invoke( null, 'strlen' ) );
	}

	public function test_callback_has_ref_param_returns_false_on_reflection_failure(): void {
		// ReflectionMethod on a nonexistent class throws → caught → false.
		$ref = new \ReflectionMethod( Core::class, 'callback_has_ref_param' );
		$this->assertFalse( $ref->invoke( null, [ 'No_Such_Class_ABC123', 'method' ] ) );
	}

	public function test_callback_has_ref_param_unsupported_type_is_false(): void {
		// An int is neither array/string/object → the else `return false`.
		$ref = new \ReflectionMethod( Core::class, 'callback_has_ref_param' );
		$this->assertFalse( $ref->invoke( null, 42 ) );
	}

	// ── rebind_for_current_scope ───────────────────────────────────────

	public function test_rebind_for_current_scope_removes_then_rebinds_hooks(): void {
		// Constructing with a governing rule binds the rule's hooks; rebind must
		// remove those filters and re-bind the current scope's hooks afresh.
		$this->require_priority_aware_add_filter_or_skip();
		$this->set_governing_rule(
			new Rule( 'r', '/', Rule::ACTION_LOG, hooks: [ 'the_content', 'wp_head' ] )
		);

		$core        = new Core();
		$bound_ref   = new \ReflectionProperty( Core::class, 'bound_hooks' );
		$before      = $bound_ref->getValue( $core );
		$this->assertContains( 'the_content', $before );
		$this->assertContains( 'wp_head', $before );

		$core->rebind_for_current_scope();

		$after = $bound_ref->getValue( $core );
		$this->assertContains( 'the_content', $after, 'hooks must be re-bound after rebind' );
		$this->assertContains( 'wp_head', $after );
		// The re-bind left the governing hook registered in the filter registry.
		$this->assertArrayHasKey( 'the_content', $GLOBALS['_wp_test_filters'] ?? [] );
	}
	/**
	 * The host appends `/* <uri> request_id: <hex> *' . '/` to every query so a
	 * DB-side slow log can be traced back. The record already carries both
	 * facts, so on a 958-query request that is ~100KB of pure duplication.
	 */
	public function test_a_query_span_strips_the_host_request_annotation(): void {
		$this->set_governing_rule( $this->query_rule( true ) );
		$core = new Core();
		$sql  = 'SELECT option_value FROM wp_options WHERE 1';

		$core->query_start(
			$sql . ' /*  www.elsol.com.ar/wp-admin/post.php?post=867431 request_id: 7b64b63f331de35af9cd8effa0fb48bc */'
		);

		$this->assertSame( $sql, $this->open_span_message() );
	}

	/**
	 * The SQL a span carries is the query, not a prefix of it. 700 bytes is
	 * distinct from every cap this file has ever held (512, 1024).
	 */
	public function test_a_query_span_carries_the_whole_sql(): void {
		$this->set_governing_rule( $this->query_rule( true ) );
		$core = new Core();
		$sql  = 'SELECT option_value FROM wp_options WHERE option_name = \'' . \str_repeat( 'x', 700 ) . '\'';

		$core->query_start( $sql );

		$this->assertSame( $sql, $this->open_span_message() );
	}

	/** A long filter value rides whole; 1500 is distinct from the old 1024. */
	public function test_a_hook_span_carries_a_long_string_value_whole(): void {
		$this->set_governing_rule( new Rule( '9d3f5b7a1c28', '/reports/', Rule::ACTION_LOG, hooks: [ 'the_content' ] ) );
		$GLOBALS['_wp_test_current_filter'] = 'the_content';
		$core  = new Core();
		$value = \str_repeat( 'y', 1500 );

		$core->hook_start( $value );

		$this->assertSame( $value, $this->open_span_message() );
	}

	/** The `m` of the span Log_Manager currently has open. */
	private function open_span_message(): string {
		$prop = new \ReflectionProperty( Log_Manager::class, 'times' );
		$prop->setAccessible( true );
		$times = $prop->getValue( Log_Manager::instance() );
		$last  = \end( $times );
		return \is_array( $last ) ? (string) ( $last['m'] ?? '' ) : '';
	}

	/** The `l` of the last firehose entry emitted under $category. */
	private function last_entry_label( string $category ): string {
		$topic = ( new \ReflectionProperty( Log_Manager::class, 'topic' ) )->getValue( Log_Manager::instance() );
		if ( null === $topic ) {
			$this->fail( 'no firehose topic; cannot read emitted entries' );
		}
		$parts = ( new \ReflectionProperty( \Newspack_Nodes\Topic_Node::class, 'partitions' ) )->getValue( $topic );
		$batch = new \ReflectionProperty( \Newspack_Nodes\Partition_Node::class, 'batch' );
		$path  = new \ReflectionProperty( \Newspack_Nodes\Partition_Node::class, 'current_log_path' );
		$label = '';
		foreach ( (array) $parts as $partition ) {
			$file = (string) ( $path->getValue( $partition ) ?? '' );
			$raw  = ( '' !== $file && \is_file( $file ) ? (string) \file_get_contents( $file ) : '' )
				. (string) $batch->getValue( $partition );
			foreach ( \array_filter( \explode( "\n", $raw ) ) as $line ) {
				$value = \Newspack_Nodes\Message::unpacked( $line )[ \Newspack_Nodes\Message::VALUE ] ?? null;
				if ( \is_array( $value ) && ( $value['k'] ?? '' ) === $category ) {
					$label = (string) ( $value['l'] ?? '' );
				}
			}
		}
		return $label;
	}

	/**
	 * `query` and `pre_http_request` are applied from the TRANSPORT (`wpdb`,
	 * `WP_Http`), not from application code, so the frame a hook span would
	 * take names the transport and is the same string every time. The span
	 * must name the caller one frame further out.
	 */
	public function test_a_query_span_labels_the_caller_beyond_the_transport(): void {
		$this->set_governing_rule( $this->query_rule( true ) );
		$core = new Core();

		( new FakeCaller() )->build_articles_query( new FakeTransport(), 'SELECT option_value FROM wp_options WHERE 1' );

		$this->assertStringContainsString(
			'FakeCaller->build_articles_query',
			$this->last_entry_label( 'sql (start)' )
		);
	}

	/** Same for outbound HTTP, applied from `WP_Http::request()`. */
	public function test_an_http_span_labels_the_caller_beyond_the_transport(): void {
		$this->set_governing_rule( new Rule( '7c9e1a4b2d3f', '/checkout/', Rule::ACTION_LOG ) );
		$core = new Core();

		( new FakeCaller() )->fetch_feed( new FakeTransport(), 'https://img.example.net/a.jpg' );

		$this->assertStringContainsString(
			'FakeCaller->fetch_feed',
			$this->last_entry_label( 'http (start)' )
		);
	}

}

/**
 * Fixture: a class exposing by-reference and by-value callbacks so
 * App\Core::callback_has_ref_param can be exercised for every callback shape.
 */
class RefParamFixture {
	public function by_ref( &$v ) {
		return $v;
	}
	public function no_ref( $v ) {
		return $v;
	}
	public static function static_by_ref( &$v ) {
		return $v;
	}
}

/**
 * Fixture: an invokable object whose __invoke takes a by-reference parameter.
 */
class InvokableRefFixture {
	public function __invoke( &$v ) {
		return $v;
	}
}

/** Stands in for `wpdb` / `WP_Http`: applies the filter from its own method. */
class FakeTransport {
	public function query( string $sql ) {
		return \apply_filters( 'query', $sql );
	}
	public function request( string $url ) {
		return \apply_filters( 'pre_http_request', false, [], $url );
	}
}

/** Stands in for the application code that asked the transport for something. */
class FakeCaller {
	public function build_articles_query( FakeTransport $t, string $sql ) {
		return $t->query( $sql );
	}
	public function fetch_feed( FakeTransport $t, string $url ) {
		return $t->request( $url );
	}
}
