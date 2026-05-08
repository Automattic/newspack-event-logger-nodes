<?php
/**
 * LRU Cache: bucket-based with optional time-rotation + on-evict callback.
 *
 * Hand-port of `Newspack_Event_Logger\LruCache` from the legacy event-logger
 * plugin. Verbatim semantics — same bucket-rotation model, same `get()`-on-old-bucket
 * promotion, same `iterate()` newest-first ordering. The only namespace change is
 * `Newspack_Event_Logger_Nodes` instead of `Newspack_Event_Logger` so this plugin
 * doesn't depend on the legacy plugin's class loader.
 *
 * Used by:
 *  - RequestBuilder (in-flight request cache, 100 × 3 buckets, 200s rotation).
 *  - FlameBuilder   (per-URL aggregate cache, 1000 × 5 buckets).
 *
 * Stores objects (not arrays) for zero-copy mutation — objects are references
 * in PHP, so `get()` returns the same instance and the caller can mutate it.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

class LruCache {

	/** @var array<int,array<string,mixed>> Buckets: int index => { key => value }. */
	private array $buckets = [];

	/** @var int Current bucket index (monotonically increasing). */
	private int $current = 0;

	/** @var int Max items per bucket. */
	private int $bucket_size;

	/** @var int Max number of buckets retained at once. */
	private int $num_buckets;

	/** @var float Seconds between time-based rotations (0 = capacity-only). */
	private float $rotate_interval = 0;

	/** @var float Last rotation timestamp. */
	private float $last_rotation = 0;

	/** @var callable|null Called with (key, value) for each evicted item. */
	private $on_evict = null;

	/**
	 * @param int $bucket_size Max items per bucket.
	 * @param int $num_buckets Max number of buckets.
	 */
	public function __construct( int $bucket_size = 250, int $num_buckets = 5 ) {
		$this->bucket_size = \max( 1, $bucket_size );
		$this->num_buckets = \max( 1, \min( $num_buckets, 100 ) );
	}

	/**
	 * Configure time-based rotation. The cache will rotate (allocating a new
	 * current bucket and evicting the oldest if `num_buckets` is exceeded)
	 * whenever `rotate_if_due()` is called past the interval.
	 *
	 * @param float    $seconds  Seconds between forced rotations.
	 * @param callable $on_evict Called with (key, value) for each evicted item.
	 * @return self
	 */
	public function with_timed_rotation( float $seconds, callable $on_evict ): self {
		$this->rotate_interval = $seconds;
		$this->on_evict        = $on_evict;
		$this->last_rotation   = \microtime( true );
		return $this;
	}

	/**
	 * Get a value by key. Returns null if missing.
	 *
	 * Promotes the entry to the current bucket if found in an older bucket,
	 * keeping recently-accessed items from being evicted.
	 *
	 * @param string $key Lookup key.
	 * @return mixed|null Stored value or null.
	 */
	public function get( string $key ) {
		for ( $i = $this->current; $i >= 0; $i-- ) {
			if ( isset( $this->buckets[ $i ] ) && \array_key_exists( $key, $this->buckets[ $i ] ) ) {
				$value = $this->buckets[ $i ][ $key ];

				// Promote to current bucket if found in older bucket.
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
	 * Store a value under the given key.
	 *
	 * @param string $key   Storage key.
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
	 * Delete a key (silent if missing).
	 *
	 * @param string $key Storage key.
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
	 * Iterate every entry, newest-bucket first.
	 *
	 * @return \Generator<string,mixed>
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
	 * Drop every bucket without firing on_evict.
	 */
	public function flush(): void {
		$this->buckets = [];
		$this->current = 0;
	}

	public function is_empty(): bool {
		return empty( $this->buckets );
	}

	/**
	 * Snapshot for serialization.
	 *
	 * @return array{buckets: array, current: int}
	 */
	public function get_state(): array {
		return [
			'buckets' => $this->buckets,
			'current' => $this->current,
		];
	}

	/**
	 * Restore from a previously-saved snapshot. Validates types; clamps `current`
	 * to the bucket-key range.
	 *
	 * @param array $state Snapshot from `get_state()`.
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

		$max_key       = \max( \array_keys( $buckets ) );
		$this->buckets = $buckets;
		$this->current = \max( 0, \min( $current, $max_key ) );
	}

	/**
	 * Force a rotation if the configured time interval has elapsed.
	 *
	 * Called from the cache user's processing loop (RequestBuilder calls this
	 * once per inbound entry; FlameBuilder calls it on flush ticks).
	 */
	public function rotate_if_due(): void {
		if ( $this->rotate_interval <= 0 ) {
			return;
		}
		$now = \microtime( true );
		if ( $now - $this->last_rotation >= $this->rotate_interval ) {
			$this->force_rotate();
		}
	}

	/**
	 * Allocate a new bucket. Evicts the oldest if `num_buckets` is exceeded
	 * (the eviction call fires on_evict per remaining item).
	 */
	private function force_rotate(): void {
		$this->last_rotation = \microtime( true );
		++$this->current;
		$this->buckets[ $this->current ] = [];

		if ( \count( $this->buckets ) > $this->num_buckets ) {
			$oldest = \min( \array_keys( $this->buckets ) );
			$this->evict_bucket( $oldest );
		}
	}

	/**
	 * Drop a single bucket, firing the on_evict callback per item.
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
	 * Rotate when the current bucket reaches capacity.
	 */
	private function maybe_rotate(): void {
		if ( \count( $this->buckets[ $this->current ] ) < $this->bucket_size ) {
			return;
		}
		$this->force_rotate();
	}
}
