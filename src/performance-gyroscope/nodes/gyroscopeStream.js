/* global EventSource */
/**
 * `gyroscope/stream` — the SSE-in node that owns the live connection to the
 * gyroscope firehose.
 *
 * `subscribe()` (re)connects an SSE source for the `gyroscope` subscription; each
 * inbound `msg` event is parsed into a Message envelope and emitted to the sink
 * (→ `gyroscope/transform`). `close()` tears the connection down.
 *
 * The connection itself — EventSource open, `msg` parse, the slot-heartbeat poke
 * that keeps `Sse_Slot_Pool`'s TTL alive, and the reconnect backoff — is the
 * connection logic that previously lived in the shared `useMessageStream` React
 * hook, extracted into a NODE. It lives behind an injectable `connector` seam:
 * tests pass `opts.connector` (a fake) so they never touch a real EventSource;
 * production lazily defaults to the real-EventSource connector below.
 *
 * The subscription is the fixed `gyroscope` feed (the topology pre-merges inflight
 * snapshots + completion events into one log), so — unlike rawLogsStream's per-log
 * `subscribe(logKey)` — there is no log-switch; `subscribe()` takes no argument.
 */

import { Node, KEY, VALUE, unpack } from '@newspack-nodes/runtime';
import { getCommandClient } from '../../shared/utils/commandClient';

// The gyroscope firehose subscription this dashboard streams.
const SUBSCRIPTION = 'gyroscope';

// Client keep-alive cadence; the slot TTL keys off this poke (not the server
// heartbeat). Half-TTL survives one missed poke without flooding.
const SLOT_HEARTBEAT_MS = 5000;
const SLOT_TTL_S = 10;

/**
 * Exponential backoff with a 30s cap.
 *
 * @param {number} retries Current retry count.
 * @return {number} Delay in milliseconds.
 */
const backoffDelay = ( retries ) =>
	Math.min( 30000, 1000 * Math.pow( 2, retries ) );

/**
 * The default connector — the real-EventSource transport for `gyroscope/stream`.
 *
 * `connect( subscription, onEnvelope )` opens an EventSource at `/messages/stream`
 * for the subscription, parses each `msg` into a Message envelope and hands it to
 * `onEnvelope`, starts the slot-heartbeat poke once the `connected` envelope
 * arrives, and reconnects with backoff on error. `close()` tears all three down.
 *
 * Faked in tests by swapping `global.EventSource`; the node-level tests inject
 * their own connector entirely.
 *
 * @return {{ connect: Function, close: Function }} The connector seam.
 */
function makeDefaultConnector() {
	let source = null;
	let reconnectTimer = null;
	let slotInterval = null;
	let retries = 0;
	let current = null;
	let handler = null;

	const clearReconnect = () => {
		if ( reconnectTimer ) {
			clearTimeout( reconnectTimer );
			reconnectTimer = null;
		}
	};
	const clearSlot = () => {
		if ( slotInterval ) {
			clearInterval( slotInterval );
			slotInterval = null;
		}
	};

	const close = () => {
		clearReconnect();
		clearSlot();
		if ( source ) {
			source.close();
			source = null;
		}
	};

	const open = () => {
		const data =
			( 'undefined' !== typeof window && window.NewspackNodesData ) || {};
		const qs =
			`subscribe=${ encodeURIComponent( current ) }` +
			`&_wpnonce=${ encodeURIComponent( data.nonce || '' ) }`;
		const url = `${
			data.restUrl || '/wp-json/'
		}newspack-nodes/v1/messages/stream?${ qs }`;
		source = new EventSource( url, { withCredentials: true } );

		source.addEventListener( 'msg', ( ev ) => {
			const envelope = unpack( ev.data );

			// First envelope (KEY='connected') carries the slot id; start the
			// keep-alive poker so an idle slot doesn't expire its TTL.
			if (
				'connected' === envelope[ KEY ] &&
				envelope[ VALUE ] &&
				Number.isInteger( envelope[ VALUE ].slot ) &&
				envelope[ VALUE ].slot >= 0
			) {
				const slot = envelope[ VALUE ].slot;
				clearSlot();
				slotInterval = setInterval( () => {
					getCommandClient()
						.send( {
							to: 'workers',
							verb: 'heartbeat',
							args: `${ slot } ${ SLOT_TTL_S }`,
						} )
						.catch( () => {
							// Best-effort; TTL grace absorbs transient failures.
						} );
				}, SLOT_HEARTBEAT_MS );
			}

			if ( handler ) {
				handler( envelope );
			}
		} );

		source.onerror = () => {
			// Reconnect-stack guard: EventSource fires `error` per readyState
			// change; without this we'd stack timers and burn the slot pool.
			if ( reconnectTimer ) {
				return;
			}
			source.close();
			source = null;
			retries += 1;
			reconnectTimer = setTimeout( () => {
				reconnectTimer = null;
				open();
			}, backoffDelay( retries ) );
		};

		source.onopen = () => {
			retries = 0;
		};
	};

	return {
		connect( subscription, onEnvelope ) {
			current = subscription;
			handler = onEnvelope;
			retries = 0;
			open();
		},
		close,
	};
}

class GyroscopeStreamNode extends Node {
	constructor( connector ) {
		super();
		this._connector = connector;
		this._subscribed = false;
	}

	// (Re)connect the live source for the gyroscope firehose. A re-subscribe
	// closes the old source first, then opens a new one; each inbound envelope
	// goes to sink.
	subscribe() {
		if ( this._subscribed ) {
			this._connector.close();
		}
		this._subscribed = true;
		this._connector.connect( SUBSCRIPTION, ( envelope ) => {
			if ( this.sink ) {
				this.sink.fill( envelope );
			}
		} );
	}

	// Tear the connection down. Unconditional so teardown closes a never-yet-
	// subscribed stream too (the connector's close is idempotent/null-guarded).
	close() {
		this._connector.close();
		this._subscribed = false;
	}
}

/**
 * Create and register the Gyroscope stream node.
 *
 * @param {string} name             Node name.
 * @param {Object} [opts]           Options.
 * @param {Object} [opts.connector] Injectable connector seam (connect/close);
 *                                  defaults to the real-EventSource connector.
 * @return {GyroscopeStreamNode} The stream node.
 */
export function createGyroscopeStream( name, opts = {} ) {
	const connector = opts.connector || makeDefaultConnector();
	const node = new GyroscopeStreamNode( connector );
	node.setName( name );
	return node;
}
