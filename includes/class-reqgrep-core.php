<?php
/**
 * Reqgrep_Core: the rid-grouping / pattern-matching engine shared by the
 * `wp nodes reqgrep` CLI and the `request_grep` performance-CI verb.
 *
 * It owns the part both consumers must agree on byte-for-byte: WHICH firehose
 * lines belong to WHICH request, and WHEN a request is "complete". The output
 * side differs (the CLI formats an indented tree to stdout; the verb builds a
 * bounded JSON summary), so that stays in each consumer and rides in as the
 * `on_complete` closure.
 *
 * Match semantics (identical to the original reqgrep): a line matches when its
 * rid equals the pattern exactly OR the pattern (preg_quote'd, case-insensitive)
 * is found anywhere in the packed Message envelope. Once any line of a request
 * matches, every line sharing that rid is grouped — earlier lines are recovered
 * from the rotating history buckets.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Reqgrep_Core {

	/**
	 * Maximum bytes per in-progress request. Disk-sourced lines are already
	 * PIPE_BUF-capped at 4KB and the `m` field is truncated to 1KB at source, so
	 * this only bites when a non-canonical producer pipes giant lines in.
	 */
	public const MAX_BYTES_PER_REQUEST = 10 * 1024 * 1024;

	/** Maximum lines per in-progress request. */
	public const MAX_LINES_PER_REQUEST = 20000;

	/** Maximum lines per request retained in the history buckets. */
	public const MAX_LINES_PER_REQUEST_IN_HISTORY = 10000;

	/** Search pattern (the exact-rid short-circuit compares against this). */
	private string $pattern;

	/** Pre-compiled case-insensitive regex the packed envelope is matched against. */
	private string $pattern_regex;

	/** In-flight matched requests. Each value is stdClass {lines:array, bytes:int}. */
	private LRU_Cache $inflight;

	/** @var array<int, array<string, array<int, string>>> History buckets; each bucket maps rid => lines. */
	private array $history = [ [] ];

	/** History bucket size (lines per bucket). */
	private int $bucket_size;

	/** History bucket count. */
	private int $num_buckets;

	/** fn(list<string> $lines, string $rid): void — invoked when a tracked rid completes. */
	private \Closure $on_complete;

	/** fn(): void — invoked when a late match (n>1) finds no history and the buckets are full. */
	private ?\Closure $on_history_miss;

	/**
	 * @param string        $pattern         Search pattern (rid, URL, or any text).
	 * @param LRU_Cache      $inflight        Pre-built in-flight cache (the caller owns its on-evict).
	 * @param int            $bucket_size     History bucket size.
	 * @param int            $num_buckets     History bucket count.
	 * @param \Closure       $on_complete     Called with (lines, rid, clipped) when a tracked rid completes.
	 * @param \Closure|null  $on_history_miss Called when a late match finds no history and buckets are full.
	 */
	public function __construct(
		string $pattern,
		LRU_Cache $inflight,
		int $bucket_size,
		int $num_buckets,
		\Closure $on_complete,
		?\Closure $on_history_miss = null
	) {
		$this->pattern         = $pattern;
		$this->pattern_regex   = self::compile( $pattern );
		$this->inflight        = $inflight;
		$this->bucket_size     = $bucket_size;
		$this->num_buckets     = $num_buckets;
		$this->on_complete     = $on_complete;
		$this->on_history_miss = $on_history_miss;
	}

	/** Compile a user pattern into the case-insensitive regex a line is matched against. */
	public static function compile( string $pattern ): string {
		return '/' . \preg_quote( $pattern, '/' ) . '/i';
	}

	/**
	 * Rid-grouping state machine — the shared tail of every read path.
	 *
	 *  - Already-tracked rid: append; fire on_complete on `process (complete)`.
	 *  - New rid + envelope matches pattern: pull history, append, start tracking.
	 *  - No match: stash in history (bounded by num_buckets × bucket_size).
	 *
	 * @param array<int|string, mixed> $entry Decoded entry hash (the Message VALUE).
	 * @param string                   $rid   Request id (the Message KEY).
	 * @param string                   $line  Packed Message envelope (spooled + grepped).
	 */
	public function push( array $entry, string $rid, string $line ): void {
		$key   = Core::as_string( $entry['k'] ?? '' );
		$state = $this->inflight->get( $rid );
		if ( $state instanceof \stdClass ) {
			// Already tracking this rid: extend it and finalize on complete.
			$this->append_to_state( $state, $line );
			$this->finalize_if_complete( $state, $rid, $key );
		} elseif ( $rid === $this->pattern || \preg_match( $this->pattern_regex, $line ) ) {
			// New matching rid: bootstrap state from history (if any).
			$state        = new \stdClass();
			$state->lines = [];
			$state->bytes = 0;

			$found_history = false;
			foreach ( $this->history as $recent ) {
				if ( isset( $recent[ $rid ] ) ) {
					$found_history = true;
					foreach ( $recent[ $rid ] as $hist_line ) {
						if ( ! $this->append_to_state( $state, $hist_line ) ) {
							break 2; // Cap hit — stop merging history.
						}
					}
				}
			}

			$n = Core::num_int( $entry['n'] ?? 0 );
			if ( ! $found_history && $n > 1 && \count( $this->history ) >= $this->num_buckets && null !== $this->on_history_miss ) {
				( $this->on_history_miss )();
			}

			$this->append_to_state( $state, $line );
			$this->inflight->set( $rid, $state );
			$this->finalize_if_complete( $state, $rid, $key );
		} else {
			// Not matching — stash in history; bound per-rid lines (memory).
			$recent_idx = \count( $this->history ) - 1;
			if ( ! isset( $this->history[ $recent_idx ][ $rid ] ) ) {
				$this->history[ $recent_idx ][ $rid ] = [];
			}
			if ( \count( $this->history[ $recent_idx ][ $rid ] ) < self::MAX_LINES_PER_REQUEST_IN_HISTORY ) {
				$this->history[ $recent_idx ][ $rid ][] = $line;
			}

			// Rotate history buckets on overflow; trim to num_buckets.
			if ( \count( $this->history[ $recent_idx ], COUNT_RECURSIVE ) > $this->bucket_size ) {
				$this->history[] = [];
			}
			if ( \count( $this->history ) > $this->num_buckets ) {
				\array_shift( $this->history );
			}
		}

		// Roll the LruCache; caller's on-evict fires for dropped rids.
		$this->inflight->rotate_if_due();
	}

	/**
	 * Append a line to the in-flight request state, respecting line + byte caps.
	 *
	 * @param \stdClass $state State object with ->lines and ->bytes fields.
	 * @param string    $line  Packed Message envelope (already m-truncated at source).
	 * @return bool True if appended; false if a cap was hit (caller may stop).
	 */
	private function append_to_state( \stdClass $state, string $line ): bool {
		$line_bytes = \strlen( $line );
		// Dynamic \stdClass: ->bytes always int, ->lines always a string list.
		$bytes = Core::int( $state->bytes );
		if ( ! \is_array( $state->lines ) ) {
			$state->lines = [];
		}
		// Reference, not copy: append mutates property in place (avoid COW).
		/** @var list<string> $lines */
		$lines = &$state->lines;
		if ( $bytes + $line_bytes > self::MAX_BYTES_PER_REQUEST ) {
			$state->clipped = true;
			return false;
		}
		if ( \count( $lines ) >= self::MAX_LINES_PER_REQUEST ) {
			$state->clipped = true;
			return false;
		}
		$lines[]      = $line;
		$state->bytes = $bytes + $line_bytes;
		return true;
	}

	/**
	 * Fire on_complete once a tracked rid's `process (complete)` arrives, then
	 * evict it from the in-flight cache.
	 *
	 * @param \stdClass $state The rid's accumulated state.
	 * @param string    $rid   Request id.
	 * @param string    $key   This entry's `k` field.
	 */
	private function finalize_if_complete( \stdClass $state, string $rid, string $key ): void {
		if ( 'process (complete)' !== $key ) {
			return;
		}
		// 3rd arg = clipped-by-caps; extra closure args are dropped (CLI).
		( $this->on_complete )(
			\array_values( \array_filter( Core::arr( $state->lines ), 'is_string' ) ),
			$rid,
			true === ( $state->clipped ?? false )
		);
		$this->inflight->delete( $rid );
	}
}
