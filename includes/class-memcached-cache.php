<?php
/**
 * Memcached_Cache: thin instance wrapper over PHP's Memcached/Memcache extensions.
 *
 * Renamed from `Memcached` (in newspack-event-logger) so the class name does not
 * collide with PHP's bundled `\Memcached`. Single-instance lifetime tied to the
 * caller (Stats_Store, SSE controllers); init() is idempotent.
 *
 * Stats path: fail-SOFT (every method returns the failure sentinel when memcache
 * is unreachable; never throws). SSE-slot path will fail-CLOSED at the caller —
 * that's the caller's policy decision, not this class's.
 *
 * Implements Cache_Interface so test doubles (FakeMemcached) and the real
 * extension wrapper share one type — no duck-typing.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;

interface Cache_Interface {
	public function is_available(): bool;
	public function get( string $key ): mixed;
	public function get_multi( array $keys ): array;
	public function set( string $key, mixed $value, int $ttl ): bool;
	public function add( string $key, mixed $value, int $ttl ): bool;
	public function delete( string $key ): bool;
	public function flush_all(): bool;

	/**
	 * SSE slot acquire (atomic add() loop). Fail-CLOSED — if the cache is
	 * unreachable the caller should refuse the connection (HTTP 429).
	 *
	 * @return int|false Slot index 0..max_slots-1 on success; false on rate-limit / cache down.
	 */
	public function acquire_sse_slot( int $user_id, string $ip_hash, int $max_slots, int $ttl, int $partition = -1 ): int|false;

	/**
	 * Check whether the named slot is still alive. Fail-CLOSED.
	 */
	public function check_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool;

	/**
	 * Refresh slot TTL (heartbeat). Fail-OPEN (caller treats unreachable cache as
	 * "not our problem to fail this heartbeat over").
	 */
	public function touch_sse_slot( int $user_id, string $ip_hash, int $slot, int $ttl, int $partition = -1 ): bool;

	/**
	 * Release slot. Fail-OPEN (slots TTL out anyway).
	 */
	public function release_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool;
}

class Memcached_Cache implements Cache_Interface {

	public const DEFAULT_SERVERS = [ '127.0.0.1:11211' ];

	/**
	 * Default slot TTL when caller doesn't specify. Browsers heartbeat every 5s
	 * (useFirehoseConnection.js), so 10s gives 2x headroom — a slot frees within
	 * ~5s of a tab closing. Aggregator paths pass SLOT_TTL_AGGREGATOR (30) instead.
	 */
	public const SSE_SLOT_TTL = 10;

	/** @var \Memcached|\Memcache|null */
	private mixed $memd = null;

	/** @var ?string 'memcached' or 'memcache'; null if neither extension available. */
	private ?string $extension = null;

	/**
	 * @param array<string> $servers host:port strings.
	 */
	public function __construct( array $servers = self::DEFAULT_SERVERS ) {
		if ( empty( $servers ) ) {
			return;
		}
		$parsed = [];
		foreach ( $servers as $s ) {
			$parts    = \explode( ':', $s );
			$parsed[] = [
				'host' => $parts[0] ?? '127.0.0.1',
				'port' => (int) ( $parts[1] ?? 11211 ),
			];
		}
		if ( \class_exists( '\Memcached' ) ) {
			$this->connect_memcached( $parsed );
		} elseif ( \class_exists( '\Memcache' ) ) {
			$this->connect_memcache( $parsed );
		}
	}

	private function connect_memcached( array $servers ): void {
		try {
			$memd = new \Memcached();
			foreach ( $servers as $s ) {
				$memd->addServer( $s['host'], $s['port'] );
			}
			if ( empty( $memd->getServerList() ) ) {
				return;
			}
			$this->memd      = $memd;
			$this->extension = 'memcached';
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: connect_memcached failed: ' . $e->getMessage() );
		}
	}

	private function connect_memcache( array $servers ): void {
		try {
			$memd = new \Memcache();
			foreach ( $servers as $s ) {
				$memd->addServer( $s['host'], $s['port'] );
			}
			$this->memd      = $memd;
			$this->extension = 'memcache';
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: connect_memcache failed: ' . $e->getMessage() );
		}
	}

	public function is_available(): bool {
		return $this->memd !== null;
	}

	public function get( string $key ): mixed {
		if ( $this->memd === null ) {
			return null;
		}
		try {
			$result = $this->memd->get( $key );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: get failed: ' . $e->getMessage() );
			return null;
		}
		return $result === false ? null : $result;
	}

	public function get_multi( array $keys ): array {
		if ( $this->memd === null || empty( $keys ) ) {
			return [];
		}
		try {
			if ( $this->extension === 'memcached' ) {
				$result = $this->memd->getMulti( $keys );
				return \is_array( $result ) ? $result : [];
			}
			// Memcache extension: no native multi-get.
			$out = [];
			foreach ( $keys as $k ) {
				$v = $this->memd->get( $k );
				if ( $v !== false ) {
					$out[ $k ] = $v;
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: get_multi failed: ' . $e->getMessage() );
			return [];
		}
	}

	public function set( string $key, mixed $value, int $ttl ): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			if ( $this->extension === 'memcached' ) {
				return (bool) $this->memd->set( $key, $value, $ttl );
			}
			return (bool) $this->memd->set( $key, $value, 0, $ttl );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: set failed: ' . $e->getMessage() );
			return false;
		}
	}

	public function add( string $key, mixed $value, int $ttl ): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			if ( $this->extension === 'memcached' ) {
				return (bool) $this->memd->add( $key, $value, $ttl );
			}
			return (bool) $this->memd->add( $key, $value, 0, $ttl );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: add failed: ' . $e->getMessage() );
			return false;
		}
	}

	public function delete( string $key ): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			return (bool) $this->memd->delete( $key );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: delete failed: ' . $e->getMessage() );
			return false;
		}
	}

	public function flush_all(): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			return (bool) $this->memd->flush();
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: flush failed: ' . $e->getMessage() );
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// SSE Connection Slots
	// -------------------------------------------------------------------------

	/**
	 * Build a slot key. When `$partition >= 0` the slot pool is per-partition so
	 * one stream-merger per partition can't crowd browser tabs out of the global
	 * pool. Browser callers pass -1.
	 */
	public function sse_slot_key( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): string {
		if ( $partition >= 0 ) {
			return "evlog:sse:{$user_id}:{$ip_hash}:p{$partition}:{$slot}";
		}
		return "evlog:sse:{$user_id}:{$ip_hash}:{$slot}";
	}

	/**
	 * Atomic add() loop across slot indices [0, $max_slots). The first index for
	 * which add() succeeds is the slot we own. Fail-CLOSED: returns false when
	 * memcache is down so the controller can issue HTTP 429.
	 */
	public function acquire_sse_slot( int $user_id, string $ip_hash, int $max_slots, int $ttl = self::SSE_SLOT_TTL, int $partition = -1 ): int|false {
		if ( $this->memd === null ) {
			return false;
		}
		$connection_id = \function_exists( 'wp_generate_uuid4' ) ? \wp_generate_uuid4() : \bin2hex( \random_bytes( 16 ) );
		for ( $slot = 0; $slot < $max_slots; $slot++ ) {
			$key = $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition );
			if ( $this->add( $key, $connection_id, $ttl ) ) {
				return $slot;
			}
		}
		return false;
	}

	/**
	 * Slot-still-alive probe (does not refresh TTL). Fail-CLOSED.
	 */
	public function check_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool {
		if ( $this->memd === null ) {
			return false;
		}
		return $this->get( $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition ) ) !== null;
	}

	/**
	 * Heartbeat. Prefers Memcached's native atomic touch(); falls back to
	 * non-atomic get-then-set on the older Memcache extension. Fail-OPEN: when
	 * the cache is unreachable, return true so a transient cache outage doesn't
	 * tear down a legitimate stream.
	 */
	public function touch_sse_slot( int $user_id, string $ip_hash, int $slot, int $ttl = self::SSE_SLOT_TTL, int $partition = -1 ): bool {
		if ( $this->memd === null ) {
			return true;
		}
		$key = $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition );
		try {
			if ( 'memcached' === $this->extension && \method_exists( $this->memd, 'touch' ) ) {
				return (bool) $this->memd->touch( $key, $ttl );
			}
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: touch failed: ' . $e->getMessage() );
		}
		// Memcache fallback: non-atomic get-then-set.
		$value = $this->get( $key );
		if ( $value === null ) {
			return false;
		}
		return $this->set( $key, $value, $ttl );
	}

	/**
	 * Release a slot. Fail-OPEN — slots TTL out without explicit release; if the
	 * cache is unreachable we just let the TTL handle it.
	 */
	public function release_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool {
		if ( $this->memd === null ) {
			return true;
		}
		return $this->delete( $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition ) );
	}
}
