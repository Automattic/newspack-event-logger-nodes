<?php
/**
 * Discovery_Collector_Node — hub-side periodic discovery fan-out + union-merge.
 *
 * fire() mints a `discovery.get` TM_COMMAND to every connected spoke's
 * Discovery_CI. Each spoke's reply self-routes back (TO=FROM) into fill(),
 * which monotonically union-merges the reply's registered_hooks into the
 * discovered_hooks staging option and its custom_events into the discovered_events
 * staging option — a passive catalog of what spokes instrument, never written
 * into the ruleset (the editor is the only rules writer). Folded incrementally
 * one reply at a time (the union is order-independent, so out-of-order / partial
 * replies converge).
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

	/** Default discovery cadence (seconds) used when arguments() is armed without an explicit interval. */
	private const DEFAULT_INTERVAL_SECONDS = 300;

	/** Maximum discovered events to merge. */
	private const MAX_EVENTS = 10000;

	/**
	 * Arm the recurring discovery-fan-out timer. A Timer_Node subclass does not
	 * self-schedule, so we explicitly call set_timer() here. A blank/absent
	 * interval falls back to the default 300s discovery cadence.
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
	 * Reply handler: fold one spoke's discovery payload into the hub's options.
	 *
	 * Gates on a TM_STRUCT-or-array VALUE carrying the unwrapped discovery
	 * payload under VALUE['payload']. The merge is monotonic + idempotent, so
	 * out-of-order / partial replies converge to the same union.
	 *
	 * @param array<int,mixed> $message Message reference (a spoke's `discovery.get` reply).
	 */
	public function fill( array $message ): void {
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
	 * Union-merge a single reply's registered_hooks / custom_events into the
	 * hub's options: remote-string sanitization, MAX_EVENTS cap, custom-event
	 * exclusion, and option-cache invalidation before the read-modify-write.
	 *
	 * @param array<array-key,mixed> $payload One spoke's discovery payload.
	 */
	private function merge_discovery( array $payload ): void {
		// Fresh snapshot: merge below RMWs WP options (avoid clobber).
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

		// Collect discovered custom events (sanitize remote strings first).
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

		if ( ! empty( $events ) ) {
			$this->stage_discovered( Config::OPTION_DISCOVERED_EVENTS, \array_keys( $events ) );
		}
		// Custom-event names never stage as hooks (belt+suspenders w/ filter).
		$hook_names = \array_keys( \array_diff_key( $hooks, $events ) );
		if ( ! empty( $hook_names ) ) {
			$this->stage_discovered( Config::OPTION_DISCOVERED_HOOKS, $hook_names );
		}
	}

	/**
	 * Union $names into a discovered_* staging catalog option — a passive record
	 * of what spokes report, never the ruleset (the editor is the only rules
	 * writer). Non-autoloaded; capped to bound option growth.
	 *
	 * @param string                 $option Fully-qualified staging option name.
	 * @param array<int,int|string> $names  Names to union in (array_keys output; numeric names coerce to int).
	 */
	private function stage_discovered( string $option, array $names ): void {
		$discovered = \get_option( $option, [] );
		if ( ! \is_array( $discovered ) ) {
			$discovered = [];
		}
		$updated = false;

		foreach ( $names as $name ) {
			if ( ! isset( $discovered[ $name ] ) ) {
				// Cap accumulated names to prevent unbounded option growth.
				if ( \count( $discovered ) >= self::MAX_EVENTS ) {
					break;
				}
				$discovered[ $name ] = true;
				$updated             = true;
			}
		}

		if ( $updated ) {
			\update_option( $option, $discovered, Config::autoload_for( $option ) );
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
			'arguments'   => [
				[ 'name' => 'interval_seconds', 'type' => 'int', 'required' => false, 'default' => (string) self::DEFAULT_INTERVAL_SECONDS, 'description' => 'Interval in seconds between discovery sweeps of the connected spokes (default 300).' ],
			],
			'commands'    => [],
			'has_target'  => true,
		] );
	}
}
