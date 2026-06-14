<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * Verify the application registers its request-scope log producers
 * (`firehose`, `jobintake`) on the substrate's
 * `newspack_nodes/registered_log_producers` filter. Read by
 * `Newspack_Nodes\Log_Cleaner` to protect `{base}/logs/firehose.p{N}/` and
 * `{base}/logs/jobintake.p{N}/` from the flat-layout log GC — these dirs are
 * written from request scope (LogManager / JobIntake) and declared in no
 * `.tsl`, so without this registration the GC would orphan them.
 */
class RegisteredLogProducersTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_actions'] = [];
		$this->tmp              = $this->make_temp_dir();
		// setUp() wipes wp_actions, so the plugin-file `add_filter` is
		// gone — re-attach the same closure the plugin file registers.
		\add_filter(
			'newspack_nodes/registered_log_producers',
			'newspack_event_logger_nodes_register_log_producers'
		);
	}

	protected function tearDown(): void {
		\Newspack_Nodes\Topology_Registry::reset();
		\Newspack_Nodes\Config::reset();
		\Newspack_Event_Logger_Nodes\Config::reset();
		parent::tearDown();
	}

	public function test_registers_runtime_producers(): void {
		// LogManager (firehose) + JobIntake (jobintake) write directly from
		// request code without any topology Partition node. They MUST be
		// registered as producers whenever the plugin is loaded — otherwise
		// Log_Cleaner would orphan the very logs the plugin always writes to.
		$producers = \apply_filters( 'newspack_nodes/registered_log_producers', [] );
		$this->assertContains( 'firehose', $producers );
		$this->assertContains( 'jobintake', $producers );
	}

	public function test_registration_is_additive(): void {
		// A prior contributor's producers must survive the merge.
		$producers = \apply_filters(
			'newspack_nodes/registered_log_producers',
			[ 'someoneelse' ]
		);
		$this->assertContains( 'someoneelse', $producers );
		$this->assertContains( 'firehose', $producers );
		$this->assertContains( 'jobintake', $producers );
	}

	public function test_registration_does_not_duplicate(): void {
		// Re-passing a runtime basename must not produce a duplicate entry.
		$producers = \apply_filters(
			'newspack_nodes/registered_log_producers',
			[ 'firehose' ]
		);
		$this->assertSame(
			[ 'firehose' ],
			\array_values( \array_filter( $producers, static fn ( $p ) => 'firehose' === $p ) )
		);
	}

	public function test_declared_log_dirs_protects_runtime_producer_partitions(): void {
		// End-to-end: the substrate's config-derived declared-set must include
		// firehose/jobintake partition dirs across the configured partition
		// count, so the log GC keeps them.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 2 ] );
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [];

		$declared = \Newspack_Nodes\Log_Cleaner::declared_log_dirs();
		$this->assertContains( 'firehose.p0', $declared );
		$this->assertContains( 'firehose.p1', $declared );
		$this->assertContains( 'jobintake.p0', $declared );
		$this->assertContains( 'jobintake.p1', $declared );
		// Beyond the configured count is undeclared (config-driven shrink GC).
		$this->assertNotContains( 'firehose.p2', $declared );
	}
}
