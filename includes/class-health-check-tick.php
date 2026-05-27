<?php
/**
 * Health Check Tick Node
 *
 * Drives the aggregator's periodic discovery + sync_all_settings sweep from
 * inside the aggregator topology. Hitchhikes on `_router`'s TIMER event
 * (~5s heartbeat) and debounces to a configurable interval (default 5 min)
 * before enqueueing a `remote_manager` job with `action: health_check`.
 *
 * Why a Node and not a WP-action listener on the supervisor heartbeat:
 *   - The sweep only matters when the aggregator topology is actually
 *     running (otherwise there are no remotes to probe). Wiring it into the
 *     topology means it stops automatically when an operator disables
 *     `enable_aggregator` (which un-spawns this topology's worker).
 *   - Keeps all hub-side periodic logic — StreamMerger heartbeats + this —
 *     under one supervisor process, sharing the Router heartbeat.
 *
 * Why enqueue rather than call `RemoteManager::health_check()` synchronously:
 *   - JobWorker handles begin_job_context / end_job_context, request_id
 *     correlation, and STALE_THRESHOLD drops uniformly across every
 *     job type. Inlining bypasses that bookkeeping.
 *   - The sweep can block on cURL probes against every remote; the
 *     aggregator topology's drain loop should keep moving.
 *
 * Replaces the legacy `newspack_event_logger_supervisor_periodic` listener
 * from newspack-event-aggregator.php — same 300s debounce, same job shape.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Node_Names;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Health_Check_Tick_Node extends Node {

	/**
	 * Minimum seconds between consecutive sweeps. Matches legacy
	 * newspack-event-aggregator's 5-minute debounce. Router TIMER fires
	 * roughly every 5s, so this gates ~60 ticks before each sweep.
	 */
	public const DEBOUNCE_SECONDS = 300;

	/** Unix timestamp of the last sweep enqueue (0 = never). */
	protected int $last_check = 0;

	public function __construct() {
		// Base ctor auto-wires the sibling :config CI from node_schema()['verbs']
		// handlers — TSL aggregator topology invokes
		// `cmd health-check-tick:config start_periodic_tick` to register with
		// _router's TIMER event.
		parent::__construct();
	}

	/**
	 * Register with `_router`'s TIMER event so `fill()` receives a TM_INFO
	 * tick on every Router heartbeat. Called by the aggregator topology
	 * after node construction. Same Router-hitchhike pattern StreamMerger
	 * uses — see StreamMerger::start_periodic_tick().
	 */
	public function start_periodic_tick(): void {
		$router = Core::node( Node_Names::ROUTER );
		if ( null === $router ) {
			Core::print_less_often( 'HealthCheckTick::start_periodic_tick: no _router; periodic tick disabled' );
			return;
		}
		$router->register( 'TIMER', $this->name );
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if (
			0 === ( $message[ Message::TYPE ] & Message::TM_INFO )
			|| 'TIMER' !== $message[ Message::KEY ]
		) {
			return;
		}
		$this->maybe_enqueue();
	}

	/**
	 * Enqueue a `remote_manager` health_check job if the debounce window has
	 * elapsed and there's at least one enabled remote. Silently no-ops when:
	 *   - within the debounce window;
	 *   - `enable_aggregator` isn't strictly `true` (mirrors SettingsSync /
	 *     StreamMerger polarity — defense-in-depth, the topology shouldn't
	 *     be running at all when the gate is off);
	 *   - no enabled remotes are registered.
	 */
	protected function maybe_enqueue(): void {
		$now = \time();
		if ( $now - $this->last_check < self::DEBOUNCE_SECONDS ) {
			return;
		}

		// Long-running worker: ServerRegistry caches its view at first
		// read and never reloads, so an operator enabling a spoke
		// AFTER this worker spawned would otherwise be invisible
		// until the worker's ~595s respawn. Reset before re-reading.
		$registry = Server_Registry::get_instance();
		$registry->reset_cache();

		if ( empty( $registry->get_enabled() ) ) {
			return;
		}

		$this->last_check = $now;

		// Worker process REQUEST_URI is the spawn endpoint (in skip_urls),
		// so the parent LogManager is disabled. begin_job_context suspends
		// it, swaps REQUEST_URI to /jobs/health-check-tick, and the fresh
		// LogManager built on first instance() call is enabled.
		$orig_server = Job_Worker_Node::begin_job_context( 'health-check-tick' );
		try {
			$log_manager = Log_Manager::instance();
			$log_manager->message(
				'job',
				[
					'm' => [
						'handler'    => 'remote_manager',
						'parameters' => [
							'action'    => 'health_check',
							'queued_at' => $now,
						],
					],
				]
			);
			$log_manager->flush();
		} finally {
			Job_Worker_Node::end_job_context( $orig_server );
		}
	}

	// -------------------------------------------------------------------------
	// Sibling-CI verb table + node_schema (A3).
	// -------------------------------------------------------------------------

	public static function node_schema(): array {
		// Hidden: HealthCheckTick is instantiated as an owned sibling
		// of StreamMerger (patron-linked in StreamMerger's constructor),
		// not built directly in TSL. Aggregator topology has a single
		// `cmd stream-merger:config start_periodic_tick` line that
		// kicks off both periodic ticks. Class stays usable for direct
		// instantiation in tests.
		return [
			'category'    => 'Hidden',
			'description' => 'Drives the aggregator periodic discovery + sync sweep (5-min debounce).',
			'ctor'        => [],
			'verbs'       => [
				[
					'name'        => 'start_periodic_tick',
					'description' => 'Register with _router TIMER for periodic ticks.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $ci, string $args ): string {
						/** @var self $patron */
						$patron = $ci->patron();
						$patron->start_periodic_tick();
						$patron->mark_verb_invoked( 'start_periodic_tick', '' );
						return 'ok';
					},
				],
			],
		];
	}
}
