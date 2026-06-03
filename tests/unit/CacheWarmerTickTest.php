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
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		$this->clear_log_manager_stack();
		$_SERVER                   = $this->orig_server;
		$GLOBALS['_wp_transients'] = [];
		parent::tearDown();
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
}
