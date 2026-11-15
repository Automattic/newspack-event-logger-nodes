<?php
/**
 * A substrate below the floor leaves the plugin file loaded and the deferred
 * bootstrap refused, so every hook that does work for a booted plugin waits
 * for the bootstrap; only the two the substrate needs before it stand at file
 * scope. The `_eln_boot_actions` snapshot is that refused state: the plugin
 * file required, and `newspack_event_logger_nodes_boot()` never run.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class DormantBootstrapTest extends TestCase {

	/** @var mixed The `REQUEST_URI` the test began under. */
	private $request_uri;

	protected function setUp(): void {
		parent::setUp();
		$this->request_uri      = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/kea-4417/outer-cron';
	}

	protected function tearDown(): void {
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		Log_Manager::reset();
		parent::tearDown();
	}

	public function test_a_refused_install_mounts_no_service_ci(): void {
		$this->assertNotContains(
			'newspack_event_logger_nodes_mount_service_cis',
			$GLOBALS['_eln_boot_actions']['newspack_nodes/request_graph_ready'] ?? [],
			'every /command POST and MCP request would build the three CIs'
		);
	}

	public function test_a_refused_install_brackets_no_reconcile(): void {
		$this->assertArrayNotHasKey( 'newspack_nodes/before_reconcile', $GLOBALS['_eln_boot_actions'], 'the minute pass would open a job context' );
		$this->assertArrayNotHasKey( 'newspack_nodes/after_reconcile', $GLOBALS['_eln_boot_actions'] );
	}

	/** The substrate reads both before, or without, the bootstrap. */
	public function test_a_refused_install_still_declares_its_keys_and_its_firehose(): void {
		$this->assertContains( 'newspack_event_logger_nodes_register_log_producers', $GLOBALS['_eln_boot_actions']['newspack_nodes/registered_log_producers'] ?? [] );
		$this->assertContains( [ \Newspack_Event_Logger_Nodes\Config::class, 'register_config_keys' ], $GLOBALS['_eln_boot_actions']['newspack_nodes/declare_config_keys'] ?? [] );
	}

	public function test_the_bootstrap_mounts_the_service_cis(): void {
		// The harness replays the mount; the base class restores it.
		$GLOBALS['_wp_actions']['newspack_nodes/request_graph_ready'] = [];
		$mounted = [];
		self::with_deferred_bootstrap(
			static function () use ( &$mounted ): void {
				$mounted = $GLOBALS['_wp_actions']['newspack_nodes/request_graph_ready'] ?? [];
			}
		);
		$this->assertContains( 'newspack_event_logger_nodes_mount_service_cis', $mounted );
	}

	/** The pass logs under its own job, and the cron request it rode comes back. */
	public function test_the_bootstrap_brackets_the_reconcile_in_its_own_context(): void {
		$during = null;
		self::with_deferred_bootstrap(
			static function () use ( &$during ): void {
				\do_action( 'newspack_nodes/before_reconcile' );
				$during = $_SERVER['REQUEST_URI'];
				\do_action( 'newspack_nodes/after_reconcile' );
			}
		);
		$this->assertSame( '/jobs/newspack-nodes', $during );
		$this->assertSame( '/kea-4417/outer-cron', $_SERVER['REQUEST_URI'] );
	}

	/** An `after` with no `before` resumes nothing it never suspended. */
	public function test_an_unmatched_after_reconcile_leaves_the_request_alone(): void {
		self::with_deferred_bootstrap(
			static function (): void {
				\do_action( 'newspack_nodes/after_reconcile' );
			}
		);
		$this->assertSame( '/kea-4417/outer-cron', $_SERVER['REQUEST_URI'] );
	}
}
