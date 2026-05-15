<?php
/**
 * Memcached_Cache
 *
 * Direct Memcached/Memcache access for Event Logger Nodes.
 * Supports both PHP extensions with consistent API.
 * Renamed from `Memcached` to avoid colliding with PHP's bundled `\Memcached`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

interface Cache_Interface {
	public function is_available(): bool;
	public function get( string $key );
	public function get_multi( array $keys ): array;
	public function set( string $key, $value, int $ttl ): bool;
	public function add( string $key, $value, int $ttl ): bool;
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

	const DEFAULT_SERVERS = [ '127.0.0.1:11211' ];

	/**
	 * Memcached or Memcache connection instance.
	 *
	 * @var \Memcached|\Memcache|null
	 */
	private $memd = null;

	/**
	 * Which extension is in use: 'memcached', 'memcache', or null.
	 *
	 * @var string|null
	 */
	private ?string $extension = null;

	/**
	 * Initialize the memcache connection.
	 *
	 * @param array $servers Array of memcache servers (host:port strings). Default: ['127.0.0.1:11211'].
	 */
	/**
	 * Build a Memcached_Cache from the substrate's `memcache_servers` config
	 * key, falling back to DEFAULT_SERVERS when the value is missing or
	 * malformed. Consolidates the same array-or-default plumbing previously
	 * inlined at every cache call-site.
	 */
	public static function from_substrate_config(): self {
		$config  = \Newspack_Nodes\Config::load_config();
		$servers = $config['memcache_servers'] ?? self::DEFAULT_SERVERS;
		if ( ! \is_array( $servers ) ) {
			$servers = self::DEFAULT_SERVERS;
		}
		return new self( $servers );
	}

	public function __construct( array $servers = self::DEFAULT_SERVERS ) {
		// Parse servers into host/port pairs.
		$parsed = [];
		foreach ( $servers as $server ) {
			$parts    = \explode( ':', $server );
			$parsed[] = [
				'host' => $parts[0] ?? '127.0.0.1',
				'port' => (int) ( $parts[1] ?? 11211 ),
			];
		}

		if ( empty( $parsed ) ) {
			return;
		}

		// Try Memcached extension first, then fall back to Memcache.
		if ( \class_exists( '\Memcached' ) ) {
			$this->connect_memcached( $parsed );
		} elseif ( \class_exists( '\Memcache' ) ) {
			$this->connect_memcache( $parsed );
		}
	}

	/**
	 * Connect using Memcached extension.
	 *
	 * @param array $servers Array of ['host' => string, 'port' => int].
	 */
	private function connect_memcached( array $servers ): void {
		try {
			$memd = new \Memcached();
			foreach ( $servers as $server ) {
				$memd->addServer( $server['host'], $server['port'] );
			}

			if ( empty( $memd->getServerList() ) ) {
				$this->memd = null;
				return;
			}

			$this->memd      = $memd;
			$this->extension = 'memcached';
		} catch ( \Exception $e ) {
			$this->memd = null;
		}
	}

	/**
	 * Connect using Memcache extension (fallback).
	 *
	 * @param array $servers Array of ['host' => string, 'port' => int].
	 */
	private function connect_memcache( array $servers ): void {
		try {
			$memd = new \Memcache();
			foreach ( $servers as $server ) {
				$memd->addServer( $server['host'], $server['port'] );
			}

			$this->memd      = $memd;
			$this->extension = 'memcache';
		} catch ( \Exception $e ) {
			$this->memd = null;
		}
	}

	/**
	 * Check if memcache is available.
	 *
	 * @return bool True if connected.
	 */
	public function is_available(): bool {
		return null !== $this->memd;
	}

	/**
	 * Get value from memcache.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null Cached value or null if not found.
	 */
	public function get( string $key ) {
		if ( null === $this->memd ) {
			return null;
		}
		$result = $this->memd->get( $key );
		return false === $result ? null : $result;
	}

	/**
	 * Set value in memcache.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time to live in seconds.
	 * @return bool Success.
	 */
	public function set( string $key, $value, int $ttl ): bool {
		if ( null === $this->memd ) {
			return false;
		}
		// Memcached: set(key, value, ttl)
		// Memcache: set(key, value, flags, ttl)
		if ( 'memcached' === $this->extension ) {
			return $this->memd->set( $key, $value, $ttl );
		}
		return $this->memd->set( $key, $value, 0, $ttl );
	}

	/**
	 * Add value to memcache (only if key doesn't exist).
	 *
	 * This is atomic - returns false if key already exists.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time to live in seconds.
	 * @return bool True if added, false if key exists or error.
	 */
	public function add( string $key, $value, int $ttl ): bool {
		if ( null === $this->memd ) {
			return false;
		}
		// Memcached: add(key, value, ttl)
		// Memcache: add(key, value, flags, ttl)
		if ( 'memcached' === $this->extension ) {
			return $this->memd->add( $key, $value, $ttl );
		}
		return $this->memd->add( $key, $value, 0, $ttl );
	}

	/**
	 * Delete a key from memcache.
	 *
	 * @param string $key Cache key.
	 * @return bool True if deleted, false otherwise.
	 */
	public function delete( string $key ): bool {
		if ( null === $this->memd ) {
			return false;
		}
		return $this->memd->delete( $key );
	}

	/**
	 * Get multiple values from memcache in a single round-trip.
	 *
	 * @param array $keys Array of cache keys.
	 * @return array Associative array of key => value for found keys.
	 */
	public function get_multi( array $keys ): array {
		if ( null === $this->memd || empty( $keys ) ) {
			return [];
		}
		if ( 'memcached' === $this->extension ) {
			$result = $this->memd->getMulti( $keys );
			return \is_array( $result ) ? $result : [];
		}
		// Memcache extension: no native multi-get, fall back to serial.
		$result = [];
		foreach ( $keys as $key ) {
			$val = $this->memd->get( $key );
			if ( false !== $val ) {
				$result[ $key ] = $val;
			}
		}
		return $result;
	}

	/**
	 * Flush all keys from memcache.
	 *
	 * @return bool Success.
	 */
	public function flush_all(): bool {
		if ( null === $this->memd ) {
			return false;
		}
		return $this->memd->flush();
	}

	// -------------------------------------------------------------------------
	// SSE Connection Slots
	// -------------------------------------------------------------------------

	/**
	 * SSE slot TTL in seconds.
	 */
	private const SSE_SLOT_TTL = 10;

	/**
	 * Build SSE slot key.
	 *
	 * Aggregator connections pass a partition >= 0 so each partition gets
	 * its own slot pool — one stream-merger per partition shouldn't be able
	 * to crowd browser tabs (or other partitions) out of the global 10-slot
	 * pool.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP address.
	 * @param int    $slot      Slot number.
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return string Cache key.
	 */
	private function sse_slot_key( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): string {
		if ( $partition >= 0 ) {
			return "evlog:sse:{$user_id}:{$ip_hash}:p{$partition}:{$slot}";
		}
		return "evlog:sse:{$user_id}:{$ip_hash}:{$slot}";
	}

	/**
	 * Acquire an SSE connection slot.
	 *
	 * Uses atomic add() to claim an available slot.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP (use substr(md5($ip), 0, 8)).
	 * @param int    $max_slots Maximum number of slots.
	 * @param int    $ttl       Slot TTL in seconds (10 for browsers, 30 for aggregators).
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return int|false Slot number on success, false if all slots taken or no memcache.
	 */
	public function acquire_sse_slot( int $user_id, string $ip_hash, int $max_slots, int $ttl = self::SSE_SLOT_TTL, int $partition = -1 ): int|false {
		if ( null === $this->memd ) {
			// No memcache - deny connection (fail closed).
			return false;
		}

		$connection_id = \wp_generate_uuid4();

		for ( $slot = 0; $slot < $max_slots; $slot++ ) {
			$key = $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition );
			// add() is atomic - only succeeds if key doesn't exist.
			if ( $this->add( $key, $connection_id, $ttl ) ) {
				return $slot;
			}
		}

		return false;
	}

	/**
	 * Check if an SSE slot is still alive (without refreshing TTL).
	 *
	 * Called by SSE loop to check if browser is still sending heartbeats.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP.
	 * @param int    $slot      Slot number.
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return bool True if slot exists.
	 */
	public function check_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool {
		if ( null === $this->memd ) {
			return false;  // No memcache - deny (fail closed).
		}

		$key = $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition );
		return null !== $this->get( $key );
	}

	/**
	 * Touch an SSE slot to keep it alive.
	 *
	 * Called by heartbeat endpoint.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP.
	 * @param int    $slot      Slot number.
	 * @param int    $ttl       Slot TTL in seconds (10 for browsers, 30 for aggregators).
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return bool Success.
	 */
	public function touch_sse_slot( int $user_id, string $ip_hash, int $slot, int $ttl = self::SSE_SLOT_TTL, int $partition = -1 ): bool {
		if ( null === $this->memd ) {
			return true;
		}

		$key = $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition );

		// Memcached extension has native atomic touch().
		if ( 'memcached' === $this->extension && \method_exists( $this->memd, 'touch' ) ) {
			return $this->memd->touch( $key, $ttl );
		}

		// Fallback for Memcache extension: non-atomic get-then-set.
		$value = $this->get( $key );
		if ( null === $value ) {
			// Slot expired - connection is stale, should exit.
			return false;
		}
		return $this->set( $key, $value, $ttl );
	}

	/**
	 * Release an SSE slot.
	 *
	 * Optional - slots auto-expire via TTL.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $ip_hash   Hashed IP.
	 * @param int    $slot      Slot number.
	 * @param int    $partition Partition number (>= 0 to scope per-partition, -1 for shared pool).
	 * @return bool Success.
	 */
	public function release_sse_slot( int $user_id, string $ip_hash, int $slot, int $partition = -1 ): bool {
		if ( null === $this->memd ) {
			return true;
		}

		$key = $this->sse_slot_key( $user_id, $ip_hash, $slot, $partition );
		return $this->delete( $key );
	}

}
