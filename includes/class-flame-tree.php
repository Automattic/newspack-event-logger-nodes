<?php
/**
 * Flame_Tree — the pure flame-graph algorithm split out of Flame_Builder_Node.
 *
 * The node owns the state, the I/O, and the clock; this file owns the math.
 * Every function here is static, takes its reference timestamp as an argument,
 * and touches nothing outside the tree it was handed — which is what makes the
 * algorithm testable without a running graph.
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

	/** Expire aggregate children not seen within this window. Keep in sync with Flame_Builder_Node's copy. */
	private const AGGREGATE_EXPIRY_SEC = 3600;

	/** Recursion ceiling for the tree walks; deeper subtrees are left alone. */
	private const MAX_RECURSION_DEPTH = 50;

	/** Open-span ceiling; spans nested deeper are recorded but never matched. */
	private const MAX_STACK_DEPTH = 50;

	/**
	 * Build a flame graph from one request's log entries.
	 *
	 * Each entry is a firehose line as `Log_Manager` wrote it: `k` holds the
	 * keyword (`<label> (start)` or `<label> (complete)`), `l` a stable label
	 * that aggregation groups on, `m` the volatile message, and a complete
	 * additionally carries `duration_ms` and `ts`.
	 *
	 * A start pushes a node; a complete matches the nearest open node of the
	 * same base name — LIFO, as `Log_Manager::complete()` itself matches —
	 * stamps its duration and timestamp, then pops it along with everything
	 * above it. Children that outlive their parent therefore survive in the
	 * tree with a value of 0, and a complete matching nothing is dropped.
	 *
	 * Spans nested beyond MAX_STACK_DEPTH are attached to the tree but never
	 * pushed, so their completes match nothing — or, when an ancestor shares
	 * the base name, the wrong node — and their own value stays 0.
	 *
	 * The root's own value is left at 0; the caller overwrites it with the
	 * request's measured duration.
	 *
	 * @param array<array-key, mixed> $entries Log entries.
	 * @param int                     $now_ts  Reference timestamp for un-stamped completes.
	 * @return array<string, mixed> Flame graph data.
	 */
	public static function build_flame_data( array $entries, int $now_ts ): array {
		$root = [
			'name'     => 'request',
			'value'    => 0,
			'children' => [],
		];

		// Stack of open nodes. Each entry: [ 'node' => &node, 'name' => base ].
		/** @var array<int, array{node: array{name?: string, value?: mixed, children?: array<int, mixed>, ts?: int, detail?: string}, name: string}> $stack */
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
				$ts_raw      = $entry['ts'] ?? $now_ts;

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
					$stack[ $found_idx ]['node']['ts']    = Core::num_int( $ts_raw, $now_ts );

					// Pop found_idx..top; orphans outlive parent (value=0).
					\array_splice( $stack, $found_idx );
				}
				// If not found, this is an orphaned complete - ignore it.
			}
		}

		// Number duplicate sibling names to prevent collapse on aggregation.
		self::number_duplicate_siblings( $root );

		// Restore $root's string-keyed contract widened by the by-ref helpers.
		/** @var array<string, mixed> $result */
		$result = $root;
		return $result;
	}

	/**
	 * Recursively number duplicate sibling names with hidden suffix.
	 *
	 * Merging keys on `name`, so two siblings sharing one would collapse into
	 * a single aggregate node. Appending \x00{N} keeps them apart; every read
	 * path strips the suffix again before storage or display.
	 *
	 * @param array<array-key, mixed> $node  Flame node (modified by reference).
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
	 * Strip hidden sequence suffixes (\x00N) from flame node names recursively.
	 *
	 * Run on a per-request tree before it is stored or displayed. Aggregate
	 * trees keep their suffixes until finalize_flame_node() strips them, since
	 * the suffix is what holds duplicate siblings apart across merges.
	 *
	 * @param array<string, mixed> $node  Flame node (modified in place).
	 * @param int                  $depth Current recursion depth.
	 */
	public static function strip_name_suffixes( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		$name     = $node['name'] ?? '';
		$name     = Core::str( $name );
		$null_pos = \strpos( $name, "\x00" );
		if ( false !== $null_pos ) {
			$node['name'] = \substr( $name, 0, $null_pos );
		}
		if ( ! empty( $node['children'] ) && \is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				if ( \is_array( $child ) ) {
					/** @var array<string, mixed> $child */
					self::strip_name_suffixes( $child, $depth + 1 );
				}
			}
			unset( $child );
		}
	}

	/**
	 * Merge child nodes from a per-request flame into the per-URL aggregate
	 * children additively (sums-not-means). Each node carries `sum_value` (sum
	 * of inclusive durations across every request the node was seen in) and
	 * `seen_count` (true count of those requests). Display values come from
	 * finalize at flush time (sum_value / total_count).
	 *
	 * Node `name` is the merge key, which is why build_flame_data numbers
	 * duplicate siblings first. `ts` records the last request to touch a node;
	 * anything older than AGGREGATE_EXPIRY_SEC is dropped, and an aggregate
	 * node carrying no `ts` at all expires on the next merge. An incoming
	 * node's `detail` never reaches the aggregate: a merged node holds `name`,
	 * `sum_value`, `seen_count`, `ts`, and `children`, nothing else. Of those,
	 * `seen_count` is bookkeeping — finalize divides by the aggregate's own
	 * request count and drops it, so nothing reads it back today.
	 *
	 * @param array<array-key, mixed> $existing Existing aggregate children (list).
	 * @param array<array-key, mixed> $incoming Incoming per-request children (list).
	 * @param int                     $now_ts   Timestamp for un-stamped nodes and the expiry cutoff.
	 * @param int                     $depth    Current recursion depth.
	 * @return array<int, mixed>
	 */
	public static function merge_flame_children_incremental( array $existing, array $incoming, int $now_ts, int $depth = 0 ): array {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return \array_values( $existing );
		}

		/** @var array<string, array<string, mixed>> $indexed */
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
					'name'       => $name,
					'sum_value'  => $incoming_value,
					'seen_count' => 1,
					'ts'         => $child_ts,
					'children'   => [],
				];
			} else {
				$indexed[ $name ]['seen_count'] = ( \is_numeric( $indexed[ $name ]['seen_count'] ?? null ) ? $indexed[ $name ]['seen_count'] : 0 ) + 1;
				$indexed[ $name ]['ts']         = $child_ts;
				$indexed[ $name ]['sum_value']  = ( \is_numeric( $indexed[ $name ]['sum_value'] ?? null ) ? $indexed[ $name ]['sum_value'] : 0 ) + $incoming_value;
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

	/**
	 * Finalize a flame node for display: convert sums to averages, strip
	 * suffixes, normalize parent ≥ children, and remove internal fields.
	 *
	 * The divisor is the aggregate's own request count, not the node's
	 * `seen_count`, so a node appearing in a minority of requests averages
	 * down — the flame shows mean cost per request, not per appearance.
	 *
	 * @param array<array-key, mixed> $node        Flame node (modified by reference).
	 * @param int                     $total_count Requests the aggregate covers; 0 skips the averaging.
	 * @param int                     $depth       Current recursion depth.
	 */
	public static function finalize_flame_node( array &$node, int $total_count, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}

		// Strip hidden sequence suffix (\x00N) for duplicate sibling tracking.
		$name     = \is_string( $node['name'] ?? null ) ? $node['name'] : 'unknown';
		$null_pos = \strpos( $name, "\x00" );
		if ( false !== $null_pos ) {
			$node['name'] = \substr( $name, 0, $null_pos );
		}

		// Convert sum to average across all requests for this URL.
		if ( $total_count > 0 && isset( $node['sum_value'] ) ) {
			$node['value'] = ( \is_numeric( $node['sum_value'] ) ? $node['sum_value'] : 0 ) / $total_count;
		} elseif ( ! isset( $node['value'] ) ) {
			$node['value'] = 0;
		}

		if ( ! empty( $node['children'] ) && \is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				if ( \is_array( $child ) ) {
					/** @var array<string, mixed> $child */
					self::finalize_flame_node( $child, $total_count, $depth + 1 );
				}
			}
			unset( $child );

			// Normalize: ensure parent value >= sum of children.
			$children_sum = 0;
			foreach ( $node['children'] as $child ) {
				$children_sum += \is_array( $child ) && \is_numeric( $child['value'] ?? null ) ? $child['value'] : 0;
			}
			$node_value = \is_numeric( $node['value'] ) ? $node['value'] : 0;
			if ( $children_sum > $node_value ) {
				$node['value'] = $children_sum;
			}
		}

		unset( $node['ts'] );
		unset( $node['sum_value'] );
		unset( $node['seen_count'] );
	}
}
