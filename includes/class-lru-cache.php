<?php
/**
 * LRU Cache
 *
 * Bucket-based LRU cache for in-memory data. Writes land in the newest
 * bucket; a full bucket (or an elapsed rotation interval) opens a fresh one
 * and drops the oldest once the bucket count exceeds capacity. Reading an
 * entry promotes it to the newest bucket, so anything touched regularly
 * outlives the rotation window and only idle keys age out.
 *
 * `Request_Builder_Node` holds in-flight requests here keyed by request id,
 * and `Reqgrep_Command` does the same for the CLI; in both, eviction is the
 * signal that a request never completed.
 *
 * A variant of Tachikoma's bucket LRU — `Nodes/Table.pm` `lru_lookup`
 * (https://github.com/datapoke/tachikoma), in the shape our DN `ReqGrep.pm`
 * uses it. That DN tree is not public, so only the Table.pm half is followable.
 *
 * Store objects (not arrays) for zero-copy mutation — objects are
 * references in PHP, so get() returns the same instance.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LRU cache using bucket rotation.
 *
 * Holds at most `bucket_size * num_buckets` items, both clamped by the
 * constructor.
 */
class LRU_Cache {

	/** @var int Upper clamp on num_buckets; caps how many buckets stay live. */
	private const MAX_BUCKETS = 100;

	/** @var int Max items per bucket. */
	private int $bucket_size;

	/** @var array<int, array<string, mixed>> Live buckets, keyed by bucket index. */
	private array $buckets = [];

	/** @var int Newest bucket index; monotonic, so an index is never reused. */
	private int $current = 0;

	/** @var float Last rotation timestamp. */
	private float $last_rotation = 0;

	/** @var int Max number of buckets. */
	private int $num_buckets;

	/** @var callable|null Called with (key, value) for each evicted item. */
	private $on_evict = null;

	/** @var float Seconds between time-based rotations (0 = capacity-only). */
	private float $rotate_interval = 0;

	/**
	 * Constructor.
	 *
	 * @param int $bucket_size Max items per bucket; clamped to at least 1.
	 * @param int $num_buckets Max number of buckets; clamped to 1..MAX_BUCKETS.
	 */
	public function __construct( int $bucket_size = 250, int $num_buckets = 5 ) {
		$this->bucket_size = \max( 1, $bucket_size );
		$this->num_buckets = \max( 1, \min( $num_buckets, self::MAX_BUCKETS ) );
	}

	/**
	 * Get item from cache, promoting it to the newest bucket.
	 *
	 * Searches newest bucket first. A hit in an older bucket moves the entry
	 * into the newest one, which resets its age and can itself trigger a
	 * rotation — so a read may evict the oldest bucket.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null Value, or null when the key is absent.
	 */
	public function get( string $key ) {
		for ( $i = $this->current; $i >= 0; $i-- ) {
			if ( isset( $this->buckets[ $i ] ) && \array_key_exists( $key, $this->buckets[ $i ] ) ) {
				$value = $this->buckets[ $i ][ $key ];

				if ( $i < $this->current ) {
					unset( $this->buckets[ $i ][ $key ] );
					$this->buckets[ $this->current ][ $key ] = $value;
					$this->maybe_rotate();
				}

				return $value;
			}
		}

		return null;
	}

	/**
	 * Rotate when the newest bucket is full. Callers must have created it.
	 */
	private function maybe_rotate(): void {
		if ( \count( $this->buckets[ $this->current ] ) < $this->bucket_size ) {
			return;
		}
		$this->force_rotate();
	}

	/**
	 * Open a fresh newest bucket, evicting the oldest one past capacity.
	 *
	 * Also stamps the rotation clock, so a capacity rotation restarts the
	 * timed-rotation interval.
	 */
	private function force_rotate(): void {
		$this->last_rotation = Core::$now ?: Core::right_now();
		++$this->current;
		$this->buckets[ $this->current ] = [];

		if ( \count( $this->buckets ) > $this->num_buckets ) {
			$oldest = \min( \array_keys( $this->buckets ) );
			$this->evict_bucket( $oldest );
		}
	}

	/**
	 * Evict a bucket, calling the on_evict callback for each item.
	 *
	 * Without a callback the items simply vanish, so a cache that treats
	 * eviction as a signal must register one via with_timed_rotation().
	 *
	 * @param int $index Bucket index to evict.
	 */
	private function evict_bucket( int $index ): void {
		if ( ! isset( $this->buckets[ $index ] ) ) {
			return;
		}
		if ( $this->on_evict ) {
			foreach ( $this->buckets[ $index ] as $key => $value ) {
				( $this->on_evict )( $key, $value );
			}
		}
		unset( $this->buckets[ $index ] );
	}

	/**
	 * Store an item in the newest bucket, rotating once that bucket fills.
	 *
	 * Re-setting a key that still sits in an older bucket leaves that copy in
	 * place, shadowed by this newer one. get() returns the newer copy, but
	 * delete() removes only that copy and the shadowed one resurfaces.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $key, $value ): void {
		if ( empty( $this->buckets ) ) {
			$this->buckets[0] = [];
			$this->current    = 0;
		}

		$this->buckets[ $this->current ][ $key ] = $value;
		$this->maybe_rotate();
	}

	/**
	 * Rotate when the configured interval has elapsed.
	 *
	 * Call this periodically from the processing loop — nothing ages out on a
	 * quiet cache otherwise, since capacity rotation needs writes. A no-op
	 * until with_timed_rotation() sets an interval.
	 */
	public function rotate_if_due(): void {
		if ( $this->rotate_interval <= 0 ) {
			return;
		}
		$now = Core::$now ?: Core::right_now();
		if ( $now - $this->last_rotation >= $this->rotate_interval ) {
			$this->force_rotate();
		}
	}

	/**
	 * Set the time-based rotation interval and the eviction callback.
	 *
	 * This is the only way to register on_evict, and the callback fires for
	 * capacity evictions too — pass a large interval to get the callback
	 * without timed rotation.
	 *
	 * @param float    $seconds  Seconds between rotations.
	 * @param callable $on_evict Called with (key, value) for each evicted item.
	 * @return self This cache, for chaining onto the constructor.
	 */
	public function with_timed_rotation( float $seconds, callable $on_evict ): self {
		$this->rotate_interval = $seconds;
		$this->on_evict        = $on_evict;
		$this->last_rotation   = Core::$now ?: Core::right_now();
		return $this;
	}

	/**
	 * Delete an item, newest copy first. Silent when the key is absent.
	 *
	 * on_evict does not fire for a delete — eviction means the cache dropped
	 * the entry, not that a caller retired it.
	 *
	 * @param string $key Cache key.
	 */
	public function delete( string $key ): void {
		for ( $i = $this->current; $i >= 0; $i-- ) {
			if ( isset( $this->buckets[ $i ] ) && \array_key_exists( $key, $this->buckets[ $i ] ) ) {
				unset( $this->buckets[ $i ][ $key ] );
				return;
			}
		}
	}

	/**
	 * Iterate every item, newest bucket first and insertion order within a
	 * bucket. Mutating the cache mid-iteration is unsupported.
	 *
	 * Keys are `array-key`, not `string`: buckets are PHP arrays, so a key that
	 * is an all-digit string comes back an int. A `url_hash` is 12 hex chars,
	 * which is all-digits roughly one time in 290 — callers must handle both,
	 * and narrowing this to `string` makes those guards look like dead code.
	 *
	 * @return \Generator<array-key, mixed> Yields value keyed by cache key.
	 */
	public function iterate(): \Generator {
		for ( $i = $this->current; $i >= 0; $i-- ) {
			if ( isset( $this->buckets[ $i ] ) ) {
				foreach ( $this->buckets[ $i ] as $key => $value ) {
					yield $key => $value;
				}
			}
		}
	}

	/**
	 * Drop every item without firing on_evict, and restart bucket numbering.
	 */
	public function flush(): void {
		$this->buckets = [];
		$this->current = 0;
	}

	/**
	 * Snapshot the buckets for serialization.
	 *
	 * `Request_Builder_Node::save_state()` persists this so in-flight requests
	 * survive a worker restart. Rotation settings and on_evict stay out — the
	 * restoring instance supplies its own.
	 *
	 * @return array<string, mixed> Keys `buckets` and `current`.
	 */
	public function get_state(): array {
		return [
			'buckets' => $this->buckets,
			'current' => $this->current,
		];
	}

	/**
	 * Restore a get_state() snapshot, replacing everything held now.
	 *
	 * Malformed input leaves the cache untouched rather than throwing. The
	 * restored buckets are clamped only in that `current` lands on a real
	 * bucket index: a snapshot holding more buckets than num_buckets, or
	 * fuller ones than bucket_size, stays oversized until successive
	 * rotations trim it one bucket at a time.
	 *
	 * @param array<string, mixed> $state State array from get_state().
	 */
	public function restore_state( array $state ): void {
		$buckets = $state['buckets'] ?? [];
		$current = $state['current'] ?? 0;

		if ( ! \is_array( $buckets ) || ! \is_int( $current ) ) {
			return;
		}

		if ( empty( $buckets ) ) {
			$this->buckets = [];
			$this->current = 0;
			return;
		}

		$max_key = \max( \array_keys( $buckets ) );
		/** @var array<int, array<string, mixed>> $buckets */
		$this->buckets = $buckets;
		$this->current = (int) \max( 0, \min( $current, $max_key ) );
	}
}
