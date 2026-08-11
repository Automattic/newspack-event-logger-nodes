<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;

/**
 * The real application topologies must declare their write-conflicts honestly:
 * `complete` owns every partition, so it clashes with each broken-out topology;
 * the broken-out set (request-builder + flame-builder + job-router) is designed
 * to run together and must be conflict-free (distinct data partitions AND
 * distinct firehose offsetlogs).
 */
class TopologyConflictsTest extends TestCase {

	public function test_complete_conflicts_with_each_decomposed_topology(): void {
		$this->assertNotEmpty(
			Topology_Analyzer::find_conflicts( [ 'complete', 'request-builder' ] ),
			'complete + request-builder both write requests.log'
		);
		$this->assertNotEmpty(
			Topology_Analyzer::find_conflicts( [ 'complete', 'flame-builder' ] ),
			'complete + flame-builder both write flames.log'
		);
		$this->assertNotEmpty(
			Topology_Analyzer::find_conflicts( [ 'complete', 'job-router' ] ),
			'complete + job-router both write jobs.log'
		);
	}

	public function test_decomposed_set_runs_together_without_conflict(): void {
		$this->assertSame(
			[],
			Topology_Analyzer::find_conflicts( [ 'request-builder', 'flame-builder', 'job-router' ] ),
			'the broken-out topologies must be safe to run together after differentiating the firehose offsetlogs'
		);
	}
}
