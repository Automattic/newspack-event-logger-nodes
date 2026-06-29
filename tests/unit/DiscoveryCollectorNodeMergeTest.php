<?php
/**
 * DiscoveryCollectorNodeMergeTest: the defensive / shape-variant branches of
 * the hub-side union-merge that the happy-path DiscoveryCollectorNodeTest
 * doesn't exercise — read-back arguments, non-array payload guards, the
 * malformed-option fallbacks (option not an array), and the associative /
 * indexed list-shape normalizations in merge_hooks / merge_events.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Discovery_Collector_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Discovery_Collector_Node::class )]
class DiscoveryCollectorNodeMergeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options'] = [];
		$GLOBALS['_wp_actions'] = [];
	}

	/** Build a named collector wired to a capturing sink and a Tee target. */
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
		$msg[ Message::VALUE ] = [ 'name' => 'get', 'payload' => $payload ];
		return $msg;
	}

	public function test_arguments_null_reads_back_last_set_value(): void {
		// arguments() arms the Router-hitchhike timer, which needs a live _router.
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );
		$node->arguments( '120' );

		$this->assertSame( '120', $node->arguments(), 'arguments() with no arg returns the last raw string' );
	}

	public function test_fire_without_sink_is_a_no_op(): void {
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );

		// No sink wired — must drop silently rather than fatal on null->fill().
		$node->fire();

		$this->assertNull( $node->sink() );
	}

	public function test_fill_ignores_struct_value_without_array_payload(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];
		$node = $this->wired_node( new Capture_Sink_Node() );

		// VALUE is an array, but `payload` is missing → merge must be skipped.
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_COMMAND | Message::TM_RESPONSE;
		$msg[ Message::VALUE ] = [ 'name' => 'get' ];
		$node->fill( $msg );

		$this->assertSame( [ 'init' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
	}

	public function test_merge_recovers_when_log_events_option_is_not_an_array(): void {
		// A corrupt/scalar option must reset to [] before the union, not fatal.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = 'corrupt-scalar';
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] );
		$node->fill( $msg );

		$this->assertSame( [ 'wp_loaded' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
	}

	public function test_merge_recovers_when_custom_events_option_is_not_an_array(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']    = [ 'init' ];
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = 'corrupt-scalar';
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] );
		$node->fill( $msg );

		$this->assertContains( 'wp_loaded', $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
	}

	public function test_merge_excludes_custom_events_supplied_as_an_indexed_list(): void {
		// custom_events as an INDEXED list (string values, not assoc keys) must
		// still seed the exclusion lookup so it never leaks into log_events.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']    = [ 'init' ];
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [ 'my_custom' ];
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'my_custom', 'wp_footer' ] ] );
		$node->fill( $msg );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertContains( 'wp_footer', $result );
		$this->assertNotContains( 'my_custom', $result );
	}

	public function test_merge_normalizes_associative_log_events_keys(): void {
		// Existing log_events stored ASSOCIATIVELY (name => true) must normalize to
		// the flat indexed list before the union folds new hooks in.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' => true ];
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] );
		$node->fill( $msg );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		\sort( $result );
		$this->assertSame( [ 'init', 'wp_loaded' ], $result );
	}

	public function test_merge_recovers_when_discovered_events_option_is_not_an_array(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] = 'corrupt-scalar';
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'custom_events' => [ 'evt_a' ] ] );
		$node->fill( $msg );

		$this->assertArrayHasKey( 'evt_a', $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] );
	}
}
