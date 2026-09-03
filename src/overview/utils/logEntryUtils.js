/**
 * Log-entry view model for the request-detail table.
 *
 * A request arrives from `Request_Builder_Node` as a flat list of log
 * entries — `n` line number, `k` keyword, `m` message, `l` a span's argument,
 * `ts` Unix timestamp, with `duration_ms` and `peak_mb` on the ones that carry
 * them. This module turns that list into the nested, foldable, time-ruled rows
 * that `LogEntriesTable` renders, in three passes:
 *
 * 1. `spliceFoldedSpans()` puts a folded record's merged tree back where its
 *    entries were, as ordinary `(start)`/`(complete)` rows, so everything
 *    below reads one list whether the record was folded or not.
 * 2. `computeIndentedEntries()` derives an indent level and a `pairId` for
 *    every entry from its `(start)`/`(complete)` keyword, and spans time
 *    gaps with placeholder rows.
 * 3. `computeVisibleEntries()` applies the fold state, replacing each
 *    collapsed pair with a single merged row, then rewrites the placeholder
 *    runs and the `displayTime` column for the rows that survive.
 *
 * The split is what keeps folding cheap: the first two are memoized on the
 * request, the last on the fold set, which changes with every click.
 *
 * `displayTime` is a time ruler, not a per-row clock. A row shows a full
 * timestamp at a 100ms mark and bullets for the 10ms ticks between marks,
 * so scanning the column reads elapsed time down the request.
 *
 * The gap rows that carry that ruler across an interval are only drawn where
 * the interval IS elapsed time. A sequence-break marker stands in for entries
 * that were removed, so the intervals either side of one are missing detail,
 * and a folded record has no measurable interval at all: everything past its
 * marker was selected out of the middle rather than kept consecutive.
 */

/**
 * Matches a `<name> (start)` keyword, capturing the pair's name.
 */
const START_REGEX = /^(.+?) \(start\)$/;

/**
 * Matches a `<name> (complete)` keyword, capturing the pair's name. Every pair
 * but the request's own closes this way.
 */
const COMPLETE_REGEX = /^(.+?) \(complete\)$/;

/**
 * The record's own close, whatever ended it. Every other pair closes as
 * `(complete)`; the REQUEST closes as a terminal carrying the disposition —
 * `aborted` from `Gyrobase::Log::abort_process()`, any suffix at all from
 * `Log_Manager::complete()`. Matching only `(complete)` leaves the terminal
 * closing nothing, so it draws one level deeper than the `(start)` it ends.
 */
const TERMINAL_REGEX = /^(.+?) \(.+\)$/;

/**
 * A 24-hour clock for the time column. The 12-hour form adds a meridiem the
 * column has no room for and varies the width of every full timestamp.
 */
const TIME_FORMAT_OPTIONS = { hour12: false };

/**
 * The base name of the outermost pair — the request itself, which never folds.
 *
 * `Log_Manager::REQUEST_LABEL` owns this word, since that class opens the frame
 * and closes it. This is the one duplicate a separate deploy unit needs.
 */
const OUTERMOST_PAIR = 'process';

/**
 * The keyword the pressure fold leaves where it merged the record's middle
 * away.
 *
 * `Request_Builder_Node::SEQUENCE_BREAK_KEYS` owns the vocabulary, since that
 * class mints the marker. This is the one deliberate duplicate, because the
 * dashboard is a separate deploy unit.
 */
const FOLD_MARKER = 'entries (aggregated)';

/**
 * Keywords that announce a break in the record: entries were dropped, or merged
 * away by the pressure fold. What went missing came from the MIDDLE, so a span
 * the record closes later spans the break and keeps its surviving children —
 * the request itself, and any job or engine pass the fold cut the inside out
 * of. Every other span still open was severed, and closes at the marker —
 * a producer drains its stack as `(orphaned)` completes before any terminal,
 * so a span with none left was cut by the fold rather than by the ending.
 */
const SEQUENCE_BREAK_KEYWORDS = new Set( [ 'entries (lost)', FOLD_MARKER ] );

/**
 * A span name without its argument.
 *
 * A span carries its argument in the entry's `l` field, and the flame fuses
 * the two into one node name — so the same span is `include` where the record
 * closes it and `include: /Macros/Global.html` where the tree names it. Pairing
 * has to see through that or a spliced frame can never be closed.
 *
 * @param {string} name Span name, decorated or not.
 * @return {string} The part before `: `.
 */
const spanBase = ( name ) => String( name ).split( ': ' )[ 0 ];

/**
 * The base name of the pair a `<name> (start)` keyword opens, or null when the
 * keyword does not open one.
 *
 * @param {string} keyword Entry keyword.
 * @return {?string} Base name, or null.
 */
const pairBaseName = ( keyword ) => {
	const match = ( keyword || '' ).match( START_REGEX );
	return match ? match[ 1 ] : null;
};

/**
 * Whether a keyword opens a pair the reader may fold.
 *
 * THE rule, in one place. Every caller asks this function — the disclosure
 * triangle, the pointer cursor, the click handler and "Unfold All" — because a
 * second spelling disagrees with it on real input: a `startsWith( 'process ' )`
 * test drops `process queue (start)` from "Unfold All" while the same row still
 * folds on click.
 *
 * @param {string} keyword Entry keyword.
 * @return {boolean} True when the pair is foldable.
 */
export const isFoldablePairStart = ( keyword ) => {
	const base = pairBaseName( keyword );
	return null !== base && base !== OUTERMOST_PAIR;
};

/**
 * Whether an entry belongs to a `(start)`/`(complete)` pair.
 *
 * @param {Object} entry Log entry.
 * @return {boolean} True when the entry carries a pairId.
 */
export const hasPair = ( entry ) =>
	null !== entry?.pairId && undefined !== entry?.pairId;

/**
 * Whether the entry at `idx` opens a pair its own `(complete)` closes on the
 * very next row. Such a pair renders as one merged row however the fold state
 * reads, so unfolding it reveals nothing.
 *
 * @param {Array}  entries Indented entries.
 * @param {number} idx     Index of the candidate `(start)`.
 * @return {boolean} True when the pair is empty.
 */
export const isEmptyPairStart = ( entries, idx ) => {
	const entry = entries[ idx ];
	const next = entries[ idx + 1 ];
	return (
		!! next &&
		hasPair( entry ) &&
		( entry.k || '' ).includes( '(start)' ) &&
		next.pairId === entry.pairId &&
		( next.k || '' ).includes( '(complete)' )
	);
};

/**
 * Format a count of 10ms dots with a space every 3 for readability.
 *
 * @param {number} count Number of dots.
 * @return {string} Formatted dot string (e.g. "••• ••• •••").
 */
export const formatDots = ( count ) => {
	if ( count <= 0 ) {
		return '';
	}
	const groups = [];
	for ( let remaining = count; remaining > 0; remaining -= 3 ) {
		groups.push( '•'.repeat( Math.min( 3, remaining ) ) );
	}
	return groups.join( ' ' );
};

/**
 * Format a full timestamp string from a Unix ts.
 *
 * @param {number} ts Unix timestamp.
 * @return {string} Formatted time `HH:MM:SS.cc` at 10ms precision, or empty
 *                  without a ts.
 */
export const formatFullTimestamp = ( ts ) => {
	if ( ! ts ) {
		return '';
	}
	const hundredth = Math.round( ts * 100 );
	const centis = hundredth % 100;
	const date = new Date( ts * 1000 );
	return (
		date.toLocaleTimeString( 'en-US', TIME_FORMAT_OPTIONS ) +
		'.' +
		String( centis ).padStart( 2, '0' )
	);
};

/**
 * Rule one row of the time column: a full timestamp at a 100ms mark, a dot per
 * 10ms tick since the row above, nothing inside the same tick.
 *
 * @param {number} ts            Current timestamp.
 * @param {number} lastHundredth Last displayed hundredth (10ms interval), -1
 *                               before any row has been ruled.
 * @return {Object} `displayTime` for this row, and `newHundredth` for the next
 *                  call to measure against.
 */
const formatTimeDisplay = ( ts, lastHundredth ) => {
	if ( ! ts ) {
		return {
			displayTime: '',
			newHundredth: lastHundredth,
		};
	}

	const currentHundredth = Math.round( ts * 100 );

	// Within the same 10ms tick, so this row shows nothing.
	if ( currentHundredth <= lastHundredth ) {
		return {
			displayTime: '',
			newHundredth: currentHundredth,
		};
	}

	const dots = currentHundredth - lastHundredth;

	// The first row, an exact 100ms mark, or a jump that skipped one.
	if ( lastHundredth < 0 || currentHundredth % 10 === 0 || dots > 9 ) {
		return {
			displayTime: formatFullTimestamp( ts ),
			newHundredth: currentHundredth,
		};
	}

	// Ticks inside the same tenth of a second draw as grouped dots.
	return {
		displayTime: formatDots( dots ),
		newHundredth: currentHundredth,
	};
};

/**
 * Indented rows plus the pre-placeholder entry count.
 *
 * `entries` carries the gap placeholders this pass inserts, so its length is
 * not the number of logged entries; `realCount` is, and the request-detail
 * header reports it.
 *
 * @typedef {Object} IndentedEntries
 * @property {Array<Object>} entries   Rows with `indent`, `pairId`, and the
 *                                     inserted `isPlaceholder` gap rows.
 * @property {number}        realCount Count of real (non-placeholder) entries.
 */

/**
 * Walk `(start)`/`(complete)` rows, LIFO, reporting every `(complete)` as it
 * matches. THE pair walk: three readers want one, differing only in what they
 * accumulate and whether the rows the splice injected count.
 *
 * Frames carry both names the tree uses: it MATCHES a `(complete)` on the base
 * but PATHS the span by `base: label`, so a reader keying paths needs `name`
 * and a reader counting spans needs `base`.
 *
 * `onClose( base, at, opened )` sees the base name, the stack index its
 * `(start)` sat at (-1 when nothing opened it), and the stack before unwinding,
 * whose first `at` frames are the span's ancestors.
 *
 * `outlive` picks WHICH unwind, and the two readers genuinely differ. The
 * merged tree pops every frame above the match — `Flame_Fold::close()` is
 * "pop everything above it" — so its paths and depths only line up with a
 * truncating walk. `computeIndentedEntries` closes the match alone, leaving a
 * child that outlived its parent open, so the budget feeding its stack has to
 * count the same way. Sharing one unwind mis-nests whichever reader loses.
 *
 * @param {Array}    entries        Rows, spliced or not.
 * @param {Object}   opts           Bounds, filter and unwind.
 * @param {number}   [opts.from]    Index to start at.
 * @param {number}   [opts.to]      Exclusive index to stop at.
 * @param {boolean}  [opts.folded]  Whether rows the splice injected count.
 * @param {boolean}  [opts.outlive] Whether a child outliving its parent stays.
 * @param {Function} [onClose]      Called per matched or orphaned `(complete)`.
 * @return {Array} `{ base, name }` frames still open, innermost last.
 */
const walkPairs = (
	entries,
	{ from = 0, to = entries.length, folded = true, outlive = false },
	onClose
) => {
	const opened = [];
	for ( let i = from; i < to; i++ ) {
		const entry = entries[ i ];
		if ( ! folded && entry.fromFold ) {
			continue;
		}
		const keyword = entry.k || '';
		const starts = keyword.match( START_REGEX );
		if ( starts ) {
			const base = starts[ 1 ];
			opened.push( {
				base,
				name: entry.l ? `${ base }: ${ entry.l }` : base,
			} );
			continue;
		}
		const completes = keyword.match( COMPLETE_REGEX );
		if ( ! completes ) {
			continue;
		}
		let at = -1;
		for ( let j = opened.length - 1; j >= 0; j-- ) {
			if ( opened[ j ].base === completes[ 1 ] ) {
				at = j;
				break;
			}
		}
		onClose?.( completes[ 1 ], at, opened );
		if ( at >= 0 ) {
			opened.splice( at, outlive ? 1 : opened.length - at );
		}
	}
	return opened;
};

/**
 * How many spans of each base name the KEPT rows close after `from` without
 * having opened them there — the spans a break falls INSIDE rather than severs.
 *
 * Counted, not collected: with two frames of one name open and a single
 * `(complete)` to come, exactly one of them straddles the break.
 *
 * Rows the splice put back are skipped. They are the merged MIDDLE of these
 * very spans, and a synthetic `(start)` repeating an open span's name would
 * otherwise swallow the real `(complete)` that proves the span straddles.
 *
 * @param {Array}  entries Rows, spliced or not.
 * @param {number} from    Index to start at (the marker).
 * @return {Map} Base name to the number of `(complete)` rows still to come.
 */
const spansClosedAfter = ( entries, from ) => {
	const closed = new Map();
	walkPairs(
		entries,
		{ from, folded: false, outlive: true },
		( name, at ) => {
			if ( at < 0 ) {
				// Keyed by BASE, so a decorated frame finds its bare close.
				const base = spanBase( name );
				closed.set( base, ( closed.get( base ) || 0 ) + 1 );
			}
		}
	);
	return closed;
};

/**
 * Drop the frames above a closing parent that the record never closes.
 *
 * A child CAN outlive its parent — improper nesting is legal and the later
 * `(complete)` proves it — so a frame with a close still to come stays. One
 * with none was severed: the fold ate its close, or the producer's drain did
 * not reach it. Left in place it adopts every row after the parent, putting an
 * aborted render's whole tail under spans that have ended.
 *
 * Same budget as `pruneSeveredSpans`, at a close rather than at a break.
 *
 * @param {Array}  pairStack  Open frames, innermost last. Mutated.
 * @param {number} matchedIdx Index of the frame being closed; below it is safe.
 * @param {Array}  entries    Rows, spliced or not.
 * @param {number} from       Index of the closing row.
 */
const pruneUnclosedAbove = ( pairStack, matchedIdx, entries, from ) => {
	if ( pairStack.length - 1 <= matchedIdx ) {
		return;
	}
	const budget = spansClosedAfter( entries, from );
	for ( let i = pairStack.length - 1; i > matchedIdx; i-- ) {
		const name = spanBase( pairStack[ i ].name );
		const owed = budget.get( name ) || 0;
		if ( owed < 1 ) {
			pairStack.splice( i, 1 );
			continue;
		}
		budget.set( name, owed - 1 );
	}
};

/**
 * Drop the spans a break severed, before they adopt the rows that follow.
 *
 * A span survives where the record still closes it after the break — counted
 * per name, so two open frames against one later `(complete)` sever the outer.
 *
 * Only the request's own frame is exempt: `process (aborted)` is a terminal
 * that matches no `(complete)`, so nothing here can close it. Every OTHER span
 * gets one, because a producer drains its open stack as `(orphaned)` completes
 * before writing a terminal — `Log_Manager::finish()` and `Gyrobase::Log`'s
 * `_unwind_to()` both do. A span still open at the end of a well-formed record
 * means the fold ate its close, and it cannot keep the tail.
 *
 * @param {Array}  pairStack Open frames, innermost last. Mutated.
 * @param {Array}  entries   Rows, spliced or not.
 * @param {number} from      Index of the break keyword.
 */
const pruneSeveredSpans = ( pairStack, entries, from ) => {
	const budget = spansClosedAfter( entries, from );
	for ( let i = pairStack.length - 1; i >= 0; i-- ) {
		// The request IS the record; its terminal closes it whatever it says.
		if ( 0 === i && OUTERMOST_PAIR === pairStack[ i ].name ) {
			continue;
		}
		const name = spanBase( pairStack[ i ].name );
		const owed = budget.get( name ) || 0;
		if ( owed < 1 ) {
			pairStack.splice( i, 1 );
			continue;
		}
		budget.set( name, owed - 1 );
	}
};

/**
 * Derive an indent level and a pairId for every entry from its
 * `(start)`/`(complete)` keyword, matching LIFO so an improperly nested span
 * still pairs.
 *
 * Inserts placeholder rows to span time gaps — but never across a
 * sequence-break marker, and never at all in a folded record, where the
 * interval is missing detail rather than elapsed time. The `displayTime`
 * column belongs to `computeVisibleEntries()`, which recomputes the whole
 * ruler per fold state.
 *
 * A break also severs the spans the record never closes, so a span the fold
 * cut short cannot adopt the rows after it.
 *
 * @param {Array} entries Log entries array.
 * @return {IndentedEntries} Indented rows and the real-entry count.
 */
export const computeIndentedEntries = ( entries ) => {
	if ( ! entries?.length ) {
		return { entries: [], realCount: 0 };
	}
	// Everything past a fold marker was selected out of the middle.
	const folded = entries.some( ( e ) => FOLD_MARKER === ( e.k || '' ) );
	// Index of a break whose prune is waiting for the folded rows to pass.
	let pendingPrune = null;
	let prevWasBreak = false;
	let lastHundredth = -1;
	const result = [];
	let realCount = 0;
	// Stack of { name, pairId } for LIFO start/complete matching.
	const pairStack = [];
	let pairCounter = 0;

	entries.forEach( ( entry, idx ) => {
		const keyword = entry.k || '';
		const ts = entry.ts || 0;

		// Extract base name from keyword.
		const startMatch = keyword.match( START_REGEX );
		// The outermost pair also closes on its terminal, whatever it says.
		const terminalMatch = startMatch
			? null
			: keyword.match( TERMINAL_REGEX );
		const completeMatch =
			keyword.match( COMPLETE_REGEX ) ||
			( terminalMatch && OUTERMOST_PAIR === terminalMatch[ 1 ]
				? terminalMatch
				: null );

		// For complete entries, find matching start using LIFO name matching.
		let matchedIdx = -1;
		if ( completeMatch ) {
			const baseName = completeMatch[ 1 ];
			// @longform Matched on the BASE. A span carries its argument in
			// `l`, and the flame fuses the two into one node name, so the
			// spliced `include: /Macros/Global.html (start)` and the record's
			// own `include (complete)` name one span in two spellings —
			// `foldedSpanEntries()` already defers to that complete rather
			// than emitting its own, which only works if they can pair.
			const wanted = spanBase( baseName );
			for ( let i = pairStack.length - 1; i >= 0; i-- ) {
				if ( spanBase( pairStack[ i ].name ) === wanted ) {
					matchedIdx = i;
					break;
				}
			}
		}

		const isBreak = SEQUENCE_BREAK_KEYWORDS.has( keyword );

		// @longform A break severs the spans the record never closes, so they
		// cannot adopt the rows after it. The folded interior spliced in AT the
		// marker is the exception: the tree puts those rows INSIDE the open
		// span, and the fold is what ate its close. Pruning first makes every
		// merged row a sibling of the parent it ran in. So the prune waits for
		// the first row the record itself resumes with.
		if ( isBreak && entries[ idx + 1 ]?.fromFold ) {
			pendingPrune = idx;
		} else if ( isBreak ) {
			pruneSeveredSpans( pairStack, entries, idx );
		}
		if ( null !== pendingPrune && ! entry.fromFold && ! isBreak ) {
			pruneSeveredSpans( pairStack, entries, pendingPrune );
			pendingPrune = null;
		}

		// Current indent is stack depth.
		const indent = pairStack.length;

		// Insert compressed placeholder rows for gaps; escalating intervals.
		const measurable = ! folded && ! isBreak && ! prevWasBreak;
		if ( measurable && lastHundredth >= 0 && ts > 0 ) {
			const currentHundredth = Math.round( ts * 100 );
			if ( currentHundredth > lastHundredth + 1 ) {
				let interval = 1;
				let rowsAtInterval = 0;
				let h = lastHundredth + 1;
				const pairIdForGap =
					pairStack.length > 0
						? pairStack[ pairStack.length - 1 ].pairId
						: null;
				while ( h < currentHundredth ) {
					// Placeholder ts from hundredth counter (recomputed later).
					result.push( {
						n: '',
						k: '',
						m: '',
						ts: h / 100,
						indent,
						isPlaceholder: true,
						pairId: pairIdForGap,
					} );
					rowsAtInterval++;
					if ( rowsAtInterval >= 10 ) {
						interval *= 10;
						rowsAtInterval = 0;
						// Jump to next interval-aligned boundary.
						h = ( Math.floor( h / interval ) + 1 ) * interval;
					} else {
						h += interval;
					}
				}
			}
		}

		// Update lastHundredth for placeholder gap tracking.
		if ( ts > 0 ) {
			lastHundredth = Math.round( ts * 100 );
		}
		prevWasBreak = isBreak;

		// Track start/complete pairs; displayTime recomputed per visible set.
		let pairId = null;
		realCount++;
		if ( startMatch ) {
			pairId = ++pairCounter;
			pairStack.push( { name: startMatch[ 1 ], pairId } );
			result.push( { ...entry, indent, pairId } );
		} else if ( completeMatch && matchedIdx >= 0 ) {
			// A matching start is on the stack, so the pair keeps its id.
			pairId = pairStack[ matchedIdx ].pairId;
			// A severed child would adopt every row after its parent.
			pruneUnclosedAbove( pairStack, matchedIdx, entries, idx );
			pairStack.splice( matchedIdx, 1 );
			result.push( {
				...entry,
				indent: matchedIdx, // Indent at the matched level.
				pairId,
			} );
		} else if ( completeMatch ) {
			// Orphan: its start was merged away. Show it where the record is.
			result.push( { ...entry, indent, pairId: null } );
		} else {
			// A leaf entry sits at the depth of the innermost open pair.
			pairId =
				pairStack.length > 0
					? pairStack[ pairStack.length - 1 ].pairId
					: null;
			result.push( { ...entry, indent, pairId } );
		}
	} );

	return { entries: result, realCount };
};

/**
 * The spans still open at a given index — the head's unclosed `(start)` chain,
 * innermost last.
 *
 * @param {Array}  entries Stored entries.
 * @param {number} upto    Exclusive index to stop at.
 * @return {Array} Tree node names of the spans still open.
 */
const openSpansAt = ( entries, upto ) =>
	walkPairs( entries, { to: upto } ).map( ( frame ) => frame.name );

/**
 * How many complete pairs of each base name the KEPT rows already show.
 *
 * The fold replays the kept head into its tree and adds every tail entry to it,
 * so those instances are counted in the merged nodes as well. A node whose
 * whole count is already on screen is a ghost of rows the reader can see.
 *
 * Keyed by the PATH the pair ran at, because the tree merges by path too: a
 * `sql` inside `gyrobase` must not cancel out a `sql` beside it.
 *
 * @param {Array} entries Stored entries — kept head, marker, kept tail.
 * @return {Map} Slash-joined tree-node-name path to the number of complete
 *               pairs kept.
 */
const keptPairCounts = ( entries ) => {
	const counts = new Map();
	// @longform A frame the record never closes is not on the TREE's path to
	// these rows either — the fold ate its close, and the render prunes it —
	// so leaving it in makes every path miss and redraws rows already shown.
	// `outlive` finds it: the truncating unwind pops a child with its parent,
	// so the unclosed frame vanishes at the request's own close.
	const severed = new Set(
		walkPairs( entries, { outlive: true } ).map( ( frame ) => frame.name )
	);
	walkPairs( entries, {}, ( name, at, opened ) => {
		if ( at < 0 ) {
			return;
		}
		const path = opened
			.slice( 0, at + 1 )
			.filter(
				// The request's own frame stays: the tree's paths start there.
				( frame, i ) =>
					0 === i || i === at || ! severed.has( frame.name )
			)
			.map( ( frame ) => frame.name )
			.join( '/' );
		counts.set( path, ( counts.get( path ) || 0 ) + 1 );
	} );
	return counts;
};

/**
 * The deepest path each base name reaches in the tree, keyed by that base.
 *
 * A span closes in the reverse of the order it opened, so the instance a
 * trailing `(orphaned)` complete belongs to is the INNERMOST one still open —
 * and in the merged tree that is the deepest frame carrying the name. Only that
 * frame may spend the complete; a shallower sibling closed normally earlier,
 * had its own close eaten by the fold, and must emit one.
 *
 * Read from the tree's shape rather than from its timings. The deepest path by
 * `t` finds the last span to START, which is a sibling that already closed
 * whenever the drained chain began earlier — and then the orphan closes nothing
 * and renders at its parent's indent instead of one level in.
 *
 * @param {?Object} flame Merged tree from Flame_Fold::tree().
 * @return {Map} Base name to the slash-joined path of its deepest frame.
 */
const deepestByBase = ( flame ) => {
	const deepest = new Map();
	const depths = new Map();
	const walk = ( nodes, depth, path ) => {
		( nodes || [] ).forEach( ( node ) => {
			const name = String( node.name );
			const base = name.split( ': ' )[ 0 ];
			const here = '' === path ? name : `${ path }/${ name }`;
			if ( depth > ( depths.get( base ) ?? -1 ) ) {
				depths.set( base, depth );
				deepest.set( base, here );
			}
			walk( node.children, depth + 1, here );
		} );
	};
	walk( flame?.children, 0, '' );
	return deepest;
};

/**
 * Expand a folded request's merged tree into log rows.
 *
 * The fold replaces thousands of entries with one node per distinct path. Those
 * nodes are already a tree, and the log table already renders a tree, so they
 * go in the log as ordinary `(start)`/`(complete)` pairs rather than a separate
 * flat table that stringifies the nesting into `a / b / c`. The existing
 * fold/unfold interaction then works on them unchanged.
 *
 * Rows carry a real `ts`, derived from the node's own start offset against the
 * request origin, so the view rules and gaps them like any other row.
 *
 * Spans that STRADDLE a boundary are not emitted twice. The one instance open
 * when the head ended is skipped — that row exists, and its tail `(complete)`
 * was already deducted. Instances the node merged BEYOND that one began
 * in the middle and do get a frame, as does a sibling merely sharing the base
 * name. A frame the tail closes with a complete no open span claimed leaves its
 * own `(complete)` off, because that real one closes it.
 *
 * Durations stay INCLUSIVE, as every other duration in the log is.
 *
 * @param {?Object} flame    Merged tree from Flame_Fold::tree().
 * @param {Array}   openPath Tree node names the kept head left open, outermost
 *                           first.
 * @param {Map}     tailEnds Base name to completes left over for the tree, the
 *                           spans the display leaves open having taken theirs.
 * @param {Map}     kept     Path to complete pairs the kept rows already show.
 * @param {Map}     drained  Base name to the path of its deepest frame.
 * @param {number}  originTs Unix seconds the request started at.
 * @return {Array} Synthetic log entries.
 */
const foldedSpanEntries = (
	flame,
	openPath,
	tailEnds,
	kept,
	drained,
	originTs
) => {
	const rows = [];
	const owedByName = new Map( tailEnds );
	const unshown = new Map( kept );
	const stampOf = ( node ) =>
		Number.isFinite( node.t ) && Number.isFinite( originTs )
			? originTs + node.t / 1000
			: 0;

	const walk = ( nodes, depth, onChain, path ) => {
		// The head left ONE span open here, not every node sharing its base.
		let onScreen = false;
		( nodes || [] ).forEach( ( node ) => {
			const name = String( node.name );
			const base = name.split( ': ' )[ 0 ];
			const here = '' === path ? name : `${ path }/${ name }`;
			// Spent once: labelled siblings share a path.
			const owns = Math.min(
				Number( node.count ),
				unshown.get( here ) || 0
			);
			unshown.set( here, ( unshown.get( here ) || 0 ) - owns );
			const claimed = onChain && ! onScreen && openPath[ depth ] === name;
			// `owns` counts completed PAIRS, so an OPEN one deducts here.
			const merged = Number( node.count ) - owns - ( claimed ? 1 : 0 );
			if ( claimed ) {
				onScreen = true;
				// Instances past it began in the middle and need a frame.
				if ( merged < 1 ) {
					walk( node.children, depth + 1, true, here );
					return;
				}
			} else if ( merged < 1 && ! node.children?.length ) {
				return;
			}
			const ts = stampOf( node );
			rows.push( {
				n: '',
				k: `${ node.name } (start)`,
				m: '',
				ts,
				fromFold: true,
			} );
			walk( node.children, depth + 1, claimed, here );
			// @longform Only a node the producer's drain actually closed may
			// spend the debt. The record's trailing `(orphaned)` completes name
			// the spans still open at the end, innermost first — the tree's
			// deepest path by `t`. Keyed on the bare base alone, the FIRST
			// same-base node in walk order takes it instead: a sibling that
			// closed normally long before, whose own complete the fold ate,
			// stays open across everything after it.
			// A tree with no timings cannot say which path the drain closed;
			// the gate stands down rather than refusing every debt.
			const onDrained = drained.get( base ) === here;
			const owed = onDrained ? owedByName.get( base ) || 0 : 0;
			if ( owed > 0 ) {
				owedByName.set( base, owed - 1 );
				return;
			}
			rows.push( {
				n: '',
				k: `${ node.name } (complete)`,
				m: merged < 1 ? '' : `${ merged.toLocaleString() } merged`,
				ts,
				duration_ms: node.value,
				fromFold: true,
			} );
		} );
	};

	walk( flame?.children, 0, true, '' );
	return rows;
};

/**
 * The tail `(complete)` rows left over for the merged tree.
 *
 * Spans the DISPLAY leaves open take theirs first: a tail row closing one of
 * those closes it on screen, whatever the tree merged underneath. Which spans
 * those are is the outlive reading, not the truncating path the tree indexes
 * by — they differ exactly when a child outlives its parent.
 *
 * @param {Array}  entries Stored entries.
 * @param {number} at      Index of the marker.
 * @return {Map} Base name to the completes the tree may still claim.
 */
const owedToTree = ( entries, at ) => {
	const owed = spansClosedAfter( entries, at );
	walkPairs( entries, { to: at, outlive: true } ).forEach( ( frame ) => {
		const left = owed.get( frame.base ) || 0;
		if ( left > 0 ) {
			owed.set( frame.base, left - 1 );
		}
	} );
	return owed;
};

/**
 * Put a folded request's merged spans back where its entries were: directly
 * after the `entries (aggregated)` marker, which is the boundary the fold left.
 *
 * A no-op for an unfolded request, which has no marker and no tree.
 *
 * @param {?Array}  entries Stored entries — kept head, marker, kept tail.
 * @param {?Object} flame   Merged tree from Flame_Fold::tree().
 * @return {?Array} Entries with the merged spans spliced in.
 */
export const spliceFoldedSpans = ( entries, flame ) => {
	if ( ! entries?.length || ! flame ) {
		return entries;
	}
	const at = entries.findIndex( ( e ) => FOLD_MARKER === ( e.k || '' ) );
	if ( at < 0 ) {
		return entries;
	}
	const origin = entries.find( ( e ) => e.ts > 0 )?.ts ?? 0;
	return [
		...entries.slice( 0, at + 1 ),
		...foldedSpanEntries(
			flame,
			openSpansAt( entries, at ),
			owedToTree( entries, at ),
			keptPairCounts( entries ),
			deepestByBase( flame ),
			origin
		),
		...entries.slice( at + 1 ),
	];
};

/**
 * Filter indented entries by fold state, emitting one merged row per collapsed
 * pair, then rewrite the placeholder runs and the `displayTime` column over
 * what is left.
 *
 * The ruler is recomputed here rather than carried from
 * `computeIndentedEntries()` because folding removes rows: a collapsed pair
 * takes its whole interior with it, placeholders included, so the interval
 * between the rows that survive is not the one the first pass measured.
 *
 * @param {Array} entries     Output of computeIndentedEntries().entries.
 * @param {Set}   expandedSet pairIds the reader has unfolded; an empty set
 *                            folds every pair.
 * @return {Array} Visible rows, each collapsed pair replaced by one merged
 *                 row.
 */
export const computeVisibleEntries = ( entries, expandedSet ) => {
	if ( ! entries?.length ) {
		return [];
	}

	const result = [];
	let i = 0;

	while ( i < entries.length ) {
		const entry = entries[ i ];
		const keyword = entry.k || '';
		const startMatch = keyword.match( START_REGEX );

		if ( startMatch && hasPair( entry ) ) {
			const baseName = startMatch[ 1 ];

			if (
				isFoldablePairStart( keyword ) &&
				! expandedSet.has( entry.pairId )
			) {
				// Collapsed: scan forward for complete, emit merged row.
				let childCount = 0;
				let completeEntry = null;
				let j = i + 1;
				while ( j < entries.length ) {
					const inner = entries[ j ];
					if (
						inner.pairId === entry.pairId &&
						( inner.k || '' ).includes( '(complete)' )
					) {
						completeEntry = inner;
						break;
					}
					// At or above its own level is a sibling, not a child.
					if (
						! inner.isPlaceholder &&
						inner.indent <= entry.indent
					) {
						break;
					}
					if ( ! inner.isPlaceholder ) {
						childCount++;
					}
					j++;
				}
				result.push( {
					...entry,
					k: baseName,
					// The complete's ts: the ruler resumes past the pair.
					ts: completeEntry?.ts || entry.ts,
					startTs: entry.ts,
					duration_ms: completeEntry?.duration_ms ?? null,
					peak_mb: completeEntry?.peak_mb || 0,
					completeMessage: completeEntry?.m || '',
					childCount,
					isMerged: true,
					originalIdx: i,
				} );
				// Past the complete, or up to the sibling that ended the scan.
				i = null !== completeEntry ? j + 1 : j;
				continue;
			}

			// Expanded (or outermost process): emit start row as-is.
			result.push( { ...entry, originalIdx: i } );
			i++;
			continue;
		}

		// Non-start entry (complete, leaf, placeholder): emit as-is.
		result.push( { ...entry, originalIdx: i } );
		i++;
	}

	// Collapse placeholder rows and recompute displayTime (≤9 dots per row).
	const collapsed = [];
	let lastH = -1;

	for ( let idx = 0; idx < result.length; idx++ ) {
		const e = result[ idx ];

		if ( e.isPlaceholder ) {
			// Collect the full run of consecutive placeholders.
			let runEnd = idx;
			while (
				runEnd + 1 < result.length &&
				result[ runEnd + 1 ].isPlaceholder
			) {
				runEnd++;
			}
			// Compute hundredth range for the run.
			const runStartH = Math.round( ( result[ idx ].ts || 0 ) * 100 );
			const runEndH = Math.round( ( result[ runEnd ].ts || 0 ) * 100 );

			// Phase 1: dots + the first two timestamps, then switch to phase 2.
			let h = runStartH;
			let timestampCount = 0;

			while ( h <= runEndH && timestampCount < 2 ) {
				const tenthBoundary = ( Math.floor( h / 10 ) + 1 ) * 10;
				const dotsToTenth = Math.min(
					tenthBoundary - h,
					runEndH - h + 1
				);

				if ( dotsToTenth > 0 && dotsToTenth <= 9 ) {
					collapsed.push( {
						n: '',
						k: '',
						m: '',
						ts: h / 100,
						indent: e.indent,
						isPlaceholder: true,
						pairId: e.pairId,
						displayTime: formatDots( dotsToTenth ),
					} );
					lastH = h + dotsToTenth - 1;
					h += dotsToTenth;
				}

				if ( h <= runEndH && h % 10 === 0 ) {
					collapsed.push( {
						n: '',
						k: '',
						m: '',
						ts: h / 100,
						indent: e.indent,
						isPlaceholder: true,
						pairId: e.pairId,
						displayTime: formatFullTimestamp( h / 100 ),
					} );
					lastH = h;
					timestampCount++;
					h++;
				}
			}

			// Phase 2: timestamps only, escalating (100ms, 1s, 10s, 1m, ...).
			if ( h <= runEndH ) {
				const intervals = [ 10, 100, 1000, 6000 ];
				let intervalIdx = 0;
				let rowsAtInterval = 0;

				// Snap to next aligned boundary for current interval.
				let step = intervals[ intervalIdx ];
				h = ( Math.floor( h / step ) + 1 ) * step;

				while ( h <= runEndH ) {
					collapsed.push( {
						n: '',
						k: '',
						m: '',
						ts: h / 100,
						indent: e.indent,
						isPlaceholder: true,
						pairId: e.pairId,
						displayTime: formatFullTimestamp( h / 100 ),
					} );
					lastH = h;
					rowsAtInterval++;

					if (
						rowsAtInterval >= 10 &&
						intervalIdx < intervals.length - 1
					) {
						intervalIdx++;
						rowsAtInterval = 0;
						step = intervals[ intervalIdx ];
					}

					h = ( Math.floor( h / step ) + 1 ) * step;
				}
			}

			idx = runEnd; // Skip past the run.
			continue;
		}

		// Non-placeholder: compute displayTime normally.
		const ts = e.ts || 0;
		const { displayTime, newHundredth } = formatTimeDisplay( ts, lastH );
		lastH = newHundredth;
		collapsed.push(
			displayTime !== e.displayTime ? { ...e, displayTime } : e
		);
	}

	// Ensure last visible row has a timestamp.
	if ( collapsed.length > 0 ) {
		const last = collapsed[ collapsed.length - 1 ];
		if ( ! last.displayTime || last.displayTime.startsWith( '•' ) ) {
			const ts = last.ts || 0;
			if ( ts ) {
				collapsed[ collapsed.length - 1 ] = {
					...last,
					displayTime: formatFullTimestamp( ts ),
				};
			}
		}
	}

	return collapsed;
};

/**
 * Walk backwards from a target entry index to collect ancestor pairIds
 * that must be expanded for the target to be visible.
 *
 * A `(complete)` carries its own `(start)`'s indent, so the walk begins at the
 * target's own level and picks up its pair first; every other row is one level
 * inside the pair that contains it.
 *
 * @param {number} targetIdx       Index in the full indented entries array.
 * @param {Array}  indentedEntries Full indented entries array.
 * @return {Set} pairIds to expand: the enclosing pairs, plus the target's own
 *               when it opens or closes one.
 */
export const getAncestorPairIds = ( targetIdx, indentedEntries ) => {
	const ids = new Set();
	const targetEntry = indentedEntries[ targetIdx ];
	if ( ! targetEntry ) {
		return ids;
	}

	// The target's containing pair must be expanded for it to show.
	const keyword = targetEntry.k || '';
	const isStart = keyword.includes( '(start)' );
	const isComplete = keyword.includes( '(complete)' );

	if ( isStart && hasPair( targetEntry ) ) {
		ids.add( targetEntry.pairId );
	}

	// Walk back to the nearest enclosing start pairId per indent level.
	const closesOwn = isComplete && hasPair( targetEntry );
	let needIndent = closesOwn ? targetEntry.indent : targetEntry.indent - 1;
	for ( let i = targetIdx - 1; i >= 0 && needIndent >= 0; i-- ) {
		const e = indentedEntries[ i ];
		if (
			e.indent === needIndent &&
			hasPair( e ) &&
			( e.k || '' ).includes( '(start)' )
		) {
			ids.add( e.pairId );
			needIndent--;
		}
	}

	return ids;
};
