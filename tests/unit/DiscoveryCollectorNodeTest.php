<?php
/**
 * Tests for Discovery_Collector_Node — the hub-side periodic discovery fan-out
 * + monotonic union-merge of spoke replies.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Nodes\Core;
use Newspack_Event_Logger_Nodes\Discovery_Collector_Node;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Settings_Event_Writer;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Discovery_Collector_Node::class )]
class DiscoveryCollectorNodeTest extends TestCase {

	/** @var array<int,array<int,mixed>> Settings events captured by the append seam. */
	private array $settings_events = [];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']     = [];
		$GLOBALS['_wp_actions']     = [];
		$this->settings_events      = [];
		Settings_Event_Writer::$append_seam = function ( array $m ): void {
			$this->settings_events[] = $m;
		};
	}

	protected function tearDown(): void {
		Settings_Event_Writer::$append_seam = null;
		unset( $GLOBALS['_test_fire_option_actions'] );
		parent::tearDown();
	}

	/** Wire the production watcher onto the WP option actions, like Settings_Event_Writer::init(). */
	private function wire_option_watcher(): void {
		$GLOBALS['_test_fire_option_actions'] = true;
		\add_action( 'update_option', [ Settings_Event_Writer::class, 'on_update' ], 10, 3 );
		\add_action( 'add_option', [ Settings_Event_Writer::class, 'on_add' ], 10, 2 );
		\add_action( 'delete_option', [ Settings_Event_Writer::class, 'on_delete' ], 10, 1 );
	}

	/** Build a named collector wired to a capturing sink and connected to a Tee target. */
	/**
	 * The collector now mints one SIGNED probe per spoke, so the harness wires a
	 * real egress node and gives it a session — a spoke it cannot sign for is
	 * skipped, which is the point of the change.
	 */
	private function wired_node( Capture_Sink_Node $sink ): Discovery_Collector_Node {
		$sink->name( '_command_interpreter' );
		\update_option( \Newspack_Nodes\Vault::OPTION_KEY, [ 'tw0' => [ 'url' => 'https://tw0.example' ] ] );
		\Newspack_Nodes\Vault::get_instance()->reset_cache();
		$egress = new \Newspack_Nodes\HTTP_Out_Node();
		$egress->name( 'spokes:tw0' );
		$egress->arguments( [ 'tw0' ] );
		\Newspack_Nodes\Command_Auth::remember_session( 'tw0', \str_repeat( '5', 32 ), 'harness-session-key' );

		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );
		$node->sink( $sink );
		$node->connect_node( 'spokes:tw0' );
		return $node;
	}

	/** A spoke reply Message carrying the unwrapped discovery payload in VALUE. */
	private function reply( array $payload ): array {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_COMMAND | Message::TM_RESPONSE;
		$msg[ Message::VALUE ] = [
			'name'    => 'get',
			'payload' => $payload,
		];
		return $msg;
	}

	/** Seed a `/` LOG rule; direct-assigned (not update_option) so it fires no settings event. */
	private function seed_rule( array $hooks = [] ): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'r', 'pattern' => '/', 'action' => 'log', 'hooks' => $hooks ],
		];
	}

	/** @return array<string,mixed> The discovered_hooks staging option. */
	private function discovered_hooks(): array {
		$v = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_hooks'] ?? [];
		return Core::arr( $v );
	}

	public function test_fire_emits_one_signed_discovery_get_per_spoke(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$node->fire();

		$this->assertCount( 1, $sink->captured );
		$out = $sink->captured[0];
		$this->assertSame( Message::TM_COMMAND, $out[ Message::TYPE ] );
		$this->assertSame( 'spokes:tw0/discovery', $out[ Message::TO ] );
		$this->assertSame( 'discovery-collector', $out[ Message::FROM ] );
		$this->assertSame( 'get', $out[ Message::VALUE ]['name'] );
		$this->assertSame( [], $out[ Message::VALUE ]['arguments'] );
	}

	public function test_arguments_arms_recurring_timer(): void {
		// A 300s cadence (interval_ms > 1000) now hitchhikes the Router TIMER and
		// throttles in fire_cb() — a real worker drain always has a _router.
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );

		$node->arguments( [ '300' ] );

		$this->assertSame( 300000, $node->interval_ms );
		$this->assertFalse( $node->oneshot );
		$mode = ( new \ReflectionObject( $node ) )->getProperty( 'mode' );
		$this->assertSame( 'router', $mode->getValue( $node ) );
	}

	public function test_arguments_blank_arms_default_cadence(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );

		$node->arguments( [] );

		$this->assertSame( 300000, $node->interval_ms );
		$this->assertFalse( $node->oneshot );
	}

	public function test_fill_unions_registered_hooks_into_discovered_hooks(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded', 'shutdown' ] ] );
		$node->fill( $msg );

		$this->assertSame( [ 'wp_loaded', 'shutdown' ], \array_keys( $this->discovered_hooks() ) );
	}

	public function test_fill_never_writes_the_ruleset(): void {
		// A seeded rule must be byte-identical after a merge — discovery stages
		// hooks, it never touches rules.
		$this->seed_rule( [ 'init' ] );
		$before = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$sink   = new Capture_Sink_Node();
		$node   = $this->wired_node( $sink );

		$node->fill( $this->reply( [ 'registered_hooks' => [ 'wp_loaded', 'shutdown' ] ] ) );

		$this->assertSame( $before, $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ], 'the ruleset is untouched' );
		$this->assertArrayHasKey( 'wp_loaded', $this->discovered_hooks() );
	}

	public function test_fill_two_partial_replies_converge_to_union(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		// Out-of-order / partial replies: each folds in independently.
		$node->fill( $this->reply( [ 'registered_hooks' => [ 'shutdown' ] ] ) );
		$node->fill( $this->reply( [ 'registered_hooks' => [ 'wp_loaded', 'shutdown' ] ] ) );

		$result = \array_keys( $this->discovered_hooks() );
		\sort( $result );
		$this->assertSame( [ 'shutdown', 'wp_loaded' ], $result );
	}

	public function test_fill_routes_hooks_and_custom_events_to_their_staging_options(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg = $this->reply( [
			'registered_hooks' => [ 'init', 'wp_footer' ],
			'custom_events'    => [ 'my_custom' ],
		] );
		$node->fill( $msg );

		$this->assertSame( [ 'init', 'wp_footer' ], \array_keys( $this->discovered_hooks() ) );
		$events = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] ?? [];
		$this->assertArrayHasKey( 'my_custom', $events );
	}

	public function test_fill_keeps_custom_events_out_of_discovered_hooks(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		// A name reported in BOTH lists stages only as an event — the hub filters
		// it out of the hook catalog (belt + suspenders with the spoke-side filter).
		$node->fill( $this->reply( [
			'registered_hooks' => [ 'init', 'my_custom' ],
			'custom_events'    => [ 'my_custom' ],
		] ) );

		$this->assertSame( [ 'init' ], \array_keys( $this->discovered_hooks() ), 'custom event excluded from hooks' );
		$events = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] ?? [];
		$this->assertArrayHasKey( 'my_custom', $events );
	}

	public function test_fill_caps_discovered_hooks_at_max(): void {
		$max_events = ( new \ReflectionClassConstant( Discovery_Collector_Node::class, 'MAX_EVENTS' ) )->getValue();
		$existing   = [];
		for ( $i = 0; $i < $max_events; $i++ ) {
			$existing[ "existing_{$i}" ] = true;
		}
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_hooks'] = $existing;
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$node->fill( $this->reply( [ 'registered_hooks' => [ 'new_one', 'new_two' ] ] ) );

		$this->assertSame( $max_events, \count( $this->discovered_hooks() ), 'cap must hold' );
	}

	public function test_fill_emits_no_settings_event_for_the_ruleset(): void {
		$this->wire_option_watcher();
		$this->seed_rule( [ 'init' ] );
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$node->fill( $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] ) );

		// The merge must actually stage the hook — otherwise the no-event assertion
		// below would pass vacuously on a broken staging path.
		$this->assertArrayHasKey( 'wp_loaded', $this->discovered_hooks(), 'the hook was staged' );

		// Discovery stages hooks in a non-autoloaded option; it never writes the
		// ruleset, so no rules settings event propagates to spokes.
		$options = \array_column( \array_column( $this->settings_events, Message::VALUE ), 'option' );
		$this->assertNotContains( Rule_Set::OPTION_RULES, $options, 'discovery must not emit a rules settings event' );
	}

	public function test_fill_ignores_non_struct_message(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'not an array';
		$node->fill( $msg );

		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_discovered_hooks', $GLOBALS['_wp_options'] );
	}

	public function test_node_schema_is_monitor(): void {
		$schema = Discovery_Collector_Node::node_schema();

		$this->assertSame( 'Monitor', $schema['category'] );
		$this->assertTrue( $schema['has_target'] );
		$this->assertContains( 'interval_seconds', \array_column( $schema['arguments'], 'name' ), 'editor CONSTRUCTOR panel must surface the interval arg' );
		$this->assertSame( [], $schema['commands'] );
	}
}
