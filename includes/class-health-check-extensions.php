<?php
/**
 * Health Check Extensions
 *
 * Performance-specific extensions for the base health check worker.
 * Processes discovered hooks and custom events from remote servers.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health Check Extensions class.
 */
class HealthCheckExtensions {

	/**
	 * Maximum discovered events to merge.
	 */
	private const MAX_EVENTS = 10000;

	/**
	 * Process discovered data from health check. Called directly by
	 * RemoteManager::health_check (no WP action indirection); the action
	 * is still fired alongside for external plugin listeners.
	 *
	 * @param array $all_discovery Map of server_id => discovery data.
	 */
	public static function process_discovery( array $all_discovery ): void {
		// Long-lived JobWorker; merge_hooks / merge_events below do
		// read-modify-write on WP options and would clobber concurrent
		// writes without a fresh alloptions snapshot.
		\Newspack_Nodes\Config::invalidate_options_cache();

		$all_hooks  = [];
		$all_events = [];

		foreach ( $all_discovery as $server_id => $data ) {
			// Collect discovered hooks (sanitize remote strings before storage).
			$hooks = $data['registered_hooks'] ?? [];
			if ( \is_array( $hooks ) ) {
				foreach ( $hooks as $hook ) {
					if ( \is_string( $hook ) ) {
						$hook = \sanitize_text_field( $hook );
						if ( '' !== $hook ) {
							$all_hooks[ $hook ] = true;
						}
					}
				}
			}

			// Collect discovered custom events (sanitize remote strings before storage).
			$events = $data['custom_events'] ?? [];
			if ( \is_array( $events ) ) {
				foreach ( $events as $event ) {
					if ( \is_string( $event ) ) {
						$event = \sanitize_text_field( $event );
						if ( '' !== $event ) {
							$all_events[ $event ] = true;
						}
					}
				}
			}
		}

		// Cap discovered hooks/events to prevent unbounded option growth.
		if ( \count( $all_hooks ) > self::MAX_EVENTS ) {
			$all_hooks = \array_slice( $all_hooks, 0, self::MAX_EVENTS, true );
		}
		if ( \count( $all_events ) > self::MAX_EVENTS ) {
			$all_events = \array_slice( $all_events, 0, self::MAX_EVENTS, true );
		}

		// Merge discovered hooks/events into local settings.
		if ( ! empty( $all_hooks ) ) {
			self::merge_hooks( \array_keys( $all_hooks ) );
		}
		if ( ! empty( $all_events ) ) {
			self::merge_events( \array_keys( $all_events ) );
		}
	}

	/**
	 * Merge remote hooks into local settings.
	 *
	 * New hooks are added as unchecked (false).
	 * Temporarily unhooks SettingsSync to prevent fan-out.
	 *
	 * @param array $remote_hooks Remote hook names.
	 */
	private static function merge_hooks( array $remote_hooks ): void {
		$existing = \get_option( 'newspack_event_logger_nodes_log_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}

		// Filter out custom events that don't belong in log_events.
		$custom = \get_option( 'newspack_event_logger_nodes_custom_events', [] );
		if ( ! \is_array( $custom ) ) {
			$custom = [];
		}
		$custom_lookup = [];
		foreach ( $custom as $key => $value ) {
			if ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) {
				$custom_lookup[ $key ] = true;
			} elseif ( \is_string( $value ) && '' !== $value ) {
				$custom_lookup[ $value ] = true;
			}
		}

		// Normalize to flat indexed -- handle both associative (key => bool) and indexed formats.
		$local = [];
		foreach ( $existing as $key => $value ) {
			if ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) {
				$local[] = $key;
			} elseif ( \is_string( $value ) && '' !== $value ) {
				$local[] = $value;
			}
		}
		$local   = \array_values( \array_unique( $local ) );
		$lookup  = \array_flip( $local );
		$updated = false;

		foreach ( $remote_hooks as $hook ) {
			// Skip custom events — they belong in custom_events, not log_events.
			if ( isset( $custom_lookup[ $hook ] ) ) {
				continue;
			}
			if ( \is_string( $hook ) && '' !== $hook && ! isset( $lookup[ $hook ] ) ) {
				// Cap total accumulated hooks to prevent unbounded option growth.
				if ( \count( $local ) >= self::MAX_EVENTS ) {
					break;
				}
				$local[]          = $hook;
				$lookup[ $hook ]  = true;
				$updated          = true;
			}
		}

		if ( $updated ) {
			// Suppress SettingsSync fan-out to prevent discovered hooks from being synced back.
			SettingsSync::suppress_sync();
			try {
				\update_option( 'newspack_event_logger_nodes_log_events', $local, false );
			} finally {
				SettingsSync::suppress_sync( false );
			}
		}
	}

	/**
	 * Merge remote custom events into local settings.
	 *
	 * Temporarily unhooks SettingsSync to prevent fan-out.
	 *
	 * @param array $remote_events Remote event names.
	 */
	private static function merge_events( array $remote_events ): void {
		$discovered = \get_option( 'newspack_event_logger_nodes_discovered_events', [] );
		if ( ! \is_array( $discovered ) ) {
			$discovered = [];
		}
		$updated = false;

		foreach ( $remote_events as $event ) {
			if ( ! isset( $discovered[ $event ] ) ) {
				// Cap total accumulated events to prevent unbounded option growth.
				if ( \count( $discovered ) >= self::MAX_EVENTS ) {
					break;
				}
				$discovered[ $event ] = true;
				$updated              = true;
			}
		}

		if ( $updated ) {
			\update_option( 'newspack_event_logger_nodes_discovered_events', $discovered, false );
		}
	}
}
