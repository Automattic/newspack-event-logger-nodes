/**
 * useErrorLogGraph — mounts the Error Log dashboard node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using a SINGLE
 * substrate `RemoteLink` node instead of hand-wiring the I/O boundary. Three
 * soft nodes hang off that backbone:
 *
 *   perferrors:link    RemoteLink — the full-duplex SSE+HTTP channel. It owns
 *                      a patron-owned `:sse-in` (EventSource ingress) and
 *                      shares the backbone singletons `_http` (the POST
 *                      /command boundary) and `_heartbeat` (slot keep-alive),
 *                      bridging `connected → slot` between them, and
 *                      defaulting its own transport.
 *   perferrors:stream  Tee — the inspectable stream edge. It earns its keep by
 *                      letting the debug overlay watch live frames through a
 *                      second target; today it fans to the view alone.
 *   perferrors:view    PerfErrorsView — the view-model the React view reads.
 *
 * The link targets the Tee, the Tee fans to the view, and the view's
 * `shapeRow()` shapes raw envelopes into rows inline. Display filtering lives
 * an INGEST gate on the view node, not a render-time scan.
 *
 * Every node sinks into the interpreter; flow is steered by each node's `target`. The
 * graph + connection lifecycle are handed to the shared `useVisibilityGatedLink` hook:
 * it mounts via `mountExospine` (snapshotting Core so the soft nodes tear down +
 * rebuild on `reinit()` — "Reset Graph"), closes the stream while hidden or paused,
 * and RECONNECTS from the last seen offset on refocus. The `connected → slot` bridge
 * and slot keep-alive live inside RemoteLink.
 *
 * Returns `setPaused` and `clear` — the thin control callbacks — plus the
 * `browse` model `useGlobBrowse` builds. The two callbacks are dispatched
 * HOOK-DIRECT to the view node (`viewRef.current.fill`), an external bridge:
 * they are NOT routed through the graph.
 */

import { useRef, useState, useCallback } from '@wordpress/element';
import { views } from '../nodes/register';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { useVisibilityGatedLink } from '@newspack-nodes/shared/hooks/useVisibilityGatedLink';
import useGlobBrowse, { reopenStream } from '../../hooks/useGlobBrowse';
import { controlMsg } from '@newspack-nodes/shared/helpers/controlMsg';

// The RemoteLink node, the inspectable stream Tee, and the view-model node.
const LINK = 'perferrors:link';
const TEE = 'perferrors:stream';
const VIEW = 'perferrors:view';
// The glob this dashboard tails across partitions.
const GLOB = 'errors.*';

/**
 * Mount the Error Log graph and return the React view's controls.
 *
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] View ring cap (default 5000).
 * @return {{ setPaused: Function, clear: () => void, browse: Object, setFilter: (term: string) => void }}
 *   Control callbacks plus the browse model for the thin React view (the view's
 *   own state is read via useNodeState). Reset Graph is driven by a
 *   `Core.bumpGraphGeneration()` bump — mountExospine subscribes this mount's
 *   rebuild to it.
 */
export function useErrorLogGraph( opts = {} ) {
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
			// The subscription is RemoteLink's only positional ctor token.
			const link = interpreter.makeNode( 'RemoteLink', LINK, [ GLOB ] );
			// Frames land on the Tee, which fans them to the view.
			link.target = TEE;

			// A second target here taps the raw stream (the debug overlay).
			const tee = interpreter.makeNode( 'Tee', TEE );
			tee.connectNode( VIEW );

			// The view-model — shapes raw envelopes into rows inline.
			const view = interpreter.makeNode( views.PerfErrorsView, VIEW );
			// The view applies controls from this FROM; records never match.
			view.controlFrom = VIEW;
			if ( maxEntries ) {
				// Pre-stream only: the ring indexes modulo maxLines.
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
		onConnect: ( link, { isReconnect } ) =>
			reopenStream( link, browseTargetRef.current, isReconnect ),
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
