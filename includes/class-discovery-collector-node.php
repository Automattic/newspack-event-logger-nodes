<?php
/**
 * Discovery_Collector_Node — hub-side periodic discovery fan-out + union-merge.
 *
 * fire() mints a `discovery.get` TM_COMMAND to every connected spoke's
 * Discovery_CI. Each spoke's reply self-routes back (TO=FROM) into fill(),
 * which monotonically union-merges the reply's registered_hooks / custom_events
 * into the hub's local options — folded incrementally one reply at a time (the
 * union is order-independent, so out-of-order / partial replies converge).
 *
 * Replaces the legacy poll-based discovery sweep (Slice A1).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Message;
use Newspack_Nodes\Timer_Node;

\defined( 'ABSPATH' ) || exit;

class Discovery_Collector_Node extends Timer_Node {

	/** Legacy discovery cadence (seconds) used when arguments() is armed without an explicit interval. */
	private const DEFAULT_INTERVAL_SECONDS = 300;

	/** Maximum discovered events to merge. */
	private const MAX_EVENTS = 10000;

	/**
	 * Arm the recurring discovery-fan-out timer. A Timer_Node subclass does not
	 * self-schedule, so we explicitly call set_timer() here. A blank/absent
	 * interval falls back to the legacy 300s discovery cadence.
	 *
	 * @api Called by the substrate during make_node construction.
	 * @param string|null $args Interval in seconds (digits), '' for the default, or null to read back.
	 * @return string Last-set raw arguments string.
	 */
	public function arguments( ?string $args = null ): string {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->arguments = $args;
		$seconds         = '' === $args ? self::DEFAULT_INTERVAL_SECONDS : (int) $args;
		$this->set_timer( $seconds * 1000 );
		return $this->arguments;
	}

	/**
	 * Periodic fan-out: emit one `discovery.get` command toward the connected
	 * Tee, which broadcasts it to every spoke's Discovery_CI. Drops silently
	 * if the node has no sink.
	 *
	 * @api Driven by the substrate Timer (fire_cb).
	 */
	public function fire(): void {
		if ( null === $this->sink ) {
			return;
		}
		$target                = \is_array( $this->target ) ? ( $this->target[0] ?? '' ) : $this->target;
		$out                   = Message::new_message();
		$out[ Message::TYPE ]  = Message::TM_COMMAND;
		$out[ Message::FROM ]  = $this->name;
		$out[ Message::TO ]    = $target . '/discovery';
		$out[ Message::VALUE ] = [
			'name'      => 'get',
			'arguments' => '',
		];
		$this->sink->fill( $out );
	}

	/**
	 * Reply handler: fold one spoke's discovery payload into the hub's options.
	 *
	 * Gates on a TM_STRUCT-or-array VALUE carrying the unwrapped discovery
	 * payload under VALUE['payload']. The merge is monotonic + idempotent, so
	 * out-of-order / partial replies converge to the same union.
	 *
	 * @param array<int,mixed> $message Message reference (a spoke's `discovery.get` reply).
	 */
	public function fill( array &$message ): void {
		++$this->counter;
		$value = $message[ Message::VALUE ];
		if ( ! \is_array( $value ) ) {
			return;
		}
		$payload = $value['payload'] ?? null;
		if ( ! \is_array( $payload ) ) {
			return;
		}
		$this->merge_discovery( $payload );
	}

	/**
	 * Union-merge a single reply's registered_hooks / custom_events into the
	 * hub's options: remote-string sanitization, MAX_EVENTS cap, custom-event
	 * exclusion, and option-cache invalidation before the read-modify-write.
	 *
	 * @param array<array-key,mixed> $payload One spoke's discovery payload.
	 */
	private function merge_discovery( array $payload ): void {
		// Long-lived worker; merge_hooks / merge_events below do read-modify-write
		// on WP options and would clobber concurrent writes without a fresh snapshot.
		RuntimeConfig::invalidate_options_cache();

		$hooks  = [];
		$events = [];

		// Collect discovered hooks (sanitize remote strings before storage).
		$remote_hooks = $payload['registered_hooks'] ?? [];
		if ( \is_array( $remote_hooks ) ) {
			foreach ( $remote_hooks as $hook ) {
				if ( \is_string( $hook ) ) {
					$hook = \sanitize_text_field( $hook );
					if ( '' !== $hook ) {
						$hooks[ $hook ] = true;
					}
				}
			}
		}

		// Collect discovered custom events (sanitize remote strings before storage).
		$remote_events = $payload['custom_events'] ?? [];
		if ( \is_array( $remote_events ) ) {
			foreach ( $remote_events as $event ) {
				if ( \is_string( $event ) ) {
					$event = \sanitize_text_field( $event );
					if ( '' !== $event ) {
						$events[ $event ] = true;
					}
				}
			}
		}

		// Cap discovered hooks/events to prevent unbounded option growth.
		if ( \count( $hooks ) > self::MAX_EVENTS ) {
			$hooks = \array_slice( $hooks, 0, self::MAX_EVENTS, true );
		}
		if ( \count( $events ) > self::MAX_EVENTS ) {
			$events = \array_slice( $events, 0, self::MAX_EVENTS, true );
		}

		if ( ! empty( $hooks ) ) {
			$this->merge_hooks( \array_keys( $hooks ) );
		}
		if ( ! empty( $events ) ) {
			$this->merge_events( \array_keys( $events ) );
		}
	}

	/**
	 * Merge remote hooks into local log_events. The watcher is
	 * Settings_Event_Writer (it watches log_events), so we wrap the write in
	 * Settings_Event_Writer::suppress so the merge doesn't bounce back out as a
	 * new settings event.
	 *
	 * @param array<int,int|string> $remote_hooks Remote hook names (array_keys output; numeric names coerce to int).
	 */
	private function merge_hooks( array $remote_hooks ): void {
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
				$local[]         = $hook;
				$lookup[ $hook ] = true;
				$updated         = true;
			}
		}

		if ( $updated ) {
			// Suppress the settings-event emission so a discovered hook doesn't
			// bounce back out as a synced change.
			Settings_Event_Writer::suppress( true );
			try {
				\update_option( 'newspack_event_logger_nodes_log_events', $local, Config::autoload_for( 'newspack_event_logger_nodes_log_events' ) );
			} finally {
				Settings_Event_Writer::suppress( false );
			}
		}
	}

	/**
	 * Merge remote custom events into discovered_events.
	 *
	 * @param array<int,int|string> $remote_events Remote event names (array_keys output; numeric names coerce to int).
	 */
	private function merge_events( array $remote_events ): void {
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
			\update_option( 'newspack_event_logger_nodes_discovered_events', $discovered, Config::autoload_for( 'newspack_event_logger_nodes_discovered_events' ) );
		}
	}

	/**
	 * Topology console manifest: palette entry — interval is positional via arguments().
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Monitor',
			'description' => 'Periodically fans discovery.get to every spoke and union-merges replies into the hub options.',
			'arguments'   => [],
			'commands'    => [],
			'has_target'  => true,
		] );
	}
}
