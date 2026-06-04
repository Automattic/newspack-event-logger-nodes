<?php
/**
 * LRU Cache
 *
 * Simple bucket-based LRU cache for in-memory data.
 * Evicts oldest bucket when capacity exceeded.
 *
 * Store objects (not arrays) for zero-copy mutation — objects are
 * references in PHP, so get() returns the same instance.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LRU cache using bucket rotation.
 */
class LRU_Cache {

	/** @var array<int, array<string, mixed>> Buckets array, indexed by bucket number. */
	private array $buckets = [];

	/** @var int Current bucket index. */
	private int $current = 0;

	/** @var int Max items per bucket. */
	private int $bucket_size;

	/** @var int Max number of buckets. */
	private int $num_buckets;

	/** @var float Seconds between time-based rotations (0 = capacity-only). */
	private float $rotate_interval = 0;

	/** @var float Last rotation timestamp. */
	private float $last_rotation = 0;

	/** @var callable|null Called with (key, value) for each evicted item. */
	private $on_evict = null;

	/**
	 * Constructor.
	 *
	 * @param int $bucket_size Max items per bucket.
	 * @param int $num_buckets Max number of buckets.
	 */
	public function __construct( int $bucket_size = 250, int $num_buckets = 5 ) {
		$this->bucket_size = \max( 1, $bucket_size );
		$this->num_buckets = \max( 1, \min( $num_buckets, 100 ) );
	}

	/**
	 * Set time-based rotation interval.
	 *
	 * @param float    $seconds  Seconds between rotations.
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
	 * Get item from cache.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null Value or null if not found.
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
	 * Set item in cache.
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
	 * Delete item from cache.
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
	 * Iterate all items in cache (newest first).
	 *
	 * @return \Generator Yields [key, value] pairs.
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
	 * Clear all items.
	 */
	public function flush(): void {
		$this->buckets = [];
		$this->current = 0;
	}

	/**
	 * Check if cache is empty.
	 *
	 * @return bool True if empty.
	 */
	public function is_empty(): bool {
		return empty( $this->buckets );
	}

	/**
	 * Get all buckets (for serialization).
	 *
	 * @return array<string, mixed> State array.
	 */
	public function get_state(): array {
		return [
			'buckets' => $this->buckets,
			'current' => $this->current,
		];
	}

	/**
	 * Restore from state (for deserialization).
	 *
	 * @param array<string, mixed> $state State array.
	 */
	public function restore_state( array $state ): void {
		$buckets = $state['buckets'] ?? [];
		$current = $state['current'] ?? 0;

		// Validate types.
		if ( ! \is_array( $buckets ) || ! \is_int( $current ) ) {
			return;
		}

		// Clamp current to valid range.
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

	/**
	 * Rotate if time interval has elapsed.
	 *
	 * Call this periodically from the processing loop.
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
	 * Force a bucket rotation, evicting the oldest bucket if at capacity.
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
	 * Evict a bucket, calling the on_evict callback for each item.
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
	 * Rotate to new bucket if current is full.
	 */
	private function maybe_rotate(): void {
		if ( \count( $this->buckets[ $this->current ] ) < $this->bucket_size ) {
			return;
		}
		$this->force_rotate();
	}
}
