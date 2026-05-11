<?php
/**
 * Auto Tuner Node
 *
 * Receives FlameBuilder's auto-tune decisions as messages and applies them:
 *   - Updates the local option (suppressing SettingsSync's static fanout
 *     listener so the local write doesn't re-queue the same change).
 *   - On hubs, also queues a `remote_manager` sync_setting job via
 *     JobIntake so every enabled spoke picks up the tuning change.
 *
 * Replaces the legacy AutoTuneHandlers static action listeners. FlameBuilder
 * used to fire three `do_action()` calls and AutoTuneHandlers wired six
 * priority-5/priority-10 listeners (hub + standalone for each event); since
 * both sides live in the same request-workers process anyway, the WP-action
 * indirection was just intra-process message-passing dressed up as hooks.
 * Now expressed as a Node fill() with dispatch by Message::KEY.
 *
 * Message shape:
 *   KEY   = 'disable_hooks' | 'disable_custom_events' | 'add_significant_events'
 *   VALUE = [ 'items' => string[], 'context' => array ]
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class AutoTuner extends Node {
	public function fill( array &$message ): void {
		if ( ( $message[ Message::TYPE ] & Message::TM_STRUCT ) === 0 ) {
			return;
		}
		$value = $message[ Message::VALUE ];
		if ( ! \is_array( $value ) ) {
			return;
		}

		$items   = \is_array( $value['items'] ?? null ) ? $value['items'] : [];
		$context = \is_array( $value['context'] ?? null ) ? $value['context'] : [];

		if ( empty( $items ) || ! $this->authorized() ) {
			return;
		}

		switch ( $message[ Message::KEY ] ?? '' ) {
			case 'disable_hooks':
				$this->apply_disable_hooks( $items, $context );
				break;
			case 'disable_custom_events':
				$this->apply_disable_custom_events( $items, $context );
				break;
			case 'add_significant_events':
				$this->apply_add_significant_events( $items );
				break;
		}
	}

	/**
	 * Auto-tune only fires from inside a request-workers worker (FlameBuilder).
	 * Workers populate `NEWSPACK_NODES_WORKER_TYPE` post-auth (substrate
	 * SpawnController + Supervisor::run); admin requests have manage_options.
	 * Tests can unset both to exercise the early-return path.
	 */
	private function authorized(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only env check.
		if ( isset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] ) ) {
			return true;
		}
		if ( \function_exists( 'current_user_can' ) ) {
			return (bool) \current_user_can( 'manage_options' );
		}
		return false;
	}

	// --- Apply -----------------------------------------------------------------

	private function apply_disable_hooks( array $hooks, array $context ): void {
		if ( ! \function_exists( 'get_option' ) ) {
			return;
		}
		$existing = \get_option( 'newspack_event_logger_nodes_log_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$significant = \is_array( $context['significant_events'] ?? null ) ? $context['significant_events'] : [];
		$to_remove   = [];
		foreach ( $hooks as $hook ) {
			if ( ! isset( $significant[ $hook ] ) ) {
				$to_remove[ $hook ] = true;
			}
		}
		$updated = \array_values( \array_filter( $existing, static fn( $v ) => ! isset( $to_remove[ $v ] ) ) );

		$this->persist( 'newspack_event_logger_nodes_log_events', $updated );
	}

	private function apply_disable_custom_events( array $events, array $context ): void {
		if ( ! \function_exists( 'get_option' ) ) {
			return;
		}
		$existing = \get_option( 'newspack_event_logger_nodes_custom_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$significant = \is_array( $context['significant_events'] ?? null ) ? $context['significant_events'] : [];
		foreach ( $events as $event ) {
			if ( isset( $significant[ $event ] ) ) {
				continue;
			}
			unset( $existing[ $event ] );
		}

		$this->persist( 'newspack_event_logger_nodes_custom_events', $existing );
	}

	private function apply_add_significant_events( array $events ): void {
		if ( ! \function_exists( 'get_option' ) ) {
			return;
		}
		$existing = \get_option( 'newspack_event_logger_nodes_significant_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$merged = \array_values( \array_unique( \array_merge( $existing, $events ) ) );

		$this->persist( 'newspack_event_logger_nodes_significant_events', $merged );
	}

	// --- Persist + fan-out -----------------------------------------------------

	/**
	 * Remote fan-out (when the aggregator is on) + local update_option (with
	 * SettingsSync's static listener suppressed so the local write doesn't
	 * re-queue what we just queued).
	 */
	private function persist( string $option, $value ): void {
		if ( true === ( Config::load_config()['enable_aggregator'] ?? false ) ) {
			SettingsSync::queue_job(
				'remote_manager',
				[
					'action'    => 'sync_setting',
					'option'    => $option,
					'value'     => $value,
					'endpoint'  => SettingsSync::ENDPOINT,
					'queued_at' => \time(),
				]
			);
		}
		if ( ! \function_exists( 'update_option' ) ) {
			return;
		}
		SettingsSync::suppress_sync( true );
		try {
			\update_option( $option, $value, false );
		} finally {
			SettingsSync::suppress_sync( false );
		}
	}
}
