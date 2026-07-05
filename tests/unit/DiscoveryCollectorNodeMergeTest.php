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
use Newspack_Event_Logger_Nodes\Rule_Set;
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

	/**
	 * Seed the ruleset with a baseline `/` LOG rule (the merge target). Direct
	 * assignment fires no settings event.
	 *
	 * @param string[] $hooks  Inline instrumented-hook list.
	 * @param string[] $custom Rule custom-event list.
	 */
	private function seed_baseline( array $hooks, array $custom = [] ): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'r', 'pattern' => '/', 'action' => 'log', 'hooks' => $hooks, 'custom_events' => $custom ],
		];
	}

	/** The baseline `/` LOG rule's current instrumented-hook list. */
	private function baseline_hooks(): array {
		$set = Rule_Set::load();
		foreach ( $set->rules() as $rule ) {
			if ( $rule->is_log() && '/' === $rule->pattern ) {
				return Rule_Set::hooks_for( $rule );
			}
		}
		return [];
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

	public function test_merge_recovers_when_rules_option_is_not_an_array(): void {
		// A corrupt/scalar rules option must fall back to the minimal baseline
		// before the union, not fatal.
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = 'corrupt-scalar';
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] );
		$node->fill( $msg );

		$this->assertSame( [ 'wp_loaded' ], $this->baseline_hooks() );
	}

	public function test_merge_mints_the_baseline_rule_when_absent(): void {
		// No rules seeded → Rule_Set::load() yields a minimal baseline the merge
		// folds hooks into without fataling.
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] );
		$node->fill( $msg );

		$this->assertContains( 'wp_loaded', $this->baseline_hooks() );
	}

	public function test_merge_excludes_rule_custom_events_supplied_as_an_indexed_list(): void {
		// The baseline rule's custom_events (an INDEXED list) seed the exclusion
		// lookup so a custom event never leaks into the instrumented-hook set.
		$this->seed_baseline( [ 'init' ], [ 'my_custom' ] );
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'my_custom', 'wp_footer' ] ] );
		$node->fill( $msg );

		$result = $this->baseline_hooks();
		$this->assertContains( 'wp_footer', $result );
		$this->assertNotContains( 'my_custom', $result );
	}

	public function test_merge_dedupes_existing_rule_hooks(): void {
		// Duplicates in the baseline rule's hooks normalize to a unique flat list
		// before the union folds new hooks in.
		$this->seed_baseline( [ 'init', 'init', 'wp' ] );
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'registered_hooks' => [ 'wp_loaded' ] ] );
		$node->fill( $msg );

		$result = $this->baseline_hooks();
		\sort( $result );
		$this->assertSame( [ 'init', 'wp', 'wp_loaded' ], $result );
	}

	public function test_merge_recovers_when_discovered_events_option_is_not_an_array(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] = 'corrupt-scalar';
		$node = $this->wired_node( new Capture_Sink_Node() );

		$msg = $this->reply( [ 'custom_events' => [ 'evt_a' ] ] );
		$node->fill( $msg );

		$this->assertArrayHasKey( 'evt_a', $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] );
	}
}
