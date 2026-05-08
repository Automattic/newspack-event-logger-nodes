<?php
/**
 * HealthCheckExtensions: post-discovery merge of remote-discovered hooks and
 * custom events into local options.
 *
 * Hooks the action fired by RemoteManager::health_check() after each periodic
 * discovery sweep and:
 *   - Merges discovered registered_hooks into `newspack_event_logger_nodes_log_events`
 *     (excluding events that belong in custom_events).
 *   - Merges discovered custom_events into `newspack_event_logger_nodes_discovered_events`.
 *
 * Caps total accumulated entries at MAX_EVENTS so a runaway spoke can't grow
 * the WP option unbounded. Each remote string is sanitized via
 * sanitize_text_field before storage — remote payloads are untrusted.
 *
 * To break the inbound→re-sync loop (HealthCheckExtensions writes update_option,
 * which would re-trigger SettingsSync's listener), it calls
 * SettingsSync::suppress_sync(true) around the writes and unsuppresses in a
 * finally block so a failed update doesn't leave the static guard set.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

class HealthCheckExtensions {
	/**
	 * Maximum discovered hooks/events to merge.
	 * Bounds option growth against a tampered or buggy remote.
	 */
	public const MAX_EVENTS = 10000;

	/**
	 * Wire the discovery action listener. Idempotent — safe to call multiple
	 * times (uses a static guard so multiple plugin activation paths don't
	 * double-register).
	 */
	public static function init(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		if ( \function_exists( 'add_action' ) ) {
			\add_action( 'newspack_event_logger_nodes/health_check_discovery', [ self::class, 'process_discovery' ] );
		}
	}

	/**
	 * Process aggregated discovery data from a health-check sweep.
	 *
	 * @param array $all_discovery Map of server_id => discovery data
	 *                             (`{registered_hooks, custom_events, lag}`).
	 */
	public static function process_discovery( array $all_discovery ): void {
		$all_hooks  = [];
		$all_events = [];

		foreach ( $all_discovery as $server_id => $data ) {
			if ( ! \is_array( $data ) ) {
				continue;
			}

			// Collect & sanitize discovered hooks.
			$hooks = $data['registered_hooks'] ?? [];
			if ( \is_array( $hooks ) ) {
				foreach ( $hooks as $hook ) {
					if ( \is_string( $hook ) ) {
						$hook = self::sanitize( $hook );
						if ( '' !== $hook ) {
							$all_hooks[ $hook ] = true;
						}
					}
				}
			}

			// Collect & sanitize discovered custom events.
			$events = $data['custom_events'] ?? [];
			if ( \is_array( $events ) ) {
				foreach ( $events as $event ) {
					if ( \is_string( $event ) ) {
						$event = self::sanitize( $event );
						if ( '' !== $event ) {
							$all_events[ $event ] = true;
						}
					}
				}
			}
		}

		// Cap discovered hooks/events to prevent unbounded option growth from
		// an over-eager (or hostile) remote.
		if ( \count( $all_hooks ) > self::MAX_EVENTS ) {
			$all_hooks = \array_slice( $all_hooks, 0, self::MAX_EVENTS, true );
		}
		if ( \count( $all_events ) > self::MAX_EVENTS ) {
			$all_events = \array_slice( $all_events, 0, self::MAX_EVENTS, true );
		}

		if ( ! empty( $all_hooks ) ) {
			self::merge_hooks( \array_keys( $all_hooks ) );
		}
		if ( ! empty( $all_events ) ) {
			self::merge_events( \array_keys( $all_events ) );
		}
	}

	/**
	 * Merge discovered hooks into `newspack_event_logger_nodes_log_events`.
	 *
	 * New hooks are added; the existing list is preserved. Events that belong
	 * in `_custom_events` are filtered out (so the same hook name can never
	 * end up in both lists).
	 *
	 * Suppresses SettingsSync's static-mode listener around the write so the
	 * inbound merge doesn't fan out back to the spokes that just told us
	 * about it.
	 *
	 * @param array $remote_hooks Remote hook names (flat list).
	 */
	private static function merge_hooks( array $remote_hooks ): void {
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return;
		}

		$existing = \get_option( 'newspack_event_logger_nodes_log_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}

		// Build the custom_events lookup so we can filter overlap. Both
		// associative (key => bool) and indexed forms are accepted.
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

		// Normalize to flat indexed list -- accept both associative + indexed.
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
			if ( isset( $custom_lookup[ $hook ] ) ) {
				continue; // Belongs in custom_events, not log_events.
			}
			if ( \is_string( $hook ) && '' !== $hook && ! isset( $lookup[ $hook ] ) ) {
				if ( \count( $local ) >= self::MAX_EVENTS ) {
					break;
				}
				$local[]         = $hook;
				$lookup[ $hook ] = true;
				$updated         = true;
			}
		}

		if ( $updated ) {
			SettingsSync::suppress_sync( true );
			try {
				\update_option( 'newspack_event_logger_nodes_log_events', $local, false );
			} finally {
				SettingsSync::suppress_sync( false );
			}
		}
	}

	/**
	 * Merge discovered custom events into the discovered-events option, so the
	 * admin UI can list newly-seen-on-spokes events as picker candidates.
	 *
	 * @param array $remote_events Remote event names.
	 */
	private static function merge_events( array $remote_events ): void {
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return;
		}

		$discovered = \get_option( 'newspack_event_logger_nodes_discovered_events', [] );
		if ( ! \is_array( $discovered ) ) {
			$discovered = [];
		}
		$updated = false;

		foreach ( $remote_events as $event ) {
			if ( ! isset( $discovered[ $event ] ) ) {
				if ( \count( $discovered ) >= self::MAX_EVENTS ) {
					break;
				}
				$discovered[ $event ] = true;
				$updated              = true;
			}
		}

		if ( $updated ) {
			SettingsSync::suppress_sync( true );
			try {
				\update_option( 'newspack_event_logger_nodes_discovered_events', $discovered, false );
			} finally {
				SettingsSync::suppress_sync( false );
			}
		}
	}

	/**
	 * Sanitize an untrusted remote string. Uses WordPress
	 * `sanitize_text_field` when present, falling back to control-char strip
	 * + trim for non-WP contexts (test bootstraps).
	 */
	private static function sanitize( string $value ): string {
		if ( \function_exists( 'sanitize_text_field' ) ) {
			return (string) \sanitize_text_field( $value );
		}
		return \trim( \preg_replace( '/[\x00-\x1f\x7f]/', '', $value ) ?? '' );
	}
}
