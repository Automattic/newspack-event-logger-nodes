/* global EventSource */
/**
 * Firehose SSE Connection Hook
 *
 * Manages SSE connection lifecycle for firehose streaming.
 * Server multiplexes all partitions into a single SSE stream.
 */

import { useState, useRef, useCallback } from '@wordpress/element';

import { getCommandClient } from '../utils/commandClient';
import unwrapCommandResponse from '../utils/unwrapCommandResponse';

/**
 * Calculate exponential backoff delay with hysteresis.
 *
 * @param {number} retries Current retry count.
 * @return {number} Delay in milliseconds.
 */
const getBackoffDelay = ( retries ) => {
	const base = 1000; // 1 second base.
	const max = 30000; // 30 second max.
	return Math.min( max, base * Math.pow( 2, retries ) );
};

/**
 * Valid SSE endpoint names.
 */
const VALID_ENDPOINTS = [ 'gyroscope', 'requests', 'rawlogs', 'errors' ];

/**
 * Heartbeat interval in milliseconds (must be less than server's 30s slot TTL).
 */
const HEARTBEAT_INTERVAL_MS = 5000;

/**
 * Hook for managing firehose SSE connection.
 *
 * Server multiplexes all partitions into a single SSE stream,
 * so only one EventSource connection is needed per dashboard.
 *
 * @param {Object}   options                 Hook options.
 * @param {string}   options.endpoint        SSE endpoint: 'gyroscope' (firehose + InflightTracker) or 'requests' (completed only).
 * @param {number}   options.intervalMs      SSE polling interval in ms.
 * @param {Object}   options.params          Additional URL parameters (e.g., { log: 'firehose' }).
 * @param {Function} options.onSource        Callback when source is created, receives single EventSource.
 * @param {Function} options.onBeforeConnect Callback before connecting, for resetting state.
 * @return {Object} Connection state and controls.
 */
export default function useFirehoseConnection( {
	endpoint = 'gyroscope',
	intervalMs = 500,
	params = {},
	onSource,
	onBeforeConnect,
} ) {
	const [ error, setError ] = useState( null );
	const [ lastEventTime, setLastEventTime ] = useState( null );

	const eventSourceRef = useRef( null );
	const retryCountRef = useRef( 0 );
	const reconnectTimeoutRef = useRef( null );
	const heartbeatIntervalRef = useRef( null );
	const slotRef = useRef( null );
	const positionsRef = useRef( null );
	// Track the params key from the last successful connect so we can detect
	// when the caller has switched (e.g., a different `log`) and drop stale
	// positions — they reference offsets in the previous log and would
	// silently mis-resume into the new file.
	const lastConnectedParamsKeyRef = useRef( null );
	// Use refs so connect() stays stable when values change.
	const intervalMsRef = useRef( intervalMs );
	intervalMsRef.current = intervalMs;
	const paramsRef = useRef( params );
	paramsRef.current = params;

	// Close EventSource and stop heartbeat.
	const close = useCallback( () => {
		if ( reconnectTimeoutRef.current ) {
			clearTimeout( reconnectTimeoutRef.current );
			reconnectTimeoutRef.current = null;
		}
		if ( heartbeatIntervalRef.current ) {
			clearInterval( heartbeatIntervalRef.current );
			heartbeatIntervalRef.current = null;
		}
		if ( eventSourceRef.current ) {
			eventSourceRef.current.close();
			eventSourceRef.current = null;
		}
		slotRef.current = null;
	}, [] );

	// Connect to SSE endpoint (single multiplexed stream).
	const connect = useCallback( async () => {
		// Validate endpoint before use.
		if ( ! VALID_ENDPOINTS.includes( endpoint ) ) {
			setError( 'Invalid endpoint' );
			return;
		}

		close();
		setError( null ); // Clear error on new connection attempt.

		// Drop saved positions when params change (e.g., log switch); they
		// only make sense in the context of the previous connection's log.
		const currentParamsKey = JSON.stringify( paramsRef.current );
		if (
			lastConnectedParamsKeyRef.current !== null &&
			lastConnectedParamsKeyRef.current !== currentParamsKey
		) {
			positionsRef.current = null;
		}
		lastConnectedParamsKeyRef.current = currentParamsKey;

		// Allow caller to reset state before connecting.
		if ( onBeforeConnect ) {
			onBeforeConnect();
		}

		const dashboards = window.NewspackNodesData;
		if ( ! dashboards || ! dashboards.restUrl ) {
			setError( 'Dashboard configuration not available.' );
			return;
		}
		const baseUrl = dashboards.restUrl;
		const nonce = dashboards.nonce;

		// Build URL with extra params (include saved positions for reconnect resume).
		const allParams = { ...paramsRef.current };
		if ( positionsRef.current ) {
			allParams.positions = JSON.stringify( positionsRef.current );
		}
		const extraParams = Object.entries( allParams )
			.map(
				( [ k, v ] ) =>
					`${ encodeURIComponent( k ) }=${ encodeURIComponent( v ) }`
			)
			.join( '&' );
		const paramStr = extraParams ? `&${ extraParams }` : '';

		// Single connection - server multiplexes all partitions.
		const url = `${ baseUrl }newspack-nodes/v1/firehose/${ endpoint }?interval=${ intervalMsRef.current }&_wpnonce=${ nonce }${ paramStr }`;
		const source = new EventSource( url, { withCredentials: true } );

		// Track server positions for reconnect resume.
		source.addEventListener( 'positions', ( event ) => {
			try {
				positionsRef.current = JSON.parse( event.data );
			} catch ( e ) {
				// Ignore.
			}
		} );

		// Track last event time for staleness indicator.
		// Listen for all common event types.
		const touchTime = () => setLastEventTime( Date.now() );
		source.addEventListener( 'heartbeat', touchTime );
		source.addEventListener( 'lines', touchTime );
		source.addEventListener( 'errors', touchTime );
		source.addEventListener( 'complete_batch', touchTime );
		source.addEventListener( 'entries', touchTime );
		source.addEventListener( 'config', touchTime );

		source.addEventListener( 'connected', ( event ) => {
			retryCountRef.current = 0;
			setError( null );
			setLastEventTime( Date.now() );
			// Capture slot number for heartbeat.
			try {
				const data = JSON.parse( event.data );
				if ( typeof data.slot === 'number' ) {
					slotRef.current = data.slot;
				}
			} catch ( e ) {
				// Ignore parse errors.
			}
		} );

		source.addEventListener( 'timeout', () => {
			if ( ! reconnectTimeoutRef.current ) {
				close();
				const delay = getBackoffDelay( retryCountRef.current );
				retryCountRef.current += 1;
				reconnectTimeoutRef.current = setTimeout( () => {
					reconnectTimeoutRef.current = null;
					connect();
				}, delay );
			}
		} );

		source.onerror = () => {
			// Cap retries to prevent infinite backoff growth.
			if ( retryCountRef.current >= 2 ) {
				setError( 'Connection lost. Reconnecting...' );
			}
			if ( retryCountRef.current >= 10 ) {
				retryCountRef.current = 0; // Reset after max retries.
			}
			if ( ! reconnectTimeoutRef.current ) {
				close();
				const delay = getBackoffDelay( retryCountRef.current );
				retryCountRef.current += 1;
				reconnectTimeoutRef.current = setTimeout( () => {
					reconnectTimeoutRef.current = null;
					connect();
				}, delay );
			}
		};

		eventSourceRef.current = source;

		// Start heartbeat to keep slot alive. Dispatches `workers.heartbeat`
		// through `/command` — the verb refreshes the SSE slot's memcache TTL
		// for the current user (same {slot} arg shape as the legacy REST
		// endpoint). unwrapCommandResponse throws on TM_ERROR so the
		// surrounding .catch swallows any failure and lets the SSE
		// connection's own reconnect path take over.
		slotRef.current = null;
		heartbeatIntervalRef.current = setInterval( () => {
			if ( slotRef.current !== null ) {
				getCommandClient()
					.send( {
						to: 'workers',
						verb: 'heartbeat',
						args: { slot: slotRef.current },
					} )
					.then( unwrapCommandResponse )
					.catch( () => {
						// Ignore heartbeat errors - SSE will reconnect if needed.
					} );
			}
		}, HEARTBEAT_INTERVAL_MS );

		// Let caller add their event listeners.
		if ( onSource ) {
			onSource( source );
		}
	}, [ endpoint, close, onSource, onBeforeConnect ] );

	return {
		error,
		connect,
		close,
		source: eventSourceRef.current,
		lastEventTime,
	};
}
