<?php
/**
 * ConfigReloadSignalTest: every ELN config writer must ask the live fleet to
 * re-read.
 *
 * A running worker's WP option cache is frozen at boot, so a writer that only
 * resets request-locally leaves every worker serving the OLD value until it
 * recycles — up to a full worker lifetime, silently. `Restart_Planner::
 * request_reloads()` is the signal: a watermark in each partition lock dir of
 * each active topology, which `Fleet_Node` consumes on its next scan.
 *
 * Two writers are covered here — `Rule_Set::save()` (every ruleset write) and
 * the `performance` CI's `set` verb (the hub->spoke settings-sync receive path).
 * The settings-form writer is covered by AdminTest.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\Helpers\TopologyLockHarness;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Core;

#[CoversClass( Rule_Set::class )]
#[CoversClass( Performance_CI_Node::class )]
final class ConfigReloadSignalTest extends TestCase {
	use TopologyLockHarness;

	/** Topology names and partition count are all off-default, so a hardcoded `combined`/p0 fails. */
	private const TOPOLOGIES    = [ 'sundial', 'moondial' ];
	private const NUM_PARTITIONS = 3;

	private string $base_dir;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_actions']       = [];
		$GLOBALS['_current_user_can'] = true;
		Core::$memd                   = null;

		// /tmp directly to dodge realpath/symlink mismatches on hosts whose
		// sys_get_temp_dir() resolves through a symlink (macOS /var).
		$this->base_dir = '/tmp/newspack-eln-reload-signal-' . \uniqid();
		\mkdir( $this->base_dir, 0755, true );
		$this->use_base_dir( $this->base_dir );

		$this->register_topology_fixtures(
			[
				'sundial'  => "make_node Job_Router job-router\n",
				'moondial' => "make_node Job_Router job-router\n",
			],
			self::TOPOLOGIES,
			self::NUM_PARTITIONS
		);
		foreach ( self::TOPOLOGIES as $topology ) {
			for ( $p = 0; $p < self::NUM_PARTITIONS; $p++ ) {
				$this->prepare_lock_dir( $topology, $p );
			}
		}
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		Config::reset();
		$this->reset_topology_fixtures();
		$this->rmdir_recursive( $this->base_dir );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		parent::tearDown();
	}

	/** Assert every live partition got a reload watermark and no restart flag. */
	private function assertFleetAskedToReload(): void {
		foreach ( self::TOPOLOGIES as $topology ) {
			for ( $p = 0; $p < self::NUM_PARTITIONS; $p++ ) {
				$this->assertReloadFlagged( $topology, $p );
				$this->assertRestartNotFlagged( $topology, $p );
			}
		}
	}

	/**
	 * Repoint Config at a base directory holding a null byte, which
	 * `Config::ensure_path()` rejects — so `get_locks_directory()` throws and the
	 * signal has nowhere to land.
	 */
	private function break_locks_directory(): void {
		$conf = $this->base_dir . '/bad-base-dir.php';
		\file_put_contents( $conf, "<?php\nreturn [ 'base_directory' => \"/tmp/has\\0null\" ];\n" );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $conf );
		\Newspack_Nodes\Config::reset();
		Config::reset();
	}

	// ---- Rule_Set::save ---------------------------------------------------

	public function test_saving_the_ruleset_asks_every_live_worker_to_re_read(): void {
		( new Rule_Set( [] ) )->save( [
			new Rule( 'ignored', '/tarot/', Rule::ACTION_LOG, hooks: [ 'init' ] ),
		] );

		$this->assertFleetAskedToReload();
	}

	public function test_saving_the_ruleset_survives_an_unresolvable_locks_directory(): void {
		$this->break_locks_directory();

		( new Rule_Set( [] ) )->save( [ new Rule( 'ignored', '/tarot/', Rule::ACTION_SKIP ) ] );

		// The write is the operation; the signal is best-effort.
		$this->assertCount( 1, $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );
	}

	// ---- performance CI `set` --------------------------------------------

	public function test_settings_set_verb_asks_every_live_worker_to_re_read(): void {
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_log_memory 1'
		);

		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
		$this->assertFleetAskedToReload();
	}

	public function test_settings_set_verb_asks_every_live_worker_to_re_read_a_synced_ruleset(): void {
		// The ruleset branch re-tiers through Rule_Set::save() rather than a raw
		// update_option, so it inherits that writer's signal.
		$rule = ( new Rule( 'ignored', '/tarot/', Rule::ACTION_LOG, hooks: [ 'init' ] ) )->to_array();
		$args = Command_Args::format(
			[ Rule_Set::OPTION_RULES, (string) \json_encode( [ $rule ] ) ],
			[]
		);

		$interpreter = new Performance_CI_Node();
		VerbHarness::fire( $interpreter, 'performance', 'set', $args );

		$this->assertFleetAskedToReload();
	}

	public function test_settings_set_verb_still_writes_the_option_with_an_unresolvable_locks_directory(): void {
		$this->break_locks_directory();

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_log_memory 1'
		);

		$this->assertSame( [ 'option' => 'newspack_event_logger_nodes_log_memory', 'updated' => true ], $result );
		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}
}
