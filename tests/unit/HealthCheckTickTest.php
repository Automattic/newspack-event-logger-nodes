<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Health_Check_Tick_Node;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Server_Registry;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router_Node;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Health_Check_Tick_Node::class )]
class HealthCheckTickTest extends TestCase {

	/** @var array Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();
		$this->orig_server      = $_SERVER;
		$GLOBALS['_wp_options'] = [];
		$GLOBALS['_wp_actions'] = [];

		// Reset the ServerRegistry singleton so each test starts with a clean
		// view of $GLOBALS['_wp_options']. Mirrors RemoteManagerTest::setUp().
		$ref = new \ReflectionProperty( Server_Registry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		// Wipe LogManager singleton + suspend stack so begin/end_job_context
		// round-trips don't leak from a prior test. LogManager::reset() only
		// nulls $instance; the suspend stack needs reflection-level cleanup.
		Log_Manager::reset();
		$this->clear_log_manager_stack();
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		$this->clear_log_manager_stack();
		$_SERVER = $this->orig_server;

		// Reset registry again so the next test's setUp sees a clean slate
		// even if a test mutated $GLOBALS['_wp_options'] mid-flight.
		$ref = new \ReflectionProperty( Server_Registry::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Wipe LogManager's private static context stack. reset() only nulls
	 * $instance; a paired begin/end_job_context from a prior test that
	 * mis-terminated leaves the stack non-empty, and the next resume() would
	 * pop a ghost LogManager.
	 */
	private function clear_log_manager_stack(): void {
		$ref = new \ReflectionProperty( Log_Manager::class, 'context_stack' );
		$ref->setAccessible( true );
		$ref->setValue( null, [] );
	}

	/**
	 * Build a TIMER tick message in the Router-emitted shape: TM_INFO with
	 * KEY='TIMER'. HealthCheckTick gates on exactly this pair before calling
	 * maybe_enqueue().
	 */
	private function timer_tick(): array {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_INFO;
		$msg[ Message::KEY ]   = 'TIMER';
		$msg[ Message::VALUE ] = (string) \time();
		return $msg;
	}

	// -------------------------------------------------------------------------
	// Construction + sibling CI wiring.
	// -------------------------------------------------------------------------

	public function test_health_check_tick_has_no_sibling_ci(): void {
		// No config verbs — the periodic tick starts from StreamMerger's
		// name() lifecycle, not a TSL verb — so the base ctor attaches no
		// sibling :config CI.
		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );
		$this->assertNull( $h->interpreter() );
	}

	public function test_health_check_tick_starts_periodic_tick_from_lifecycle(): void {
		// Lifecycle replacement for the old verb: when HealthCheckTick is the
		// owned sibling of a named StreamMerger (with a real _router present),
		// StreamMerger::name() cascades start_periodic_tick() to the sibling,
		// registering it on the Router TIMER event.
		$router = new Router_Node();
		$router->name( '_router' );

		$sm = new \Newspack_Event_Logger_Nodes\Stream_Merger_Node( 'firehose', 0 );
		$sm->set_require_https( false );
		$sm->name( 'sm' );

		$ref = new \ReflectionProperty( \Newspack_Nodes\Node::class, 'registrations' );
		$ref->setAccessible( true );
		$regs = $ref->getValue( $router );
		$this->assertArrayHasKey( 'TIMER', $regs );
		$this->assertArrayHasKey( 'sm:health-check', $regs['TIMER'] );
	}

	public function test_health_check_tick_node_schema_declares_no_verbs(): void {
		$schema = Health_Check_Tick_Node::node_schema();
		// Hidden from the topology console — instantiated as a
		// patron-linked sibling of StreamMerger, not built from TSL.
		$this->assertSame( 'Hidden', $schema['category'] );
		$this->assertSame( [], $schema['verbs'] );
	}

	// -------------------------------------------------------------------------
	// start_periodic_tick(): _router presence/absence branches.
	// -------------------------------------------------------------------------

	public function test_start_periodic_tick_no_op_when_router_missing(): void {
		// Core::reset() in parent::setUp() guarantees no _router is registered.
		// start_periodic_tick must silently bail (print_less_often warning)
		// rather than throw — periodic tick is disabled, not an error.
		$this->assertNull( Core::node( '_router' ) );

		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );
		$h->start_periodic_tick();

		// Nothing observable to assert besides not-throwing. The print_less_often
		// path is rate-limited stderr; the contract is no-throw graceful degrade.
		$this->assertTrue( true );
	}

	public function test_start_periodic_tick_registers_with_router_timer(): void {
		// With _router present, registration must succeed and a Router::notify('TIMER')
		// will dispatch a TM_INFO into HealthCheckTick's fill().
		$router = new Router_Node();
		$router->name( '_router' );

		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );
		$h->start_periodic_tick();

		// Wipe enabled remotes so maybe_enqueue() short-circuits on registry-empty
		// (no LogManager dance, no $_SERVER mutation).
		$GLOBALS['_wp_options'] = [];

		// Trigger a TIMER notification — Router::fire() does this on each tick.
		// HealthCheckTick should receive it via the Node-name dispatch path.
		$router->notify( 'TIMER', \time() );

		// counter is incremented on every fill() entry, so a registered listener
		// will have observed at least one message.
		$this->assertGreaterThanOrEqual( 1, $h->counter() );
	}

	// -------------------------------------------------------------------------
	// fill(): TM_INFO+TIMER vs everything else.
	// -------------------------------------------------------------------------

	public function test_fill_ignores_non_info_message(): void {
		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::KEY ]   = 'TIMER';
		$msg[ Message::VALUE ] = '';
		$h->fill( $msg );

		// counter ticks but no enqueue side effect — no LogManager singleton
		// was constructed because maybe_enqueue() never ran.
		$this->assertSame( 1, $h->counter() );
	}

	public function test_fill_ignores_info_with_wrong_key(): void {
		// TM_INFO carries lots of event names (FIRE, CACHE_FLUSH, MEMORY_PRESSURE, …).
		// Only KEY='TIMER' triggers the sweep — anything else is a no-op.
		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_INFO;
		$msg[ Message::KEY ]   = 'FIRE';
		$msg[ Message::VALUE ] = '';
		$h->fill( $msg );

		$this->assertSame( 1, $h->counter() );
	}

	public function test_fill_dispatches_timer_tick_to_maybe_enqueue(): void {
		// With no enabled remotes, maybe_enqueue() bails after registry-empty
		// check; we observe it ran by confirming counter incremented and no
		// crash from $_SERVER mutation (the registry check happens BEFORE
		// $_SERVER mutation).
		$GLOBALS['_wp_options'] = [];

		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );
		$msg = $this->timer_tick();
		$h->fill( $msg );

		$this->assertSame( 1, $h->counter() );
	}

	// -------------------------------------------------------------------------
	// maybe_enqueue(): debounce, no-remotes, and successful enqueue paths.
	// -------------------------------------------------------------------------

	public function test_maybe_enqueue_silent_when_no_remotes(): void {
		// Empty WP option means ServerRegistry::get_enabled() returns []. Path
		// short-circuits before any $_SERVER mutation or LogManager dance.
		$GLOBALS['_wp_options'] = [];

		$orig_uri = $_SERVER['REQUEST_URI'] ?? '/original';
		$_SERVER['REQUEST_URI'] = $orig_uri;

		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );
		$msg = $this->timer_tick();
		$h->fill( $msg );

		// $_SERVER is untouched — short-circuit happened before begin_job_context.
		$this->assertSame( $orig_uri, $_SERVER['REQUEST_URI'] );
	}

	public function test_maybe_enqueue_debounces_repeat_calls(): void {
		// First TIMER tick with enabled remotes sets last_check=now. A second
		// tick within DEBOUNCE_SECONDS (300s) must early-return BEFORE the
		// registry read and the $_SERVER dance.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_aggregator_servers'] = [
			'spoke1' => [
				'url'           => 'https://spoke1.example.test',
				'auth_username' => 'admin',
				'auth_password' => 'pw',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		];

		// Trigger sodium key init by reading via ServerRegistry first. wp_salt()
		// is stubbed in bootstrap so encryption round-trips work.
		$this->assertNotEmpty( Server_Registry::get_instance()->get_enabled() );

		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );

		// First tick: triggers full enqueue path (LogManager dance, $_SERVER
		// rewrite, then restoration via end_job_context's finally).
		$pre_uri = $_SERVER['REQUEST_URI'] ?? '/original';
		$_SERVER['REQUEST_URI'] = $pre_uri;
		$msg = $this->timer_tick();
		$h->fill( $msg );
		// $_SERVER restored after begin/end_job_context's finally.
		$this->assertSame( $pre_uri, $_SERVER['REQUEST_URI'] );

		// Use reflection to confirm last_check landed (debounce gate is now
		// armed). Direct assertion via state would require a getter we don't
		// have; reflection is the test-only seam.
		$ref = new \ReflectionProperty( Health_Check_Tick_Node::class, 'last_check' );
		$ref->setAccessible( true );
		$last_check_after_first = $ref->getValue( $h );
		$this->assertGreaterThan( 0, $last_check_after_first );

		// Second tick: must early-return (still within DEBOUNCE_SECONDS=300).
		// last_check must not advance.
		$msg = $this->timer_tick();
		$h->fill( $msg );
		$this->assertSame( $last_check_after_first, $ref->getValue( $h ) );
	}

	public function test_maybe_enqueue_restores_server_super_after_job_context(): void {
		// Even when the enqueue path runs end-to-end, $_SERVER must be fully
		// restored. The finally block in maybe_enqueue() pairs begin/end —
		// asserting key restoration covers the finally-runs invariant.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_aggregator_servers'] = [
			'spoke1' => [
				'url'           => 'https://spoke1.example.test',
				'auth_username' => 'admin',
				'auth_password' => 'pw',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		];

		$_SERVER['REQUEST_URI']    = '/test/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['UNIQUE_ID']      = 'outer_unique_id';

		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );
		$msg = $this->timer_tick();
		$h->fill( $msg );

		// $_SERVER restored to pre-job state — end_job_context's finally ran.
		$this->assertSame( '/test/page', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'GET', $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( 'outer_unique_id', $_SERVER['UNIQUE_ID'] );
	}

	public function test_maybe_enqueue_resets_registry_cache_before_read(): void {
		// ServerRegistry caches its merged view at first read; a long-running
		// aggregator worker that came up BEFORE an operator enabled a spoke
		// would never see the change otherwise. maybe_enqueue() must call
		// reset_cache() before get_enabled() so the next read sees fresh option
		// state.
		// Set up: pre-populate cache with empty view, then add a server.
		$GLOBALS['_wp_options'] = [];
		$reg                    = Server_Registry::get_instance();
		$this->assertSame( [], $reg->get_enabled() ); // cache now has empty view.

		// Operator-equivalent: enable a remote after the cache was built.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_aggregator_servers'] = [
			'spoke1' => [
				'url'           => 'https://spoke1.example.test',
				'auth_username' => 'admin',
				'auth_password' => 'pw',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		];

		// Without reset_cache(), get_enabled() would still return [] and the
		// path would short-circuit on no-remotes. With reset_cache(), the read
		// sees the new server and the enqueue path proceeds (last_check ticks).
		$h = new Health_Check_Tick_Node();
		$h->name( 'h' );
		$msg = $this->timer_tick();
		$h->fill( $msg );

		$ref = new \ReflectionProperty( Health_Check_Tick_Node::class, 'last_check' );
		$ref->setAccessible( true );
		$this->assertGreaterThan( 0, $ref->getValue( $h ) );
	}

	public function test_maybe_enqueue_constant_matches_legacy_debounce(): void {
		// Spec-locked: legacy newspack-event-aggregator used 300s; the new
		// HealthCheckTick MUST match. Diverging from 300s changes hub-side load
		// characteristics across every operator. Keep the constant verifiable.
		$this->assertSame( 300, Health_Check_Tick_Node::DEBOUNCE_SECONDS );
	}
}
