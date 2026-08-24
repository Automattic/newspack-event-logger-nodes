<?php
/**
 * Flame_Fold — the merging, resumable variant of `Flame_Tree`'s stack machine.
 *
 * `Flame_Tree::build_flame_data()` runs once over a finished request and keeps
 * every span as its own node; `number_duplicate_siblings()` even works to hold
 * repeated siblings apart. This does the opposite, and stays open afterwards:
 * same-name siblings under a parent collapse into ONE node carrying `count`,
 * summed `value` and `max`, and entries keep arriving into that same tree.
 *
 * Cost is therefore O(distinct paths), not O(messages) — which is the whole
 * point. A five-minute request and a fifty-minute one fold to the same size.
 *
 * One consequence to know about: a folded tree reaches the per-URL aggregate as
 * ONE node per path where an unfolded one reaches it as N numbered siblings, so
 * that aggregate's shape depends on whether a request folded. A merged node
 * also declines the extent covering `Flame_Tree` gives a positioned family —
 * `t` is one instance's and `value` is every instance's, so it keeps the sum
 * instead, and can read lower than the unfolded tree by the gaps it no longer
 * paints. Every stat outside the flame — leaderboard, categories, hourly,
 * per-URL — reads the record's own duration and `profiles`, so those are
 * identical either way.
 *
 * Because it is the same stack machine, pair balance is inherent: there is no
 * way to emit an orphaned complete or an unclosed span, which is exactly what
 * disqualified dropping every Nth entry.
 *
 * Static over a plain-array state, like `Flame_Tree`, and for a harder reason:
 * a fold lives on an in-flight envelope, and those ride the Consumer's
 * co-committed checkpoint frame through `Message` packing. An object would not
 * come back.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One in-flight request's entries, merged by path as they arrive.
 *
 * @phpstan-type Fold_Frame array{name: string, path: list<string>}
 * @phpstan-type Fold_State array{root: array<array-key,mixed>, stack: list<Fold_Frame>, count: int, origin: float|null}
 */
final class Flame_Fold {

	/**
	 * A fresh fold state.
	 *
	 * `origin` is the request start in unix seconds — the reference every `t`
	 * offset is measured from. Callers that know it (Request_Builder holds the
	 * envelope's `timestamp`) should pass it; folding a full entry list from
	 * its first line arrives at the same answer, but folding a partial one
	 * would not.
	 *
	 * @param float|null $origin Request start, or null to take the earliest seen.
	 * @return Fold_State State for add()/tree().
	 */
	public static function start( ?float $origin = null ): array {
		return [
			// Merged tree, children keyed by name so a merge is a lookup.
			'root'    => self::empty_node(),
			// Open spans, innermost last; `path` is the name chain into root.
			'stack'   => [],
			'count'   => 0,
			'origin'  => $origin,
		];
	}

	/**
	 * Fold one log entry in. Anything that is neither a start nor a complete
	 * is counted and dropped — the tree is built from spans alone.
	 *
	 * @param Fold_State          $state Fold state, by reference.
	 * @param array<string,mixed> $entry One stored entry.
	 */
	public static function add( array &$state, array $entry ): void {
		++$state['count'];
		$ts = $entry['ts'] ?? null;
		// @longform Seeded once and then FROZEN. Lowering it later would leave
		// the frames already stamped measured from a different zero than the
		// ones after, and those offsets are what the tree's `t` reports.
		if ( null === $state['origin'] && \is_numeric( $ts ) && (float) $ts > 0 ) {
			$state['origin'] = (float) $ts;
		}
		$keyword = Core::str( $entry['k'] ?? '' );

		if ( \preg_match( Flame_Tree::PATTERN_START, $keyword, $m ) ) {
			self::open( $state, $m[1], Core::str( $entry['l'] ?? '' ), $ts );
			return;
		}
		if ( \preg_match( Flame_Tree::PATTERN_COMPLETE, $keyword, $m ) ) {
			$duration = $entry['duration_ms'] ?? 0;
			self::close( $state, $m[1], \is_numeric( $duration ) ? (float) $duration : 0.0 );
		}
	}

	/**
	 * Close the nearest open span of this base name — LIFO, as
	 * `Log_Manager::complete()` itself matches — and pop everything above it.
	 * A complete matching nothing is dropped.
	 *
	 * @param Fold_State $state    Fold state, by reference.
	 * @param string     $base     Span base name.
	 * @param float      $duration Milliseconds the span took.
	 */
	private static function close( array &$state, string $base, float $duration ): void {
		for ( $i = \count( $state['stack'] ) - 1; $i >= 0; $i-- ) {
			if ( $state['stack'][ $i ]['name'] !== $base ) {
				continue;
			}
			$frame = $state['stack'][ $i ];
			self::record( $state['root'], $frame['path'], $duration );
			\array_splice( $state['stack'], $i );
			return;
		}
	}

	/**
	 * Open a span: ensure its merged node exists, then push it.
	 *
	 * @param Fold_State $state Fold state, by reference.
	 * @param string     $base  Span base name.
	 * @param string     $label Stable aggregation label, or ''.
	 * @param mixed      $ts    Entry timestamp, unix seconds.
	 */
	private static function open( array &$state, string $base, string $label, mixed $ts ): void {
		$name   = '' !== $label ? "{$base}: {$label}" : $base;
		$parent = $state['stack'][ \count( $state['stack'] ) - 1 ]['path'] ?? [];
		$path   = [ ...$parent, $name ];
		$offset = Flame_Tree::offset_ms( $state['origin'], $ts );
		self::record( $state['root'], $path, null, $offset );

		if ( \count( $state['stack'] ) < Flame_Tree::MAX_STACK_DEPTH ) {
			// No `t`: the node keeps the earliest, and frames ride checkpoints.
			$state['stack'][] = [
				'name' => $base,
				'path' => $path,
			];
		}
	}

	/**
	 * Walk to a name path, creating nodes along the way, and fold one
	 * instance's duration into the node it lands on.
	 *
	 * A null duration only creates the path — what a `(start)` needs, so that
	 * a span still shows as a frame if its complete never arrives.
	 *
	 * @param array<array-key,mixed> $node     Node to descend from, by reference.
	 * @param list<string>           $path     Remaining name chain.
	 * @param float|null             $duration Milliseconds to fold in, or null to only create.
	 * @param float|null             $t        Start offset in ms, kept at its EARLIEST.
	 */
	private static function record( array &$node, array $path, ?float $duration, ?float $t = null ): void {
		if ( [] === $path ) {
			if ( null !== $t ) {
				$seen      = $node['t'] ?? null;
				$node['t'] = \is_numeric( $seen ) ? \min( (float) $seen, $t ) : $t;
			}
			if ( null === $duration ) {
				// Starts, not completions: see positioned().
				$node['starts'] = Core::num_int( $node['starts'] ?? null ) + 1;
				return;
			}
			$node['value'] = ( \is_numeric( $node['value'] ?? null ) ? (float) $node['value'] : 0.0 ) + $duration;
			$node['max']   = \max( \is_numeric( $node['max'] ?? null ) ? (float) $node['max'] : 0.0, $duration );
			$node['count'] = Core::num_int( $node['count'] ?? null ) + 1;
			return;
		}
		$name     = \array_shift( $path );
		$children = \is_array( $node['children'] ?? null ) ? $node['children'] : [];
		if ( ! isset( $children[ $name ] ) || ! \is_array( $children[ $name ] ) ) {
			$children[ $name ] = self::empty_node();
		}
		self::record( $children[ $name ], $path, $duration, $t );
		$node['children'] = $children;
	}

	/**
	 * A merged node with nothing folded into it yet.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_node(): array {
		return [
			'value'    => 0.0,
			'count'    => 0,
			'starts'   => 0,
			'max'      => 0.0,
			't'        => null,
			'children' => [],
		];
	}

	/**
	 * The merged tree in the shape `Flame_Builder_Node` and the browser read:
	 * name-keyed children flattened to a list, each node carrying `count` and
	 * `max` alongside its summed `value`.
	 *
	 * Each node's `t` is the offset its EARLIEST instance started at — the one
	 * position that is true of a merged node. The detail view stamps its log
	 * rows from it, and `FlameGraph` positions the frame by it.
	 *
	 * @param Fold_State $state Fold state.
	 * @return array<string,mixed> Flame tree rooted at `request`.
	 */
	public static function tree( array $state ): array {
		$tree           = self::flatten( 'request', $state['root'] );
		$tree['folded'] = true;
		return $tree;
	}

	/**
	 * Turn one name-keyed node into the list-keyed display shape, raising a
	 * parent that never closed to cover the children that did — the browser
	 * prunes on a single value cutoff and takes a whole subtree with anything
	 * it drops, so a 0 here would delete exactly the frames worth reading.
	 *
	 * @param string                 $name Node name.
	 * @param array<array-key,mixed> $node Name-keyed node.
	 * @return array<string,mixed> Display-shaped node.
	 */
	private static function flatten( string $name, array $node ): array {
		$children     = [];
		$sum          = 0.0;
		$extent       = null;
		$start        = self::positioned( $node );
		$raw_children = \is_array( $node['children'] ?? null ) ? $node['children'] : [];
		foreach ( $raw_children as $child_name => $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$flat        = self::flatten( (string) $child_name, $child );
			$value       = \is_numeric( $flat['value'] ) ? (float) $flat['value'] : 0.0;
			$sum        += $value;
			$children[]  = $flat;
			$child_start = self::positioned( $child );
			if ( null === $start || null === $child_start ) {
				$start = null;
				continue;
			}
			$extent = \max( $extent ?? 0.0, $child_start + $value );
		}
		// Spacers fill the gaps, so children reach the EXTENT, not their sum.
		$needed = null !== $start && null !== $extent
			? \max( $extent - $start, $sum )
			: $sum;
		return [
			'name'     => $name,
			'value'    => \max( \is_numeric( $node['value'] ?? null ) ? (float) $node['value'] : 0.0, $needed ),
			'count'    => \is_numeric( $node['count'] ?? null ) ? (int) $node['count'] : 0,
			'merged'   => self::merged( $node ),
			'max'      => \is_numeric( $node['max'] ?? null ) ? (float) $node['max'] : 0.0,
			't'        => \is_numeric( $node['t'] ?? null ) ? (float) $node['t'] : null,
			'children' => $children,
		];
	}

	/**
	 * The offset a node can be laid out from, or null when it stands for no
	 * single span. A merged node's `t` is its earliest instance's start while
	 * `value` totals them all, so `t + value` is no instance's end and a gap
	 * measured from it is fiction — the sum is its only honest width.
	 *
	 * @param array<array-key,mixed> $node Folded node.
	 * @return float|null Offset in milliseconds, or null.
	 */
	private static function positioned( array $node ): ?float {
		if ( self::merged( $node ) ) {
			return null;
		}
		return \is_numeric( $node['t'] ?? null ) ? (float) $node['t'] : null;
	}

	/**
	 * Whether a node folded more than one span in. STARTS, not completions:
	 * `close()` splices off every frame above the one it matches, so a span
	 * outliving its parent is left open and never reaches `count` — while its
	 * `t` is already stamped and its path free to be opened again.
	 *
	 * Completions are the FLOOR, for the state a worker restores from a
	 * checkpoint written before `starts` existed: a missing key reads as 0,
	 * which would claim one span and hand every such node back to the extent
	 * rule. A path can never close more often than it opened, so the max is
	 * the identity on anything this version wrote. One case stays wrong there
	 * — two starts and a single completion — until that path completes again
	 * or opens twice more; a single further open leaves both counters at 1.
	 *
	 * @param array<array-key,mixed> $node Folded node.
	 * @return bool True when the node stands for two or more spans.
	 */
	private static function merged( array $node ): bool {
		return 1 < \max(
			Core::num_int( $node['starts'] ?? null ),
			Core::num_int( $node['count'] ?? null )
		);
	}
}
