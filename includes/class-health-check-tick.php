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

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class HealthCheckTick extends Node {

	/**
	 * Minimum seconds between consecutive sweeps. Matches legacy
	 * newspack-event-aggregator's 5-minute debounce. Router TIMER fires
	 * roughly every 5s, so this gates ~60 ticks before each sweep.
	 */
	public const DEBOUNCE_SECONDS = 300;

	/** Unix timestamp of the last sweep enqueue (0 = never). */
	protected int $last_check = 0;

	/**
	 * Register with `_router`'s TIMER event so `fill()` receives a TM_INFO
	 * tick on every Router heartbeat. Called by the aggregator topology
	 * after node construction. Same Router-hitchhike pattern StreamMerger
	 * uses — see StreamMerger::start_periodic_tick().
	 */
	public function start_periodic_tick(): void {
		$router = Core::node( '_router' );
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

		// Long-running worker: Config and ServerRegistry both cache their
		// view at first read and never reload, so an operator turning the
		// aggregator on (or enabling a spoke) AFTER this worker spawned
		// would otherwise be invisible until the worker's ~595s respawn.
		// Reset both before re-reading the gate.
		Config::reset();
		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();

		if ( true !== ( Config::load_config()['enable_aggregator'] ?? false ) ) {
			return;
		}

		if ( empty( $registry->get_enabled() ) ) {
			return;
		}

		$this->last_check = $now;

		$log_manager = LogManager::instance();
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
	}
}
