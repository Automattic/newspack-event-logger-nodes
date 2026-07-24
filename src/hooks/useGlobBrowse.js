/**
 * useGlobBrowse — Kafka-UI-style browsing for a dashboard whose RemoteLink
 * subscribes a GLOB (`errors.*` / `completed.*`) across partitions. Shared by the
 * Error Log and Request Log trees so the wiring is written once.
 *
 * The browse model over a glob is a two-level pick:
 *   - selectedPartition '' → the glob, tailed live (positions=null) — today's
 *     byte-identical default; the caller renders no sidebar in this state.
 *   - selectedPartition 'errors.p3' → that ONE dir, with a segment sidebar and
 *     Live / Replay / per-segment seeks (via the substrate's useLogPositions).
 *
 * Seeks ride the existing `positions` transport (keyed by partition DIRECTORY,
 * the same key SseIn tracks resume offsets under), so no new server verb is
 * needed: `list_logs` catalogs the concrete dirs, `log_status` their segments —
 * both reached through the RemoteLink's own HttpOut (`link.send`) and settled via
 * the view node's PendingReplies, the established ELN verb path.
 *
 * Repositioning is imperative and gated on `isActive`: while the stream is closed
 * (paused / hidden) a browse action only records the target in `browseTargetRef`;
 * the graph hook's `onConnect` re-applies it on the next connect. That ref is the
 * single source of truth the graph hook reads for first-connect + refocus.
 *
 * @param {Object}  o
 * @param {string}  o.glob            The subscription glob (e.g. `errors.*`).
 * @param {string}  o.linkName        RemoteLink node name (HttpOut + SSE seek).
 * @param {string}  o.viewName        View node name (owns the reply PendingReplies).
 * @param {boolean} o.isActive        Whether the stream is open right now.
 * @param {Object}  o.browseTargetRef `{ current: { subscribe, positions } }` the
 *                                    graph hook reads on (re)connect.
 * @return {Object} Browse state + actions for the LogBrowser + partition select.
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import {
	Core,
	useNodeState,
	newMessage,
	TYPE,
	FROM,
	TO,
	ID,
	VALUE,
	TM_COMMAND,
	TM_STRUCT,
} from '@newspack-nodes/runtime';
import useLogPositions, {
	segmentPositions,
	replayPositions,
} from '@newspack-nodes/shared/hooks/useLogPositions';
import { endPosition } from '@newspack-nodes/shared/nodes/seekTracker';

// The substrate service CI that catalogs on-disk logs + segments.
const RAW_LOGS = 'raw-logs';

// Monotonic op-id correlating a catalog reply to its pending Promise.
let nextOpId = 0;
function makeOpId() {
	nextOpId += 1;
	return `globbrowse-op-${ Date.now() }-${ nextOpId }`;
}

// A raw-logs verb command; reply routes back to FROM (the view's replies).
function catalogCommand( id, from, name, args ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND;
	m[ FROM ] = from;
	m[ TO ] = RAW_LOGS;
	m[ ID ] = id;
	m[ VALUE ] = { name, arguments: args };
	return m;
}

// A TM_STRUCT control message routed by the view's fill() on its `action`.
function viewControl( value ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
}

export default function useGlobBrowse( {
	glob,
	linkName,
	viewName,
	isActive,
	browseTargetRef,
} ) {
	const globPrefix = glob.endsWith( '*' ) ? glob.slice( 0, -1 ) : glob;

	const [ partitions, setPartitions ] = useState( [] );
	const [ selectedPartition, setSelectedPartition ] = useState( '' );
	const [ segments, setSegments ] = useState( [] );

	// Read the latest selection + active flag + segments from the handlers.
	const selectedRef = useRef( selectedPartition );
	selectedRef.current = selectedPartition;
	const isActiveRef = useRef( isActive );
	isActiveRef.current = isActive;
	const segmentsRef = useRef( segments );
	segmentsRef.current = segments;

	// View-derived seek model (mode + received segment), NOT the click state.
	const viewModel = useNodeState( viewName, 'view' );

	// The CLICKED segment (LogBrowser selectedKey) tracked via useLogPositions.
	const {
		segmentId,
		follow: lpFollow,
		browseSegment: lpBrowse,
		replay: lpReplay,
	} = useLogPositions( selectedPartition );

	// Enter replay on the view, carrying the captured boundary for catch-up.
	const browseView = useCallback( () => {
		const end = endPosition( segmentsRef.current );
		Core.node( viewName )?.fill(
			viewControl( {
				action: 'browse',
				endSegment: end?.segment ?? null,
				endOffset: end?.offset ?? 0,
			} )
		);
	}, [ viewName ] );

	// Fire a catalog verb through the link's HttpOut; settle via view.replies.
	const fetchCatalog = useCallback(
		( name, args ) => {
			const link = Core.node( linkName );
			const view = Core.node( viewName );
			if ( ! link || ! view || ! view.replies ) {
				return Promise.reject( new Error( 'graph not ready' ) );
			}
			const id = makeOpId();
			const promise = new Promise( ( resolve, reject ) =>
				view.replies.add( id, resolve, reject )
			);
			link.send( catalogCommand( id, viewName, name, args ) );
			return promise;
		},
		[ linkName, viewName ]
	);

	// One-shot list_logs → the glob's concrete partition dirs.
	useEffect( () => {
		let cancelled = false;
		fetchCatalog( 'list_logs', [] )
			.then( ( logs ) => {
				if ( cancelled || ! Array.isArray( logs ) ) {
					return;
				}
				setPartitions(
					logs.filter(
						( l ) =>
							'string' === typeof l?.key &&
							l.key.startsWith( globPrefix )
					)
				);
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [ fetchCatalog, globPrefix ] );

	// The selected partition's segment catalog (log_status); '' clears it.
	useEffect( () => {
		if ( ! selectedPartition ) {
			setSegments( [] );
			return undefined;
		}
		let cancelled = false;
		fetchCatalog( 'log_status', [ selectedPartition ] )
			.then( ( status ) => {
				if ( ! cancelled ) {
					setSegments( status?.segments ?? [] );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setSegments( [] );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ selectedPartition, fetchCatalog ] );

	// Record the browse target; reposition the live stream only when active.
	const reposition = useCallback(
		( subscribe, positions ) => {
			browseTargetRef.current = { subscribe, positions };
			if ( isActiveRef.current ) {
				Core.node( linkName )?.setSubscribe( subscribe, positions );
			}
		},
		[ browseTargetRef, linkName ]
	);

	// Switch partition: reset+arm the view's seek (dir), or widen to glob ('').
	const selectPartition = useCallback(
		( key ) => {
			setSelectedPartition( key );
			Core.node( viewName )?.fill(
				viewControl( { action: 'select', dir: key } )
			);
			reposition( key ? [ key ] : [ glob ], null );
		},
		[ glob, viewName, reposition ]
	);

	// A sole partition auto-selects: one dir IS the whole live glob.
	const selectPartitionRef = useRef( selectPartition );
	selectPartitionRef.current = selectPartition;
	useEffect( () => {
		if ( 1 === partitions.length && '' === selectedRef.current ) {
			selectPartitionRef.current( partitions[ 0 ].key );
		}
	}, [ partitions ] );

	// Live / Replay / segment seeks — only within an explicitly selected dir.
	const follow = useCallback( () => {
		const sel = selectedRef.current;
		if ( ! sel ) {
			return;
		}
		lpFollow();
		Core.node( viewName )?.fill( viewControl( { action: 'follow' } ) );
		reposition( [ sel ], null );
	}, [ lpFollow, reposition, viewName ] );

	const replay = useCallback( () => {
		const sel = selectedRef.current;
		if ( ! sel ) {
			return;
		}
		lpReplay();
		browseView();
		reposition( [ sel ], replayPositions( sel ) );
	}, [ lpReplay, reposition, browseView ] );

	const browseSegment = useCallback(
		( segment ) => {
			const sel = selectedRef.current;
			if ( ! sel ) {
				return;
			}
			lpBrowse( segment.id );
			browseView();
			reposition( [ sel ], segmentPositions( sel, segment.id ) );
		},
		[ lpBrowse, reposition, browseView ]
	);

	return {
		partitions,
		selectedPartition,
		selectPartition,
		segments,
		mode: viewModel?.mode ?? 'live',
		lastReceivedSegment: viewModel?.lastReceivedSegment ?? null,
		segmentId,
		follow,
		replay,
		browseSegment,
	};
}
