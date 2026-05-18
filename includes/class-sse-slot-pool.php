<?php
/**
 * Sse_Slot_Pool: app-side wiring for the substrate's
 * `Messages_Stream_Controller` slot-pool seams.
 *
 * The substrate exposes three static Closure properties (acquire,
 * release, check) on `\Newspack_Nodes\Rest\Messages_Stream_Controller`.
 * This class' `wire()` populates them with closures that delegate to
 * `Memcached_Cache::acquire_sse_slot()` / `release_sse_slot()` /
 * `check_sse_slot()` so the unified SSE endpoint inherits the same
 * concurrency-cap behavior the legacy per-feed controllers had.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Rest\Messages_Stream_Controller;

class Sse_Slot_Pool {

	/**
	 * Maximum concurrent SSE streams per user/IP per pool. Matches the
	 * legacy `SSEControllerBase::MAX_SSE_SLOTS`.
	 */
	public static int $max_slots = 8;

	/**
	 * Slot TTL (seconds) for the shared browser pool (partition === -1).
	 * Heartbeat from the dashboard refreshes; expiry frees the slot if
	 * the browser tab drops without disconnect.
	 */
	public static int $ttl_browser = 30;

	/**
	 * Slot TTL (seconds) for per-partition aggregator pools (partition
	 * >= 0). Higher than browser to absorb cross-server latency.
	 */
	public static int $ttl_aggregator = 60;

	/**
	 * Cache instance the closures delegate to. Lazily defaulted to a real
	 * `Memcached_Cache` at first use; tests inject a `FakeMemcached` here.
	 */
	public static ?Cache_Interface $cache = null;

	/**
	 * Install the closures on the substrate's static seams. Idempotent —
	 * subsequent calls just overwrite with the same Closures. Call from
	 * the app bootstrap once memcache config is loadable.
	 */
	public static function wire(): void {
		Messages_Stream_Controller::$acquire_slot = static function ( int $partition ): int|false {
			$cache   = self::cache();
			$ttl     = $partition >= 0 ? self::$ttl_aggregator : self::$ttl_browser;
			return $cache->acquire_sse_slot(
				self::user_id(),
				self::ip_hash(),
				self::$max_slots,
				$ttl,
				$partition
			);
		};
		Messages_Stream_Controller::$release_slot = static function ( int $slot, int $partition ): void {
			self::cache()->release_sse_slot(
				self::user_id(),
				self::ip_hash(),
				$slot,
				$partition
			);
		};
		Messages_Stream_Controller::$check_slot = static function ( int $slot, int $partition ): bool {
			// Refresh-on-check: every drain-loop iteration (controller polls
			// this) touches the slot's TTL so a long-lived SSE connection
			// doesn't get torn down at the `$ttl_browser`-second boundary.
			// Matches the legacy SSEControllerBase touch_sse_slot heartbeat
			// pattern but without a separate client-side ping. Falls back to
			// check_sse_slot when touch fails — the latter is the
			// authoritative "is the slot still ours?" signal.
			$cache   = self::cache();
			$user_id = self::user_id();
			$ip_hash = self::ip_hash();
			$ttl     = $partition >= 0 ? self::$ttl_aggregator : self::$ttl_browser;
			if ( $cache->touch_sse_slot( $user_id, $ip_hash, $slot, $ttl, $partition ) ) {
				return true;
			}
			return $cache->check_sse_slot( $user_id, $ip_hash, $slot, $partition );
		};
	}

	/**
	 * Resolve the cache instance. Tests-injected first; otherwise build a
	 * fresh `Memcached_Cache` from substrate config (mirrors the legacy
	 * `SSEControllerBase::cache()`).
	 */
	private static function cache(): Cache_Interface {
		if ( null === self::$cache ) {
			self::$cache = Memcached_Cache::from_substrate_config();
		}
		return self::$cache;
	}

	private static function user_id(): int {
		return \function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
	}

	/**
	 * 8-character md5 of REMOTE_ADDR. Used only as a cache-key shard,
	 * never displayed or stored on disk — privacy-safe by construction.
	 */
	private static function ip_hash(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return \substr( \md5( (string) $ip ), 0, 8 );
	}
}
