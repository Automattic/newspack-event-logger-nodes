/**
 * useRequestLogGraph — mounts the Request Log dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using a SINGLE
 * substrate `RemoteLink` node instead of hand-wiring three I/O boundary nodes:
 *
 *   requestlog:link        (RemoteLink — composes + registers one child,
 *                          `requestlog:link:sse-in` (SseIn — EventSource ingress,
 *                          ctor token `[ 'completed.*' ]`; restUrl/nonce from the global),
 *                          and shares the backbone singletons `_http` (POST /command)
 *                          and `_heartbeat` (slot keep-alive), wiring the
 *                          `connected → slot` bridge between them, and defaulting
 *                          its own transport.)
 *
 * Plus the single view node:
 *
 *   requestlog:view        (the view-model node the React view reads)
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`.
 * The graph is a direct sse-in → requestlog:view. The view's `shapeRow()` does
 * the defensive shaping (drop missing-url, clip url@2000 + UA@500, default-fill) —
 * the single place that knows envelope → render-row mapping. Display filtering
 * is an INGEST gate on the view node, not a render-time scan.
 *
 * The graph + connection lifecycle are handed to the shared
 * `useVisibilityGatedLink` hook: it mounts via `mountExospine` (snapshotting Core so
 * the soft nodes tear down + rebuild on `reinit()` — "Reset Graph"), closes the
 * stream while hidden or paused, and RECONNECTS from the last seen offset on refocus.
 * The `connected → slot` bridge lives inside RemoteLink.
 */

import { useRef, useState, useCallback } from '@wordpress/element';
import '../nodes/register';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { useVisibilityGatedLink } from '@newspack-nodes/shared/hooks/useVisibilityGatedLink';
import useGlobBrowse, { connectPositions } from '../../hooks/useGlobBrowse';
import { controlMsg } from '@newspack-nodes/shared/helpers/controlMsg';

// The RemoteLink node, the inspectable stream Tee, and the view-model node.
const LINK = 'requestlog:link';
const TEE = 'requestlog:stream';
const VIEW = 'requestlog:view';
// The glob this dashboard tails across partitions.
const GLOB = 'completed.*';

/**
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] View ring cap (default 1000).
 * @return {{ setPaused: Function, clear: () => void, browse: Object, setFilter: (term: string) => void }}
 *   Control callbacks for the thin React view (the view's own state is read via
 *   useNodeState). Reset Graph is driven by a `Core.bumpGraphGeneration()`
 *   bump — mountExospine subscribes this reused mount's rebuild to it.
 */
export function useRequestLogGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Paused drives the view control and the connection lifecycle (closes SSE).
	const [ isPaused, setIsPaused ] = useState( false );
	const isPageVisible = usePageVisibility();

	// Mirror isPaused into a ref so reinit mountNodes sees current pause.
	const isPausedRef = useRef( isPaused );
	isPausedRef.current = isPaused;
	const isPageVisibleRef = useRef( isPageVisible );
	isPageVisibleRef.current = isPageVisible;
	// Same-tick truth for browse actions (a click pauses AND seeks at once).
	const isActiveNow = useCallback(
		() => isPageVisibleRef.current && ! isPausedRef.current,
		[]
	);
	const setPausedRef = useRef( null );

	const isActive = isPageVisible && ! isPaused;
	// Browse target useGlobBrowse writes; onConnect reads it on (re)connect.
	const browseTargetRef = useRef( { subscribe: [ GLOB ], positions: null } );

	// First connect applies the browse target; a reconnect resumes its offset.
	const { viewRef } = useVisibilityGatedLink( {
		mountNodes: ( interpreter ) => {
			const { maxEntries } = optsRef.current;

			// Subscribe topic is the only ctor token now.
			const link = interpreter.makeNode( 'RemoteLink', LINK, [ GLOB ] );
			// Pass-through Tee on the stream edge; copies each frame to view.
			link.target = TEE;

			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// The view node — the single dashboard consumer of the stream.
			const view = interpreter.makeNode( 'RequestLogView', VIEW );
			// The view applies controls from this FROM; records never match.
			view.controlFrom = VIEW;
			if ( maxEntries ) {
				// Safe pre-stream: the base ring caps writes against maxLines.
				view.maxLines = maxEntries;
			}

			// Re-publish a surviving pause to the fresh view on reinit.
			if ( isPausedRef.current ) {
				view.fill(
					controlMsg( view, { action: 'pause', paused: true } )
				);
			}

			return { link, view };
		},
		isActive,
		onConnect: ( link, { isReconnect } ) => {
			const target = browseTargetRef.current;
			link.setSubscribe(
				target.subscribe,
				connectPositions( target, link, isReconnect )
			);
		},
	} );

	// Kafka-UI browse over the glob (partition select + segment seeks).
	const browse = useGlobBrowse( {
		glob: GLOB,
		linkName: LINK,
		viewName: VIEW,
		isActive,
		browseTargetRef,
		setPausedRef,
		isActiveNow,
	} );

	// setPaused: flip hook state (re-runs effect) and publish it to view.
	const setPaused = ( paused ) => {
		// Refs flip NOW: a same-tick seek must record, not hit the stream.
		isPausedRef.current = paused;
		setIsPaused( paused );
		if ( viewRef.current ) {
			viewRef.current.fill(
				controlMsg( viewRef.current, { action: 'pause', paused } )
			);
		}
	};
	setPausedRef.current = setPaused;

	// Ingest gate: only matching rows enter the ring from here on.
	const setFilter = ( term ) => {
		if ( viewRef.current ) {
			viewRef.current.fill(
				controlMsg( viewRef.current, { action: 'filter', term } )
			);
		}
	};

	// clear: empty the view ring (counter + rate reset ride along).
	const clear = () => {
		if ( viewRef.current ) {
			viewRef.current.fill(
				controlMsg( viewRef.current, { action: 'clear' } )
			);
		}
	};

	return { setPaused, clear, browse, setFilter };
}
