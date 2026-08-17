/**
 * useGlobStreamGraph — the SSE stream-dashboard graph, declared per dashboard.
 *
 * Three soft nodes hang off the backbone, named from `prefix`:
 *
 *   {prefix}:link    RemoteLink — the full-duplex SSE+HTTP channel. It owns a
 *                    patron-owned `:sse-in` (EventSource ingress) and shares the
 *                    backbone singletons `_http` (the POST /command boundary)
 *                    and `_heartbeat` (slot keep-alive), bridging
 *                    `connected → slot` between them, and defaulting its own
 *                    transport. `glob` is its only positional ctor token.
 *   {prefix}:stream  Tee — the inspectable stream edge, letting the debug
 *                    overlay watch live frames through a second target; today
 *                    it fans to the view alone.
 *   {prefix}:view    `viewClass` — the view-model the React view reads, whose
 *                    `shapeRow()` shapes raw envelopes into rows inline.
 *
 * The link targets the Tee, the Tee fans to the view. Display filtering is an
 * INGEST gate on the view node, not a render-time scan.
 *
 * Every node sinks into the interpreter; flow is steered by each node's
 * `target`. The graph and connection lifecycle belong to
 * `useVisibilityGatedLink`: it mounts via `mountExospine` (snapshotting Core so
 * the soft nodes tear down and rebuild on `reinit()` — "Reset Graph"), closes
 * the stream while hidden or paused, and RECONNECTS from the last seen offset
 * on refocus. The `connected → slot` bridge lives inside RemoteLink.
 */

import { useRef, useState, useCallback } from '@wordpress/element';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { useVisibilityGatedLink } from '@newspack-nodes/shared/hooks/useVisibilityGatedLink';
import useGlobBrowse, { reopenStream } from './useGlobBrowse';
import { controlMsg } from '@newspack-nodes/shared/helpers/controlMsg';

/**
 * Mount one dashboard's stream graph and return its React view's controls.
 *
 * The three control callbacks are dispatched HOOK-DIRECT to the view node
 * (`viewRef.current.fill`), an external bridge that is NOT routed through the
 * graph. Reset Graph is driven by a `Core.bumpGraphGeneration()` bump —
 * mountExospine subscribes this mount's rebuild to it.
 *
 * @param {Object}   spec              The dashboard's declaration.
 * @param {string}   spec.prefix       Node-name prefix for the three soft nodes.
 * @param {string}   spec.glob         Partition glob this dashboard tails.
 * @param {Function} spec.viewClass    View-model node class to mount.
 * @param {Object}   [opts]            Options.
 * @param {number}   [opts.maxEntries] View ring cap; the view class's own default when unset.
 * @return {{ setPaused: Function, clear: () => void, browse: Object, setFilter: (term: string) => void }}
 *   Control callbacks plus the browse model for the thin React view; the view's
 *   own state is read via useNodeState.
 */
export function useGlobStreamGraph( { prefix, glob, viewClass }, opts = {} ) {
	const linkName = `${ prefix }:link`;
	const teeName = `${ prefix }:stream`;
	const viewName = `${ prefix }:view`;

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
	const browseTargetRef = useRef( { subscribe: [ glob ], positions: null } );

	// First connect applies the browse target; a reconnect resumes its offset.
	const { viewRef } = useVisibilityGatedLink( {
		mountNodes: ( interpreter ) => {
			const { maxEntries } = opts;
			// The subscription is RemoteLink's only positional ctor token.
			const link = interpreter.makeNode( 'RemoteLink', linkName, [
				glob,
			] );
			// Frames land on the Tee, which fans them to the view.
			link.target = teeName;

			// A second target here taps the raw stream (the debug overlay).
			const tee = interpreter.makeNode( 'Tee', teeName );
			tee.connectNode( viewName );

			// The view-model — shapes raw envelopes into rows inline.
			const view = interpreter.makeNode( viewClass, viewName );
			// The view applies controls from this FROM; records never match.
			view.controlFrom = viewName;
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
		glob,
		linkName,
		viewName,
		isActive,
		browseTargetRef,
		setPausedRef,
		isActiveNow,
	} );

	// The one control publisher; an optional call never reaches controlMsg.
	const control = ( value ) =>
		viewRef.current?.fill( controlMsg( viewRef.current, value ) );

	// setPaused: flip hook state (re-runs effect) and publish it to view.
	const setPaused = ( paused ) => {
		// Refs flip NOW: a same-tick seek must record, not hit the stream.
		isPausedRef.current = paused;
		setIsPaused( paused );
		control( { action: 'pause', paused } );
	};
	setPausedRef.current = setPaused;

	// Ingest gate: only matching rows enter the ring from here on.
	const setFilter = ( term ) => {
		control( { action: 'filter', term } );
	};

	// clear: empty the view ring (counter + rate reset ride along).
	const clear = () => {
		control( { action: 'clear' } );
	};

	return { setPaused, clear, browse, setFilter };
}
