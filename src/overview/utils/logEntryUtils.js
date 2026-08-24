/**
 * Log-entry view model for the request-detail table.
 *
 * A request arrives from `Request_Builder_Node` as a flat list of log
 * entries — `n` line number, `k` keyword, `m` message, `ts` Unix timestamp
 * with `duration_ms` and `peak_mb` on the ones that carry them. This module
 * turns that list into the nested, foldable, time-ruled rows that
 * `LogEntriesTable` renders, in two passes:
 *
 * 1. `computeIndentedEntries()` derives an indent level and a `pairId` for
 *    every entry from its `(start)`/`(complete)` keyword, and spans time
 *    gaps with placeholder rows.
 * 2. `computeVisibleEntries()` applies the fold state, replacing each
 *    collapsed pair with a single merged row, then rewrites the placeholder
 *    runs and the `displayTime` column for the rows that survive.
 *
 * The split is what keeps folding cheap: the first pass is memoized on the
 * request, the second on the fold set, which changes with every click.
 *
 * `displayTime` is a time ruler, not a per-row clock. A row shows a full
 * timestamp at a 100ms mark and bullets for the 10ms ticks between marks,
 * so scanning the column reads elapsed time down the request.
 */

const START_REGEX = /^(.+?) \(start\)$/;
const COMPLETE_REGEX = /^(.+?) \(complete\)$/;
const TIME_FORMAT_OPTIONS = { hour12: false };

/**
 * The base name of the outermost pair — the request itself, which never folds.
 */
const OUTERMOST_PAIR = 'process';

/**
 * Keywords that announce a break in the record: entries were dropped, or merged
 * away by the pressure fold. Nothing before one of these can contain anything
 * after it, so they close every span still open — except the request itself,
 * which does span the break.
 */
const FOLD_MARKER = 'entries (aggregated)';
const SEQUENCE_BREAK_KEYWORDS = new Set( [ 'entries (lost)', FOLD_MARKER ] );

/**
 * The base name of the pair a `<name> (start)` keyword opens, or null when the
 * keyword does not open one.
 *
 * @param {string} keyword Entry keyword.
 * @return {?string} Base name, or null.
 */
const pairBaseName = ( keyword ) => {
	const match = ( keyword || '' ).match( /^(.+?) \(start\)$/ );
	return match ? match[ 1 ] : null;
};

/**
 * Whether a keyword opens a pair the reader may fold.
 *
 * THE rule, in one place. It previously had five spellings across this file
 * and LogEntriesTable, and they disagreed on real input: a
 * `startsWith( 'process ' )` test excluded `process queue (start)` from
 * "Unfold All" while the same row still got a disclosure triangle, a pointer
 * cursor, and a working click handler.
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
 * @return {string} Formatted time "HH:MM:SS.TH" (2 decimal places, 10ms precision), or empty without a ts.
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
 * Format time display - timestamps at 100ms marks, bullets at 10ms marks.
 *
 * @param {number} ts            Current timestamp.
 * @param {number} lastHundredth Last displayed hundredth (10ms interval).
 * @return {Object} { displayTime, newHundredth }
 */
const formatTimeDisplay = ( ts, lastHundredth ) => {
	if ( ! ts ) {
		return {
			displayTime: '',
			newHundredth: lastHundredth,
		};
	}

	const currentHundredth = Math.round( ts * 100 );

	// Same 10ms interval - no display.
	if ( currentHundredth <= lastHundredth ) {
		return {
			displayTime: '',
			newHundredth: currentHundredth,
		};
	}

	const dots = currentHundredth - lastHundredth;

	// First entry, exact 100ms boundary, or crossed (>9 dots skipped a mark).
	if ( lastHundredth < 0 || currentHundredth % 10 === 0 || dots > 9 ) {
		return {
			displayTime: formatFullTimestamp( ts ),
			newHundredth: currentHundredth,
		};
	}

	// 10ms boundaries within the same tenth - show grouped dots.
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
 * Compute indentation levels for log entries based on (start)/(complete) pairs.
 * Uses LIFO name matching to handle improperly nested events.
 * Adds time display: timestamps at 100ms marks, bullets at 10ms marks.
 * Inserts placeholder rows with bullets to show time gaps.
 *
 * @param {Array} entries Log entries array.
 * @return {IndentedEntries} Indented rows and the real-entry count.
 */
export const computeIndentedEntries = ( entries ) => {
	if ( ! entries?.length ) {
		return { entries: [], realCount: 0 };
	}
	let lastHundredth = -1;
	const result = [];
	let realCount = 0;
	// Stack of { name, pairId } for LIFO start/complete matching.
	const pairStack = [];
	let pairCounter = 0;

	entries.forEach( ( entry ) => {
		const keyword = entry.k || '';
		const ts = entry.ts || 0;

		// Extract base name from keyword.
		const startMatch = keyword.match( START_REGEX );
		const completeMatch = keyword.match( COMPLETE_REGEX );

		// For complete entries, find matching start using LIFO name matching.
		let matchedIdx = -1;
		if ( completeMatch ) {
			const baseName = completeMatch[ 1 ];
			// Search backwards for matching name.
			for ( let i = pairStack.length - 1; i >= 0; i-- ) {
				if ( pairStack[ i ].name === baseName ) {
					matchedIdx = i;
					break;
				}
			}
		}

		// Left open, a severed span adopts every row after it.
		if ( SEQUENCE_BREAK_KEYWORDS.has( keyword ) ) {
			const outermost =
				pairStack.length > 0 && pairStack[ 0 ].name === OUTERMOST_PAIR
					? 1
					: 0;
			pairStack.length = outermost;
		}

		// Current indent is stack depth.
		const indent = pairStack.length;

		// Insert compressed placeholder rows for gaps; escalating intervals.
		if ( lastHundredth >= 0 && ts > 0 ) {
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

		// Track start/complete pairs; displayTime recomputed per visible set.
		let pairId = null;
		realCount++;
		if ( startMatch ) {
			pairId = ++pairCounter;
			pairStack.push( { name: startMatch[ 1 ], pairId } );
			result.push( { ...entry, indent, pairId } );
		} else if ( completeMatch && matchedIdx >= 0 ) {
			// Found matching start - use its pairId.
			pairId = pairStack[ matchedIdx ].pairId;
			// Remove only the matched entry; children outliving parent stay.
			pairStack.splice( matchedIdx, 1 );
			result.push( {
				...entry,
				indent: matchedIdx, // Indent at the matched level.
				pairId,
			} );
		} else if ( completeMatch ) {
			// No matching start - orphaned complete, show at indent 0.
			result.push( { ...entry, indent: 0, pairId: null } );
		} else {
			// Non-start/complete entry - use current stack depth.
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
 * @return {Array} Base names of the spans still open.
 */
const openSpansAt = ( entries, upto ) => {
	const stack = [];
	for ( let i = 0; i < upto; i++ ) {
		const keyword = entries[ i ].k || '';
		const opened = keyword.match( START_REGEX );
		if ( opened ) {
			stack.push( opened[ 1 ] );
			continue;
		}
		const closed = keyword.match( COMPLETE_REGEX );
		if ( ! closed ) {
			continue;
		}
		const at = stack.lastIndexOf( closed[ 1 ] );
		if ( at >= 0 ) {
			stack.length = at;
		}
	}
	return stack;
};

/**
 * Base names the kept TAIL closes without having opened — spans that began in
 * the folded middle and end after it.
 *
 * @param {Array}  entries Stored entries.
 * @param {number} from    Index to start at (the marker).
 * @return {Set} Base names whose `(complete)` the tail supplies.
 */
const tailClosedNames = ( entries, from ) => {
	const opened = [];
	const closed = new Set();
	for ( let i = from; i < entries.length; i++ ) {
		const keyword = entries[ i ].k || '';
		const starts = keyword.match( START_REGEX );
		if ( starts ) {
			opened.push( starts[ 1 ] );
			continue;
		}
		const completes = keyword.match( COMPLETE_REGEX );
		if ( ! completes ) {
			continue;
		}
		const at = opened.lastIndexOf( completes[ 1 ] );
		if ( at >= 0 ) {
			opened.length = at;
			continue;
		}
		closed.add( completes[ 1 ] );
	}
	return closed;
};

/**
 * How many complete pairs of each base name the KEPT rows already show.
 *
 * The fold replays the kept head into its tree and adds every tail entry to it,
 * so those instances are counted in the merged nodes as well. A node whose
 * whole count is already on screen is a ghost of rows the reader can see.
 *
 * @param {Array} entries Stored entries — kept head, marker, kept tail.
 * @return {Map} Base name to the number of complete pairs kept.
 */
const keptPairCounts = ( entries ) => {
	const counts = new Map();
	const opened = [];
	entries.forEach( ( entry ) => {
		const keyword = entry.k || '';
		const starts = keyword.match( START_REGEX );
		if ( starts ) {
			opened.push( starts[ 1 ] );
			return;
		}
		const completes = keyword.match( COMPLETE_REGEX );
		if ( ! completes ) {
			return;
		}
		const at = opened.lastIndexOf( completes[ 1 ] );
		if ( at < 0 ) {
			return;
		}
		opened.length = at;
		counts.set( completes[ 1 ], ( counts.get( completes[ 1 ] ) || 0 ) + 1 );
	} );
	return counts;
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
 * Spans that STRADDLE a boundary are not emitted twice. One already open when
 * the head ended is skipped whole — that row exists. One the tail CLOSES but
 * never opened began in the middle: the tree opens it and the tail's real
 * `(complete)` closes it, so the merged half is left off.
 *
 * Durations stay INCLUSIVE, as every other duration in the log is.
 *
 * @param {?Object} flame    Merged tree from Flame_Fold::tree().
 * @param {Array}   openPath Base names the kept head left open, outermost first.
 * @param {Set}     tailEnds Base names the kept tail closes on the tree's behalf.
 * @param {Map}     kept     Base name to complete pairs the kept rows already show.
 * @param {number}  originTs Unix seconds the request started at.
 * @return {Array} Synthetic log entries.
 */
const foldedSpanEntries = ( flame, openPath, tailEnds, kept, originTs ) => {
	const rows = [];
	const stampOf = ( node ) =>
		Number.isFinite( node.t ) && Number.isFinite( originTs )
			? originTs + node.t / 1000
			: 0;

	const walk = ( nodes, depth ) => {
		( nodes || [] ).forEach( ( node ) => {
			const base = String( node.name ).split( ': ' )[ 0 ];
			if ( openPath[ depth ] === base ) {
				walk( node.children, depth + 1 );
				return;
			}
			// Already on screen: re-emitting shows a "1 merged" ghost of it.
			const shown = kept.get( base ) || 0;
			const merged = Number( node.count ) - shown;
			if ( merged < 1 && ! node.children?.length ) {
				return;
			}
			const ts = stampOf( node );
			rows.push( { n: '', k: `${ node.name } (start)`, m: '', ts } );
			walk( node.children, depth + 1 );
			if ( tailEnds.has( base ) ) {
				return;
			}
			rows.push( {
				n: '',
				k: `${ node.name } (complete)`,
				m: `${ merged.toLocaleString() } merged`,
				ts,
				duration_ms: node.value,
			} );
		} );
	};

	walk( flame?.children, 0 );
	return rows;
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
			tailClosedNames( entries, at ),
			keptPairCounts( entries ),
			origin
		),
		...entries.slice( at + 1 ),
	];
};

/**
 * Filter indented entries by fold state, emitting merged rows for collapsed pairs.
 *
 * @param {Array} entries     Output of computeIndentedEntries().entries.
 * @param {Set}   expandedSet Set of pairIds that are expanded. Empty = all folded.
 * @return {Array} Visible entries, with collapsed pairs replaced by merged rows.
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
		const startMatch = keyword.match( /^(.+?) \(start\)$/ );

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
					// Use complete ts so next compare starts after pair.
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
 * @param {number} targetIdx       Index in the full indented entries array.
 * @param {Array}  indentedEntries Full indented entries array.
 * @return {Set} Set of pairIds (ancestors + target) to expand.
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
	let needIndent = isComplete ? targetEntry.indent : targetEntry.indent - 1;
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
