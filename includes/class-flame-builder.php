<?php
/**
 * FlameBuilder: aggregates completed-request events into per-event aggregates,
 * batched in a 5×1000 LRU bucket cache, flushed to memcache via Stats_Store on
 * bucket rotation.
 *
 * Receives JSON-encoded completed requests (output of RequestBuilder).
 *
 * Bucket structure (5×1000):
 *   - Up to NUM_BUCKETS (default 5) sequence-indexed buckets, each capped at
 *     MAX_PER_BUCKET (default 1000) distinct event-name entries:
 *       { event_name => { count: int, sum_time: float } }
 *   - Each bucket carries a wall-clock id (floor(creation_time / interval))
 *     used as the memcache key on flush — so cross-worker / cross-rotation
 *     merges land in the same key when the wall-clock window matches.
 *   - Rotation triggers:
 *     (a) time-based: a fill arriving in a wall-clock window past the current
 *         bucket's window forces rotation. Default interval matches
 *         RequestBuilder (200s).
 *     (b) size-based: a fill that would push the current bucket past
 *         MAX_PER_BUCKET entries forces rotation.
 *   - On rotation, if NUM_BUCKETS would be exceeded, the oldest bucket is
 *     merge-flushed into Stats_Store under NS_FLAME_CACHE keyed by its
 *     wall-clock id, then dropped.
 *   - Sums-not-means storage: rotated buckets store raw count + sum_time;
 *     cross-bucket merge in memcache is exact addition. Display layer
 *     computes means at read time.
 *
 * Stats_Store integration is opt-in: if no store is set, rotated buckets are
 * dropped (test mode / single-process mode). flush() still empties the cache.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class FlameBuilder extends Node {
	public const BUCKET_INTERVAL_S = 200;
	public const NUM_BUCKETS       = 5;
	public const MAX_PER_BUCKET    = 1000;

	/**
	 * Sequence-indexed buckets. The keys are monotonic integers (0, 1, 2, ...)
	 * so we can pop the oldest in O(1). Each entry pairs the bucket payload
	 * with the wall-clock id used as the flush key.
	 *
	 * @var array<int,array{id:int,data:array<string,array{count:int,sum_time:float}>}>
	 */
	private array $buckets = [];

	private int $next_seq = 0;

	private int $bucket_interval_s;
	private int $max_per_bucket;
	private int $num_buckets;

	private ?Stats_Store $stats_store = null;

	public function __construct(
		int $bucket_interval_s = self::BUCKET_INTERVAL_S,
		int $max_per_bucket    = self::MAX_PER_BUCKET,
		int $num_buckets       = self::NUM_BUCKETS
	) {
		$this->bucket_interval_s = \max( 1, $bucket_interval_s );
		$this->max_per_bucket    = \max( 1, $max_per_bucket );
		$this->num_buckets       = \max( 1, $num_buckets );
	}

	/**
	 * Inject a Stats_Store for memcache flushes. Pass null to drop rotated
	 * buckets (test/single-process mode).
	 */
	public function set_stats_store( ?Stats_Store $store ): void {
		$this->stats_store = $store;
	}

	public function stats_count(): int {
		$total = 0;
		foreach ( $this->buckets as $bucket ) {
			$total += \count( $bucket['data'] );
		}
		return $total;
	}

	/**
	 * Return-and-clear the entire bucket map as a single flat aggregate. Used
	 * by tests and the legacy single-process path. Does NOT flush to memcache —
	 * that happens on bucket rotation.
	 *
	 * @return array<string,array{count:int,sum_time:float}>
	 */
	public function flush(): array {
		$out = [];
		foreach ( $this->buckets as $bucket ) {
			foreach ( $bucket['data'] as $name => $entry ) {
				$out[ $name ] ??= [ 'count' => 0, 'sum_time' => 0.0 ];
				$out[ $name ]['count']    += $entry['count'];
				$out[ $name ]['sum_time'] += $entry['sum_time'];
			}
		}
		$this->buckets = [];
		return $out;
	}

	/**
	 * Force-flush every aged-out bucket to memcache. Call from a Timer /
	 * maintenance loop. Buckets whose wall-clock window is older than
	 * (current_window - num_buckets + 1) get evicted.
	 */
	public function maintenance(): void {
		$current_id = $this->current_bucket_id();
		$cutoff     = $current_id - $this->num_buckets + 1;
		foreach ( $this->buckets as $seq => $bucket ) {
			if ( $bucket['id'] < $cutoff ) {
				$this->evict_bucket( $seq );
			}
		}
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$req = \json_decode( $message[ Message::VALUE ], true );
		if ( ! \is_array( $req ) || empty( $req['events'] ) ) {
			Core::print_less_often( 'FlameBuilder: invalid completed-request line' );
			return;
		}

		// Time-based rotation: any aged-out bucket flushes before we accept
		// new entries. Cheap when no rotation is due (single comparison).
		$this->maintenance();

		// Pick the active bucket — either the newest open one whose wall-clock
		// id matches the current window, or a fresh slot otherwise.
		$current_id = $this->current_bucket_id();
		$active_seq = $this->active_seq( $current_id );

		foreach ( $req['events'] as $event ) {
			$name = $event['name'] ?? '';
			$time = (float) ( $event['time'] ?? 0 );
			if ( $name === '' ) {
				continue;
			}

			// Size-based rotation: if adding a NEW name would push past the cap,
			// roll over before inserting. Existing-name updates don't grow the
			// bucket, so they don't trigger rotation.
			if (
				! isset( $this->buckets[ $active_seq ]['data'][ $name ] )
				&& \count( $this->buckets[ $active_seq ]['data'] ) >= $this->max_per_bucket
			) {
				$active_seq = $this->roll_over( $current_id );
			}

			$this->buckets[ $active_seq ]['data'][ $name ] ??= [ 'count' => 0, 'sum_time' => 0.0 ];
			++$this->buckets[ $active_seq ]['data'][ $name ]['count'];
			$this->buckets[ $active_seq ]['data'][ $name ]['sum_time'] += $time;
		}
	}

	/**
	 * Wall-clock-derived bucket id; lets independent workers / restarts merge
	 * additively into the same memcache key when their windows align.
	 */
	private function current_bucket_id(): int {
		return (int) \floor( Core::$right_now / $this->bucket_interval_s );
	}

	/**
	 * Find or allocate the active bucket whose wall-clock id matches $id.
	 * Returns the sequence index for direct array access.
	 */
	private function active_seq( int $id ): int {
		// Linear scan: with NUM_BUCKETS=5 this is faster than maintaining a
		// reverse map (and the buckets array is too small for the reverse map
		// to amortize its own bookkeeping cost).
		foreach ( $this->buckets as $seq => $bucket ) {
			if ( $bucket['id'] === $id ) {
				return $seq;
			}
		}
		return $this->allocate_bucket( $id );
	}

	/**
	 * Roll over: allocate a brand-new bucket at the same wall-clock id (used
	 * when the size-cap is hit within a single time window). The old bucket
	 * still gets the same id so it merges additively in memcache.
	 */
	private function roll_over( int $id ): int {
		return $this->allocate_bucket( $id );
	}

	private function allocate_bucket( int $id ): int {
		$seq                   = $this->next_seq++;
		$this->buckets[ $seq ] = [ 'id' => $id, 'data' => [] ];
		// At-cap eviction: oldest sequence (= lowest seq number) drops out.
		if ( \count( $this->buckets ) > $this->num_buckets ) {
			$oldest_seq = \array_key_first( $this->buckets );
			if ( $oldest_seq !== null && $oldest_seq !== $seq ) {
				$this->evict_bucket( $oldest_seq );
			}
		}
		return $seq;
	}

	/**
	 * Evict a single bucket: serialize its contents into Stats_Store under
	 * NS_FLAME_CACHE (keyed by the wall-clock id so multi-promote merges are
	 * additive), then drop from the local cache.
	 */
	private function evict_bucket( int $seq ): void {
		if ( ! isset( $this->buckets[ $seq ] ) ) {
			return;
		}
		$bucket = $this->buckets[ $seq ];
		unset( $this->buckets[ $seq ] );
		if ( $this->stats_store !== null && ! empty( $bucket['data'] ) ) {
			$this->stats_store->merge_flame_bucket( (string) $bucket['id'], $bucket['data'] );
		}
	}
}
