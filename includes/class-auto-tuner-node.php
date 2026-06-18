<?php
/**
 * Auto Tuner Node
 *
 * Receives FlameBuilder's auto-tune decisions as messages and applies them by
 * updating the local option. The write is NOT suppressed: it fires
 * Settings_Event_Writer's option-change listener so the tuned value propagates
 * to spokes through the settings-sync node graph (Settings_Sync_Node), exactly
 * as an admin edit would.
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

class Auto_Tuner_Node extends Node {

	public function fill( array &$message ): void {
		/** @var int $type_flags */
		$type_flags = $message[ Message::TYPE ];
		if ( ( $type_flags & Message::TM_STRUCT ) === 0 ) {
			return;
		}
		$value = $message[ Message::VALUE ];
		if ( ! \is_array( $value ) ) {
			return;
		}

		/** @var array<string, mixed> $items dynamic message VALUE['items'] (string[]). */
		$items = \is_array( $value['items'] ?? null ) ? $value['items'] : [];
		/** @var array<string, mixed> $context dynamic message VALUE['context']. */
		$context = \is_array( $value['context'] ?? null ) ? $value['context'] : [];

		if ( empty( $items ) || ! $this->authorized() ) {
			return;
		}

		// AutoTuner runs inside a long-lived request-workers process,
		// and each apply_* method below does read-modify-write on a WP
		// option. Without invalidating the alloptions snapshot, the
		// RMW would clobber writes made by other workers / SettingsSync
		// fanouts / admin edits since this worker spawned.
		\Newspack_Nodes\Config::invalidate_options_cache();

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
			return \current_user_can( 'manage_options' );
		}
		return false;
	}

	// --- Apply -----------------------------------------------------------------

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $hooks
	 */
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
		/** @var string $hook */
		foreach ( $hooks as $hook ) {
			if ( ! isset( $significant[ $hook ] ) ) {
				$to_remove[ $hook ] = true;
			}
		}
		/** @var array<int|string, string> $existing */
		$updated = \array_values( \array_filter( $existing, static fn( $v ) => ! isset( $to_remove[ $v ] ) ) );

		$this->persist( 'newspack_event_logger_nodes_log_events', $updated );
	}

	// --- Persist + fan-out -----------------------------------------------------

	/**
	 * Local update_option. Auto-tuning is an ORIGINATING setting change, so it is
	 * NOT suppressed: the write fires Settings_Event_Writer's option-change
	 * listener, which propagates the tuned value to spokes immediately through the
	 * settings-sync node graph (Settings_Sync_Node) — exactly as an admin edit
	 * would; the periodic Settings_Sync_Node tick is the backstop. (The old
	 * suppress paired with a now-deleted explicit remote_manager queue to avoid
	 * double-firing; keeping it would silently exempt auto-tuned changes from
	 * immediate propagation.)
	 *
	 * @param mixed $value New option value to persist.
	 */
	private function persist( string $option, $value ): void {
		if ( ! \function_exists( 'update_option' ) ) {
			return;
		}
		\update_option( $option, $value, Config::autoload_for( $option ) );
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $events
	 */
	private function apply_disable_custom_events( array $events, array $context ): void {
		if ( ! \function_exists( 'get_option' ) ) {
			return;
		}
		$existing = \get_option( 'newspack_event_logger_nodes_custom_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$significant = \is_array( $context['significant_events'] ?? null ) ? $context['significant_events'] : [];
		/** @var string $event */
		foreach ( $events as $event ) {
			if ( isset( $significant[ $event ] ) ) {
				continue;
			}
			unset( $existing[ $event ] );
		}

		$this->persist( 'newspack_event_logger_nodes_custom_events', $existing );
	}

	/**
	 * @param array<string, mixed> $events
	 */
	private function apply_add_significant_events( array $events ): void {
		if ( ! \function_exists( 'get_option' ) ) {
			return;
		}
		$existing = \get_option( 'newspack_event_logger_nodes_significant_events', [] );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		/** @var array<int|string, string> $combined */
		$combined = \array_merge( $existing, $events );
		$merged   = \array_values( \array_unique( $combined ) );

		$this->persist( 'newspack_event_logger_nodes_significant_events', $merged );
	}

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		// Hidden: AutoTuner is instantiated as a sibling/patron of
		// FlameBuilder (handled via $interpreter->patron()), not built directly
		// in TSL. Keeping it out of the palette prevents operators from
		// wiring up a second instance that nothing routes messages to.
		return [
			'category'    => 'Hidden',
			'description' => 'Receives FlameBuilder auto-tune decisions and applies them via WP options.',
			'arguments'        => [],
			'commands'       => [],
		];
	}
}
