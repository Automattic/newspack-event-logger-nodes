/* global EventSource */
/**
 * Topology Console SSE Connection Hook
 *
 * Subscribes to TopologyStreamController for a single (topology, partition)
 * pair. The endpoint is a pivoted-REPL-over-HTTP: it emits `hello` once,
 * `msg` for every Message the worker's _repl conduit forwards, and
 * `heartbeat` every five seconds while the connection is open.
 *
 * The hook keeps the EventSource open for the lifetime of the component.
 * Unmount closes the connection so the server-side loop's
 * connection_aborted() check fires and the worker stops being poked with
 * ls commands.
 */

import { useEffect, useState, useRef } from '@wordpress/element';

export function useTopologyStream( topology, partition ) {
	const [ status, setStatus ] = useState( 'connecting' );
	const [ lastMessage, setLastMessage ] = useState( null );
	const messagesRef = useRef( [] );

	useEffect( () => {
		const data = window.NewspackNodesData;
		if ( ! data || ! data.restUrl ) {
			setStatus( 'error' );
			return undefined;
		}
		const baseUrl = data.restUrl;
		const nonce = data.nonce || '';
		const url = `${ baseUrl }newspack-event-logger-nodes/v1/topology/${ encodeURIComponent(
			topology
		) }/p${ encodeURIComponent(
			partition
		) }/stream?_wpnonce=${ encodeURIComponent( nonce ) }`;
		const es = new EventSource( url, { withCredentials: true } );

		es.addEventListener( 'hello', () => setStatus( 'open' ) );
		es.addEventListener( 'heartbeat', () => {
			/* keep-alive only */
		} );
		es.addEventListener( 'msg', ( e ) => {
			try {
				const m = JSON.parse( e.data );
				messagesRef.current.push( m );
				if ( messagesRef.current.length > 100 ) {
					messagesRef.current.shift();
				}
				setLastMessage( m );
			} catch ( err ) {
				// Malformed payloads are dropped silently — the SSE
				// controller already validates JSON before emit, so this
				// branch is defensive against future protocol drift.
			}
		} );
		es.onerror = () => setStatus( 'error' );

		return () => {
			es.close();
			setStatus( 'closed' );
		};
	}, [ topology, partition ] );

	return { status, lastMessage, messages: messagesRef.current };
}
