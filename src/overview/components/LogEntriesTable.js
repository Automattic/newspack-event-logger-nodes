/* global requestAnimationFrame */
/**
 * Log Entries Table
 *
 * Renders one request's log entries as an indented, foldable table in the
 * request-detail modal. Entries arrive already indented and pair-tagged from
 * `computeIndentedEntries()`; this component owns only view state — which
 * pairs are unfolded, which rows the search matched, and which range the pair
 * swatch highlights.
 *
 * Folding a `(start)`/`(complete)` pair replaces both rows and everything
 * between them with one merged row, computed by `computeVisibleEntries()`.
 * The outermost `process` pair never folds.
 *
 * Search is debounced, counts a start/complete pair once, and answers to
 * `/`, `n`, `p`, and Escape. `revealRef` hands `revealPath()` back to the
 * parent so the flame graph can unfold and scroll to a clicked span.
 */

import {
	useState,
	useCallback,
	useMemo,
	useEffect,
	useRef,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import {
	getStateColor,
	hexToRgba,
} from '@newspack-nodes/shared/utils/formatUtils';
import {
	computeVisibleEntries,
	formatDots,
	formatFullTimestamp,
	getAncestorPairIds,
	hasPair,
	isEmptyPairStart,
	isFoldablePairStart,
} from '../utils/logEntryUtils';

/**
 * Whether an entry carries its own duration stat.
 *
 * @param {Object} entry Log entry.
 * @return {boolean} True when duration_ms is set.
 */
const hasDuration = ( entry ) =>
	null !== entry.duration_ms && undefined !== entry.duration_ms;

/**
 * Index of a pair's `(start)` row in the full entries array, or -1.
 *
 * @param {Array}  entries Full indented entries array.
 * @param {number} pairId  The pair to look up.
 * @return {number} Index of the start row, or -1.
 */
const findStartIdx = ( entries, pairId ) =>
	entries.findIndex(
		( e ) => e.pairId === pairId && ( e.k || '' ).includes( '(start)' )
	);

/**
 * Render a merged row's time cell: both ends when they fall in different
 * tenths, a dot per 10ms tick when they share one, else the row's ruler time.
 *
 * @param {Object} entry Merged entry.
 * @return {import('react').ReactNode} Time cell content.
 */
const renderMergedTime = ( entry ) => {
	const startTime = formatFullTimestamp( entry.startTs );
	const endTime = formatFullTimestamp( entry.ts );
	const startH = Math.round( ( entry.startTs || 0 ) * 100 );
	const endH = Math.round( ( entry.ts || 0 ) * 100 );
	const dots = endH - startH;
	const spansTenths = Math.floor( startH / 10 ) !== Math.floor( endH / 10 );
	if ( ( startTime && endTime && spansTenths ) || dots > 9 ) {
		return (
			<>
				{ startTime }
				<br />
				{ endTime }
			</>
		);
	}
	return dots > 0 ? formatDots( dots ) : entry.displayTime;
};

/**
 * Collect all descendant pairIds between a start entry and its matching complete.
 *
 * Empty pairs — a start immediately followed by its own complete — are left
 * out: they always render as a single merged row, so unfolding them changes
 * nothing.
 *
 * @param {Array}  entries  Full indented entries array.
 * @param {number} startIdx Index of the (start) entry.
 * @param {number} pairId   The pairId of the start entry.
 * @return {Array} Array of descendant pairIds (not including the parent).
 */
const collectDescendantPairIds = ( entries, startIdx, pairId ) => {
	const ids = [];
	for ( let i = startIdx + 1; i < entries.length; i++ ) {
		const e = entries[ i ];
		if ( e.pairId === pairId && ( e.k || '' ).includes( '(complete)' ) ) {
			break;
		}
		if (
			hasPair( e ) &&
			e.pairId !== pairId &&
			( e.k || '' ).match( /\(start\)$/ ) &&
			! isEmptyPairStart( entries, i )
		) {
			ids.push( e.pairId );
		}
	}
	return ids;
};

/**
 * Active highlight state — the currently highlighted cells and the timer that
 * clears them, so navigating to a new match drops the old highlight at once.
 * Module-scoped, so exactly one highlight exists across every mounted table.
 */
let activeHighlight = { cells: [], timer: null };

/**
 * Clear any active cell highlights.
 */
const clearHighlight = () => {
	for ( const td of activeHighlight.cells ) {
		td.style.boxShadow = '';
	}
	clearTimeout( activeHighlight.timer );
	activeHighlight = { cells: [], timer: null };
};

/**
 * Scroll to a table row by data-pair-id or data-entry-idx and flash-highlight it.
 * Highlights individual <td> cells since <tr> doesn't support box-shadow reliably.
 *
 * The lookup runs inside `requestAnimationFrame` so the row an expand just
 * revealed exists in the DOM by the time we query for it. The highlight
 * clears itself after two seconds, or sooner if another call preempts it.
 *
 * @param {Object} tableRef React ref to the table element.
 * @param {Object} selector Either { pairId: number } or { entryIdx: number }.
 */
const scrollToAndHighlight = ( tableRef, selector ) => {
	requestAnimationFrame( () => {
		if ( ! tableRef.current ) {
			return;
		}

		clearHighlight();

		let row;
		if ( selector.pairId !== undefined ) {
			const rows =
				tableRef.current.querySelectorAll( 'tr[data-pair-id]' );
			for ( const r of rows ) {
				if ( r.dataset.pairId === String( selector.pairId ) ) {
					row = r;
					break;
				}
			}
		} else if ( selector.entryIdx !== undefined ) {
			row = tableRef.current.querySelector(
				`tr[data-entry-idx="${ selector.entryIdx }"]`
			);
		}
		if ( ! row ) {
			return;
		}
		row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		const cells = row.querySelectorAll( 'td' );
		const highlighted = [];
		for ( const td of cells ) {
			td.style.boxShadow =
				'inset 0 2px 0 0 rgba(79, 195, 247, 0.8), inset 0 -2px 0 0 rgba(79, 195, 247, 0.8)';
			highlighted.push( td );
		}
		// Add left edge to first cell and right edge to last.
		if ( cells.length > 0 ) {
			cells[ 0 ].style.boxShadow +=
				', inset 2px 0 0 0 rgba(79, 195, 247, 0.8)';
			cells[ cells.length - 1 ].style.boxShadow +=
				', inset -2px 0 0 0 rgba(79, 195, 247, 0.8)';
		}
		activeHighlight.cells = highlighted;
		activeHighlight.timer = setTimeout( () => {
			clearHighlight();
		}, 2000 );
	} );
};

/**
 * Log Entries Table component.
 *
 * Every pair starts folded: `expandedSet` holds the pairIds the reader has
 * opened, and an empty set means everything is collapsed.
 *
 * @param {Object} props           Component props.
 * @param {Array}  props.entries   Array of indented log entries (from computeIndentedEntries).
 * @param {number} props.realCount Count of real (non-placeholder) entries; the heading falls back to entries.length.
 * @param {Object} props.revealRef Ref the component fills with `revealPath( path )`, the flame graph's way in.
 * @return {import('react').ReactElement|null} Rendered component or null if no entries.
 */
export default function LogEntriesTable( { entries, realCount, revealRef } ) {
	const tableRef = useRef( null );
	const searchContainerRef = useRef( null );
	const [ expandedSet, setExpandedSet ] = useState( () => new Set() );
	const [ highlightRange, setHighlightRange ] = useState( null );

	// Search state.
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ matchedIndices, setMatchedIndices ] = useState( [] );
	const [ currentMatchIndex, setCurrentMatchIndex ] = useState( -1 );
	const preSearchExpandedRef = useRef( null );
	const searchTimerRef = useRef( null );

	/**
	 * Recompute matches 150ms after the query settles.
	 *
	 * A match is a case-insensitive substring hit on the keyword or on the
	 * message (objects are matched against their JSON). One start/complete
	 * pair counts once: when the query matched the start's keyword, the
	 * complete's identical keyword is skipped unless its own message also
	 * matched. Keywords are tested suffix-anchored, like the parser, so a
	 * truncated tail such as `foo (start` opts out of the pairing rule.
	 * Placeholder gap rows never match.
	 */
	useEffect( () => {
		if ( searchTimerRef.current ) {
			clearTimeout( searchTimerRef.current );
		}

		if ( ! searchQuery ) {
			setMatchedIndices( [] );
			setCurrentMatchIndex( -1 );
			return;
		}

		searchTimerRef.current = setTimeout( () => {
			const query = searchQuery.toLowerCase();
			const matches = [];
			// Starts precede their completes, so one pass suffices.
			const startKeywordHits = new Set();

			for ( let i = 0; i < entries.length; i++ ) {
				const e = entries[ i ];
				if ( e.isPlaceholder ) {
					continue;
				}
				const keyword = ( e.k || '' ).toLowerCase();
				let message = '';
				if ( typeof e.m === 'string' ) {
					message = e.m.toLowerCase();
				} else if ( typeof e.m === 'object' ) {
					message = JSON.stringify( e.m ).toLowerCase();
				}

				const keywordHit = keyword.includes( query );
				const messageHit = message.includes( query );
				if ( ! keywordHit && ! messageHit ) {
					continue;
				}
				const paired = hasPair( e );
				if ( keywordHit && paired && keyword.endsWith( '(start)' ) ) {
					startKeywordHits.add( e.pairId );
				}
				// The pair's start already matched — count the pair once.
				if (
					! messageHit &&
					paired &&
					keyword.endsWith( '(complete)' ) &&
					startKeywordHits.has( e.pairId )
				) {
					continue;
				}
				matches.push( i );
			}

			setMatchedIndices( matches );
			setCurrentMatchIndex( -1 );
		}, 150 );

		return () => {
			if ( searchTimerRef.current ) {
				clearTimeout( searchTimerRef.current );
			}
		};
	}, [ searchQuery, entries ] );

	/**
	 * Every pairId that can be unfolded — the set "Unfold All" applies and
	 * search navigation checks before expanding an ancestor. Empty pairs and
	 * any pair whose keyword begins with `process ` (the outermost pair, which
	 * never folds) are excluded; neither has anything to reveal.
	 */
	const allPairIds = useMemo( () => {
		const ids = new Set();
		for ( let i = 0; i < entries.length; i++ ) {
			const entry = entries[ i ];
			if (
				hasPair( entry ) &&
				isFoldablePairStart( entry.k ) &&
				! isEmptyPairStart( entries, i )
			) {
				ids.add( entry.pairId );
			}
		}
		return ids;
	}, [ entries ] );

	/**
	 * Jump to one search match: unfold its ancestors, scroll, and highlight.
	 *
	 * The first navigation of a search snapshots the reader's fold state so
	 * `clearSearch()` can restore it. A match that is itself half of an empty
	 * pair owns no row — the merged row stands in for both halves — so it is
	 * addressed by pairId; every other match by entry index.
	 *
	 * @param {number} matchIdx Index into matchedIndices; out-of-range is ignored.
	 */
	const navigateToMatch = useCallback(
		( matchIdx ) => {
			if ( matchIdx < 0 || matchIdx >= matchedIndices.length ) {
				return;
			}

			// Snapshot fold state before first search navigation.
			if ( preSearchExpandedRef.current === null ) {
				preSearchExpandedRef.current = new Set( expandedSet );
			}

			const entryIdx = matchedIndices[ matchIdx ];
			const ancestorIds = getAncestorPairIds( entryIdx, entries );

			// Only expand pairs with children; empty pair stays merged.
			setExpandedSet( ( prev ) => {
				const next = new Set( prev );
				for ( const id of ancestorIds ) {
					if ( allPairIds.has( id ) ) {
						next.add( id );
					}
				}
				return next;
			} );

			setCurrentMatchIndex( matchIdx );

			// Empty-pair match: scroll by pairId (row carries start idx).
			const ownPairId = entries[ entryIdx ]?.pairId;
			const staysFolded =
				isEmptyPairStart( entries, entryIdx ) ||
				isEmptyPairStart( entries, entryIdx - 1 );
			scrollToAndHighlight(
				tableRef,
				staysFolded ? { pairId: ownPairId } : { entryIdx }
			);
		},
		[ matchedIndices, entries, expandedSet, allPairIds ]
	);

	/** Step to the next match, wrapping past the last one. */
	const gotoNext = useCallback( () => {
		navigateToMatch(
			currentMatchIndex + 1 >= matchedIndices.length
				? 0
				: currentMatchIndex + 1
		);
	}, [ navigateToMatch, currentMatchIndex, matchedIndices ] );

	/** Step to the previous match, wrapping past the first one. */
	const gotoPrev = useCallback( () => {
		navigateToMatch(
			currentMatchIndex - 1 < 0
				? matchedIndices.length - 1
				: currentMatchIndex - 1
		);
	}, [ navigateToMatch, currentMatchIndex, matchedIndices ] );

	/**
	 * Clear the search and restore the fold state the search expanded past.
	 */
	const clearSearch = useCallback( () => {
		setSearchQuery( '' );
		setMatchedIndices( [] );
		setCurrentMatchIndex( -1 );
		if ( preSearchExpandedRef.current !== null ) {
			setExpandedSet( preSearchExpandedRef.current );
			preSearchExpandedRef.current = null;
		}
	}, [] );

	/**
	 * Document-level search keybindings, captured before other handlers see
	 * them: `/` focuses the input, `n` and `p` (or `N`) walk the matches
	 * wrapping at both ends, and Escape clears the search. Typing in an input
	 * suppresses `n`/`p`; Enter navigates from inside the field instead.
	 */
	useEffect( () => {
		const handleKeyDown = ( e ) => {
			// Escape: clear search, refocus so next Escape closes modal.
			if ( e.key === 'Escape' && searchQuery ) {
				e.preventDefault();
				e.stopPropagation();
				clearSearch();
				const modal = /** @type {HTMLElement|null} */ (
					document.querySelector( '.components-modal__frame' )
				);
				if ( modal ) {
					modal.focus();
				}
				return;
			}

			// '/' focuses search input.
			const tag = e.target.tagName;
			if ( e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA' ) {
				e.preventDefault();
				e.stopPropagation();
				const input =
					searchContainerRef.current?.querySelector( 'input' );
				if ( input ) {
					input.focus();
				}
				return;
			}

			// Skip n/p while typing in an input (Enter navigates).
			if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
				return;
			}

			if ( ! searchQuery || matchedIndices.length === 0 ) {
				return;
			}

			if ( e.key === 'n' && ! e.shiftKey ) {
				e.preventDefault();
				gotoNext();
			} else if ( e.key === 'p' || e.key === 'N' ) {
				e.preventDefault();
				gotoPrev();
			}
		};

		document.addEventListener( 'keydown', handleKeyDown, true );
		return () =>
			document.removeEventListener( 'keydown', handleKeyDown, true );
	}, [ searchQuery, matchedIndices, gotoNext, gotoPrev, clearSearch ] );

	// Compute visible entries from fold state.
	const visibleEntries = useMemo(
		() => computeVisibleEntries( entries, expandedSet ),
		[ entries, expandedSet ]
	);

	/**
	 * Map flame-graph paths to pairIds, so `revealPath()` can resolve a
	 * clicked span to a row.
	 *
	 * Each open pair contributes two keys along the current spine: the detail
	 * path (`name: message` per segment), which the flame graph prefers, and
	 * the base-name path as a fallback. Base-name keys are first-wins, so
	 * repeated spans resolve to their earliest occurrence.
	 */
	const pathToPairId = useMemo( () => {
		const map = {};
		const stack = [];

		for ( const entry of entries ) {
			const keyword = entry.k || '';
			const startMatch = keyword.match( /^(.+?) \(start\)$/ );
			const completeMatch = keyword.match( /^(.+?) \(complete\)$/ );

			if ( startMatch && hasPair( entry ) ) {
				const name = startMatch[ 1 ];
				const msg =
					typeof entry.m === 'string' && entry.m ? entry.m : '';
				const detail = msg ? `${ name }: ${ msg }` : name;
				stack.push( { name, detail } );
				const detailKey = stack.map( ( s ) => s.detail ).join( '/' );
				map[ detailKey ] = entry.pairId;
				const baseKey = stack.map( ( s ) => s.name ).join( '/' );
				if ( ! ( baseKey in map ) ) {
					map[ baseKey ] = entry.pairId;
				}
			} else if ( completeMatch ) {
				for ( let i = stack.length - 1; i >= 0; i-- ) {
					if ( stack[ i ].name === completeMatch[ 1 ] ) {
						stack.splice( i, 1 );
						break;
					}
				}
			}
		}
		return map;
	}, [ entries ] );

	/**
	 * Reveal the row for a flame-graph path: expand its ancestors and scroll
	 * to it. An unresolvable path is a no-op.
	 *
	 * @param {Array} path Segment names from the flame root down to the span.
	 */
	const revealPath = useCallback(
		( path ) => {
			// Flame graph paths have an extra "request" root — strip it.
			let cleanPath = path;
			if ( cleanPath[ 0 ] === 'request' ) {
				cleanPath = cleanPath.slice( 1 );
			}

			// Try detail path ("name: message"), fall back to base-name.
			const detailKey = cleanPath.join( '/' );
			const baseKey = cleanPath
				.map( ( seg ) => seg.replace( /: .+$/, '' ) )
				.join( '/' );
			const targetPairId =
				pathToPairId[ detailKey ] ?? pathToPairId[ baseKey ];
			if ( targetPairId === undefined ) {
				return;
			}

			const targetIdx = findStartIdx( entries, targetPairId );

			const ancestorIds =
				targetIdx >= 0
					? getAncestorPairIds( targetIdx, entries )
					: new Set();
			ancestorIds.add( targetPairId );

			setExpandedSet( ( prev ) => {
				const next = new Set( prev );
				for ( const id of ancestorIds ) {
					next.add( id );
				}
				return next;
			} );

			scrollToAndHighlight( tableRef, { pairId: targetPairId } );
		},
		[ pathToPairId, entries ]
	);

	// Hand revealPath to the parent; the flame graph calls it on Cmd-click.
	useEffect( () => {
		if ( revealRef ) {
			revealRef.current = revealPath;
		}
	}, [ revealRef, revealPath ] );

	/**
	 * Toggle fold for a single pair. Folding also removes all descendant pairIds.
	 *
	 * `recursive` — a Cmd/Ctrl-click — only ever unfolds: an already-open pair
	 * stays open and its descendants open with it.
	 *
	 * @param {number}  pairId    The pairId to toggle.
	 * @param {number}  entryIdx  Index in the full entries array.
	 * @param {boolean} recursive If true, unfold all descendants too.
	 */
	const toggleFold = useCallback(
		( pairId, entryIdx, recursive ) => {
			setExpandedSet( ( prev ) => {
				const next = new Set( prev );
				if ( next.has( pairId ) && ! recursive ) {
					// Folding: remove this and all descendants.
					next.delete( pairId );
					const descendants = collectDescendantPairIds(
						entries,
						entryIdx,
						pairId
					);
					for ( const id of descendants ) {
						next.delete( id );
					}
				} else {
					// Unfolding.
					next.add( pairId );
					if ( recursive ) {
						const descendants = collectDescendantPairIds(
							entries,
							entryIdx,
							pairId
						);
						for ( const id of descendants ) {
							next.add( id );
						}
					}
				}
				return next;
			} );
		},
		[ entries ]
	);

	/** Collapse every pair. */
	const foldAll = useCallback( () => {
		setExpandedSet( new Set() );
	}, [] );

	/** Expand every pair that has children; empty pairs stay merged. */
	const unfoldAll = useCallback( () => {
		setExpandedSet( new Set( allPairIds ) );
	}, [ allPairIds ] );

	/**
	 * Find matching start/complete pair range in the visible entries for highlighting.
	 *
	 * A merged row spans itself alone. A complete whose start is folded away
	 * anchors on the merged row standing in for it.
	 *
	 * @param {number} idx Index in visibleEntries.
	 * @return {Object|null} Range with {start, end} indices, or null.
	 */
	const findPairRange = useCallback(
		( idx ) => {
			if ( ! visibleEntries?.length ) {
				return null;
			}
			const entry = visibleEntries[ idx ];
			const keyword = entry.k || '';
			const entryPairId = entry.pairId;

			if ( entryPairId === null || entryPairId === undefined ) {
				return null;
			}

			if ( entry.isMerged ) {
				// Merged rows are self-contained.
				return { start: idx, end: idx };
			}

			if ( keyword.includes( '(start)' ) ) {
				for ( let i = idx + 1; i < visibleEntries.length; i++ ) {
					if (
						visibleEntries[ i ].pairId === entryPairId &&
						( visibleEntries[ i ].k || '' ).includes( '(complete)' )
					) {
						return { start: idx, end: i };
					}
				}
			} else if ( keyword.includes( '(complete)' ) ) {
				for ( let i = idx - 1; i >= 0; i-- ) {
					if (
						visibleEntries[ i ].pairId === entryPairId &&
						( ( visibleEntries[ i ].k || '' ).includes(
							'(start)'
						) ||
							visibleEntries[ i ].isMerged )
					) {
						return { start: i, end: idx };
					}
				}
			}
			return null;
		},
		[ visibleEntries ]
	);

	/**
	 * Handle swatch click for pair highlighting. Clicking the highlighted
	 * range again clears it; the click never reaches the row's fold handler.
	 *
	 * @param {Object} entry The entry.
	 * @param {number} idx   Index in visibleEntries.
	 * @param {Event}  event Click event.
	 */
	const handleSwatchClick = useCallback(
		( entry, idx, event ) => {
			event.stopPropagation();
			// No guard: `findPairRange` already refuses a row with no pairId.
			const range = findPairRange( idx );
			if ( range ) {
				if (
					highlightRange?.start === range.start &&
					highlightRange?.end === range.end
				) {
					setHighlightRange( null );
				} else {
					setHighlightRange( range );
				}
			}
		},
		[ findPairRange, highlightRange ]
	);

	/**
	 * Handle row click for fold/unfold. Merged and `(start)` rows toggle;
	 * everything else, including the outermost `process` pair, ignores the
	 * click. Cmd/Ctrl-click unfolds the whole subtree.
	 *
	 * @param {Object} entry Visible entry.
	 * @param {number} idx   Index in visibleEntries.
	 * @param {Event}  event Click event.
	 */
	const handleRowClick = useCallback(
		( entry, idx, event ) => {
			const foldable =
				entry.isMerged ||
				( hasPair( entry ) && isFoldablePairStart( entry.k ) );
			const fullIdx = foldable
				? findStartIdx( entries, entry.pairId )
				: -1;
			if ( fullIdx >= 0 ) {
				toggleFold(
					entry.pairId,
					fullIdx,
					event.metaKey || event.ctrlKey
				);
			}
		},
		[ entries, toggleFold ]
	);

	/**
	 * Get row style based on highlight state and entry type.
	 *
	 * Rows tint with the keyword's state color, alternating opacity for
	 * zebra striping; a highlighted pair range takes the blue tint instead.
	 * Foldable rows get a pointer cursor.
	 *
	 * @param {Object} entry Entry object.
	 * @param {number} idx   Index in visibleEntries.
	 * @return {Object} Style object.
	 */
	const getRowStyle = useCallback(
		( entry, idx ) => {
			const keyword = entry.k || '';
			const isFoldable = entry.isMerged || isFoldablePairStart( keyword );
			const isHighlighted =
				highlightRange &&
				idx >= highlightRange.start &&
				idx <= highlightRange.end;

			const eventColor = getStateColor( keyword );
			const baseOpacity = idx % 2 === 0 ? 0.15 : 0.08;

			const backgroundColor = isHighlighted
				? `rgba(79, 195, 247, ${ idx % 2 === 0 ? 0.25 : 0.15 })`
				: hexToRgba( eventColor, baseOpacity );

			return {
				backgroundColor,
				cursor: isFoldable ? 'pointer' : undefined,
			};
		},
		[ highlightRange ]
	);

	/**
	 * Format message content for display.
	 *
	 * Objects pretty-print with keys sorted; strings fall back to the stable
	 * label `l` when the volatile message `m` is empty. A row that already
	 * carries its own stats — merged, complete, or duration-bearing — shows
	 * nothing rather than a placeholder dash.
	 *
	 * @param {Object} entry Log entry object.
	 * @return {string} Formatted message.
	 */
	const formatMessage = ( entry ) => {
		if ( entry.isPlaceholder ) {
			return '';
		}
		if ( typeof entry.m === 'object' ) {
			// Pretty-print object values on indented, alpha-sorted lines.
			const value =
				entry.m && ! Array.isArray( entry.m )
					? Object.fromEntries(
							Object.keys( entry.m )
								.sort()
								.map( ( k ) => [ k, entry.m[ k ] ] )
					  )
					: entry.m;
			return JSON.stringify( value, null, 2 );
		}
		const msg = entry.m || entry.l || '';
		// Merged/complete rows and duration-stat entries carry their own stats.
		const carriesStats =
			entry.isMerged ||
			( entry.k || '' ).includes( '(complete)' ) ||
			hasDuration( entry );
		if ( carriesStats && ( ! msg || '-' === msg ) ) {
			return '';
		}
		return msg || '-';
	};

	/**
	 * Render the keyword cell content. Foldable rows lead with a disclosure
	 * triangle: right when merged, down when unfolded.
	 *
	 * @param {Object} entry Entry object.
	 * @return {import('react').ReactNode} Keyword content.
	 */
	const renderKeyword = ( entry ) => {
		if ( entry.isPlaceholder ) {
			return '';
		}

		const keyword = entry.k || '';

		if ( ! entry.isMerged && ! isFoldablePairStart( keyword ) ) {
			return keyword || '-';
		}

		return (
			<>
				<span
					className="newspack-nodes-status is-info"
					style={ {
						fontSize: '10px',
						marginRight: '4px',
					} }
				>
					{ entry.isMerged ? '▶' : '▼' }
				</span>
				{ keyword }
			</>
		);
	};

	/**
	 * Render duration + peak_mb stats line, or nothing when the entry carries
	 * neither.
	 *
	 * @param {Object} entry Entry with duration_ms and peak_mb.
	 * @return {import('react').ReactElement|null} Stats span or null.
	 */
	const renderStats = ( entry ) => {
		const hasPeak = entry.peak_mb > 0;
		if ( ! hasDuration( entry ) && ! hasPeak ) {
			return null;
		}
		return (
			<span className="newspack-nodes-status">
				{ hasDuration( entry ) &&
					`(${ entry.duration_ms.toFixed( 3 ) }ms)` }
				{ hasPeak && (
					<span
						className="newspack-nodes-status is-warning"
						style={ { marginLeft: '6px' } }
					>
						[{ entry.peak_mb }MB]
					</span>
				) }
			</span>
		);
	};

	/**
	 * Render message cell for merged (collapsed) rows.
	 * Shows start message + complete message, then stats on new line,
	 * followed by a badge counting the entries the fold hides.
	 *
	 * @param {Object} entry Merged entry.
	 * @return {import('react').ReactElement} Message content.
	 */
	const renderMergedMessage = ( entry ) => {
		const startMsg = formatMessage( entry );
		let completeMsg = '';
		if ( entry.completeMessage && entry.completeMessage !== '-' ) {
			completeMsg =
				typeof entry.completeMessage === 'object'
					? JSON.stringify( entry.completeMessage )
					: entry.completeMessage;
		}
		const hasContent = startMsg || completeMsg;
		const stats = renderStats( entry );
		const childBadge = entry.childCount > 0 && (
			<span
				className="newspack-nodes-status is-muted"
				style={ {
					marginLeft: '6px',
				} }
			>
				{ sprintf(
					// translators: %d: number of nested log entries hidden inside this collapsed pair.
					_n(
						'[%d entry]',
						'[%d entries]',
						entry.childCount,
						'newspack-event-logger-nodes'
					),
					entry.childCount
				) }
			</span>
		);
		return (
			<>
				{ startMsg }
				{ startMsg && completeMsg && ' ' }
				{ completeMsg }
				{ ( stats || childBadge ) && (
					<>
						{ hasContent && <br /> }
						{ stats }
						{ childBadge }
					</>
				) }
			</>
		);
	};

	/**
	 * Render message cell for non-merged entries.
	 * Complete entries show duration/peak on a new line after content;
	 * everything else keeps them inline.
	 *
	 * @param {Object} entry Entry object.
	 * @return {import('react').ReactNode} Message content.
	 */
	const renderEntryMessage = ( entry ) => {
		if ( entry.isPlaceholder ) {
			return '';
		}
		const msg = formatMessage( entry );
		const isComplete = ( entry.k || '' ).includes( '(complete)' );
		const stats = renderStats( entry );

		if ( isComplete ) {
			return (
				<>
					{ msg }
					{ stats && (
						<>
							{ msg && <br /> }
							{ stats }
						</>
					) }
				</>
			);
		}

		return (
			<>
				{ msg }
				{ stats && (
					<span style={ { marginLeft: '8px' } }>{ stats }</span>
				) }
			</>
		);
	};

	if ( ! entries || entries.length === 0 ) {
		return null;
	}

	return (
		<div className="event-logger-log-entries">
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
				} }
			>
				<h3>
					{ sprintf(
						// translators: %d: number of log entries in the request.
						__( 'Log Entries (%d)', 'newspack-event-logger-nodes' ),
						realCount ?? entries.length
					) }
				</h3>
				<div className="log-entries-actions">
					<button
						type="button"
						className="button-link"
						onClick={ foldAll }
					>
						&minus;{ ' ' }
						{ __( 'Fold All', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						className="button-link"
						onClick={ unfoldAll }
					>
						+ { __( 'Unfold All', 'newspack-event-logger-nodes' ) }
					</button>
				</div>
			</div>
			<div className="log-entries-search">
				<div ref={ searchContainerRef } style={ { flex: 1 } }>
					<TextControl
						__next40pxDefaultSize
						placeholder={ __(
							'Search entries…',
							'newspack-event-logger-nodes'
						) }
						value={ searchQuery }
						onChange={ setSearchQuery }
						onKeyDown={ ( e ) => {
							if (
								e.key === 'Enter' &&
								matchedIndices.length > 0
							) {
								e.preventDefault();
								// Blur so n/p keybindings work immediately.
								/** @type {HTMLInputElement} */ (
									e.target
								).blur();
								if ( e.shiftKey ) {
									gotoPrev();
								} else {
									gotoNext();
								}
							}
						} }
						__nextHasNoMarginBottom
					/>
				</div>
				{ searchQuery && (
					<div className="log-entries-search__controls">
						<span className="log-entries-search__count newspack-nodes-status">
							{ matchedIndices.length === 0 &&
								__(
									'No matches',
									'newspack-event-logger-nodes'
								) }
							{ matchedIndices.length > 0 &&
								currentMatchIndex >= 0 &&
								`${ currentMatchIndex + 1 }/${
									matchedIndices.length
								}` }
							{ matchedIndices.length > 0 &&
								currentMatchIndex < 0 &&
								sprintf(
									// translators: %d: number of entries matching the search query.
									_n(
										'%d match',
										'%d matches',
										matchedIndices.length,
										'newspack-event-logger-nodes'
									),
									matchedIndices.length
								) }
						</span>
						<button
							type="button"
							className="button button-small log-entries-search__nav"
							onClick={ gotoPrev }
							disabled={ matchedIndices.length === 0 }
							title={ __(
								'Previous match (p)',
								'newspack-event-logger-nodes'
							) }
						>
							&#9650;
						</button>
						<button
							type="button"
							className="button button-small log-entries-search__nav"
							onClick={ gotoNext }
							disabled={ matchedIndices.length === 0 }
							title={ __(
								'Next match (n)',
								'newspack-event-logger-nodes'
							) }
						>
							&#9660;
						</button>
						<button
							type="button"
							className="button button-small log-entries-search__nav"
							onClick={ clearSearch }
							title={ __(
								'Clear search (Esc)',
								'newspack-event-logger-nodes'
							) }
						>
							&#10005;
						</button>
					</div>
				) }
			</div>
			<div>
				<table
					ref={ tableRef }
					className="newspack-nodes-table newspack-nodes-table--undivided"
				>
					<thead>
						<tr>
							<th style={ { width: '6px', padding: 0 } }></th>
							<th style={ { width: '45px' } }>#</th>
							<th style={ { width: '120px' } }>
								{ __( 'Time', 'newspack-event-logger-nodes' ) }
							</th>
							<th style={ { whiteSpace: 'nowrap' } }>
								{ __(
									'Keyword',
									'newspack-event-logger-nodes'
								) }
							</th>
							<th>
								{ __(
									'Message',
									'newspack-event-logger-nodes'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ visibleEntries.map( ( entry, idx ) => {
							const keyword = entry.k || '';
							const eventColor = getStateColor( keyword );
							return (
								<tr
									key={ idx }
									data-ask={
										undefined !== entry.n
											? `entry:${ entry.n }`
											: undefined
									}
									data-pair-id={
										hasPair( entry )
											? entry.pairId
											: undefined
									}
									data-entry-idx={
										entry.originalIdx !== undefined
											? entry.originalIdx
											: undefined
									}
									onClick={ ( e ) =>
										handleRowClick( entry, idx, e )
									}
									style={ getRowStyle( entry, idx ) }
								>
									<td
										onClick={ ( e ) =>
											handleSwatchClick( entry, idx, e )
										}
										style={ {
											width: '6px',
											padding: '4px 2px',
											background: hexToRgba(
												eventColor,
												0.45
											),
											cursor: hasPair( entry )
												? 'pointer'
												: undefined,
										} }
										title={
											hasPair( entry )
												? __(
														'Click to highlight pair',
														'newspack-event-logger-nodes'
												  )
												: undefined
										}
									/>
									<td>{ entry.n }</td>
									<td
										className="newspack-nodes-table__terminal-data"
										style={ {
											fontSize: '11px',
											whiteSpace: 'nowrap',
										} }
									>
										{ entry.isMerged
											? renderMergedTime( entry )
											: entry.displayTime }
									</td>
									<td
										className="newspack-nodes-table__terminal-data"
										style={ {
											fontSize: '12px',
											whiteSpace: 'nowrap',
											paddingLeft: `${
												8 + entry.indent * 16
											}px`,
										} }
									>
										{ renderKeyword( entry ) }
									</td>
									<td
										className="newspack-nodes-table__terminal-data"
										style={ {
											fontSize: '11px',
											whiteSpace: 'pre-wrap',
											wordBreak: 'break-all',
											paddingLeft: `${
												8 + entry.indent * 16
											}px`,
										} }
									>
										{ entry.isMerged
											? renderMergedMessage( entry )
											: renderEntryMessage( entry ) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
