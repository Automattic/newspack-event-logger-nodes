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
 * truth and lets `dump_config` round-trip via mark_verb_invoked.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( RequestBuilder::class )]
class RequestBuilderConfigVerbsTest extends TestCase {

	public function test_set_completed_target_verb_persists_value(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$ci    = $rb->interpreter();
		$verbs = $ci->commands();
		$this->assertArrayHasKey( 'set_completed_target', $verbs );
		$this->assertSame( 'ok', $verbs['set_completed_target']( $ci, 'completed:tee' ) );
		$this->assertSame( 'completed:tee', $this->read_private( $rb, 'completed_target' ) );
	}

	public function test_set_completed_target_empty_args_clears_target(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$ci    = $rb->interpreter();
		$verbs = $ci->commands();
		// Seed a non-empty target.
		$this->assertSame( 'ok', $verbs['set_completed_target']( $ci, 'completed:tee' ) );
		$this->assertSame( 'completed:tee', $this->read_private( $rb, 'completed_target' ) );
		// Empty arg clears the target (returns 'ok', not 'usage:').
		$this->assertSame( 'ok', $verbs['set_completed_target']( $ci, '' ) );
		$this->assertSame( '', $this->read_private( $rb, 'completed_target' ) );
	}

	public function test_set_inflight_target_verb_writes_to_flight(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$ci    = $rb->interpreter();
		$verbs = $ci->commands();
		$this->assertArrayHasKey( 'set_inflight_target', $verbs );
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $ci, 'gyroscope:partition' ) );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
	}

	public function test_set_inflight_target_empty_args_clears_flight_target(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$ci    = $rb->interpreter();
		$verbs = $ci->commands();
		// Seed a non-empty flight target.
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $ci, 'gyroscope:partition' ) );
		$this->assertSame( 'gyroscope:partition', $rb->flight()->target() );
		// Empty arg clears the flight target (returns 'ok', not 'usage:').
		$this->assertSame( 'ok', $verbs['set_inflight_target']( $ci, '' ) );
		$this->assertSame( '', $rb->flight()->target() );
	}

	public function test_set_errors_target_empty_args_clears_target(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$ci    = $rb->interpreter();
		$verbs = $ci->commands();
		// Seed a non-empty errors target.
		$this->assertSame( 'ok', $verbs['set_errors_target']( $ci, 'errors:partition' ) );
		$this->assertSame( 'errors:partition', $this->read_private( $rb, 'errors_target' ) );
		// Empty arg clears the errors target.
		$this->assertSame( 'ok', $verbs['set_errors_target']( $ci, '' ) );
		$this->assertSame( '', $this->read_private( $rb, 'errors_target' ) );
	}

	public function test_set_inflight_interval_verb_calls_flight_set_interval(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$ci    = $rb->interpreter();
		$verbs = $ci->commands();
		$this->assertArrayHasKey( 'set_inflight_interval', $verbs );
		$this->assertSame( 'ok', $verbs['set_inflight_interval']( $ci, '2500' ) );
		$this->assertSame( 2500, $rb->flight()->interval() );
	}

	public function test_set_inflight_interval_rejects_non_numeric(): void {
		$rb = new RequestBuilder();
		$rb->name( 'rb' );
		$ci    = $rb->interpreter();
		$verbs = $ci->commands();
		$this->assertStringContainsString( 'usage:', $verbs['set_inflight_interval']( $ci, 'abc' ) );
	}

	private function read_private( object $obj, string $name ) {
		$r = new \ReflectionObject( $obj );
		$p = $r->getProperty( $name );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}
}
