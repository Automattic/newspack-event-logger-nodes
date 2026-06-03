<?php
/**
 * Sibling discipline (Rule 2) for the hidden Flight sibling RequestBuilder
 * creates in its ctor: it must be NAMED ({patron}:flight), have its PATRON
 * pointer set to RequestBuilder (so dump_metadata hides it from the canvas),
 * and — when a `_command_interpreter` is in scope at construction — be SUNK
 * into that interpreter (so its in-flight emits route somewhere sensible
 * before the topology wires RequestBuilder's own downstream sink).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Request_Builder_Node::class )]
class RequestBuilderFlightSiblingSinkTest extends TestCase {

	/** Register a `_command_interpreter` and return it. */
	private function register_interpreter(): Command_Interpreter_Node {
		$ci = new Command_Interpreter_Node();
		$ci->name( Node_Names::COMMAND_INTERPRETER );
		return $ci;
	}

	public function test_flight_sibling_patron_is_request_builder(): void {
		$rb = new Request_Builder_Node();
		$this->assertSame( $rb, $rb->flight()->patron() );
	}

	public function test_flight_sibling_named_as_patron_flight(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$this->assertSame( 'rb:flight', $rb->flight()->name() );
	}

	public function test_flight_sibling_sinks_into_interpreter_at_construction(): void {
		// Rule 2c: with a `_command_interpreter` registered, the ctor must sink
		// the Flight sibling into it. Currently FAILS — the ctor sets patron but
		// leaves Flight's sink null until RequestBuilder's own sink() is called.
		$ci = $this->register_interpreter();
		$rb = new Request_Builder_Node();
		$this->assertSame( $ci, $rb->flight()->sink() );
	}

	public function test_flight_sibling_sink_null_when_no_interpreter(): void {
		// Rule 4 exception: no `_command_interpreter` in scope → no interpreter
		// sink; the sibling stays sinkless until RequestBuilder is wired.
		$rb = new Request_Builder_Node();
		$this->assertNull( $rb->flight()->sink() );
	}

	public function test_explicit_sink_still_propagates_to_flight(): void {
		// The construction-time interpreter sink is only a default: a topology
		// that later sinks RequestBuilder must still override Flight's sink via
		// the existing sink() cascade — the interpreter default doesn't pin it.
		$this->register_interpreter();
		$rb       = new Request_Builder_Node();
		$capture  = new \Newspack_Nodes\Tests\Capture_Sink_Node();
		$rb->sink( $capture );
		$this->assertSame( $capture, $rb->flight()->sink() );
	}
}
