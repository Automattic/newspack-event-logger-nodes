<?php
/**
 * make_node resolution guard for the atomic cutover (#41 final).
 *
 * The old ELN `Newspack_Event_Logger_Nodes\Remote_Source_Node` is deleted; the
 * self-sufficient substrate `Newspack_Nodes\Remote_Source_Node` (with its
 * SSE_In + HTTP_Out patrons) replaces it. With both production namespaces
 * registered, `make_node Remote_Source <name> <vault> <topic> <partition>`
 * MUST resolve the SUBSTRATE class — there can be no first-registered-wins
 * ambiguity because the ELN class is gone.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Remote_Source_Node;
use Newspack_Nodes\Router_Node;

class RemoteSourceMakeNodeResolutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Both production namespaces are registered for node-class resolution:
		// the substrate's own prefix plus ELN's. ELN registers BEFORE the
		// substrate alphabetically, so this is the exact first-registered-wins
		// arrangement the cutover has to be unambiguous under.
		Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\' );
		Command_Interpreter_Node::register_namespace( 'Newspack_Nodes\\' );
	}

	public function test_make_node_remote_source_resolves_substrate_class(): void {
		$router = new Router_Node();
		$router->name( '_router' );

		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );

		$node = $interpreter->make_node( 'Remote_Source', 'spoke-x', 'austin', 'firehose', '0' );

		$this->assertInstanceOf( Remote_Source_Node::class, $node );
		$this->assertSame( 'Newspack_Nodes\\Remote_Source_Node', \get_class( $node ) );
	}

	public function test_old_eln_remote_source_class_is_deleted(): void {
		$this->assertFalse(
			\class_exists( 'Newspack_Event_Logger_Nodes\\Remote_Source_Node' ),
			'The old ELN Remote_Source_Node must be deleted — the substrate class replaces it.'
		);
	}

	public function test_stream_merger_class_is_deleted(): void {
		$this->assertFalse(
			\class_exists( 'Newspack_Event_Logger_Nodes\\Stream_Merger_Node' ),
			'Stream_Merger_Node must be deleted — the Remote_Source pull graph replaces it.'
		);
	}
}
