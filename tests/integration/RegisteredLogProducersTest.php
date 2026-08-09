<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * Verify the application registers the firehose on the substrate's
 * `newspack_nodes/registered_log_producers` filter, as the dir template
 * `Log_Manager` itself writes through. Read by `Newspack_Nodes\Log_Cleaner` to
 * protect `{base}/logs/firehose.p{N}/` from the flat-layout log GC — those dirs
 * are written from request scope and declared in no `.tsl`, so without this
 * registration the GC would orphan them. `jobintake` is `Job_Intake`'s,
 * substrate code that registers itself.
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

	public function test_registers_the_template_the_firehose_writer_uses(): void {
		// LogManager writes the firehose directly from request code, with no
		// topology Partition node — without this registration Log_Cleaner would
		// orphan the very log the plugin always writes to. The registered value is
		// the writer's OWN dir template, so the two cannot drift.
		$producers = \apply_filters( 'newspack_nodes/registered_log_producers', [] );
		$this->assertContains( \Newspack_Event_Logger_Nodes\Log_Manager::firehose_dir_template(), $producers );
	}

	public function test_registration_is_additive(): void {
		// A prior contributor's producers must survive the merge.
		$producers = \apply_filters(
			'newspack_nodes/registered_log_producers',
			[ 'someoneelse' ]
		);
		$this->assertContains( 'someoneelse', $producers );
		$this->assertContains( \Newspack_Event_Logger_Nodes\Log_Manager::firehose_dir_template(), $producers );
	}

	public function test_registration_does_not_duplicate(): void {
		// Re-passing a registered template must not produce a duplicate entry.
		$template  = \Newspack_Event_Logger_Nodes\Log_Manager::firehose_dir_template();
		$producers = \apply_filters( 'newspack_nodes/registered_log_producers', [ $template ] );
		$this->assertSame(
			[ $template ],
			\array_values( \array_filter( $producers, static fn ( $p ) => $template === $p ) )
		);
	}

	public function test_declared_log_dirs_protects_runtime_producer_partitions(): void {
		// End-to-end: the substrate's config-derived declared-set must include the
		// firehose partition dirs across the configured partition count, so the log
		// GC keeps them. jobintake rides along from the substrate's own
		// registration — Job_Intake is its code, not this plugin's.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 2 ] );
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [];
		\add_filter(
			'newspack_nodes/registered_log_producers',
			[ \Newspack_Nodes\Bootstrap::class, 'register_log_producers' ]
		);

		$declared = \Newspack_Nodes\Log_Cleaner::declared_log_dirs();
		$this->assertContains( 'firehose.p0', $declared );
		$this->assertContains( 'firehose.p1', $declared );
		$this->assertContains( 'jobintake.p0', $declared );
		$this->assertContains( 'jobintake.p1', $declared );
		// Beyond the configured count is undeclared (config-driven shrink GC).
		$this->assertNotContains( 'firehose.p2', $declared );
	}
}
