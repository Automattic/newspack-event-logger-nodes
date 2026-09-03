<?php
/**
 * Discovery_Collector_Node — the hub's periodic sweep of what its spokes instrument.
 *
 * Mounted by the `hub-control` topology; an operator connects it to the same
 * per-spoke `HTTP_Out` egress nodes that carry settings-sync. It mints and signs
 * one probe per spoke rather than letting a Tee fan one out, for the reason
 * Settings_Sync_Node is the hub's other minter: a signature verifies only at the
 * destination it was minted for, so a command re-addressed after the mint
 * verifies nowhere.
 *
 * What the replies build is a staging catalog — the `discovered_hooks` and
 * `discovered_events` options the rule editor's hook picker offers. Nothing here
 * writes the ruleset; the editor is the only rules writer.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Auth;
use Newspack_Nodes\Core;
use Newspack_Nodes\Fanout_Targets;
use Newspack_Nodes\HTTP_Out_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Message;
use Newspack_Nodes\Timer_Node;

\defined( 'ABSPATH' ) || exit;

/**
 * The hub's discovery collector: one node both asks and folds.
 *
 * Asking and folding live together because a reply routes back to its sender —
 * `Command_Interpreter_Node` addresses every reply TO=FROM — so a spoke's answer
 * arrives at the node that minted the question, with no correlation id and no
 * pending map to keep.
 *
 * Every name it collects lives in the two staging options, and nothing
 * accumulates in memory between ticks. A respawned worker therefore keeps the
 * union already staged, and a spoke that misses one sweep costs a cadence
 * rather than a catalog.
 */
class Discovery_Collector_Node extends Timer_Node {
	use Fanout_Targets;

	/** Default discovery cadence (seconds), used when the positional interval token is blank or absent. */
	private const DEFAULT_INTERVAL_SECONDS = 300;

	/** Ceiling on the names one staging option holds, so an untrusted spoke cannot grow the row without bound. */
	private const MAX_EVENTS = 10000;

	/** Discovery sweep cadence in seconds; positional 0. */
	protected int $interval_seconds = self::DEFAULT_INTERVAL_SECONDS;

	/**
	 * Arm the recurring discovery-fan-out timer. A Timer_Node subclass does not
	 * self-schedule, so this override calls set_timer() itself. A blank or absent
	 * interval falls back to DEFAULT_INTERVAL_SECONDS.
	 *
	 * The token is SECONDS, unlike the base Timer_Node argument, which is
	 * milliseconds; this converts before scheduling.
	 *
	 * @api Called by the substrate during make_node construction.
	 * @param list<string>|null $args Interval in seconds (digits) at token 0, empty for the default, or null to read back.
	 * @return list<string> Last-set argument tokens.
	 * @throws \InvalidArgumentException When the interval token isn't a non-negative decimal integer.
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->parse_schema_args( $args );
		$this->set_timer( $this->cadence_ms( $this->interval_seconds ) );
		return $this->arguments;
	}

	/**
	 * Reply handler: fold one spoke's discovery payload into the hub's options.
	 *
	 * Gates on an array VALUE carrying the verb result under VALUE['payload'] —
	 * the reply envelope Command_Interpreter_Node mints — and ignores anything
	 * else, TYPE included. The merge is monotonic and idempotent, so
	 * out-of-order or partial replies converge to the same union.
	 *
	 * Terminal: nothing is forwarded to the sink, because a staged catalog has
	 * no next hop.
	 *
	 * @param array<int,mixed> $message A spoke's `discovery.get` reply.
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
	 * Periodic fan-out: mint and sign one `discovery.get` command per live
	 * target, addressed at that spoke's `discovery` CI. Drops silently if the
	 * node has no sink.
	 *
	 * The override replaces Timer_Node's heartbeat emit, so this node puts
	 * nothing on the wire but the probes themselves.
	 *
	 * A target with no established session is skipped and asked to handshake:
	 * the far side has no key to verify a signature yet, and the skip alone
	 * would deadlock, since nothing else drives HTTP_Out's `/auth` round-trip.
	 * The missing-session warning stays quiet for the first 30 seconds of
	 * process life, when a handshake is still in flight.
	 *
	 * @api Driven by the substrate Timer (fire_cb).
	 */
	public function fire(): void {
		if ( null === $this->sink ) {
			return;
		}
		$sink = $this->sink;
		// One signed probe per spoke: a re-addressed command cannot verify.
		foreach ( $this->live_targets() as $target ) {
			$egress = $this->egress_for( $target );
			$spoke  = $egress?->vault_id() ?? '';
			if ( '' === $spoke || ! Command_Auth::has_session( $spoke ) ) {
				$uptime = (int) ( Core::$now - Core::$init_time );
				if ( $uptime > 30 ) {
					$this->print_less_often( 'no session for ', $target, '; skipping' );
				}
				// Skipping alone deadlocks: someone must ask for the handshake.
				$egress?->ensure_session();
				continue;
			}
			$out                   = Message::new_message();
			$out[ Message::TYPE ]  = Message::TM_COMMAND;
			$out[ Message::FROM ]  = $this->name;
			$out[ Message::TO ]    = $this->target_path( $target, 'discovery' );
			$out[ Message::VALUE ] = [
				'name'      => 'get',
				'arguments' => [],
			];
			Command_Auth::sign_for( $spoke, $out );
			$sink->fill( $out );
		}
	}

	/**
	 * The egress a target names; a target may be a path, so resolve its head.
	 *
	 * @param string $target Target name or path, as stored by connect_node().
	 * @return HTTP_Out_Node|null The egress node, or null when the head names something else.
	 */
	private function egress_for( string $target ): ?HTTP_Out_Node {
		[ $head ] = Message::split_first( $target );
		$node     = Core::node( $head );
		return $node instanceof HTTP_Out_Node ? $node : null;
	}

	/**
	 * Union-merge a single reply's registered_hooks / custom_events into the
	 * hub's options: remote-string sanitization, custom-event exclusion, and
	 * option-cache invalidation before the read-modify-write. The MAX_EVENTS
	 * ceiling belongs to stage_discovered(), which is what actually writes.
	 *
	 * Union-only — a name a spoke stops reporting stays staged until an
	 * operator clears the option.
	 *
	 * @param array<array-key,mixed> $payload One spoke's discovery payload.
	 */
	private function merge_discovery( array $payload ): void {
		// A stale option cache makes this read-modify-write clobber.
		RuntimeConfig::invalidate_options_cache();

		$hooks  = self::sanitized_names( $payload['registered_hooks'] ?? null );
		$events = self::sanitized_names( $payload['custom_events'] ?? null );

		if ( ! empty( $events ) ) {
			$this->stage_discovered( Config::OPTION_DISCOVERED_EVENTS, \array_keys( $events ) );
		}
		// A custom-event name never stages as a hook; the spoke filters too.
		$hook_names = \array_keys( \array_diff_key( $hooks, $events ) );
		if ( ! empty( $hook_names ) ) {
			$this->stage_discovered( Config::OPTION_DISCOVERED_HOOKS, $hook_names );
		}
	}

	/**
	 * A remote name list as a `name => true` set: sanitized, blank-free and
	 * deduplicated. The set shape is what lets merge_discovery() subtract the
	 * events from the hooks in one array_diff_key().
	 *
	 * These are array keys, so a numeric name returns as an int — which is why
	 * stage_discovered() accepts both.
	 *
	 * @param mixed $raw The payload's list, from an untrusted spoke.
	 * @return array<array-key,bool>
	 */
	private static function sanitized_names( mixed $raw ): array {
		$out = [];
		foreach ( Core::arr( $raw ) as $name ) {
			if ( \is_string( $name ) ) {
				$name = \sanitize_text_field( $name );
				if ( '' !== $name ) {
					$out[ $name ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Union $names into a discovered_* staging catalog option — a passive record
	 * of what spokes report, never the ruleset (the editor is the only rules
	 * writer). Non-autoloaded; once the option holds MAX_EVENTS names, further
	 * new ones are dropped. Writes only when something actually changed.
	 *
	 * The stored shape is `name => true`, a presence flag. `Config::get_custom_colors()`
	 * reads the events option as `event => color` and renders every non-string
	 * value with its default swatch, so the two shapes coexist.
	 *
	 * @param string                $option Fully-qualified staging option name.
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
	 * Topology console manifest: the palette entry and the constructor panel,
	 * where the interval is positional through arguments().
	 *
	 * The verb list is empty because the cadence is the only thing to set; a
	 * sweep is the timer's to schedule, not an operator's to force.
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Monitor',
			'description' => 'Periodically fans discovery.get to every spoke and union-merges replies into the hub options.',
			'arguments'   => [
				[ 'name' => 'interval_seconds', 'type' => 'int', 'default' => self::DEFAULT_INTERVAL_SECONDS, 'description' => 'Interval in seconds between discovery sweeps of the connected spokes (digits only; default 300, floored at 1).' ],
			],
			'commands'    => [],
			'has_target'  => true,
		] );
	}
}
