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
 *   - The job pipeline handles request_id correlation and STALE_THRESHOLD
 *     drops uniformly across every job type. Inlining bypasses that
 *     bookkeeping. (Log_Manager::begin/end_job_context wraps this tick in a
 *     synthetic /jobs/health-check-tick context, same as a dispatched job.)
 *   - The sweep can block on cURL probes against every remote; the
 *     aggregator topology's drain loop should keep moving.
 *
 * Replaces the legacy `newspack_event_logger_supervisor_periodic` listener
 * from newspack-event-aggregator.php — same 300s debounce, same job shape.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Health_Check_Tick_Node extends Timer_Node {

	/**
	 * Minimum seconds between consecutive sweeps. Matches legacy
	 * newspack-event-aggregator's 5-minute debounce. Router TIMER fires
	 * roughly every 5s, so this gates ~60 ticks before each sweep.
	 */
	public const DEBOUNCE_SECONDS = 300;

	/** Unix timestamp of the last sweep enqueue (0 = never). */
	protected int $last_check = 0;

	public function __construct() {
		// No config verbs: this node is an owned sibling of StreamMerger and its
		// periodic tick is started from StreamMerger's name() lifecycle, not a
		// TSL verb. parent::__construct() with empty verbs attaches no interpreter.
		parent::__construct();
	}

	/**
	 * @api Used by substrate.
	 *
	 * fire (Timer_Node override): Router::notify_timer() -> fire_cb() -> fire() on
	 * each TIMER tick. Enqueues a `remote_manager` health_check job if the debounce
	 * window has elapsed and there's at least one enabled remote. Silently no-ops:
	 *   - within the debounce window;
	 *   - no enabled remotes are registered.
	 *
	 * No `enable_aggregator` gate here — this node only runs inside the
	 * aggregator topology, which only spawns when `enable_aggregator` is
	 * on. The structural gate is upstream; this method just checks the
	 * remote registry (architecture decision #4 in AGENTS.md).
	 */
	public function fire(): void {
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
		Log_Manager::begin_job_context( 'health-check-tick' );
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
			Log_Manager::end_job_context();
		}
	}

	/**
	 * Register with `_router`'s TIMER event so `fill()` receives a TM_INFO
	 * tick on every Router heartbeat. Called by the aggregator topology
	 * after node construction. Same Router-hitchhike pattern StreamMerger
	 * uses — see StreamMerger::start_periodic_tick().
	 */
	public function start_periodic_tick(): void {
		if ( '' === $this->name || null === Core::node( Node_Names::ROUTER ) ) {
			Core::print_less_often( 'HealthCheckTick::start_periodic_tick: no _router; periodic tick disabled' );
			return;
		}
		// Router-hitchhike: notify_timer() calls fire_cb() -> fire() each tick.
		$this->set_timer();
	}

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		// Hidden: HealthCheckTick is instantiated as an owned sibling
		// of StreamMerger (patron-linked in StreamMerger's constructor),
		// not built directly in TSL. Its periodic tick starts from
		// StreamMerger's name() lifecycle — no config verbs. Class stays
		// usable for direct instantiation in tests.
		return [
			'category'    => 'Hidden',
			'description' => 'Drives the aggregator periodic discovery + sync sweep (5-min debounce).',
			'arguments'        => [],
			'commands'       => [],
		];
	}
}
