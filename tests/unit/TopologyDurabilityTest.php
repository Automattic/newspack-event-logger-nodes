<?php
/**
 * Every reader this plugin's topologies declare must be durable.
 *
 * The offsetlog and dead-letter dirs are arguments, and omitting one fails SILENT:
 * no cursor means the reader replays from the head after every restart; no
 * quarantine means poison is logged and dropped. Both firehose:consumer topologies,
 * requests:consumer and settings:consumer all shipped without a dead-letter dir
 * before this guard existed.
 *
 * The audit itself is the substrate's (it reads each node's own node_schema for the
 * argument positions); this pins THIS plugin's topologies to it.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Newspack_Nodes\Tests\Helpers\TopologyDurability;

class TopologyDurabilityTest extends TestCase {

	public function test_stock_topologies_declare_a_cursor_and_a_quarantine(): void {
		$violations = TopologyDurability::audit( \dirname( __DIR__, 2 ) . '/topologies' );
		$this->assertSame( [], $violations, \implode( "\n", $violations ) );
	}
}
