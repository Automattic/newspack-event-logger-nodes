<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\HealthCheckTick;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( HealthCheckTick::class )]
class HealthCheckTickTest extends TestCase {

	public function test_health_check_tick_constructs_sibling_ci(): void {
		$h = new HealthCheckTick();
		$h->name( 'h' );
		$this->assertNotNull( $h->interpreter() );
		$this->assertSame( 'h:config', $h->interpreter()->name() );
	}

	public function test_health_check_tick_start_periodic_tick_verb_round_trips(): void {
		$h = new HealthCheckTick();
		$h->name( 'h' );
		$result = $h->interpreter()->execute( 'start_periodic_tick' );
		$this->assertSame( 'ok', $result );

		$dump = $h->dump_config();
		$this->assertStringContainsString( 'cmd h:config start_periodic_tick', $dump );
	}

	public function test_health_check_tick_node_schema_declares_verb(): void {
		$schema = HealthCheckTick::node_schema();
		// Hidden from the topology console — instantiated as a
		// patron-linked sibling of StreamMerger, not built from TSL.
		$this->assertSame( 'Hidden', $schema['category'] );
		$verb_names = \array_column( $schema['verbs'], 'name' );
		$this->assertContains( 'start_periodic_tick', $verb_names );
	}
}
