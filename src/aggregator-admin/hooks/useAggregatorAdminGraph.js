/**
 * useAggregatorAdminGraph — mounts the Configured-Servers admin node graph
 * onto the canonical rule-#2 backbone (`_command_interpreter → _router`) using
 * the substrate's HTTP I/O boundary node — the minimal mount surface a
 * CRUD-on-demand dashboard needs:
 *
 *   _http       (HttpOut — POST /command boundary; .client = CommandClient)
 *
 * Plus the application's render-model node:
 *
 *   servers:view (the view-model node React reads + the hook's pending-Promise registry)
 *
 * Dashboards aren't REPLs: no transcript window, no tab-completion input, no
 * uptime display, no `cd` navigation. So `_output` / `_completion` / `_uptime` /
 * `_cwd` are NOT mounted here — they'd be dead weight and would collide with
 * the debug-overlay's REPL when it opens on this page.
 *
 * Every node sinks into the CI; flow is steered by each node's `target`. The
 * hook owns the CRUD dispatch — on each call it builds a TM_COMMAND
 * (FROM=`servers:view`, TO=`_http/servers`, verb in VALUE.name) with a unique
 * `message[ID]`, stashes a `{ resolve, reject }` resolver in `servers:view`'s
 * `pending` Map under that ID, and fills the message into the CI. The router
 * peels `_http`, HttpOut POSTs, the server pivots the reply TO=FROM, the router
 * peels `servers:view`, and the view's `fill()` matches `message[ID]` against
 * `pending`, resolving or rejecting the Promise (and updating the render model
 * for `list` replies + surfacing TM_ERROR into the view's `error`).
 *
 * Mutations (add/update/delete) re-list on success to refresh the table —
 * replaces the legacy `window.location.reload()`. test() is read-only and does
 * NOT re-list. Mutation rejections also surface into the view model (via the
 * view's TM_ERROR path) so the table shows them, AND re-throw to the caller.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` (assigned
 * to `_http.client`) so the hook never touches the network. Production lazily
 * defaults to the shared CommandClient singleton.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	mountExospine,
	HttpOut,
	CommandClient,
	newMessage,
	TYPE,
	TO,
	FROM,
	ID,
	VALUE,
	TM_COMMAND,
} from '@newspack-nodes/runtime';
import { createServersView } from '../nodes/serversView';

const HTTP = '_http';
const VIEW = 'servers:view';
const GRAPH_NODE_NAMES = [ HTTP, VIEW ];

// Monotonic per-hook-instance ID counter — message[ID] is what the view uses
// to match a reply back to a pending Promise resolver.
let nextOpId = 0;
function makeOpId() {
	nextOpId += 1;
	return `servers-op-${ Date.now() }-${ nextOpId }`;
}

/**
 * Build a TM_COMMAND addressed at the `servers` CI: FROM=`servers:view` so the
 * server's reply pivot lands on the view; TO=`_http/servers` so the router peels
 * `_http` and HttpOut POSTs the bare command. `id` is the correlator the view
 * uses to resolve the hook's Promise.
 *
 * @param {string} verb    Verb name (list / add / update / delete / test).
 * @param {*}      payload Verb payload (the structured-data slot).
 * @param {string} id      Correlator stamped into message[ID].
 * @return {Array} A 7-field positional Message.
 */
function buildCommand( verb, payload, id ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND;
	m[ FROM ] = VIEW;
	m[ TO ] = `${ HTTP }/servers`;
	m[ ID ] = id;
	m[ VALUE ] = { name: verb, arguments: '', payload };
	return m;
}

/**
 * @param {Object} [opts]               Options (testing seams).
 * @param {Object} [opts.commandClient] CommandClient seam assigned to `_http.client`;
 *                                      defaults to a freshly-constructed CommandClient.
 * @return {{ addServer: Function, updateServer: Function, removeServer: Function,
 *   testServer: Function }} CRUD callbacks for the thin React view (the model is
 *   read via useNodeState).
 */
export function useAggregatorAdminGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live CI handle for the CRUD callbacks.
	const ciRef = useRef( null );

	// Flipped true once the graph (and its view node) is mounted, so the React
	// view's useNodeState re-subscribes to the now-registered view node.
	const [ , setViewReady ] = useState( false );

	// Mount the graph once: clip it onto the exospine, then fire one immediate list.
	useEffect( () => {
		const data =
			( typeof window !== 'undefined' && window.NewspackNodesData ) || {};

		// The canonical backbone every node clips onto: everything → CI → router.
		const { ci, teardown: teardownSpine } = mountExospine();

		// I/O boundary node — HttpOut is the only one this CRUD-on-demand
		// dashboard needs.
		const http = new HttpOut();
		http.client =
			optsRef.current.commandClient ||
			new CommandClient( {
				baseUrl: data.restUrl || '/wp-json/',
				nonce: data.nonce || '',
			} );
		http.setName( HTTP );
		http.sink = ci;

		// The application view-model node — receiver of every reply via TO=FROM pivot.
		const view = createServersView( VIEW );
		view.sink = ci;

		ciRef.current = ci;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );

		// Fire one immediate list (the canonical "everything sinks into the CI"
		// path — CI forwards to router → router peels `_http` → HttpOut POSTs).
		// Fire-and-forget: the view updates render state on the reply.
		ci.fill( buildCommand( 'list', null, makeOpId() ) );

		return () => {
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			teardownSpine();
			ciRef.current = null;
		};
	}, [] );

	// Dispatch a verb and return a Promise that resolves with the unwrapped
	// payload (or rejects with a TM_ERROR). The view matches `message[ID]`
	// against its `pending` Map to settle the Promise.
	const dispatch = useCallback( ( verb, payload ) => {
		const ci = ciRef.current;
		if ( ! ci ) {
			return Promise.reject( new Error( 'graph not mounted' ) );
		}
		const view = Core.node( VIEW );
		if ( ! view ) {
			return Promise.reject( new Error( 'view not mounted' ) );
		}
		const id = makeOpId();
		const promise = new Promise( ( resolve, reject ) => {
			view.pending.set( id, { resolve, reject } );
		} );
		ci.fill( buildCommand( verb, payload, id ) );
		return promise;
	}, [] );

	// Run a registry-mutating verb, then re-list to refresh the table. A failure
	// rejects to the caller; the view already surfaced the error into its model
	// via the TM_ERROR reply path (no extra control fill needed).
	const runMutation = useCallback(
		async ( verb, payload ) => {
			const result = await dispatch( verb, payload );
			// Fire-and-forget re-list (replaces window.location.reload()).
			dispatch( 'list', null ).catch( () => {} );
			return result;
		},
		[ dispatch ]
	);

	const addServer = useCallback(
		( fields ) =>
			runMutation( 'add', {
				id: fields.id,
				url: fields.url,
				auth_username: fields.auth_username,
				auth_password: fields.auth_password,
				enabled: true,
			} ),
		[ runMutation ]
	);

	// Spread partial FIRST so the positional `id` always wins — a partial that
	// happens to carry an `id` key can't silently retarget the row.
	const updateServer = useCallback(
		( id, partial ) => runMutation( 'update', { ...partial, id } ),
		[ runMutation ]
	);

	const removeServer = useCallback(
		( id ) => runMutation( 'delete', { id } ),
		[ runMutation ]
	);

	// test() is read-only — return its probe result to the caller for per-row
	// status; no re-list (a probe doesn't change the registry).
	const testServer = useCallback(
		( id ) => dispatch( 'test', { id } ),
		[ dispatch ]
	);

	return { addServer, updateServer, removeServer, testServer };
}
