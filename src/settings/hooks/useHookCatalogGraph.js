/**
 * useHookCatalogGraph — mounts the Performance Logger hook-catalog graph onto
 * the canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's HTTP I/O boundary node — the minimal mount surface this
 * fire-on-OPEN modal needs:
 *
 *   _http       (HttpOutNode — POST /command boundary; .client = CommandClient)
 *
 * Plus the application's render-model node:
 *
 *   hookcatalog:view (the view-model node React reads + the pending-Promise registry)
 *
 * Dashboards aren't REPLs: no transcript window, no tab-completion input, no
 * uptime display, no `cd` navigation. So `_output` / `_completion` / `_uptime` /
 * `_cwd` are NOT mounted here — they'd be dead weight and would collide with
 * the debug-overlay's REPL when it opens on this page.
 *
 * The trigger is fire-on-OPEN: an effect keyed on `isOpen` builds a TM_COMMAND
 * (FROM=`hookcatalog:view`, TO=`_http/performance`, VALUE.name=`hooks_registered`)
 * with a unique `message[ID]`, stashes a Promise resolver in the view's `pending`
 * Map, and fills the message into the interpreter. The router peels `_http`, HttpOutNode
 * POSTs, the server replies TO=FROM, the router peels
 * `hookcatalog:view`, and the view's `fill()` matches `message[ID]` to settle
 * the Promise + extract hooks_by_category for the render model.
 *
 * Error contract: a failure clears the spinner with an empty catalog —
 * HookSelectorModal has no error UI. Pending-matched TM_ERROR rejects the
 * Promise without polluting view.error; this hook's catch synthesizes a fake
 * empty-catalog reply into the view to clear loading.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` (assigned
 * to `_http.client`) so the hook never touches the network. Production lazily
 * defaults to a freshly-constructed CommandClient.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	mountExospine,
	CommandClient,
	useNodeState,
	newMessage,
	TYPE,
	TO,
	FROM,
	ID,
	VALUE,
	TM_COMMAND,
	TM_RESPONSE,
} from '@newspack-nodes/runtime';
import '../nodes/register';

const HTTP = '_http';
const VIEW = 'hookcatalog:view';
// `_http` is backbone-owned (teardownSpine removes it); only the view is ours.
const GRAPH_NODE_NAMES = [ VIEW ];

// Monotonic ID counter; the view matches message[ID] to a pending resolver.
let nextOpId = 0;
function makeOpId() {
	nextOpId += 1;
	return `hookcatalog-op-${ Date.now() }-${ nextOpId }`;
}

// Build the TM_COMMAND addressed at the `performance` CI.
function buildCommand( verb, id ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND;
	m[ FROM ] = VIEW;
	m[ TO ] = `${ HTTP }/performance`;
	m[ ID ] = id;
	m[ VALUE ] = { name: verb, arguments: '' };
	return m;
}

/**
 * @param {Object}  [opts]               Options.
 * @param {boolean} [opts.isOpen]        When true, fires one hook-catalog fetch.
 * @param {Object}  [opts.commandClient] CommandClient seam assigned to `_http.client`;
 *                                       defaults to a freshly-constructed CommandClient.
 * @return {{ hooksByCategory: Object, loading: boolean }} The render model.
 */
export function useHookCatalogGraph( opts = {} ) {
	const { isOpen } = opts;

	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live interpreter handle for the dispatch callback.
	const interpreterRef = useRef( null );

	// Flipped once the graph mounts so useNodeState re-subscribes to the view.
	const [ , setViewReady ] = useState( false );

	// Mount the graph once: clip it onto the exospine.
	useEffect( () => {
		const data =
			( typeof window !== 'undefined' && window.NewspackNodesData ) || {};

		// Canonical backbone: everything → interpreter → router.
		const { interpreter, http, teardown: teardownSpine } = mountExospine();

		// _http is a backbone singleton (mountExospine owns it); reuse it.
		http.client =
			optsRef.current.commandClient ||
			new CommandClient( {
				baseUrl: data.restUrl || '/wp-json/',
				nonce: data.nonce || '',
			} );

		// Application view-model node — receives every reply via TO=FROM.
		interpreter.makeNode( 'HookCatalogView', VIEW );

		interpreterRef.current = interpreter;

		// Re-render so useNodeState re-subscribes to the freshly-mounted view node.
		setViewReady( true );

		return () => {
			for ( const name of GRAPH_NODE_NAMES ) {
				Core.unregisterNode( name );
			}
			teardownSpine();
			interpreterRef.current = null;
		};
	}, [] );

	// Dispatch a verb and return a Promise the view settles via message[ID].
	const dispatch = useCallback( ( verb ) => {
		const interpreter = interpreterRef.current;
		if ( ! interpreter ) {
			return Promise.reject( new Error( 'graph not mounted' ) );
		}
		const view = Core.node( VIEW );
		if ( ! view ) {
			return Promise.reject( new Error( 'view not mounted' ) );
		}
		const id = makeOpId();
		const promise = new Promise( ( resolve, reject ) => {
			view.replies.add( id, resolve, reject );
		} );
		interpreter.fill( buildCommand( verb, id ) );
		return promise;
	}, [] );

	// Fetch hooks_registered on open; on failure route an empty-catalog reply.
	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}
		dispatch( 'hooks_registered' ).catch( () => {
			const interpreter = interpreterRef.current;
			if ( ! interpreter ) {
				return;
			}
			const fake = newMessage();
			fake[ TYPE ] = TM_COMMAND | TM_RESPONSE;
			fake[ TO ] = VIEW;
			fake[ VALUE ] = {
				name: 'hooks_registered',
				payload: { hooks_by_category: {} },
			};
			interpreter.fill( fake );
		} );
	}, [ isOpen, dispatch ] );

	// Read the published model for the modal (keeps the modal presentational).
	const view = useNodeState( VIEW, 'view' );
	return {
		hooksByCategory: view?.hooksByCategory || {},
		loading: view?.loading || false,
	};
}
