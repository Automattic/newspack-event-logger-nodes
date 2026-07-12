<?php
/**
 * Sibling-node discipline for Performance_CI_Node's disk-walking helpers.
 *
 * Rule 2 of the make_node plan: a utility Partition created INSIDE a node as
 * internal plumbing must be NAMED, have its `patron()` set (so dump_metadata
 * hides it from the canvas), and (Rule 4) be SUNK into the
 * `_command_interpreter` when one is in scope. The six scratch Partitions the
 * performance verbs build over `requests.log` / `flames.log` are such siblings —
 * these tests pin that contract.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Performance_CI_Node::class )]
class PerformanceCISiblingNodesTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = '/tmp/performance-ci-sibling-test-' . \uniqid();
		\mkdir( $this->tmp . '/logs', 0755, true );
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 86400 ] );
		$GLOBALS['_current_user_can'] = true;
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_current_user_can'] = false;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/** Locate the most-recently-registered requests.log / flames.log scratch Partition. */
	private function find_scratch_partition( string $prefix ): ?Partition_Node {
		$found = null;
		foreach ( Core::$nodes_by_name as $name => $node ) {
			if ( $node instanceof Partition_Node && 0 === \strpos( $name, $prefix ) ) {
				$found = $node;
			}
		}
		return $found;
	}

	/** Invoke the private name_scratch_partition helper on a fresh Partition. */
	private function wire_scratch( string $log, int $index ): Partition_Node {
		$p = new Partition_Node();
		$m = new \ReflectionMethod( Performance_CI_Node::class, 'name_scratch_partition' );
		$m->invoke( null, $p, $log, $index );
		return $p;
	}

	/**
	 * A scratch Partition is NAMED `{log}.{token}.p{N}` so it shows up in
	 * dump_metadata / `ls` while it's in use.
	 */
	public function test_scratch_partition_is_named(): void {
		$p = $this->wire_scratch( 'requests', 0 );
		$this->assertStringStartsWith( 'requests.', $p->name() );
		$this->assertStringEndsWith( '.p0', $p->name() );
	}

	/**
	 * The scratch Partition is plumbing — self-patron (the helper is static) so
	 * dump_metadata hides it from the topology console canvas.
	 */
	public function test_scratch_partition_has_patron_set(): void {
		$p = $this->wire_scratch( 'requests', 0 );
		$this->assertSame( $p, $p->patron(), 'sibling Partition must be self-patron-linked (marks it as plumbing)' );
	}

	/**
	 * Rule 4: with an `_command_interpreter` in scope the scratch Partition sinks into it.
	 */
	public function test_scratch_partition_sunk_into_command_interpreter_when_present(): void {
		$ci = new Command_Interpreter_Node();
		$ci->name( Node_Names::COMMAND_INTERPRETER );

		$p = $this->wire_scratch( 'requests', 0 );
		$this->assertSame( $ci, $p->sink(), 'sibling Partition must sink into the interpreter when one is registered' );
	}

	/**
	 * The flames.log scratch Partition gets the same name + self-patron treatment.
	 */
	public function test_flames_scratch_partition_named_and_patron_set(): void {
		$p = $this->wire_scratch( 'flames', 0 );
		$this->assertStringStartsWith( 'flames.', $p->name() );
		$this->assertStringEndsWith( '.p0', $p->name() );
		$this->assertSame( $p, $p->patron() );
	}

	/**
	 * Rule 4 exception: no `_command_interpreter` in scope — still NAME + patron, but
	 * the interpreter sink stays null (no fatal).
	 */
	public function test_scratch_partition_sink_null_without_interpreter(): void {
		$this->assertNull( Core::node( Node_Names::COMMAND_INTERPRETER ) );

		$p = $this->wire_scratch( 'flames', 0 );
		$this->assertStringStartsWith( 'flames.', $p->name() );
		$this->assertNotNull( $p->patron() );
		$this->assertNull( $p->sink(), 'with no interpreter in scope the sibling sink stays null (Rule 4)' );
	}

	/**
	 * The verbs remove every scratch Partition they create — these are transient
	 * read probes, so nothing is left registered in Core after the verb returns.
	 */
	public function test_verb_removes_scratch_partitions(): void {
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire( $interpreter, 'performance', 'request_search', 'rid-anything' );

		$this->assertNull( $this->find_scratch_partition( 'requests.' ), 'scratch Partitions must be removed after the verb' );
	}
}
