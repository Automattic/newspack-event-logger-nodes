/* global requestAnimationFrame */
/**
 * Log Entries Table Component
 *
 * Displays log entries with indentation, collapsible start/complete pairs,
 * and click-to-highlight for pair matching.
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
	getAncestorPairIds,
} from '../utils/logEntryUtils';

const TIME_FORMAT_OPTIONS = { hour12: false };

/**
 * Format a timestamp to HH:MM:SS.TH (2 decimal places, 10ms precision).
 *
 * @param {number} ts Unix timestamp.
 * @return {string} Formatted time string, or empty if no ts.
 */
const formatTimestamp = ( ts ) => {
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
 * Check if a keyword represents a start or complete entry.
 *
 * @param {string} keyword Log entry keyword.
 * @return {boolean} True if keyword contains (start) or (complete).
 */
const isStartOrComplete = ( keyword ) =>
	keyword.includes( '(start)' ) || keyword.includes( '(complete)' );

/**
 * Collect all descendant pairIds between a start entry and its matching complete.
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
			e.pairId !== null &&
			e.pairId !== undefined &&
			e.pairId !== pairId &&
			( e.k || '' ).match( /\(start\)$/ )
		) {
			// Skip empty pairs (start immediately followed by complete).
			const next = entries[ i + 1 ];
			if (
				next &&
				next.pairId === e.pairId &&
				( next.k || '' ).includes( '(complete)' )
			) {
				continue;
			}
			ids.push( e.pairId );
		}
	}
	return ids;
};

/**
 * Active highlight state — tracks the currently highlighted cells and the
 * clear timer so navigating to a new match immediately clears the old one.
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
 * @param {Object} props           Component props.
 * @param {Array}  props.entries   Array of indented log entries (from computeIndentedEntries).
 * @param {number} props.realCount Count of real (non-placeholder) entries.
 * @param {Object} props.revealRef Ref to expose revealPath function for flame graph integration.
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

	// Recompute matches when search query changes (debounced).
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

				if ( keyword.includes( query ) || message.includes( query ) ) {
					matches.push( i );
				}
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

	// Collect pairIds with content; empty start/complete pairs aren't unfoldable.
	const allPairIds = useMemo( () => {
		const ids = new Set();
		for ( let i = 0; i < entries.length; i++ ) {
			const entry = entries[ i ];
			if (
				entry.pairId !== null &&
				entry.pairId !== undefined &&
				( entry.k || '' ).match( /\(start\)$/ ) &&
				! ( entry.k || '' ).startsWith( 'process ' )
			) {
				// Check if next entry is the matching complete (nothing between).
				const next = entries[ i + 1 ];
				if (
					next &&
					next.pairId === entry.pairId &&
					( next.k || '' ).includes( '(complete)' )
				) {
					continue; // Empty pair — skip.
				}
				ids.add( entry.pairId );
			}
		}
		return ids;
	}, [ entries ] );

	// Navigate to a specific match index: unfold ancestors, scroll, highlight.
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

			// Only expand pairs with children; an empty pair stays a merged row.
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

			// Match on an empty pair: scroll by pairId (its row carries the start idx).
			const matchEntry = entries[ entryIdx ];
			const ownPairId = matchEntry?.pairId;
			const keyword = matchEntry?.k || '';
			let staysFolded = false;
			if ( ownPairId !== null && ownPairId !== undefined ) {
				if ( keyword.includes( '(start)' ) ) {
					const next = entries[ entryIdx + 1 ];
					staysFolded =
						!! next &&
						next.pairId === ownPairId &&
						( next.k || '' ).includes( '(complete)' );
				} else if ( keyword.includes( '(complete)' ) ) {
					const prev = entries[ entryIdx - 1 ];
					staysFolded =
						!! prev &&
						prev.pairId === ownPairId &&
						( prev.k || '' ).includes( '(start)' );
				}
			}
			scrollToAndHighlight(
				tableRef,
				staysFolded ? { pairId: ownPairId } : { entryIdx }
			);
		},
		[ matchedIndices, entries, expandedSet, allPairIds ]
	);

	// Clear search and restore pre-search fold state.
	const clearSearch = useCallback( () => {
		setSearchQuery( '' );
		setMatchedIndices( [] );
		setCurrentMatchIndex( -1 );
		if ( preSearchExpandedRef.current !== null ) {
			setExpandedSet( preSearchExpandedRef.current );
			preSearchExpandedRef.current = null;
		}
	}, [] );

	// Keyboard navigation: n = next, p = previous, Escape = clear.
	useEffect( () => {
		const handleKeyDown = ( e ) => {
			// Escape: clear search, refocus so the next Escape closes the modal.
			if ( e.key === 'Escape' && searchQuery ) {
				e.preventDefault();
				e.stopPropagation();
				clearSearch();
				const modal = document.querySelector(
					'.components-modal__frame'
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

			// Skip n/p while typing in an input (search uses Enter to navigate).
			if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
				return;
			}

			if ( ! searchQuery || matchedIndices.length === 0 ) {
				return;
			}

			if ( e.key === 'n' && ! e.shiftKey ) {
				e.preventDefault();
				if ( currentMatchIndex < 0 ) {
					navigateToMatch( 0 );
				} else {
					navigateToMatch(
						currentMatchIndex + 1 >= matchedIndices.length
							? 0
							: currentMatchIndex + 1
					);
				}
			} else if ( e.key === 'p' || e.key === 'N' ) {
				e.preventDefault();
				if ( currentMatchIndex < 0 ) {
					navigateToMatch( matchedIndices.length - 1 );
				} else {
					navigateToMatch(
						currentMatchIndex - 1 < 0
							? matchedIndices.length - 1
							: currentMatchIndex - 1
					);
				}
			}
		};

		document.addEventListener( 'keydown', handleKeyDown, true );
		return () =>
			document.removeEventListener( 'keydown', handleKeyDown, true );
	}, [
		searchQuery,
		matchedIndices,
		currentMatchIndex,
		navigateToMatch,
		clearSearch,
	] );

	// Compute visible entries from fold state.
	const visibleEntries = useMemo(
		() => computeVisibleEntries( entries, expandedSet ),
		[ entries, expandedSet ]
	);

	// Build path→pairId map (flame "name: message" keys, base-name fallback).
	const pathToPairId = useMemo( () => {
		const map = {};
		const stack = [];

		for ( const entry of entries ) {
			const keyword = entry.k || '';
			const startMatch = keyword.match( /^(.+?) \(start\)$/ );
			const completeMatch = keyword.match( /^(.+?) \(complete\)$/ );

			if (
				startMatch &&
				entry.pairId !== null &&
				entry.pairId !== undefined
			) {
				const name = startMatch[ 1 ];
				const msg =
					typeof entry.m === 'string' && entry.m ? entry.m : '';
				const detail = msg ? `${ name }: ${ msg }` : name;
				stack.push( { name, detail } );
				// Detail path for precise matching.
				const detailKey = stack.map( ( s ) => s.detail ).join( '/' );
				map[ detailKey ] = entry.pairId;
				// Base-name path as fallback (first occurrence wins).
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

	// Reveal a specific entry by path: expand ancestors, scroll into view.
	const revealPath = useCallback(
		( path ) => {
			// Flame graph paths have an extra "request" root — strip it.
			let cleanPath = path;
			if ( cleanPath[ 0 ] === 'request' ) {
				cleanPath = cleanPath.slice( 1 );
			}

			// Try the detail path ("name: message"), fall back to the base-name path.
			const detailKey = cleanPath.join( '/' );
			const baseKey = cleanPath
				.map( ( seg ) => seg.replace( /: .+$/, '' ) )
				.join( '/' );
			const targetPairId =
				pathToPairId[ detailKey ] ?? pathToPairId[ baseKey ];
			if ( targetPairId === undefined ) {
				return;
			}

			// Find the target entry's index in the full entries array.
			const targetIdx = entries.findIndex(
				( e ) =>
					e.pairId === targetPairId &&
					( e.k || '' ).includes( '(start)' )
			);

			// Compute ancestors using shared helper.
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

	// Expose revealPath via ref so parent components can call it.
	useEffect( () => {
		if ( revealRef ) {
			revealRef.current = revealPath;
		}
	}, [ revealRef, revealPath ] );

	/**
	 * Toggle fold for a single pair. Folding also removes all descendant pairIds.
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

	const foldAll = useCallback( () => {
		setExpandedSet( new Set() );
	}, [] );

	const unfoldAll = useCallback( () => {
		setExpandedSet( new Set( allPairIds ) );
	}, [ allPairIds ] );

	/**
	 * Find matching start/complete pair range in the visible entries for highlighting.
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
	 * Handle swatch click for pair highlighting.
	 *
	 * @param {Object} entry The entry.
	 * @param {number} idx   Index in visibleEntries.
	 * @param {Event}  event Click event.
	 */
	const handleSwatchClick = useCallback(
		( entry, idx, event ) => {
			event.stopPropagation();
			const keyword = entry.k || '';
			if (
				isStartOrComplete( keyword ) ||
				entry.isMerged ||
				( entry.pairId !== null && entry.pairId !== undefined )
			) {
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
			}
		},
		[ findPairRange, highlightRange ]
	);

	/**
	 * Handle row click for fold/unfold.
	 *
	 * @param {Object} entry Visible entry.
	 * @param {number} idx   Index in visibleEntries.
	 * @param {Event}  event Click event.
	 */
	const handleRowClick = useCallback(
		( entry, idx, event ) => {
			if ( entry.isMerged ) {
				// Find index of this entry's start in the full entries array.
				const fullIdx = entries.findIndex(
					( e ) =>
						e.pairId === entry.pairId &&
						( e.k || '' ).includes( '(start)' )
				);
				if ( fullIdx >= 0 ) {
					toggleFold(
						entry.pairId,
						fullIdx,
						event.metaKey || event.ctrlKey
					);
				}
				return;
			}

			const keyword = entry.k || '';
			if (
				keyword.includes( '(start)' ) &&
				entry.pairId !== null &&
				entry.pairId !== undefined
			) {
				const baseName = keyword.replace( / \(start\)$/, '' );
				if ( baseName === 'process' ) {
					return; // Outermost pair not foldable.
				}
				const fullIdx = entries.findIndex(
					( e ) =>
						e.pairId === entry.pairId &&
						( e.k || '' ).includes( '(start)' )
				);
				if ( fullIdx >= 0 ) {
					toggleFold(
						entry.pairId,
						fullIdx,
						event.metaKey || event.ctrlKey
					);
				}
			}
		},
		[ entries, toggleFold ]
	);

	/**
	 * Get row style based on highlight state and entry type.
	 *
	 * @param {Object} entry Entry object.
	 * @param {number} idx   Index in visibleEntries.
	 * @return {Object} Style object.
	 */
	const getRowStyle = useCallback(
		( entry, idx ) => {
			const keyword = entry.k || '';
			const isFoldable =
				entry.isMerged ||
				( keyword.includes( '(start)' ) &&
					! keyword.startsWith( 'process (start)' ) );
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
		// Suppress bare '-' for merged/complete rows and duration-stat entries.
		const hasDuration =
			entry.duration_ms !== null && entry.duration_ms !== undefined;
		if (
			! msg &&
			( entry.isMerged ||
				( entry.k || '' ).includes( '(complete)' ) ||
				hasDuration )
		) {
			return '';
		}
		if (
			msg === '-' &&
			( entry.isMerged ||
				( entry.k || '' ).includes( '(complete)' ) ||
				hasDuration )
		) {
			return '';
		}
		return msg || '-';
	};

	/**
	 * Render the keyword cell content.
	 *
	 * @param {Object} entry Entry object.
	 * @return {import('react').ReactElement} Keyword content.
	 */
	const renderKeyword = ( entry ) => {
		if ( entry.isPlaceholder ) {
			return '';
		}

		const keyword = entry.k || '';

		if ( entry.isMerged ) {
			return (
				<>
					<span
						style={ {
							color: 'var(--cyan, #003da5)',
							fontSize: '10px',
							marginRight: '4px',
						} }
					>
						&#9654;
					</span>
					{ entry.k }
				</>
			);
		}

		if (
			keyword.includes( '(start)' ) &&
			! keyword.startsWith( 'process (start)' )
		) {
			return (
				<>
					<span
						style={ {
							color: 'var(--cyan, #003da5)',
							fontSize: '10px',
							marginRight: '4px',
						} }
					>
						&#9660;
					</span>
					{ keyword }
				</>
			);
		}

		return keyword || '-';
	};

	/**
	 * Render duration + peak_mb stats line.
	 *
	 * @param {Object} entry Entry with duration_ms and peak_mb.
	 * @return {import('react').ReactElement|null} Stats span or null.
	 */
	const renderStats = ( entry ) => {
		const hasDuration =
			entry.duration_ms !== null && entry.duration_ms !== undefined;
		const hasPeak = entry.peak_mb > 0;
		if ( ! hasDuration && ! hasPeak ) {
			return null;
		}
		return (
			<span style={ { color: '#666' } }>
				{ hasDuration && `(${ entry.duration_ms.toFixed( 3 ) }ms)` }
				{ hasPeak && (
					<span style={ { color: '#996600', marginLeft: '6px' } }>
						[{ entry.peak_mb }MB]
					</span>
				) }
			</span>
		);
	};

	/**
	 * Render message cell for merged (collapsed) rows.
	 * Shows start message + complete message, then stats on new line.
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
				style={ {
					color: '#888',
					fontSize: '11px',
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
	 * Complete entries show duration/peak on a new line after content.
	 *
	 * @param {Object} entry Entry object.
	 * @return {import('react').ReactElement} Message content.
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
				<div style={ { fontSize: '13px' } }>
					<button
						type="button"
						onClick={ foldAll }
						style={ {
							background: 'none',
							border: 'none',
							color: 'var(--cyan, #003da5)',
							cursor: 'pointer',
							fontSize: '13px',
							marginRight: '12px',
							padding: 0,
						} }
					>
						&minus;{ ' ' }
						{ __( 'Fold All', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						onClick={ unfoldAll }
						style={ {
							background: 'none',
							border: 'none',
							color: 'var(--cyan, #003da5)',
							cursor: 'pointer',
							fontSize: '13px',
							padding: 0,
						} }
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
								e.target.blur();
								if ( currentMatchIndex < 0 ) {
									navigateToMatch( 0 );
									return;
								}
								let next;
								if ( e.shiftKey ) {
									next =
										currentMatchIndex - 1 < 0
											? matchedIndices.length - 1
											: currentMatchIndex - 1;
								} else {
									next =
										currentMatchIndex + 1 >=
										matchedIndices.length
											? 0
											: currentMatchIndex + 1;
								}
								navigateToMatch( next );
							}
						} }
						__nextHasNoMarginBottom
					/>
				</div>
				{ searchQuery && (
					<div className="log-entries-search__controls">
						<span className="log-entries-search__count">
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
							className="log-entries-search__nav"
							onClick={ () => {
								if ( currentMatchIndex < 0 ) {
									navigateToMatch( 0 );
									return;
								}
								const prev =
									currentMatchIndex - 1 < 0
										? matchedIndices.length - 1
										: currentMatchIndex - 1;
								navigateToMatch( prev );
							} }
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
							className="log-entries-search__nav"
							onClick={ () => {
								if ( currentMatchIndex < 0 ) {
									navigateToMatch( 0 );
									return;
								}
								const next =
									currentMatchIndex + 1 >=
									matchedIndices.length
										? 0
										: currentMatchIndex + 1;
								navigateToMatch( next );
							} }
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
							className="log-entries-search__nav"
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
				<table ref={ tableRef } className="widefat striped">
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
									data-pair-id={
										entry.pairId !== null &&
										entry.pairId !== undefined
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
											cursor:
												entry.pairId !== null &&
												entry.pairId !== undefined
													? 'pointer'
													: undefined,
										} }
										title={
											entry.pairId !== null &&
											entry.pairId !== undefined
												? __(
														'Click to highlight pair',
														'newspack-event-logger-nodes'
												  )
												: undefined
										}
									/>
									<td>{ entry.n }</td>
									<td
										style={ {
											fontFamily: 'monospace',
											fontSize: '11px',
											whiteSpace: 'nowrap',
										} }
									>
										{ entry.isMerged
											? ( () => {
													const startTime =
														formatTimestamp(
															entry.startTs
														);
													const endTime =
														formatTimestamp(
															entry.ts
														);
													// Different tenths: dual timestamps.
													const startTenth =
														Math.floor(
															Math.round(
																( entry.startTs ||
																	0 ) * 100
															) / 10
														);
													const endTenth = Math.floor(
														Math.round(
															( entry.ts || 0 ) *
																100
														) / 10
													);
													if (
														startTime &&
														endTime &&
														startTenth !== endTenth
													) {
														return (
															<>
																{ startTime }
																<br />
																{ endTime }
															</>
														);
													}
													// Same tenth: show bullets for 10ms boundaries crossed.
													const startH = Math.round(
														( entry.startTs || 0 ) *
															100
													);
													const endH = Math.round(
														( entry.ts || 0 ) * 100
													);
													const dots = endH - startH;
													if ( dots > 9 ) {
														return (
															<>
																{ formatTimestamp(
																	entry.startTs
																) }
																<br />
																{ formatTimestamp(
																	entry.ts
																) }
															</>
														);
													}
													if ( dots > 0 ) {
														return formatDots(
															dots
														);
													}
													return entry.displayTime;
											  } )()
											: entry.displayTime }
									</td>
									<td
										style={ {
											fontFamily: 'monospace',
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
										style={ {
											fontFamily: 'monospace',
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
