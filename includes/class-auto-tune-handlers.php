<?php
/**
 * AutoTuneHandlers: registers listeners for FlameBuilder's auto-tune actions.
 *
 * FlameBuilder fires three actions when it detects noisy events or discovers
 * new significant ones:
 *
 *   newspack_event_logger_nodes/disable_hooks         (hooks, context)
 *   newspack_event_logger_nodes/disable_custom_events (events, context)
 *   newspack_event_logger_nodes/add_significant_events (events, context)
 *
 * Without listeners these signals land in the void (the legacy plugin's
 * gap-analysis flagged this as dead code). This class registers the missing
 * listeners in two flavours:
 *
 *  1. Standalone-mode (priority 10): direct `update_option` updates against
 *     this WordPress instance. Safe on every install — workers pre-set the
 *     EVENT_LOGGER_WORKER_TYPE env var so the capability check passes from
 *     the cron context, while admin requests fall back to manage_options.
 *
 *  2. Hub-mode (priority 5): when running on a hub
 *     (`enable_workers === true`), fan the change out to every spoke via
 *     `JobIntake::queue('remote_manager', sync_setting)` so each remote
 *     applies the same tuning. Priority 5 fires BEFORE priority 10 — the
 *     hub-mode handler fans out, the standalone-mode handler then writes
 *     the local hub option (so the hub itself is also tuned the same way
 *     it told its spokes to be).
 *
 * Loop avoidance: standalone-mode's `update_option` would normally be picked
 * up by SettingsSync's static listener and queue _another_ sync_setting job
 * for the same value. The hub-mode handler has already done that fan-out,
 * so we suppress SettingsSync briefly around the standalone update. (On a
 * spoke, enable_workers is false and SettingsSync's listener fails closed
 * before queueing anything anyway, so suppression is a hub-only safety.)
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

class AutoTuneHandlers {
	/**
	 * Wire listeners on the three FlameBuilder auto-tune actions. Idempotent.
	 */
	public static function init(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		if ( ! \function_exists( 'add_action' ) ) {
			return;
		}

		// Hub-mode listeners: fan-out to spokes BEFORE the standalone update.
		// Priority 5 < 10 so the hub-mode handler runs first.
		\add_action( 'newspack_event_logger_nodes/disable_hooks', [ self::class, 'hub_disable_hooks' ], 5, 2 );
		\add_action( 'newspack_event_logger_nodes/disable_custom_events', [ self::class, 'hub_disable_custom_events' ], 5, 2 );
		\add_action( 'newspack_event_logger_nodes/add_significant_events', [ self::class, 'hub_add_significant_events' ], 5, 2 );

		// Standalone-mode listeners: update local options.
		\add_action( 'newspack_event_logger_nodes/disable_hooks', [ self::class, 'standalone_disable_hooks' ], 10, 2 );
		\add_action( 'newspack_event_logger_nodes/disable_custom_events', [ self::class, 'standalone_disable_custom_events' ], 10, 2 );
		\add_action( 'newspack_event_logger_nodes/add_significant_events', [ self::class, 'standalone_add_significant_events' ], 10, 2 );
	}

	// --- Capability check ----------------------------------------------------

	/**
	 * Auto-tune handlers must run from a worker (cron) OR an admin request.
	 * Workers set EVENT_LOGGER_WORKER_TYPE in their environment; admin requests
	 * have manage_options.
	 */
	private static function authorized(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only env check.
		if ( isset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] ) ) {
			return true;
		}
		if ( \function_exists( 'current_user_can' ) ) {
			return (bool) \current_user_can( 'manage_options' );
		}
		return false;
	}

	/**
	 * Determine whether this instance is configured as a hub (strict check).
	 * Mirrors SettingsSync's fail-closed polarity: missing/non-true means
	 * not-a-hub, no fan-out.
	 */
	private static function is_hub(): bool {
		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			return false;
		}
		$config = Config::load_config();
		return isset( $config['enable_workers'] ) && true === $config['enable_workers'];
	}

	// --- Hub-mode (priority 5) ----------------------------------------------

	/**
	 * Hub-mode: queue a sync_setting job to fan the disabled-hooks change out
	 * to every spoke. The standalone handler (priority 10) then applies the
	 * same change locally.
	 *
	 * @param array $hooks   Hook names FlameBuilder wants disabled.
	 * @param array $context Context (`{significant_events => bool[]}`).
	 */
	public static function hub_disable_hooks( $hooks, $context = [] ): void {
		if ( ! self::authorized() || ! self::is_hub() || empty( $hooks ) ) {
			return;
		}

		// Compute the new value (existing minus to-disable, minus
		// "significant" events that should be preserved despite noise).
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

		self::queue_remote(
			'newspack_event_logger_nodes_log_events',
			$updated
		);
	}

	/**
	 * Hub-mode: fan out a disable_custom_events change.
	 *
	 * @param array $events  Custom event names to disable.
	 * @param array $context Context (`{significant_events}`).
	 */
	public static function hub_disable_custom_events( $events, $context = [] ): void {
		if ( ! self::authorized() || ! self::is_hub() || empty( $events ) ) {
			return;
		}
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

		self::queue_remote(
			'newspack_event_logger_nodes_custom_events',
			$existing
		);
	}

	/**
	 * Hub-mode: fan out an add_significant_events change.
	 *
	 * @param array $events  Event names FlameBuilder wants flagged significant.
	 * @param array $context Unused (kept for action signature).
	 */
	public static function hub_add_significant_events( $events, $context = [] ): void {
		if ( ! self::authorized() || ! self::is_hub() || empty( $events ) ) {
			return;
		}
		if ( ! \function_exists( 'get_option' ) ) {
			return;
		}
		$existing = \get_option( 'newspack_event_logger_nodes_significant_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$merged = \array_values( \array_unique( \array_merge( $existing, $events ) ) );

		self::queue_remote(
			'newspack_event_logger_nodes_significant_events',
			$merged
		);
	}

	// --- Standalone-mode (priority 10) --------------------------------------

	/**
	 * Standalone-mode: directly update_option on this instance. On a hub the
	 * hub-mode handler at priority 5 has already queued the fan-out, so we
	 * suppress SettingsSync's static listener around the local update to
	 * avoid re-queuing the same change.
	 *
	 * @param array $hooks   Hook names FlameBuilder wants disabled.
	 * @param array $context Context (`{significant_events}`).
	 */
	public static function standalone_disable_hooks( $hooks, $context = [] ): void {
		if ( ! self::authorized() || empty( $hooks ) ) {
			return;
		}
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return;
		}

		$existing = \get_option( 'newspack_event_logger_nodes_log_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$significant = \is_array( $context['significant_events'] ?? null ) ? $context['significant_events'] : [];

		$to_remove = [];
		foreach ( $hooks as $hook ) {
			if ( ! isset( $significant[ $hook ] ) ) {
				$to_remove[ $hook ] = true;
			}
		}
		$updated = \array_values( \array_filter( $existing, static fn( $v ) => ! isset( $to_remove[ $v ] ) ) );

		self::guarded_update_option( 'newspack_event_logger_nodes_log_events', $updated );
	}

	/**
	 * Standalone-mode: directly update_option for custom_events.
	 *
	 * @param array $events  Custom event names to disable.
	 * @param array $context Context (`{significant_events}`).
	 */
	public static function standalone_disable_custom_events( $events, $context = [] ): void {
		if ( ! self::authorized() || empty( $events ) ) {
			return;
		}
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
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

		self::guarded_update_option( 'newspack_event_logger_nodes_custom_events', $existing );
	}

	/**
	 * Standalone-mode: directly update_option for significant_events.
	 *
	 * @param array $events  Event names FlameBuilder wants flagged significant.
	 * @param array $context Unused (kept for action signature).
	 */
	public static function standalone_add_significant_events( $events, $context = [] ): void {
		if ( ! self::authorized() || empty( $events ) ) {
			return;
		}
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return;
		}

		$existing = \get_option( 'newspack_event_logger_nodes_significant_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$merged = \array_values( \array_unique( \array_merge( $existing, $events ) ) );

		self::guarded_update_option( 'newspack_event_logger_nodes_significant_events', $merged );
	}

	// --- Helpers -------------------------------------------------------------

	/**
	 * Queue a sync_setting job to RemoteManager via JobIntake. Used by the
	 * hub-mode listeners to fan out a tuning change to every enabled spoke.
	 *
	 * Best-effort: silently bails if neither JobIntake target is loaded.
	 */
	private static function queue_remote( string $option, $value ): void {
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

	/**
	 * update_option wrapper that briefly suppresses SettingsSync's static
	 * listener so the standalone-mode write doesn't double-queue (the
	 * hub-mode handler at priority 5 has already queued the fan-out).
	 */
	private static function guarded_update_option( string $option, $value ): void {
		SettingsSync::suppress_sync( true );
		try {
			\update_option( $option, $value, false );
		} finally {
			SettingsSync::suppress_sync( false );
		}
	}
}
