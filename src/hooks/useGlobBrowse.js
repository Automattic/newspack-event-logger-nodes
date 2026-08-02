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
 *
 * Repositioning is imperative and gated on `isActive`: while the stream is closed
 * (paused / hidden) a browse action only records the target in `browseTargetRef`;
 * the graph hook's `onConnect` re-applies it on the next connect. That ref is the
 * single source of truth the graph hook reads for first-connect + refocus.
 *
 * @param {Object}  o
 * @param {string}  o.glob            The subscription glob (e.g. `errors.*`).
 * @param {string}  o.linkName        RemoteLink node name (HttpOut + SSE seek).
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
	VALUE,
	TM_STRUCT,
} from '@newspack-nodes/runtime';

import useLogPositions, {
	segmentPositions,
	replayPositions,
	stepPosition,
} from '@newspack-nodes/shared/hooks/useLogPositions';
import { endPosition } from '@newspack-nodes/shared/nodes/seekTracker';
import useRouterTick from '@newspack-nodes/shared/hooks/useRouterTick';
import useRequestNode from '@newspack-nodes/shared/hooks/useRequestNode';

// The substrate service CI that catalogs on-disk logs + segments.
const RAW_LOGS = 'raw-logs';

// Segment-rail maintenance cadence (rotation + size growth).
const SEGMENTS_REFRESH_MS = 10000;

// Reconnect positions: an explicit seek applies ONCE; else resume the tail.
export function connectPositions( target, link, isReconnect ) {
	if ( target.explicit ) {
		target.explicit = false;
		return target.positions;
	}
	return isReconnect
		? link.resumePositions() ?? target.positions
		: target.positions;
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
	setPausedRef,
	isActiveNow,
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

	// A node per catalog verb; each reply lands on the one that asked.
	const listNode = useRequestNode( `${ viewName }:list`, RAW_LOGS );
	const statusNode = useRequestNode( `${ viewName }:status`, RAW_LOGS );
	const readNode = useRequestNode( `${ viewName }:read`, RAW_LOGS );

	const fetchCatalog = useCallback(
		( name, args ) => {
			const request = {
				list_logs: listNode,
				log_status: statusNode,
				read_message: readNode,
			}[ name ];
			if ( ! request ) {
				return Promise.reject(
					new Error( `no request node for ${ name }` )
				);
			}
			return request( name, args );
		},
		[ listNode, statusNode, readNode ]
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

	// @longform
	// The selected partition's segment catalog (log_status); '' clears it.
	// Keyed on the partition the reply BELONGS to (reusing selectedRef above),
	// not a shared cancelled flag: React runs the old cleanup then the new
	// effect body, so one boolean is un-set by the very re-run it should
	// cancel, and a slow reply repopulates the rail for a partition the user
	// already left. Both segment writers below go through this.
	const applySegments = useCallback( ( forPartition, status ) => {
		if ( selectedRef.current === forPartition ) {
			setSegments( status?.segments ?? [] );
		}
	}, [] );

	const refreshSegments = useCallback( () => {
		const forPartition = selectedPartition;
		if ( ! forPartition ) {
			return;
		}
		fetchCatalog( 'log_status', [ forPartition ] )
			.then( ( status ) => applySegments( forPartition, status ) )
			.catch( () => {} );
	}, [ selectedPartition, fetchCatalog, applySegments ] );

	useEffect( () => {
		if ( ! selectedPartition ) {
			setSegments( [] );
			return;
		}
		refreshSegments();
	}, [ selectedPartition, refreshSegments ] );

	// Maintain the rail: rotation and size growth while streaming.
	useRouterTick( {
		name: `${ viewName }:segments`,
		onTick: refreshSegments,
		intervalMs: SEGMENTS_REFRESH_MS,
		enabled: Boolean( selectedPartition ),
	} );

	// A record from an unknown segment = rotation; refetch once (no loops).
	const staleSegmentRef = useRef( null );
	const lastReceivedSegment = viewModel?.lastReceivedSegment ?? null;
	useEffect( () => {
		if (
			! selectedPartition ||
			null === lastReceivedSegment ||
			staleSegmentRef.current === lastReceivedSegment ||
			0 === segments.length ||
			segments.some( ( s ) => s.id === lastReceivedSegment )
		) {
			return;
		}
		staleSegmentRef.current = lastReceivedSegment;
		const forPartition = selectedPartition;
		fetchCatalog( 'log_status', [ forPartition ] )
			.then( ( status ) => applySegments( forPartition, status ) )
			.catch( () => {} );
	}, [
		lastReceivedSegment,
		segments,
		selectedPartition,
		fetchCatalog,
		applySegments,
	] );

	// @longform Record the browse target; reposition the live stream only
	// when active. A PAUSED-time seek records as EXPLICIT and reconnect
	// applies it once (connectPositions); a live delivery consumes it, so
	// later reconnects resume the tail instead of re-running the old seek.
	const reposition = useCallback(
		( subscribe, positions ) => {
			const active = isActiveNow ? isActiveNow() : isActiveRef.current;
			browseTargetRef.current = {
				subscribe,
				positions,
				explicit: null !== positions && ! active,
			};
			if ( active ) {
				Core.node( linkName )?.setSubscribe( subscribe, positions );
			}
		},
		[ browseTargetRef, linkName, isActiveNow ]
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
			// Time-travel: a past segment pauses; Step walks it, Play streams.
			setPausedRef?.current?.( true );
			lpBrowse( segment.id );
			browseView();
			reposition( [ sel ], segmentPositions( sel, segment.id ) );
		},
		[ lpBrowse, reposition, browseView, setPausedRef ]
	);

	// @longform Paused-only single-step: the stream stays OFFLINE; one record
	// is fetched over the command channel (the raw-logs read_message verb,
	// server-stamped by the real read model), admitted through the view's
	// paused belt, and the recorded target advances to the post-step cursor
	// so the next step continues from there and Play resumes streaming there.
	const step = useCallback( () => {
		const sel = selectedRef.current;
		const link = Core.node( linkName );
		if ( ! sel || ! link || ( isActiveNow && isActiveNow() ) ) {
			return undefined;
		}
		const position = stepPosition(
			link,
			sel,
			browseTargetRef.current.positions
		);
		if ( null === position ) {
			return undefined;
		}
		return fetchCatalog( 'read_message', [ sel, position ] )
			.then( ( result ) => {
				const view = Core.node( viewName );
				if ( ! result?.message || ! view ) {
					return;
				}
				view.fill( viewControl( { action: 'step', frames: 1 } ) );
				view.fill( result.message );
				browseTargetRef.current = {
					subscribe: [ sel ],
					positions: { [ sel ]: { ...result.cursor } },
					explicit: true,
				};
			} )
			.catch( () => {} );
	}, [ linkName, viewName, fetchCatalog, browseTargetRef, isActiveNow ] );

	// Offset jump: pause, seek the pasted position, and step that message.
	const jumpTo = useCallback(
		( position ) => {
			const sel = selectedRef.current;
			if ( ! sel ) {
				return undefined;
			}
			setPausedRef?.current?.( true );
			lpBrowse( position.segment );
			browseView();
			reposition( [ sel ], { [ sel ]: position } );
			return step();
		},
		[ lpBrowse, browseView, reposition, step, setPausedRef ]
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
		step,
		jumpTo,
	};
}
