<?php
/**
 * DiscoveryCollectorNodeMergeTest: the defensive / shape-variant branches of
 * the hub-side union-merge that the happy-path DiscoveryCollectorNodeTest
 * doesn't exercise — read-back arguments, non-array payload guards, and the
 * malformed-option fallbacks for the discovered_hooks / discovered_events
 * staging options. Discovery stages spoke-reported hooks/events into those
 * options; it never writes the ruleset (the editor is the only rules writer).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Nodes\Core;
use Newspack_Event_Logger_Nodes\Discovery_Collector_Node;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Discovery_Collector_Node::class )]
class DiscoveryCollectorNodeMergeTest extends TestCase {

	private const HOOKS_OPTION  = 'newspack_event_logger_nodes_discovered_hooks';
	private const EVENTS_OPTION = 'newspack_event_logger_nodes_discovered_events';

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

	/** @return array<string,mixed> The discovered_hooks staging option. */
	private function discovered_hooks(): array {
		$v = $GLOBALS['_wp_options'][ self::HOOKS_OPTION ] ?? [];
		return Core::arr( $v );
	}

	public function test_arguments_null_reads_back_last_set_value(): void {
		// arguments() arms the Router-hitchhike timer, which needs a live _router.
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );
		$node->arguments( [ '120' ] );

		$this->assertSame( [ '120' ], $node->arguments(), 'arguments() with no arg returns the last token list' );
	}

	public function test_fire_without_sink_is_a_no_op(): void {
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );

		// No sink wired — must drop silently rather than fatal on null->fill().
		$node->fire();

		$this->assertNull( $node->sink() );
	}

	public function test_fill_ignores_struct_value_without_array_payload(): void {
		$node = $this->wired_node( new Capture_Sink_Node() );

		// VALUE is an array, but `payload` is missing → merge must be skipped.
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_COMMAND | Message::TM_RESPONSE;
		$msg[ Message::VALUE ] = [ 'name' => 'get' ];
		$node->fill( $msg );

		$this->assertArrayNotHasKey( self::HOOKS_OPTION, $GLOBALS['_wp_options'], 'no payload → nothing staged' );
	}

	public function test_hooks_stage_into_discovered_hooks_and_never_touch_the_ruleset(): void {
		// Spoke-reported hooks accumulate in the discovered_hooks staging option;
		// the ruleset is not written (not even minted) — the editor owns rules.
		$node = $this->wired_node( new Capture_Sink_Node() );

		$node->fill( $this->reply( [ 'registered_hooks' => [ 'wp_loaded', 'init' ] ] ) );

		$this->assertArrayHasKey( 'wp_loaded', $this->discovered_hooks() );
		$this->assertArrayHasKey( 'init', $this->discovered_hooks() );
		$this->assertArrayNotHasKey( Rule_Set::OPTION_RULES, $GLOBALS['_wp_options'], 'discovery must never write the ruleset' );
	}

	public function test_hooks_union_across_replies_and_dedupe(): void {
		$node = $this->wired_node( new Capture_Sink_Node() );

		$node->fill( $this->reply( [ 'registered_hooks' => [ 'init', 'wp' ] ] ) );
		$node->fill( $this->reply( [ 'registered_hooks' => [ 'wp', 'wp_loaded' ] ] ) );

		$this->assertSame( [ 'init', 'wp', 'wp_loaded' ], \array_keys( $this->discovered_hooks() ) );
	}

	public function test_merge_recovers_when_discovered_hooks_option_is_not_an_array(): void {
		$GLOBALS['_wp_options'][ self::HOOKS_OPTION ] = 'corrupt-scalar';
		$node = $this->wired_node( new Capture_Sink_Node() );

		$node->fill( $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] ) );

		$this->assertArrayHasKey( 'wp_loaded', $this->discovered_hooks() );
	}

	public function test_merge_recovers_when_discovered_events_option_is_not_an_array(): void {
		$GLOBALS['_wp_options'][ self::EVENTS_OPTION ] = 'corrupt-scalar';
		$node = $this->wired_node( new Capture_Sink_Node() );

		$node->fill( $this->reply( [ 'custom_events' => [ 'evt_a' ] ] ) );

		$this->assertArrayHasKey( 'evt_a', $GLOBALS['_wp_options'][ self::EVENTS_OPTION ] );
	}
}
