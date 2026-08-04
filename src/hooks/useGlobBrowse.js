/**
 * useGlobBrowse — Kafka-UI-style browsing for a dashboard whose RemoteLink
 * subscribes a GLOB (`errors.*` / `completed.*`) across partitions. Shared by the
 * Error Log and Request Log trees so the wiring is written once.
 *
 * The browse model over a glob is a two-level pick:
 *   - selectedPartition '' → the glob, tailed live (positions=null). The
 *     sidebar shows the partition picker alone, with no segment rail.
 *   - selectedPartition 'errors.p3' → that ONE dir, with a segment rail and
 *     Live / Replay / per-segment seeks (via the substrate's useLogPositions).
 *
 * A sole partition auto-selects, because one dir already IS the whole live glob.
 *
 * Seeks ride the existing `positions` transport (keyed by partition DIRECTORY,
 * the same key SseIn tracks resume offsets under), so no new server verb is
 * needed. The substrate's `raw-logs` CI answers all three reads: `list_logs`
 * catalogs the concrete dirs, `log_status` one dir's segments, and
 * `read_message` the single record a paused Step or `jumpTo` displays. Each verb
 * gets its OWN `useRequestNode` node, so every reply lands on the node that
 * asked for it and nothing needs correlating.
 *
 * Repositioning is imperative and gated on `isActive`: while the stream is closed
 * (paused / hidden) a browse action only records the target in `browseTargetRef`;
 * the graph hook's `onConnect` re-applies it on the next connect. That ref is the
 * single source of truth the graph hook reads for first-connect + refocus.
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

// The substrate service CI that catalogs and reads the on-disk logs.
const RAW_LOGS = 'raw-logs';

// Segment-rail maintenance cadence (rotation + size growth).
const SEGMENTS_REFRESH_MS = 10000;

/**
 * The `positions` a (re)connect should subscribe with.
 *
 * An EXPLICIT seek — one recorded while the stream was closed — applies ONCE and
 * clears its own flag, so later reconnects resume the tail instead of re-running
 * a seek the user has already left behind. Every other reconnect resumes at the
 * last record SseIn saw; a first connect takes the recorded target as it stands.
 *
 * @param {Object}  target      `browseTargetRef.current`, the recorded target.
 * @param {Object}  link        The RemoteLink, for its `resumePositions()`.
 * @param {boolean} isReconnect True when reopening an already-seen stream.
 * @return {?Object} The positions to subscribe with; null tails.
 */
export function connectPositions( target, link, isReconnect ) {
	if ( target.explicit ) {
		target.explicit = false;
		return target.positions;
	}
	return isReconnect
		? link.resumePositions() ?? target.positions
		: target.positions;
}

/**
 * Build a control message the view's `fill()` routes on its `action`.
 *
 * @param {Object} value The control payload; `action` picks the view's verb.
 * @return {Array} The 7-field TM_STRUCT message.
 */
function viewControl( value ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = value;
	return m;
}

/**
 * @param {Object}        o                 Hook options.
 * @param {string}        o.glob            The subscription glob (e.g.
 *                                          `errors.*`).
 * @param {string}        o.linkName        RemoteLink node name; it takes the
 *                                          seeks and holds the resume cursor.
 * @param {string}        o.viewName        View node name, and the prefix this
 *                                          hook names its own Request + Timer
 *                                          nodes with.
 * @param {boolean}       o.isActive        Whether the stream is open right
 *                                          now.
 * @param {Object}        o.browseTargetRef `{ current: { subscribe, positions,
 *                                          explicit } }` the graph hook reads
 *                                          on (re)connect.
 * @param {Object}        [o.setPausedRef]  `{ current: setPaused }`; a
 *                                          time-travel action pauses the
 *                                          stream through it.
 * @param {() => boolean} [o.isActiveNow]   Same-tick `isActive`, for a click
 *                                          that pauses AND seeks in one tick.
 *                                          `step` treats its absence as "not
 *                                          paused", so a caller that omits it
 *                                          can step an open stream.
 * @return {{ partitions: Object[], selectedPartition: string,
 *   selectPartition: (key: string) => void, segments: Object[], mode: string,
 *   lastReceivedSegment: ?number, segmentId: (number|string|null),
 *   follow: () => void, replay: () => void,
 *   browseSegment: (segment: Object) => void,
 *   step: () => (Promise<void>|undefined),
 *   jumpTo: (position: Object) => (Promise<void>|undefined) }}
 *   Browse state + actions for `SegmentBrowseSidebar`. `partitions` are the
 *   `list_logs` rows (`{ key, label, … }`) narrowed to the glob; `mode` and
 *   `lastReceivedSegment` are read off the view model, not held here.
 *   `segmentId` is the CLICKED segment straight out of `useLogPositions`, so it
 *   is not always a segment: Replay sets the literal `'start'` token and Live
 *   sets null. The sidebar hands it to `LogBrowser` as `selectedKey`, which
 *   compares against numeric segment ids — so neither of those highlights a row.
 */
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

	// Latest selection / active flag / rail, readable from any async handler.
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

	/**
	 * Send one `raw-logs` verb through the Request node that owns it.
	 *
	 * @param {string}   name The verb: list_logs, log_status, or read_message.
	 * @param {string[]} args The verb's token array.
	 * @return {Promise<*>} The reply payload.
	 */
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

	/**
	 * Write a `log_status` reply into the rail, if the rail still wants it.
	 *
	 * The guard keys on the partition the reply BELONGS to (reusing
	 * `selectedRef` above) rather than a shared cancelled flag: React runs the
	 * old cleanup and then the new effect body, so one boolean is un-set by the
	 * very re-run that should cancel it, and a slow reply repopulates the rail
	 * for a partition the user already left. Both segment writers below go
	 * through here.
	 *
	 * @param {string}  forPartition The dir the request was made for.
	 * @param {?Object} status       The `log_status` reply, if one arrived.
	 */
	const applySegments = useCallback( ( forPartition, status ) => {
		if ( selectedRef.current === forPartition ) {
			setSegments( status?.segments ?? [] );
		}
	}, [] );

	// Refetch the selected dir's rail; a no-op with the glob selected.
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

	/**
	 * Record the browse target, and move the live stream only when it is open.
	 *
	 * A seek taken while the stream is closed records as EXPLICIT, which
	 * `connectPositions` honors once on the next connect and then clears — so
	 * later reconnects resume the tail rather than re-running the old seek.
	 *
	 * @param {string[]} subscribe The subscription: one dir, or the whole glob.
	 * @param {?Object}  positions The seek, or null to tail.
	 */
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

	/**
	 * Advance one record while paused, over the command channel.
	 *
	 * The stream stays OFFLINE. `read_message` returns the single record at the
	 * pending cursor, server-stamped by the real read model, and the view admits
	 * it through its paused belt on a one-frame step budget. The recorded target
	 * then advances to the post-step cursor, so the next Step continues from
	 * there and Play resumes streaming from there.
	 *
	 * The paused gate is `isActiveNow`: a caller that omits it can step a stream
	 * that is still open.
	 *
	 * @return {Promise<void>|undefined} Undefined when there is nothing to step.
	 */
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
