/**
 * Utility functions for processing and displaying log entries.
 */

const START_REGEX = /^(.+?) \(start\)$/;
const COMPLETE_REGEX = /^(.+?) \(complete\)$/;
const TIME_FORMAT_OPTIONS = { hour12: false };

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
 * @return {string} Formatted time "HH:MM:SS.TH" (2 decimal places, 10ms precision).
 */
const formatFullTimestamp = ( ts ) => {
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

	// First entry, exact 100ms boundary, or crossed a 100ms boundary
	// (more than 9 dots means we skipped past a tenth mark).
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
 * Compute indentation levels for log entries based on (start)/(complete) pairs.
 * Uses LIFO name matching to handle improperly nested events.
 * Adds time display: timestamps at 100ms marks, bullets at 10ms marks.
 * Inserts placeholder rows with bullets to show time gaps.
 *
 * @param {Array} entries Log entries array.
 * @return {Array} Entries with indent level and displayTime added.
 */
export const computeIndentedEntries = ( entries ) => {
	if ( ! entries?.length ) {
		return { entries: [], realCount: 0 };
	}
	let lastHundredth = -1;
	const result = [];
	let realCount = 0;
	// Stack to track start/complete pairs with names for LIFO matching.
	// Each entry: { name, pairId }
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

		// Current indent is stack depth.
		const indent = pairStack.length;

		// Insert compressed placeholder rows for time gaps between entries.
		// Uses escalating intervals: 10ms x10, then 100ms x10, then 1s x10, etc.
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
					// Placeholder ts derived from hundredth counter for
					// displayTime recomputation in computeVisibleEntries.
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

		// Track start/complete pairs for click-to-highlight.
		// displayTime is left empty — computeVisibleEntries recomputes it
		// dynamically based on which entries are actually visible.
		let pairId = null;
		realCount++;
		if ( startMatch ) {
			pairId = ++pairCounter;
			pairStack.push( { name: startMatch[ 1 ], pairId } );
			result.push( { ...entry, indent, pairId } );
		} else if ( completeMatch && matchedIdx >= 0 ) {
			// Found matching start - use its pairId.
			pairId = pairStack[ matchedIdx ].pairId;
			// Remove only the matched entry (like log-manager does).
			// Children that outlive their parent stay on stack.
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

		if (
			startMatch &&
			entry.pairId !== null &&
			entry.pairId !== undefined
		) {
			const baseName = startMatch[ 1 ];
			const isOutermost = baseName === 'process';

			if ( ! isOutermost && ! expandedSet.has( entry.pairId ) ) {
				// Collapsed: scan forward to find matching complete, emit merged row.
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
					if ( ! inner.isPlaceholder ) {
						childCount++;
					}
					j++;
				}
				result.push( {
					...entry,
					k: baseName,
					// Use complete's ts for timeline flow so the next
					// entry's timestamp comparison starts after this pair.
					ts: completeEntry?.ts || entry.ts,
					startTs: entry.ts,
					duration_ms: completeEntry?.duration_ms ?? null,
					peak_mb: completeEntry?.peak_mb || 0,
					completeMessage: completeEntry?.m || '',
					childCount,
					isMerged: true,
					originalIdx: i,
				} );
				// Skip past the complete entry.
				i = j + 1;
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

	// Collapse consecutive placeholder rows and recompute displayTime.
	// Placeholders are merged: up to 9 dots per row, then a timestamp row,
	// repeating. Non-placeholder entries get displayTime from formatTimeDisplay.
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

			// Phase 1: dots + first 2 timestamps.
			// Show dots before first tenth boundary, a timestamp,
			// dots before second, a timestamp, then switch to phase 2.
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

			// Phase 2: timestamps only with escalating intervals.
			// 100ms x 10, then 1s x 10, then 10s x 10, then 1m x 10, ...
			// (intervals in hundredths: 10, 100, 1000, 6000, ...)
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

	// The target's containing pair must be expanded for it to be visible.
	// For start entries, that's their own pairId.
	// For complete entries and leaf entries, it's their parent pair's pairId
	// (which we find by walking backwards to the enclosing start).
	const keyword = targetEntry.k || '';
	const isStart = keyword.includes( '(start)' );
	const isComplete = keyword.includes( '(complete)' );

	if (
		isStart &&
		targetEntry.pairId !== null &&
		targetEntry.pairId !== undefined
	) {
		ids.add( targetEntry.pairId );
	}

	// Walk backwards collecting the nearest start-entry pairId at each
	// decreasing indent level. A complete entry's indent is normalized down to
	// its matched start's level, so its enclosing start sits at its OWN indent;
	// a start or a leaf (error/info) child sits one level DEEPER than the start
	// that contains it, so those look one indent up. (A leaf reading its own
	// indent would never find its parent start — the include-error reveal bug.)
	let needIndent = isComplete ? targetEntry.indent : targetEntry.indent - 1;
	for ( let i = targetIdx - 1; i >= 0 && needIndent >= 0; i-- ) {
		const e = indentedEntries[ i ];
		if (
			e.indent === needIndent &&
			e.pairId !== null &&
			e.pairId !== undefined &&
			( e.k || '' ).includes( '(start)' )
		) {
			ids.add( e.pairId );
			needIndent--;
		}
	}

	return ids;
};
