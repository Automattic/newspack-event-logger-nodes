<?php
/**
 * Tests for Discovery_Collector_Node — the hub-side periodic discovery fan-out
 * + monotonic union-merge of spoke replies.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Discovery_Collector_Node;
use Newspack_Event_Logger_Nodes\Settings_Event_Writer;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Discovery_Collector_Node::class )]
class DiscoveryCollectorNodeTest extends TestCase {

	/** @var array<int,array<int,mixed>> Settings events captured by the suppress seam. */
	private array $settings_events = [];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']     = [];
		$this->settings_events      = [];
		Settings_Event_Writer::suppress( false );
		Settings_Event_Writer::$append_seam = function ( array $m ): void {
			$this->settings_events[] = $m;
		};
	}

	protected function tearDown(): void {
		Settings_Event_Writer::$append_seam = null;
		Settings_Event_Writer::suppress( false );
		parent::tearDown();
	}

	/** Build a named collector wired to a capturing sink and connected to a Tee target. */
	private function wired_node( Capture_Sink_Node $sink ): Discovery_Collector_Node {
		$sink->name( '_command_interpreter' );
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );
		$node->sink( $sink );
		$node->connect_node( 'spokes:tee' );
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

	public function test_fire_emits_one_discovery_get_command_to_tee(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$node->fire();

		$this->assertCount( 1, $sink->captured );
		$out = $sink->captured[0];
		$this->assertSame( Message::TM_COMMAND, $out[ Message::TYPE ] );
		$this->assertSame( 'spokes:tee/discovery', $out[ Message::TO ] );
		$this->assertSame( 'discovery-collector', $out[ Message::FROM ] );
		$this->assertSame( 'get', $out[ Message::VALUE ]['name'] );
		$this->assertSame( '', $out[ Message::VALUE ]['arguments'] );
	}

	public function test_arguments_arms_recurring_timer(): void {
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );

		$node->arguments( '300' );

		$this->assertSame( 300000, $node->interval_ms );
		$this->assertFalse( $node->oneshot );
		$this->assertGreaterThan( 0.0, $node->next_fire );
	}

	public function test_arguments_blank_arms_default_cadence(): void {
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );

		$node->arguments( '' );

		$this->assertSame( 300000, $node->interval_ms );
		$this->assertFalse( $node->oneshot );
	}

	public function test_fill_unions_registered_hooks_into_log_events(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded', 'shutdown' ] ] );
		$node->fill( $msg );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( [ 'init', 'wp_loaded', 'shutdown' ], $result );
	}

	public function test_fill_two_partial_replies_converge_to_union(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		// Out-of-order / partial replies: each folds in independently.
		$first  = $this->reply( [ 'registered_hooks' => [ 'shutdown' ] ] );
		$second = $this->reply( [ 'registered_hooks' => [ 'wp_loaded', 'init' ] ] );
		$node->fill( $first );
		$node->fill( $second );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		\sort( $result );
		$this->assertSame( [ 'init', 'shutdown', 'wp_loaded' ], $result );
	}

	public function test_fill_excludes_custom_events_from_log_events(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']    = [ 'init' ];
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [ 'my_custom' => true ];
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg = $this->reply( [
			'registered_hooks' => [ 'init', 'my_custom', 'wp_footer' ],
			'custom_events'    => [ 'my_custom' ],
		] );
		$node->fill( $msg );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertContains( 'wp_footer', $result );
		$this->assertNotContains( 'my_custom', $result, 'custom events must not pollute log_events' );

		$discovered = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] ?? [];
		$this->assertArrayHasKey( 'my_custom', $discovered );
	}

	public function test_fill_caps_log_events_at_max(): void {
		$max_events = ( new \ReflectionClassConstant( Discovery_Collector_Node::class, 'MAX_EVENTS' ) )->getValue();
		$existing   = [];
		for ( $i = 0; $i < $max_events; $i++ ) {
			$existing[] = "existing_{$i}";
		}
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = $existing;
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg = $this->reply( [ 'registered_hooks' => [ 'new_one', 'new_two' ] ] );
		$node->fill( $msg );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( $max_events, \count( $result ), 'cap must hold' );
	}

	public function test_fill_does_not_emit_a_settings_event(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] );
		$node->fill( $msg );

		$this->assertCount( 0, $this->settings_events, 'merge must run under suppress(true) — no bounce' );
	}

	public function test_fill_ignores_non_struct_message(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];
		$sink = new Capture_Sink_Node();
		$node = $this->wired_node( $sink );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'not an array';
		$node->fill( $msg );

		$this->assertSame( [ 'init' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
	}

	public function test_node_schema_is_monitor(): void {
		$schema = Discovery_Collector_Node::node_schema();

		$this->assertSame( 'Monitor', $schema['category'] );
		$this->assertTrue( $schema['has_target'] );
		$this->assertSame( [], $schema['arguments'] );
		$this->assertSame( [], $schema['commands'] );
	}
}
