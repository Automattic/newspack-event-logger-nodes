<?php
/**
 * Flame_Tree — the pure flame-graph algorithm split out of Flame_Builder_Node.
 *
 * The node owns the state, the I/O, and the clock; this file owns the math.
 * Every function here is static, takes any clock reading it needs as an
 * argument, and touches nothing outside the tree it was handed — which is what
 * makes the algorithm testable without a running graph.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless flame-tree construction, aggregation, and finalization.
 *
 * The four stages, in the order Flame_Builder_Node runs them:
 *
 * 1. `build_flame_data()` turns one request's log entries into a tree.
 * 2. `strip_name_suffixes()` drops the hidden duplicate-sibling numbering
 *    before a per-request tree is stored or displayed.
 * 3. `merge_flame_children_incremental()` folds a per-request tree into the
 *    per-URL aggregate, accumulating sums rather than means.
 * 4. `finalize_flame_node()` divides those sums by the aggregate's request
 *    count and strips the internal bookkeeping fields for display.
 */
final class Flame_Tree {

	/** Keyword a closing span logs: `<label> (complete)`. Capture 1 is the base name. */
	const PATTERN_COMPLETE = '/^(.+?) \(complete\)$/';

	/** Keyword an opening span logs: `<label> (start)`. Capture 1 is the base name. */
	const PATTERN_START = '/^(.+?) \(start\)$/';

	/**
	 * Expire anything on a per-URL aggregate not seen within this window —
	 * merged flame children here, profile categories in `Flame_Builder_Node`.
	 * They are one window, so this class owns it and the builder reads it.
	 */
	public const AGGREGATE_EXPIRY_SEC = 3600;

	/** Recursion ceiling for the tree walks; deeper subtrees are left alone. */
	private const MAX_RECURSION_DEPTH = 50;

	/** Open-span ceiling; spans nested deeper are recorded but never matched. */
	public const MAX_STACK_DEPTH = 50;

	/**
	 * Build a flame graph from one request's log entries.
	 *
	 * Each entry is a firehose line as `Log_Manager` wrote it: `k` holds the
	 * keyword (`<label> (start)` or `<label> (complete)`), `l` a stable label
	 * that aggregation groups on, `m` the volatile message, and a complete
	 * additionally carries `duration_ms` and `ts`.
	 *
	 * A start pushes a node, stamped with `t` — the span's offset in
	 * milliseconds from the request's own start, which is what positions it on
	 * the graph's x-axis. A complete matches the nearest open node of the same
	 * base name — LIFO, as `Log_Manager::complete()` itself matches — stamps
	 * its duration, then pops it along with everything above it. A frame's
	 * extent is therefore `[ t, t + value ]`. Children that outlive their
	 * parent survive in the tree with a value of 0, and a complete matching
	 * nothing is dropped.
	 *
	 * `t` is absent, never zero, wherever the offset is unknown: an entry with
	 * no usable `ts`, or a whole request logged without one. Zero is a real
	 * position and cannot double as "unknown".
	 *
	 * Spans nested beyond MAX_STACK_DEPTH are attached to the tree but never
	 * pushed, so their completes match nothing — or, when an ancestor shares
	 * the base name, the wrong node — and their own value stays 0. Their `t`
	 * is still honest, and `cover_children()` gives them an extent.
	 *
	 * The root's own value is left at 0; the caller overwrites it with the
	 * request's measured duration.
	 *
	 * @param array<array-key,mixed> $entries Log entries.
	 * @return array<string,mixed> Flame graph data.
	 */
	public static function build_flame_data( array $entries ): array {
		$origin = self::request_origin( $entries );
		$root   = [
			'name'     => 'request',
			'value'    => 0,
			'children' => [],
		];
		if ( null !== $origin ) {
			$root['t'] = 0.0;
		}

		// Stack of open nodes. Each entry: [ 'node' => &node, 'name' => base ].
		/** @var array<int,array{node: array{name?: string,value?: mixed,children?: array<int,mixed>,t?: float,detail?: string},name: string}> $stack */
		$stack   = [];
		$stack[] = [
			'node' => &$root,
			'name' => 'request',
		];

		foreach ( $entries as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			$keyword_raw = $entry['k'] ?? '';
			$keyword     = Core::str( $keyword_raw );

			if ( \preg_match( self::PATTERN_START, $keyword, $m ) ) {
				$base_name = $m[1];
				// 'l' = stable label (aggregation); 'm' = volatile message.
				$label  = Core::str( $entry['l'] ?? '' );
				$detail = Core::str( $entry['m'] ?? '' );
				$new_node = [
					'name'     => $label ? "{$base_name}: {$label}" : $base_name,
					'value'    => 0,
					'children' => [],
				];
				if ( $detail && $detail !== $label ) {
					$new_node['detail'] = "{$base_name}: {$detail}";
				}
				$offset = self::offset_ms( $origin, $entry['ts'] ?? null );
				if ( null !== $offset ) {
					$new_node['t'] = $offset;
				}

				// Attach by reference so the complete can stamp it in place.
				$top_idx                                 = \count( $stack ) - 1;
				$stack[ $top_idx ]['node']['children'][] = &$new_node;

				// Push onto stack (with depth limit to prevent DoS).
				if ( \count( $stack ) < self::MAX_STACK_DEPTH ) {
					$stack[] = [
						'node' => &$new_node,
						'name' => $base_name,
					];
				}
				unset( $new_node ); // Break reference for next iteration.

			} elseif ( \preg_match( self::PATTERN_COMPLETE, $keyword, $m ) ) {
				$base_name   = $m[1];
				$duration_ms = $entry['duration_ms'] ?? 0;

				// Search stack from top (LIFO) for matching name.
				$found_idx = -1;
				for ( $i = \count( $stack ) - 1; $i >= 1; $i-- ) { // Don't pop root.
					if ( ( $stack[ $i ]['name'] ?? null ) === $base_name ) {
						$found_idx = $i;
						break;
					}
				}

				if ( $found_idx >= 1 ) {
					$stack[ $found_idx ]['node']['value'] = $duration_ms;

					// Pop found_idx..top; orphans outlive parent (value=0).
					\array_splice( $stack, $found_idx );
				}
				// If not found, this is an orphaned complete - ignore it.
			}
		}

		// Number duplicate sibling names to prevent collapse on aggregation.
		self::number_duplicate_siblings( $root );
		// An unclosed span must still cover the children that did finish.
		self::cover_children_deep( $root );

		// Restore $root's string-keyed contract widened by the by-ref helpers.
		/** @var array<string,mixed> $result */
		$result = $root;
		return $result;
	}

	/**
	 * Apply `cover_children()` to a whole tree, deepest first.
	 *
	 * @param array<array-key,mixed> $node  Node to normalize, by reference.
	 * @param int                     $depth Current recursion depth.
	 */
	private static function cover_children_deep( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		if ( ! empty( $node['children'] ) && \is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				if ( \is_array( $child ) ) {
					self::cover_children_deep( $child, $depth + 1 );
				}
			}
			unset( $child );
		}
		self::cover_children( $node );
	}

	/**
	 * Recursively number duplicate sibling names with hidden suffix.
	 *
	 * Merging keys on `name`, so two siblings sharing one would collapse into
	 * a single aggregate node. Appending \x00{N} keeps them apart; every read
	 * path strips the suffix again before storage or display.
	 *
	 * @param array<array-key,mixed> $node  Flame node (modified by reference).
	 * @param int                     $depth Current recursion depth.
	 */
	private static function number_duplicate_siblings( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		if ( empty( $node['children'] ) || ! \is_array( $node['children'] ) ) {
			return;
		}

		// Count occurrences of each name among siblings.
		$name_counts = [];
		foreach ( $node['children'] as $child ) {
			$child_name           = \is_array( $child ) ? ( $child['name'] ?? 'unknown' ) : 'unknown';
			$name                 = Core::str( $child_name, 'unknown' );
			$name_counts[ $name ] = ( $name_counts[ $name ] ?? 0 ) + 1;
		}

		// Add sequence numbers to duplicates.
		$name_seq = [];
		foreach ( $node['children'] as &$child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$child_name = $child['name'] ?? 'unknown';
			$name       = Core::str( $child_name, 'unknown' );
			if ( $name_counts[ $name ] > 1 ) {
				$seq               = ( $name_seq[ $name ] ?? 0 ) + 1;
				$name_seq[ $name ] = $seq;
				$child['name']     = $name . "\x00" . $seq;
			}
			self::number_duplicate_siblings( $child, $depth + 1 );
		}
	}

	/**
	 * Offset in milliseconds from the request's start, to microsecond
	 * precision. Null — not zero — when either end is unknown, because zero is
	 * where the request itself begins, and null for a negative offset, which
	 * only a clock running backwards can produce.
	 *
	 * @param float|null $origin Request start, unix seconds.
	 * @param mixed      $ts     Entry timestamp, unix seconds.
	 * @return float|null Milliseconds elapsed, or null when unknown.
	 */
	public static function offset_ms( ?float $origin, mixed $ts ): ?float {
		if ( null === $origin || ! \is_numeric( $ts ) || (float) $ts <= 0 ) {
			return null;
		}
		$offset = \round( ( (float) $ts - $origin ) * 1000, 3 );
		return $offset < 0 ? null : $offset;
	}

	/**
	 * The instant the request opened: the EARLIEST timestamp among its
	 * entries. `Log_Manager` writes `process (start)` first, so that is
	 * normally entry zero — but taking the minimum makes the ordering an
	 * enforced property rather than an assumed one, and one out-of-order
	 * entry would otherwise push every earlier span to a negative offset.
	 *
	 * `Core::right_now()` stamps every firehose line, so this is unix seconds
	 * with a microsecond fraction. The fraction is the whole point: it is what
	 * separates two spans inside the same second.
	 *
	 * @param array<array-key,mixed> $entries Log entries.
	 * @return float|null Unix seconds, or null when nothing was timestamped.
	 */
	private static function request_origin( array $entries ): ?float {
		$origin = null;
		foreach ( $entries as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			$ts = $entry['ts'] ?? null;
			if ( \is_numeric( $ts ) && (float) $ts > 0 ) {
				$origin = null === $origin ? (float) $ts : \min( $origin, (float) $ts );
			}
		}
		return $origin;
	}

	/**
	 * Finalize a flame node for display: convert sums to averages, strip
	 * suffixes, normalize parent ≥ children, and remove internal fields.
	 *
	 * The divisor is the aggregate's own request count, not the node's
	 * `seen_count`, so a node appearing in a minority of requests averages
	 * down — the flame shows mean cost per request, not per appearance.
	 *
	 * @param array<array-key,mixed> $node        Flame node (modified by reference).
	 * @param int                     $total_count Requests the aggregate covers; 0 skips the averaging.
	 * @param int                     $depth       Current recursion depth.
	 */
	public static function finalize_flame_node( array &$node, int $total_count, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}

		// Strip hidden sequence suffix (\x00N) for duplicate sibling tracking.
		$node['name'] = self::strip_suffix( \is_string( $node['name'] ?? null ) ? $node['name'] : 'unknown' );

		// Convert sum to average across all requests for this URL.
		if ( $total_count > 0 && isset( $node['sum_value'] ) ) {
			$node['value'] = ( \is_numeric( $node['sum_value'] ) ? $node['sum_value'] : 0 ) / $total_count;
		} elseif ( ! isset( $node['value'] ) ) {
			$node['value'] = 0;
		}

		if ( ! empty( $node['children'] ) && \is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				if ( \is_array( $child ) ) {
					/** @var array<string,mixed> $child */
					self::finalize_flame_node( $child, $total_count, $depth + 1 );
				}
			}
			unset( $child );
			self::cover_children( $node );
		}

		unset( $node['ts'] );
		unset( $node['sum_value'] );
		unset( $node['seen_count'] );
	}

	/**
	 * Raise a node's `value` to the sum of its children's when it falls short.
	 *
	 * The browser's `pruneFlameGraph` prunes on a single value cutoff and takes
	 * a whole subtree with the node it drops, which is only safe because a
	 * child never exceeds its parent. `build_flame_data()` stamps each span's
	 * value from its own `(complete)` entry, so a span whose complete never
	 * arrived — request died, log truncated, entry dropped — keeps a 0 while
	 * its finished children keep real durations. Past 1000 nodes that 0 falls
	 * below the cutoff and silently deletes exactly the frames a viewer opened
	 * the graph to see.
	 *
	 * Where the whole family is positioned, the coverage a parent needs is its
	 * children's time EXTENT — last end minus its own start — not their sum.
	 * The two agree only when the children are contiguous; the gap between two
	 * spans is precisely what a viewer opens a 10-minute request to find, and
	 * summing hides it by widening the spans either side. Aggregate trees carry
	 * no `t`, having merged many requests, so they keep the sum.
	 *
	 * The sum stays the floor. Where two spans OVERLAP the extent is smaller
	 * than their combined width, and `treemapDice` scales children by the
	 * parent's value — so covering by the extent alone would paint them past
	 * its right edge. Overlap has no honest side-by-side layout anyway, and
	 * `withTimeSpacers` declines to position such a family for that reason.
	 *
	 * There is no merged case here because no merged node reaches this: every
	 * node `build_flame_data()` makes is one span, and
	 * `merge_flame_children_incremental()` drops `t` on the way into the
	 * aggregate, so `$start` is null and the sum stands. Restoring `t` there
	 * would need the guard `Flame_Fold::positioned()` carries for the folded
	 * path — an extent read off a node standing for several spans measures
	 * from one of them to another's child.
	 *
	 * Children are assumed already covered, so callers walk bottom-up.
	 *
	 * @param array<array-key,mixed> $node Node to normalize, by reference.
	 */
	private static function cover_children( array &$node ): void {
		if ( empty( $node['children'] ) || ! \is_array( $node['children'] ) ) {
			return;
		}
		$children_sum = 0;
		$extent       = null;
		// Own start; null once any child is unpositioned: no honest extent.
		$start = \is_numeric( $node['t'] ?? null ) ? (float) $node['t'] : null;
		foreach ( $node['children'] as $child ) {
			if ( ! \is_array( $child ) ) {
				$start = null;
				continue;
			}
			$value         = \is_numeric( $child['value'] ?? null ) ? $child['value'] : 0;
			$children_sum += $value;
			if ( null === $start || ! \is_numeric( $child['t'] ?? null ) ) {
				$start = null;
				continue;
			}
			$extent = \max( $extent ?? 0.0, (float) $child['t'] + (float) $value );
		}
		$needed = null !== $start && null !== $extent
			? \max( $extent - $start, $children_sum )
			: $children_sum;

		$node_value = \is_numeric( $node['value'] ?? null ) ? $node['value'] : 0;
		if ( $needed > $node_value ) {
			$node['value'] = $needed;
		}
	}

	/**
	 * Strip hidden sequence suffixes (\x00N) from flame node names recursively.
	 *
	 * Run on a per-request tree before it is stored or displayed. Aggregate
	 * trees keep their suffixes until finalize_flame_node() strips them, since
	 * the suffix is what holds duplicate siblings apart across merges.
	 *
	 * @param array<string,mixed> $node  Flame node (modified in place).
	 * @param int                  $depth Current recursion depth.
	 */
	public static function strip_name_suffixes( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		$node['name'] = self::strip_suffix( Core::str( $node['name'] ?? '' ) );
		if ( ! empty( $node['children'] ) && \is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				if ( \is_array( $child ) ) {
					/** @var array<string,mixed> $child */
					self::strip_name_suffixes( $child, $depth + 1 );
				}
			}
			unset( $child );
		}
	}

	/**
	 * A node name without its duplicate-sibling suffix.
	 *
	 * `number_duplicate_siblings()` appends `\x00N` so a merge cannot collapse
	 * two siblings of the same name; nothing downstream should ever see it.
	 *
	 * @param string $name The stored name.
	 */
	private static function strip_suffix( string $name ): string {
		$null_pos = \strpos( $name, "\x00" );
		return false === $null_pos ? $name : \substr( $name, 0, $null_pos );
	}

	/**
	 * Merge child nodes from a per-request flame into the per-URL aggregate
	 * children additively (sums-not-means). Each node carries `sum_value` (sum
	 * of inclusive durations across every request the node was seen in) and
	 * the aggregate's own request count. Display values come from
	 * finalize at flush time (sum_value / total_count).
	 *
	 * Node `name` is the merge key, which is why build_flame_data numbers
	 * duplicate siblings first. `ts` records when a node was last touched —
	 * `$now_ts`, since per-request trees carry no timestamp of their own —
	 * and anything older than AGGREGATE_EXPIRY_SEC is dropped. An incoming
	 * node's `detail` and `t` never reach the aggregate, which has merged many
	 * requests and so has no single position: a merged node holds `name`,
	 * `sum_value`, `ts` and `children`, nothing else. Finalize divides by the
	 * aggregate's own request count, so no per-node tally is kept.
	 *
	 * @param array<array-key,mixed> $existing Existing aggregate children (list).
	 * @param array<array-key,mixed> $incoming Incoming per-request children (list).
	 * @param int                     $now_ts   Timestamp for un-stamped nodes and the expiry cutoff.
	 * @param int                     $depth    Current recursion depth.
	 * @return array<int,mixed>
	 */
	public static function merge_flame_children_incremental( array $existing, array $incoming, int $now_ts, int $depth = 0 ): array {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return \array_values( $existing );
		}

		/** @var array<string,array<string,mixed>> $indexed */
		$indexed = [];
		foreach ( $existing as $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$child_name = \is_string( $child['name'] ?? null ) ? $child['name'] : 'unknown';
			$indexed[ $child_name ] = $child;
		}

		foreach ( $incoming as $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$name           = \is_string( $child['name'] ?? null ) ? $child['name'] : 'unknown';
			$child_ts       = \is_numeric( $child['ts'] ?? null ) ? (int) $child['ts'] : $now_ts;
			$incoming_value = \is_numeric( $child['value'] ?? null ) ? (float) $child['value'] : 0.0;
			if ( ! isset( $indexed[ $name ] ) ) {
				$indexed[ $name ] = [
					'name'      => $name,
					'sum_value' => $incoming_value,
					'ts'        => $child_ts,
					'children'  => [],
				];
			} else {
				$indexed[ $name ]['ts']        = $child_ts;
				$indexed[ $name ]['sum_value'] = Core::num_float( $indexed[ $name ]['sum_value'] ?? null ) + $incoming_value;
			}

			$child_children   = $child['children'] ?? null;
			$indexed_children = $indexed[ $name ]['children'] ?? [];
			if ( ! empty( $child_children ) && \is_array( $child_children ) ) {
				$indexed[ $name ]['children'] = self::merge_flame_children_incremental(
					\array_values( Core::arr( $indexed_children ) ),
					\array_values( $child_children ),
					$now_ts,
					$depth + 1
				);
			}
		}

		// Expire entries not seen in over 1 hour.
		$cutoff = $now_ts - self::AGGREGATE_EXPIRY_SEC;
		foreach ( $indexed as $name => $child ) {
			if ( ( $child['ts'] ?? 0 ) < $cutoff ) {
				unset( $indexed[ $name ] );
			}
		}

		return \array_values( $indexed );
	}
}
