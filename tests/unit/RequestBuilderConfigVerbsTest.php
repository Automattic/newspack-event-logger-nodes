<?php
/**
 * Verb-table tests for RequestBuilder's sibling :config CommandInterpreter.
 *
 * These verbs let TSL topology files declaratively wire the secondary
 * outputs RequestBuilder owns (errors target, completed-summary target)
 * and the hidden Flight sibling (in-flight snapshot target + interval),
 * without needing PHP glue in the topology. They're invoked via:
 *
 *     cmd request-builder:config set_completed_target completed:tee
 *     cmd request-builder:config set_inflight_target  gyroscope:partition
 *     cmd request-builder:config set_inflight_interval 2500
 *
 * The Flight verbs proxy through `$patron->flight()` (the hidden sibling
 * attached in RequestBuilder's ctor) rather than touching $patron state
 * directly — that keeps the Timer's interval as the single source of
 * truth, and `dump_config` round-trips each setting straight from that
 * state (no generic verb recording).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Request_Builder_Node::class )]
class RequestBuilderConfigVerbsTest extends TestCase {

	/** set_inflight_interval drives Flight's Router-hitchhike, which needs a live _router. */
	protected function setUp(): void {
		parent::setUp();
		( new Router_Node() )->name( Node_Names::ROUTER );
	}

	public function test_set_completed_target_verb_persists_value(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $rb->interpreter();
		$verbs = $interpreter->commands();
		$this->assertArrayHasKey( 'set_completed_target', $verbs );
		$this->assertSame( 'ok', $verbs['set_completed_target']( $interpreter, 'completed:tee' ) );
		$this->assertSame( 'completed:tee', $this->read_private( $rb, 'completed_target' ) );
	}

	public function test_set_completed_target_empty_args_clears_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $rb->interpreter();
		$verbs = $interpreter->commands();
		// Seed a non-empty target.
		$this->assertSame( 'ok', $verbs['set_completed_target']( $interpreter, 'completed:tee' ) );
		$this->assertSame( 'completed:tee', $this->read_private( $rb, 'completed_target' ) );
		// Empty arg clears the target (returns 'ok', not 'usage:').
		$this->assertSame( 'ok', $verbs['set_completed_target']( $interpreter, '' ) );
		$this->assertSame( '', $this->read_private( $rb, 'completed_target' ) );
	}

	public function test_set_inflight_target_verb_writes_to_flight(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $rb->interpreter();
		$verbs = $interpreter->commands();
		$this->assertArrayHasKey( 'set_inflight_target', $verbs );
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $interpreter, 'gyroscope:partition' ) );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
	}

	public function test_set_inflight_target_empty_args_clears_flight_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $rb->interpreter();
		$verbs = $interpreter->commands();
		// Seed a non-empty flight target.
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $interpreter, 'gyroscope:partition' ) );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
		// Empty arg clears the flight target (returns 'ok', not 'usage:').
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $interpreter, '' ) );
		$this->assertSame( '', $rb->flight()->target() );
	}

	public function test_set_errors_target_empty_args_clears_target(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $rb->interpreter();
		$verbs = $interpreter->commands();
		// Seed a non-empty errors target.
		$this->assertSame( 'ok', $verbs['set_errors_target']( $interpreter, 'errors:partition' ) );
		$this->assertSame( 'errors:partition', $this->read_private( $rb, 'errors_target' ) );
		// Empty arg clears the errors target.
		$this->assertSame( 'ok', $verbs['set_errors_target']( $interpreter, '' ) );
		$this->assertSame( '', $this->read_private( $rb, 'errors_target' ) );
	}

	public function test_set_inflight_interval_verb_calls_flight_set_interval(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $rb->interpreter();
		$verbs = $interpreter->commands();
		$this->assertArrayHasKey( 'set_inflight_interval', $verbs );
		$this->assertSame( 'ok', $verbs['set_inflight_interval']( $interpreter, '2500' ) );
		$this->assertSame( 2500, $rb->flight()->interval() );
	}

	public function test_set_inflight_interval_rejects_non_numeric(): void {
		$rb = new Request_Builder_Node();
		$rb->name( 'rb' );
		$interpreter    = $rb->interpreter();
		$verbs = $interpreter->commands();
		$this->assertStringContainsString( 'usage:', $verbs['set_inflight_interval']( $interpreter, 'abc' ) );
	}

	private function read_private( object $obj, string $name ) {
		$r = new \ReflectionObject( $obj );
		$p = $r->getProperty( $name );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}
}
