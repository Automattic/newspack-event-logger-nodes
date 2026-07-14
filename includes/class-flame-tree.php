<?php
/**
 * Flame_Tree — the pure flame-graph algorithm split out of Flame_Builder_Node.
 *
 * Stateless tree construction and manipulation: build a flame tree from a
 * request's log entries (LIFO span matching), number duplicate siblings so they
 * survive aggregation, strip those suffixes for display/storage, merge trees
 * incrementally across requests, and finalize a merged tree (sums→averages,
 * parent≥children normalization). No node state, no I/O — Flame_Builder_Node
 * calls these; the clock is passed in rather than read.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

final class Flame_Tree {

	const PATTERN_COMPLETE = '/^(.+?) \(complete\)$/';
	const PATTERN_START    = '/^(.+?) \(start\)$/';

	/** Expire aggregate children not seen within this window. Keep in sync with Flame_Builder_Node's copy. */
	private const AGGREGATE_EXPIRY_SEC = 3600;

	private const MAX_RECURSION_DEPTH = 50;
	private const MAX_STACK_DEPTH     = 50;

	/**
	 * Build a flame graph from a request's log entries.
	 *
	 * This handles improperly nested events (e.g., when a child span outlives
	 * its parent) by using LIFO matching like the log-manager does.
	 *
	 * @param array<array-key, mixed> $entries Log entries.
	 * @param int                     $now_ts  Reference timestamp for un-stamped completes.
	 * @return array<string, mixed> Flame graph data.
	 */
	public static function build_flame_data( array $entries, int $now_ts ): array {
		// Root node.
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

				// Add as child of current top of stack.
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
					// Set duration and timestamp on matched node.
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
	 * Appends \x00{N} to duplicate names so they stay separate during merge,
	 * but the suffix is stripped before display.
	 *
	 * @param array<array-key, mixed> $node  Flame node (modified by reference).
	 * @param int                  $depth Current recursion depth.
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
	 * @param array<array-key, mixed> $existing Existing aggregate children (list).
	 * @param array<array-key, mixed> $incoming Incoming per-request children (list).
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
	 * @param array<array-key, mixed> $node Flame node (modified by reference).
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
