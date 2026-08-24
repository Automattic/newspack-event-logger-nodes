<?php
/**
 * Verb-table tests for RequestBuilder's sibling :config CommandInterpreter.
 *
 * These verbs let TSL topology files declaratively wire the secondary
 * outputs RequestBuilder owns (errors target, completed-summary target)
 * and the hidden Flight sibling (in-flight snapshot target + delta toggle;
 * the snapshot cadence is the Router's tick, not a knob), without needing
 * PHP glue in the topology. They're invoked via:
 *
 *     command_node request-builder:config set_completed_target completed:tee
 *     command_node request-builder:config set_inflight_target  gyroscope:partition
 *
 * `set_inflight_target` proxies through `$patron->flight()` (the hidden
 * sibling attached in RequestBuilder's ctor), the target being a base Node
 * property whose setter arms the Timer. `set_inflight_delta` is a declared
 * toggle on the patron itself, which is what Flight reads at fire time and
 * what `dump_config` round-trips.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;

#[CoversClass( Request_Builder_Node::class )]
class RequestBuilderConfigVerbsTest extends TestCase {

	/** set_inflight_target drives Flight's Router-hitchhike, which needs a live _router. */
	protected function setUp(): void {
		parent::setUp();
		( new Router_Node() )->name( Node_Names::ROUTER );
	}

	public function test_set_completed_target_verb_persists_value(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $this->read_private( $rb, 'interpreter' );
		$verbs = $interpreter->commands();
		$this->assertArrayHasKey( 'set_completed_target', $verbs );
		$this->assertSame( "ok\n", $verbs['set_completed_target']( $interpreter, [ 'completed:tee' ] ) );
		$this->assertSame( 'completed:tee', $this->read_private( $rb, 'completed_target' ) );
	}

	public function test_set_completed_target_empty_args_clears_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $this->read_private( $rb, 'interpreter' );
		$verbs = $interpreter->commands();
		// Seed a non-empty target.
		$this->assertSame( "ok\n", $verbs['set_completed_target']( $interpreter, [ 'completed:tee' ] ) );
		$this->assertSame( 'completed:tee', $this->read_private( $rb, 'completed_target' ) );
		// Empty arg clears the target (returns 'ok', not 'usage:').
		$this->assertSame( "ok\n", $verbs['set_completed_target']( $interpreter, [] ) );
		$this->assertSame( '', $this->read_private( $rb, 'completed_target' ) );
	}

	public function test_set_inflight_target_verb_writes_to_flight(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $this->read_private( $rb, 'interpreter' );
		$verbs = $interpreter->commands();
		$this->assertArrayHasKey( 'set_inflight_target', $verbs );
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $interpreter, [ 'gyroscope:partition' ] ) );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
	}

	public function test_set_inflight_target_empty_args_clears_flight_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $this->read_private( $rb, 'interpreter' );
		$verbs = $interpreter->commands();
		// Seed a non-empty flight target.
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $interpreter, [ 'gyroscope:partition' ] ) );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
		// Empty arg clears the flight target (returns 'ok', not 'usage:').
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $interpreter, [] ) );
		$this->assertSame( '', $rb->flight()->target() );
	}

	public function test_set_inflight_delta_verb_writes_the_builders_toggle(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter = $this->read_private( $rb, 'interpreter' );
		$verbs       = $interpreter->commands();
		$this->assertArrayHasKey( 'set_inflight_delta', $verbs );
		$this->assertFalse( $rb->inflight_delta(), 'delta defaults off' );
		$this->assertSame( "ok\n", $verbs['set_inflight_delta']( $interpreter, [ '1' ] ) );
		$this->assertTrue( $rb->inflight_delta() );
	}

	public function test_set_inflight_delta_bare_or_zero_arg_disables(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter = $this->read_private( $rb, 'interpreter' );
		$verbs       = $interpreter->commands();
		$verbs['set_inflight_delta']( $interpreter, [ '1' ] );
		$this->assertTrue( $rb->inflight_delta() );
		$this->assertSame( "ok\n", $verbs['set_inflight_delta']( $interpreter, [ '0' ] ) );
		$this->assertFalse( $rb->inflight_delta(), '0 disables' );
		$verbs['set_inflight_delta']( $interpreter, [ '1' ] );
		$this->assertSame( "ok\n", $verbs['set_inflight_delta']( $interpreter, [] ) );
		$this->assertFalse( $rb->inflight_delta(), 'bare arg disables' );
	}

	public function test_dump_config_round_trips_the_inflight_delta_knob(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-delta-dump' );
		// Default off — dump_config emits only non-default settings, so no knob.
		$this->assertStringNotContainsString( 'set_inflight_delta', $rb->dump_config() );
		$rb->set_inflight_delta( true );
		$this->assertStringContainsString(
			'command_node rb-delta-dump:config set_inflight_delta true',
			$rb->dump_config()
		);
	}

	/**
	 * The verb declares a `bool` arg, so it takes the substrate's canonical
	 * `truthy()` spellings. A `false` or `off` argument disables it, rather
	 * than reading as "a non-empty string that is not 0, therefore on".
	 */
	public function test_set_inflight_delta_takes_the_canonical_bool_spellings(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-delta-words' );
		$interpreter = $this->read_private( $rb, 'interpreter' );
		$verbs       = $interpreter->commands();
		$verbs['set_inflight_delta']( $interpreter, [ 'yes' ] );
		$this->assertStringContainsString( 'set_inflight_delta', $rb->dump_config(), 'yes enables' );
		$verbs['set_inflight_delta']( $interpreter, [ 'false' ] );
		$this->assertStringNotContainsString( 'set_inflight_delta', $rb->dump_config(), 'false disables' );
		$verbs['set_inflight_delta']( $interpreter, [ 'on' ] );
		$this->assertStringContainsString( 'set_inflight_delta', $rb->dump_config(), 'on enables' );
		$verbs['set_inflight_delta']( $interpreter, [ 'off' ] );
		$this->assertStringNotContainsString( 'set_inflight_delta', $rb->dump_config(), 'off disables' );
	}

	public function test_set_errors_target_empty_args_clears_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $this->read_private( $rb, 'interpreter' );
		$verbs = $interpreter->commands();
		// Seed a non-empty errors target.
		$this->assertSame( "ok\n", $verbs['set_errors_target']( $interpreter, [ 'errors:partition' ] ) );
		$this->assertSame( 'errors:partition', $this->read_private( $rb, 'errors_target' ) );
		// Empty arg clears the errors target.
		$this->assertSame( "ok\n", $verbs['set_errors_target']( $interpreter, [] ) );
		$this->assertSame( '', $this->read_private( $rb, 'errors_target' ) );
	}

	public function test_set_alerts_target_verb_persists_and_clears(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter = $this->read_private( $rb, 'interpreter' );
		$verbs       = $interpreter->commands();
		$this->assertArrayHasKey( 'set_alerts_target', $verbs );
		$this->assertSame( "ok\n", $verbs['set_alerts_target']( $interpreter, [ 'alerts:partition' ] ) );
		$this->assertSame( 'alerts:partition', $this->read_private( $rb, 'alerts_target' ) );
		// Empty arg clears the target.
		$this->assertSame( "ok\n", $verbs['set_alerts_target']( $interpreter, [] ) );
		$this->assertSame( '', $this->read_private( $rb, 'alerts_target' ) );
	}

	public function test_dump_config_round_trips_the_alerts_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb-alerts-dump' );
		$this->assertStringNotContainsString( 'set_alerts_target', $rb->dump_config() );
		$rb->set_alerts_target( 'alerts:partition' );
		$this->assertStringContainsString(
			'command_node rb-alerts-dump:config set_alerts_target alerts:partition',
			$rb->dump_config()
		);
	}

	/**
	 * A torn-down builder refuses the two Flight verbs rather than reporting
	 * `ok` for a setting nothing can hold. It is refused one step BEFORE
	 * `flight()`, at the patron link every verb handler reads, and teardown
	 * unregisters the `:config` interpreter with it, so no addressed command
	 * reaches the table at all.
	 */
	public function test_a_torn_down_builder_refuses_the_inflight_verbs(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'anemometer' );
		$interpreter = $this->read_private( $rb, 'interpreter' );
		$verbs       = $interpreter->commands();

		$rb->remove_node();

		$this->assertNull( Core::node( 'anemometer:config' ), 'no addressed command can reach the table' );
		$this->assertNull( $interpreter->patron(), 'the handler is refused at the patron link' );
		foreach ( [ 'set_inflight_target' => 'gyroscope.p9', 'set_inflight_delta' => '1' ] as $verb => $arg ) {
			try {
				$verbs[ $verb ]( $interpreter, [ $arg ] );
				$this->fail( "expected {$verb} to refuse a torn-down builder" );
			} catch ( \Throwable $e ) {
				$this->assertNotSame( 'ok', $e->getMessage() );
			}
		}
	}
}
