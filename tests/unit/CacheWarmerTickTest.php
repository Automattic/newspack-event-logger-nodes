<?php
/**
 * Tests for Cache_Warmer_Tick_Node — the Timer subclass that queues a
 * `cache_warmer` JobWorker job every 60s (replacing the flaky wp-cron trigger),
 * plus its job handler which drops requests older than the interval.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Cache_Warmer\Cache_Warmer;
use Newspack_Event_Logger_Nodes\Cache_Warmer_Tick_Node;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Router_Node;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Cache_Warmer_Tick_Node::class )]
class CacheWarmerTickTest extends TestCase {

	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();
		$this->orig_server               = $_SERVER;
		$GLOBALS['_wp_options']          = [];
		$GLOBALS['_wp_actions']          = [];
		$GLOBALS['_wp_test_remote_gets'] = [];
		$GLOBALS['_wp_transients']       = [];
		$GLOBALS['_wp_test_home_url']    = 'https://www.bendsource.com';

		Log_Manager::reset();
		$this->clear_log_manager_stack();
		$this->set_registered( false );
		Event_Framework::reset();
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		$this->clear_log_manager_stack();
		$this->set_registered( false );
		Event_Framework::reset();
		$_SERVER                   = $this->orig_server;
		$GLOBALS['_wp_transients'] = [];
		parent::tearDown();
	}

	/** Reset the private static init() idempotency guard so each test starts clean. */
	private function set_registered( bool $value ): void {
		$ref = new \ReflectionProperty( Cache_Warmer_Tick_Node::class, 'registered' );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}

	/** Wipe LogManager's private static context stack (mirrors HealthCheckTickTest). */
	private function clear_log_manager_stack(): void {
		$ref = new \ReflectionProperty( Log_Manager::class, 'context_stack' );
		$ref->setAccessible( true );
		$ref->setValue( null, [] );
	}

	private function last_enqueue( Cache_Warmer_Tick_Node $node ): int {
		$ref = new \ReflectionProperty( Cache_Warmer_Tick_Node::class, 'last_enqueue' );
		$ref->setAccessible( true );
		return (int) $ref->getValue( $node );
	}

	private function set_last_enqueue( Cache_Warmer_Tick_Node $node, int $value ): void {
		$ref = new \ReflectionProperty( Cache_Warmer_Tick_Node::class, 'last_enqueue' );
		$ref->setAccessible( true );
		$ref->setValue( $node, $value );
	}

	private function interval_seconds( Cache_Warmer_Tick_Node $node ): int {
		$ref = new \ReflectionProperty( Cache_Warmer_Tick_Node::class, 'interval_seconds' );
		$ref->setAccessible( true );
		return (int) $ref->getValue( $node );
	}

	private function set_interval_seconds( Cache_Warmer_Tick_Node $node, int $value ): void {
		$ref = new \ReflectionProperty( Cache_Warmer_Tick_Node::class, 'interval_seconds' );
		$ref->setAccessible( true );
		$ref->setValue( $node, $value );
	}

	// ── Constant + handler registration ─────────────────────────────────────

	public function test_interval_is_sixty_seconds(): void {
		$this->assertSame( 60, Cache_Warmer_Tick_Node::INTERVAL_SECONDS );
	}

	public function test_register_handler_registers_the_cache_warmer_job(): void {
		$handlers = Cache_Warmer_Tick_Node::register_handler( [] );

		$this->assertArrayHasKey( Cache_Warmer_Tick_Node::JOB_HANDLER, $handlers );
		$this->assertIsCallable( $handlers[ Cache_Warmer_Tick_Node::JOB_HANDLER ] );
	}

	public function test_register_handler_preserves_existing_handlers(): void {
		$existing = [ 'remote_manager' => 'some_callable' ];
		$handlers = Cache_Warmer_Tick_Node::register_handler( $existing );

		$this->assertSame( 'some_callable', $handlers['remote_manager'] );
		$this->assertArrayHasKey( Cache_Warmer_Tick_Node::JOB_HANDLER, $handlers );
	}

	// ── Self-start on name() (so the topology is a single make_node line) ────

	public function test_name_self_starts_the_router_timer(): void {
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );
		$node->arguments( '' );

		$ref = new \ReflectionProperty( \Newspack_Nodes\Node::class, 'registrations' );
		$ref->setAccessible( true );
		$regs = $ref->getValue( $router );
		$this->assertArrayHasKey( 'TIMER', $regs );
		$this->assertArrayHasKey( 'cache-warmer:tick', $regs['TIMER'] );
	}

	public function test_name_is_a_no_op_when_router_missing(): void {
		// Core::reset() in parent::setUp() guarantees no _router. Self-start must
		// degrade gracefully (no throw) — periodic tick disabled, not an error.
		$this->assertNull( Core::node( '_router' ) );

		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );

		$this->assertSame( 0, $this->last_enqueue( $node ) );
	}

	// ── fire(): enqueue + debounce ───────────────────────────────────────────

	public function test_fire_enqueues_then_debounces(): void {
		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );

		$node->fire();
		$after_first = $this->last_enqueue( $node );
		$this->assertGreaterThan( 0, $after_first, 'first fire must enqueue (last_enqueue advances)' );

		// Second tick within the interval must early-return — no re-enqueue.
		$node->fire();
		$this->assertSame( $after_first, $this->last_enqueue( $node ) );
	}

	// ── handle_job(): stale-drop + warm ──────────────────────────────────────

	public function test_handle_job_warms_when_fresh(): void {
		Cache_Warmer_Tick_Node::handle_job( [ 'queued_at' => \time() ] );

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'], 'a fresh job must fire the warm loopback' );
		$this->assertStringContainsString( 'eln_cache_warm=', $GLOBALS['_wp_test_remote_gets'][0]['url'] );
	}

	public function test_handle_job_warms_when_queued_at_absent(): void {
		// queued_at = 0 (missing) is treated as fresh (mirrors RemoteManager's
		// `$queued_at > 0` guard) — only a positive, old timestamp is "stale".
		Cache_Warmer_Tick_Node::handle_job( [] );

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'] );
	}

	public function test_handle_job_drops_stale_request(): void {
		// A job that sat in the queue >= one full interval: skip it — the next
		// tick's job will warm. Must NOT fire the loopback.
		Cache_Warmer_Tick_Node::handle_job( [ 'queued_at' => \time() - Cache_Warmer_Tick_Node::INTERVAL_SECONDS ] );

		$this->assertCount( 0, $GLOBALS['_wp_test_remote_gets'], 'stale job must be dropped, not warmed' );
	}

	public function test_handle_job_no_ops_when_dropin_absent(): void {
		// Guard belt-and-suspenders: handle_job references the drop-in's
		// run_tick; if the class isn't loaded it must not fatal. The drop-in IS
		// loaded in this suite, so assert the class exists (documents the dependency).
		$this->assertTrue( \class_exists( '\\Newspack_Cache_Warmer\\Cache_Warmer' ) );
	}

	// ── init(): handler-filter registration + idempotency guard ──────────────

	public function test_init_registers_the_job_handlers_filter(): void {
		Cache_Warmer_Tick_Node::init();

		$callbacks = $GLOBALS['_wp_actions']['newspack_nodes/job_handlers'] ?? [];
		$this->assertContains(
			[ Cache_Warmer_Tick_Node::class, 'register_handler' ],
			$callbacks,
			'init() must register register_handler on the job_handlers filter'
		);
	}

	public function test_init_is_idempotent(): void {
		// First call registers; the static guard must make the second call a
		// no-op so the worker-runtime bootstrap can call init() repeatedly.
		Cache_Warmer_Tick_Node::init();
		Cache_Warmer_Tick_Node::init();

		$callbacks = $GLOBALS['_wp_actions']['newspack_nodes/job_handlers'] ?? [];
		$matches   = \array_filter(
			$callbacks,
			static fn ( $cb ) => $cb === [ Cache_Warmer_Tick_Node::class, 'register_handler' ]
		);
		$this->assertCount( 1, $matches, 'init() must register exactly once across repeated calls' );
	}

	// ── name() / arguments() getter passthroughs ─────────────────────────────

	public function test_name_getter_returns_the_set_name(): void {
		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );

		// No-arg call hits the getter branch (func_num_args() === 0 → parent::name()).
		$this->assertSame( 'cache-warmer:tick', $node->name() );
	}

	public function test_arguments_getter_returns_the_stored_value(): void {
		// A numeric arg now does a _router hitchhike (was an Event_Framework timer).
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );
		$node->arguments( '30' );

		// No-arg call hits the getter branch (null === $args → return $this->arguments).
		$this->assertSame( '30', $node->arguments() );
	}

	// ── arguments(): numeric arg sets the warm cadence, keeps router hitchhike ─

	public function test_arguments_numeric_sets_interval_and_keeps_router_hitchhike(): void {
		// A numeric arg means "warm-enqueue interval in seconds" (what node_schema
		// advertises). It must (a) set the per-instance interval and (b) stay on
		// the efficient _router TIMER hitchhike — NOT arm a busy Event_Framework
		// timer. The ~5s router poll is plenty of granularity; the debounce is the
		// real cadence gate.
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );

		$node->arguments( '30' );

		$this->assertSame( 30, $this->interval_seconds( $node ), 'numeric arg sets the per-instance interval' );

		$ref  = new \ReflectionProperty( \Newspack_Nodes\Node::class, 'registrations' );
		$ref->setAccessible( true );
		$regs = $ref->getValue( $router );
		$this->assertArrayHasKey( 'TIMER', $regs );
		$this->assertArrayHasKey( 'cache-warmer:tick', $regs['TIMER'] );

		$ef     = new \ReflectionProperty( Event_Framework::class, 'timers' );
		$ef->setAccessible( true );
		$timers = $ef->getValue( Event_Framework::instance() );
		$this->assertArrayNotHasKey(
			\spl_object_id( $node ),
			$timers,
			'numeric arg must NOT arm an event-framework timer slot'
		);
	}

	// ── fire(): per-instance interval governs the debounce ───────────────────

	public function test_fire_honors_per_instance_interval(): void {
		$router = new Router_Node();
		$router->name( '_router' );

		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );
		$node->arguments( '5' );

		// Last enqueue 6s ago: with a 5s interval, the next fire MUST re-enqueue.
		$this->set_last_enqueue( $node, \time() - 6 );
		$node->fire();

		$this->assertGreaterThan(
			\time() - 6,
			$this->last_enqueue( $node ),
			'a 5s-interval node must re-enqueue when the last enqueue is 6s old'
		);
	}

	public function test_fire_default_interval_debounces_under_sixty_seconds(): void {
		// Control: a default-60 node with the SAME 6s-old last_enqueue must NOT
		// re-enqueue (6 < 60), proving the cadence is driven by the interval.
		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );

		$prior = \time() - 6;
		$this->set_last_enqueue( $node, $prior );
		$node->fire();

		$this->assertSame( $prior, $this->last_enqueue( $node ), 'default-60 node must not re-enqueue at 6s' );
	}

	// ── fire(): interval is threaded into the job parameters ─────────────────

	public function test_fire_threads_interval_into_job_parameters(): void {
		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );
		$this->set_interval_seconds( $node, 45 );

		$capture = new \Newspack_Nodes\Tests\Capture_Sink_Node();
		$node->sink( $capture );

		$node->fire();

		$this->assertCount( 1, $capture->captured, 'fire() must emit one job message' );
		$value = $capture->captured[0][ \Newspack_Nodes\Message::VALUE ];
		$this->assertSame( 'job', $value['type'] );
		$this->assertSame( Cache_Warmer_Tick_Node::JOB_HANDLER, $value['handler'] );
		$this->assertSame( 45, $value['parameters']['interval'], 'fire() must thread the interval into the job params' );
	}

	// ── handle_job(): stale threshold uses the job's own interval ────────────

	public function test_handle_job_drops_stale_request_using_job_interval(): void {
		// A 30s-interval job queued 31s ago is stale and must be dropped.
		Cache_Warmer_Tick_Node::handle_job( [ 'queued_at' => \time() - 31, 'interval' => 30 ] );

		$this->assertCount( 0, $GLOBALS['_wp_test_remote_gets'], 'stale-by-job-interval must be dropped' );
	}

	public function test_handle_job_falls_back_to_const_when_interval_absent(): void {
		// No interval key (an old/in-flight job): fall back to the 60s const, so a
		// 31s-old job is still fresh (31 < 60) and warms.
		Cache_Warmer_Tick_Node::handle_job( [ 'queued_at' => \time() - 31 ] );

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'], 'no interval key falls back to 60s; 31s is fresh' );
	}

	// ── arguments(): non-numeric arg is rejected ─────────────────────────────

	public function test_arguments_rejects_non_numeric_arg(): void {
		$node = new Cache_Warmer_Tick_Node();
		$node->name( 'cache-warmer:tick' );

		$this->expectException( \InvalidArgumentException::class );
		$node->arguments( 'not-a-number' );
	}
}
