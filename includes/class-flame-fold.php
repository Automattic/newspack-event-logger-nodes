<?php
/**
 * Flame_Fold — the merging, resumable variant of `Flame_Tree`'s stack machine.
 *
 * `Flame_Tree::build_flame_data()` runs once over a finished request and keeps
 * every span as its own node, and `number_duplicate_siblings()` numbers repeats
 * to hold them apart. This does the opposite, and stays open afterwards:
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
 * Because it is the same stack machine, pair balance is inherent: every entry
 * reaches it, so a start is never severed from its complete — which is what
 * disqualifies dropping every Nth entry instead.
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
 * @phpstan-type Fold_Frame array{name: string, path: list<string>, shape?: string|null}
 * @phpstan-type Fold_State array{root: array<array-key,mixed>, stack: list<Fold_Frame>, count: int, shape_bytes?: int, shape_budget?: int, origin: float|null}
 */
final class Flame_Fold {

	/**
	 * Distinct statements a merged transport node keeps, before the rest fold
	 * into one bucket. A caller running more than this many shapes is building
	 * its SQL rather than repeating it, and the bucket still carries the count
	 * and the time, which is what a repeat finding reads.
	 */
	public const MAX_SHAPES = 24;

	/**
	 * Bytes one shape keeps. Measured over 308,395 real `sql` completes here:
	 * mean 146, median 122, p90 259, p99 488, so this cuts 0.6% of statements
	 * where 1024 cuts 0.4% at twice the worst case.
	 */
	public const MAX_SHAPE_BYTES = 512;

	/**
	 * What one table row costs beyond its key: the array bucket, the pair, and
	 * the two numbers in it. Charged with the key so the budget bounds ROWS as
	 * well as bytes — a record of eight-byte shapes would otherwise take twelve
	 * times the memory the budget thinks it has allowed.
	 */
	private const SHAPE_ROW_BYTES = 128;

	/**
	 * Bytes of NEW shapes one record takes, past which every further statement
	 * counts under `SHAPES_OVERFLOW`.
	 *
	 * The per-node caps bound a node; a record's node count is O(distinct
	 * paths) and deliberately unbounded, and the heaviest real folded record
	 * here holds 117 transport nodes against 73KB of record — so per-node caps
	 * alone admit megabytes. The POOL is what sizes this: `Request_Builder_Node`
	 * holds `DEFAULT_BUCKET_SIZE` × `DEFAULT_NUM_BUCKETS` (300) requests in
	 * flight against a `DEFAULT_ENTRY_BUDGET` worth ~18MB, so 32KB apiece keeps
	 * the worst case near half of it. A folded envelope is invisible to
	 * `relieve_pressure()`, which counts `entries` and skips a folded one, so
	 * nothing downstream would catch the growth — this is the only relief.
	 * That heaviest record's own statements cost 33.7KB, so it is the one shape
	 * of record that spends its budget and buckets the tail.
	 */
	public const MAX_RECORD_SHAPE_BYTES = 32768;

	/** What a cut shape ends in, and what the overflow bucket is named for. */
	private const ELISION = '…';

	/** The bucket every shape past the cap folds into; its slot is reserved. */
	public const SHAPES_OVERFLOW = self::ELISION;

	/**
	 * A fresh fold state.
	 *
	 * `origin` is the request start in unix seconds — the reference every `t`
	 * offset is measured from. Callers that know it (Request_Builder holds the
	 * envelope's `timestamp`) should pass it. Left null, the first entry
	 * carrying a usable `ts` seeds it: right for a whole entry list, wrong for
	 * a partial one. The fold streams, so it cannot take the earliest across
	 * every entry the way `Flame_Tree::request_origin()` does.
	 *
	 * `$shape_budget` is the record's allowance of new shape bytes. A live
	 * fold keeps `MAX_RECORD_SHAPE_BYTES`, because it rides a pool of in-flight
	 * envelopes; a fold that is built, read and dropped — `Findings` replaying
	 * an unfolded record — has no pool to protect and passes `PHP_INT_MAX`, so
	 * a slow statement first seen late is not bucketed for a memory it never
	 * holds.
	 *
	 * @param float|null $origin       Request start, or null to take the first seen.
	 * @param int        $shape_budget New shape bytes the record may take.
	 * @return Fold_State State for add()/tree().
	 */
	public static function start( ?float $origin = null, int $shape_budget = self::MAX_RECORD_SHAPE_BYTES ): array {
		return [
			// Merged tree, children keyed by name so a merge is a lookup.
			'root'        => [ 'k' => 'request', 'l' => '' ] + self::empty_node(),
			// Open spans, innermost last; `path` is the name chain into root.
			'stack'       => [],
			// Every entry added, span or not; the fold marker counts from it.
			'count'       => 0,
			// New shape bytes taken; see MAX_RECORD_SHAPE_BYTES.
			'shape_bytes' => 0,
			'shape_budget' => $shape_budget,
			'origin'      => $origin,
		];
	}

	/**
	 * Fold one log entry in. Anything that is neither a start nor a complete
	 * is counted and dropped — the tree is built from spans alone.
	 *
	 * @param Fold_State          $state Fold state, by reference.
	 * @param array<array-key,mixed> $entry One stored entry, as the record or a restored checkpoint holds it.
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
			self::open(
				$state,
				$m[1],
				Core::str( $entry['l'] ?? '' ),
				$ts,
				self::shape_of( $m[1], $entry['m'] ?? null ),
				self::number_of( $entry )
			);
			return;
		}
		if ( \preg_match( Flame_Tree::PATTERN_COMPLETE, $keyword, $m ) ) {
			$duration = $entry['duration_ms'] ?? 0;
			$end      = [ 't_end' => Flame_Tree::offset_ms( $state['origin'], $ts ), 'n_end' => self::number_of( $entry ) ];
			self::close(
				$state,
				$m[1],
				\is_numeric( $duration ) ? (float) $duration : 0.0,
				self::shape_of( $m[1], $entry['m'] ?? null ),
				\array_filter( $end, static fn ( $v ) => null !== $v )
			);
		}
	}

	/**
	 * Close the nearest open span of this base name — LIFO, as
	 * `Log_Manager::complete()` itself matches — and pop everything above it.
	 * A complete matching nothing is dropped.
	 *
	 * @param Fold_State  $state    Fold state, by reference.
	 * @param string      $base     Span base name.
	 * @param float       $duration Milliseconds the span took.
	 * @param string|null $shape    The statement or URL this instance ran, or null.
	 * @param array{t_end?: float, n_end?: int} $end Where this instance ended, and its entry number.
	 */
	private static function close( array &$state, string $base, float $duration, ?string $shape, array $end ): void {
		for ( $i = \count( $state['stack'] ) - 1; $i >= 0; $i-- ) {
			if ( $state['stack'][ $i ]['name'] !== $base ) {
				continue;
			}
			$frame = $state['stack'][ $i ];
			// @longform A frame restored from a checkpoint written before
			// frames carried shapes has no key to give, and the complete's own
			// `m` is the fallback — except for http, where that `m` is the
			// status code and the URL rode the start this state has lost.
			$shape = $frame['shape'] ?? ( Flame_Tree::HTTP_STATE === $base ? null : $shape );
			$meta  = $end + ( null === $shape ? [] : [ 'shape' => $shape ] );
			// A row already there costs nothing; fold_shape knows which is new.
			if ( [] !== $meta && ( $state['shape_bytes'] ?? 0 ) >= ( $state['shape_budget'] ?? self::MAX_RECORD_SHAPE_BYTES ) ) {
				$meta['spent'] = true;
			}
			$state['shape_bytes'] = ( $state['shape_bytes'] ?? 0 ) + self::record(
				$state['root'],
				$frame['path'],
				$duration,
				$meta
			);
			\array_splice( $state['stack'], $i );
			return;
		}
	}

	/**
	 * An entry's number, or null when it carries none.
	 *
	 * @param array<array-key,mixed> $entry One stored entry.
	 * @return int|null Its `n`.
	 */
	private static function number_of( array $entry ): ?int {
		return \is_numeric( $entry['n'] ?? null ) ? (int) $entry['n'] : null;
	}

	/**
	 * The statement or URL an instance ran, for the spans whose message is a
	 * bounded identifier: a query shape, literals already replaced by
	 * `App\Core::without_literals()`, or a redacted URL. A hook's message is
	 * prose of any size and any cardinality, so it is not one of these.
	 *
	 * @param string $base Span base name.
	 * @param mixed  $m    The complete's message.
	 * @return string|null The shape to fold in, or null.
	 */
	private static function shape_of( string $base, mixed $m ): ?string {
		if ( ! \is_string( $m ) || '' === $m || self::SHAPES_OVERFLOW === $m
				|| ! Flame_Tree::is_transport_span( $base ) ) {
			return null;
		}
		if ( \strlen( $m ) <= self::MAX_SHAPE_BYTES ) {
			return $m;
		}
		// @longform Cut, then walk back to the torn sequence's start — at most
		// three bytes, since that is the longest tail a cut can break. Taking
		// the whole trailing non-ASCII RUN instead throws away everything an
		// all-multibyte statement had, leaving the elision alone, which is the
		// bucket's own key. `mb_strcut()` says it in one call and adds an
		// ext-mbstring dependency WordPress does not polyfill.
		$cut = \substr( $m, 0, self::MAX_SHAPE_BYTES - \strlen( self::ELISION ) );
		for ( $back = 1; $back <= 3 && 1 !== \preg_match( '//u', $cut ); $back++ ) {
			$cut = \substr( $m, 0, self::MAX_SHAPE_BYTES - \strlen( self::ELISION ) - $back );
		}
		return $cut . self::ELISION;
	}

	/**
	 * Open a span: ensure its merged node exists, then push it.
	 *
	 * Past `Flame_Tree::MAX_STACK_DEPTH` a span still gets its node and its
	 * start counted, but no frame, so its complete matches nothing — or, when
	 * an open ancestor shares the base name, the wrong span. The depth bounds
	 * the stack; the path is what a reader needs to see the span ran at all.
	 *
	 * @param Fold_State $state Fold state, by reference.
	 * @param string     $base  Span base name.
	 * @param string     $label Stable aggregation label, or ''.
	 * @param mixed      $ts    Entry timestamp, unix seconds.
	 * @param string|null $shape What this instance ran, when the START names it.
	 * @param int|null    $n     The start's entry number, or null.
	 */
	private static function open( array &$state, string $base, string $label, mixed $ts, ?string $shape, ?int $n ): void {
		$name   = Flame_Tree::node_name( $base, $label );
		$parent = $state['stack'][ \count( $state['stack'] ) - 1 ]['path'] ?? [];
		$path   = [ ...$parent, $name ];
		$offset = Flame_Tree::offset_ms( $state['origin'], $ts );
		$meta   = [ 'k' => $base, 'l' => $label ];
		if ( null !== $offset ) {
			$meta['t'] = $offset;
		}
		if ( null !== $n ) {
			$meta['n'] = $n;
		}
		self::record( $state['root'], $path, null, $meta );

		if ( \count( $state['stack'] ) < Flame_Tree::MAX_STACK_DEPTH ) {
			// No `t`: the node keeps the earliest, and frames ride checkpoints.
			$frame = [
				'name' => $base,
				'path' => $path,
			];
			if ( null !== $shape ) {
				$frame['shape'] = $shape;
			}
			$state['stack'][] = $frame;
		}
	}

	/**
	 * Walk to a name path, creating nodes along the way, and fold one
	 * instance's duration into the node it lands on.
	 *
	 * A null duration records a start rather than a completion: it creates the
	 * path and counts the open in `starts`, folding no duration in. That is
	 * what a `(start)` needs, so a span still shows as a frame when its
	 * complete never arrives.
	 *
	 * @param array<array-key,mixed> $node     Node to descend from, by reference.
	 * @param list<string>           $path     Remaining name chain.
	 * @param float|null             $duration Milliseconds to fold in, or null for a start.
	 * @param array{k?: string, l?: string, t?: float, n?: int, t_end?: float, n_end?: int, shape?: string, spent?: bool} $meta What this instance carried: the key and label a start was logged with, and its entry number, kept from the node's first open; its offset, kept at the EARLIEST; where it ended and that complete's number, kept from the LATEST end; and the statement or URL it ran, for a transport span (see shape_of()).
	 * @return int Bytes a shape new to its node took, for the record's budget.
	 */
	private static function record( array &$node, array $path, ?float $duration, array $meta = [] ): int {
		if ( [] === $path ) {
			if ( isset( $meta['k'], $meta['l'] ) ) {
				$node['k'] ??= $meta['k'];
				$node['l'] ??= $meta['l'];
			}
			if ( isset( $meta['t'] ) ) {
				$seen      = $node['t'] ?? null;
				$node['t'] = \is_numeric( $seen ) ? \min( (float) $seen, $meta['t'] ) : $meta['t'];
			}
			if ( isset( $meta['n'] ) ) {
				$node['n'] ??= $meta['n'];
			}
			if ( isset( $meta['t_end'] ) && $meta['t_end'] >= Core::num_float( $node['t_end'] ?? null ) ) {
				$node['t_end'] = $meta['t_end'];
				$node['n_end'] = $meta['n_end'] ?? null;
			}
			if ( null === $duration ) {
				// Starts, not completions: see merged().
				$node['starts'] = Core::num_int( $node['starts'] ?? null ) + 1;
				return 0;
			}
			$node['value'] = ( \is_numeric( $node['value'] ?? null ) ? (float) $node['value'] : 0.0 ) + $duration;
			$node['max']   = \max( \is_numeric( $node['max'] ?? null ) ? (float) $node['max'] : 0.0, $duration );
			$node['count'] = Core::num_int( $node['count'] ?? null ) + 1;
			if ( ! isset( $meta['shape'] ) ) {
				return 0;
			}
			// Raw: `fold_shape()` normalizes the one row it touches.
			$table = \is_array( $node['shapes'] ?? null ) ? $node['shapes'] : [];
			$took  = self::fold_shape( $table, $meta['shape'], 1, $duration, isset( $meta['spent'] ) );
			$node['shapes'] = $table;
			return $took;
		}
		$name     = \array_shift( $path );
		$children = \is_array( $node['children'] ?? null ) ? $node['children'] : [];
		if ( ! isset( $children[ $name ] ) || ! \is_array( $children[ $name ] ) ) {
			$children[ $name ] = self::empty_node();
		}
		$took             = self::record( $children[ $name ], $path, $duration, $meta );
		$node['children'] = $children;
		return $took;
	}

	/**
	 * A merged node with nothing folded into it yet.
	 *
	 * `t` starts null, never 0: `record()` keeps the EARLIEST offset, so a zero
	 * seed would win every comparison and put every node at the request's own
	 * start. Zero is a real position and cannot double as "no instance yet".
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
			'n'        => null,
			't_end'    => null,
			'n_end'    => null,
			'children' => [],
		];
	}

	/**
	 * Fold one instance into a node's shape table: a count and the summed
	 * milliseconds per distinct statement, which is what says whether 667 calls
	 * were one query repeated or thirty different ones.
	 *
	 * A full table EVICTS its cheapest row rather than refusing the newcomer.
	 * The statement worth naming is as likely to arrive twenty-fifth as first,
	 * and first-seen-wins would bury it on exactly the request this exists for.
	 * What is evicted folds into `SHAPES_OVERFLOW`, whose slot is reserved
	 * inside `MAX_SHAPES` rather than added to it, so the totals stay whole
	 * while the table holds no more than `MAX_SHAPES` rows in all.
	 *
	 * The return is what the table GREW by, in key bytes — what a statement the
	 * bucket swallowed costs (nothing) is what the record's budget must charge
	 * it, or one high-cardinality caller spends the whole budget on rows nobody
	 * stored and strips every other node's table.
	 *
	 * @param array<array-key,mixed>            $shapes The table, extended in place; a restored one is trusted row by row.
	 * @param string                            $shape  The statement or URL, already cut.
	 * @param int                               $calls  Instances to add.
	 * @param float                             $ms     Milliseconds to add.
	 * @param bool                              $spent  Whether the record's byte budget is gone: it refuses a new row the table has room for, and a trade that would grow the record; a trade into a shorter or equal key still goes through.
	 * @return int Bytes the table gained — key plus row — less any an eviction freed.
	 */
	public static function fold_shape( array &$shapes, string $shape, int $calls, float $ms, bool $spent = false ): int {
		$time_of = static fn ( mixed $row ): float => Core::num_float( Core::arr( $row )[1] ?? null );
		$real  = \count( $shapes ) - ( isset( $shapes[ self::SHAPES_OVERFLOW ] ) ? 1 : 0 );
		$freed = 0;
		$full = $real >= self::MAX_SHAPES - 1;
		// Spent refuses a new ROW; a full table trades and pays the difference.
		if ( $spent && ! $full && ! isset( $shapes[ $shape ] ) ) {
			$shape = self::SHAPES_OVERFLOW;
		}
		if ( self::SHAPES_OVERFLOW !== $shape && ! isset( $shapes[ $shape ] ) && $full ) {
			$cheapest = self::cheapest_of( $shapes );
			// Spent, a trade is free only where the new key is no longer.
			$grows    = '' !== $cheapest && \strlen( $shape ) > \strlen( $cheapest );
			if ( '' === $cheapest || ( $spent && $grows ) || $ms <= $time_of( $shapes[ $cheapest ] ) ) {
				$shape = self::SHAPES_OVERFLOW;
			} else {
				$evicted = Core::arr( $shapes[ $cheapest ] );
				$freed   = \strlen( $cheapest ) + self::SHAPE_ROW_BYTES;
				unset( $shapes[ $cheapest ] );
				$freed -= self::fold_shape(
					$shapes,
					self::SHAPES_OVERFLOW,
					Core::num_int( $evicted[0] ?? null ),
					Core::num_float( $evicted[1] ?? null )
				);
			}
		}
		$took             = isset( $shapes[ $shape ] ) ? 0 : \strlen( $shape ) + self::SHAPE_ROW_BYTES;
		$seen             = Core::arr( $shapes[ $shape ] ?? null );
		$shapes[ $shape ] = [
			Core::num_int( $seen[0] ?? null ) + $calls,
			Core::num_float( $seen[1] ?? null ) + $ms,
		];
		return $took - $freed;
	}

	/**
	 * The table's cheapest row by time — what a full table gives up. The
	 * overflow bucket is never it, so eviction cannot empty what it fills.
	 *
	 * @param array<array-key,mixed> $shapes The table.
	 * @return string The key holding the least time, or '' when only the bucket is there. A shape reading as a number arrives here as an int key.
	 */
	private static function cheapest_of( array $shapes ): string {
		$cheapest = '';
		$least    = null;
		foreach ( $shapes as $key => $seen ) {
			$ms = Core::num_float( Core::arr( $seen )[1] ?? null );
			if ( self::SHAPES_OVERFLOW !== $key && ( null === $least || $ms < $least ) ) {
				$least    = $ms;
				$cheapest = (string) $key;
			}
		}
		return $cheapest;
	}

	/**
	 * The merged tree in the shape `Flame_Builder_Node` and the browser read:
	 * name-keyed children flattened to a list, each node carrying `count` and
	 * `max` alongside its summed `value`, and the `k` and `l` its spans were
	 * logged with. `name` is only the key the fold merges on; the rows a
	 * folded request shows read the two fields as logged.
	 *
	 * Each node's `t` is the offset its EARLIEST instance started at, and `n`
	 * that start's entry number; `t_end` is where its LATEST instance ended,
	 * and `n_end` that complete's number, both null until one closes. Those
	 * are the positions true of a merged node. The detail view numbers and
	 * stamps its log rows from them, and `FlameGraph` positions the frame by
	 * `t`.
	 *
	 * The root carries `folded`, which marks the tree as this machine's output
	 * rather than `Flame_Tree`'s.
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
	 * Turn one name-keyed node into the list-keyed display shape, raising any
	 * parent that falls short of its positioned children. The browser prunes on
	 * a single value cutoff and takes a whole subtree with anything it drops, so
	 * a parent that never closed would carry 0 and delete exactly the frames
	 * worth reading.
	 *
	 * `starts` is this machine's own bookkeeping and stops here; the display
	 * node carries `merged` instead, which is the one verdict a reader wants
	 * from it.
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
		// Normalized ONCE here; the browser destructures rows, never coerces.
		$table  = self::shape_table( $node );
		$shapes = [] === $table ? [] : [ 'shapes' => $table ];
		// A node a pre-change checkpoint restored has no key or label to give.
		$logged = isset( $node['k'], $node['l'] )
			? [
				'k' => Core::str( $node['k'] ),
				'l' => Core::str( $node['l'] ),
			]
			: [];
		return [
			'name'     => $name,
			...$logged,
			'value'    => \max( \is_numeric( $node['value'] ?? null ) ? (float) $node['value'] : 0.0, $needed ),
			'count'    => \is_numeric( $node['count'] ?? null ) ? (int) $node['count'] : 0,
			...$shapes,
			'merged'   => self::merged( $node ),
			'max'      => \is_numeric( $node['max'] ?? null ) ? (float) $node['max'] : 0.0,
			't'        => \is_numeric( $node['t'] ?? null ) ? (float) $node['t'] : null,
			'n'        => \is_numeric( $node['n'] ?? null ) ? (int) $node['n'] : null,
			't_end'    => \is_numeric( $node['t_end'] ?? null ) ? (float) $node['t_end'] : null,
			'n_end'    => \is_numeric( $node['n_end'] ?? null ) ? (int) $node['n_end'] : null,
			'children' => $children,
		];
	}

	/**
	 * One node's shape table, whatever shape a checkpoint restored it in.
	 *
	 * @param array<array-key,mixed> $node A merged node.
	 * @return array<array-key,array{int,float}>
	 */
	public static function shape_table( array $node ): array {
		$out = [];
		foreach ( Core::arr( $node['shapes'] ?? null ) as $shape => $seen ) {
			$pair                   = Core::arr( $seen );
			$out[ (string) $shape ] = [
				Core::num_int( $pair[0] ?? null ),
				Core::num_float( $pair[1] ?? null ),
			];
		}
		return $out;
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
	 * Completions are the FLOOR, because a checkpoint frame a worker restores
	 * may carry no `starts` at all: a missing key reads as 0, which would claim
	 * one span and hand every such node back to the extent rule. A path can
	 * never close more often than it opened, so on a node this machine counted
	 * the max is the identity. One case stays wrong on a node missing `starts`
	 * — two starts and a single completion — until that path completes again or
	 * opens twice more; a single further open leaves both counters at 1.
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
