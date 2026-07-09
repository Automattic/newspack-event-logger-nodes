/**
 * useRulesGraph — mounts the per-URL logging-ruleset editor node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's HTTP I/O boundary node. Modeled on useVaultGraph, single-concern:
 *
 *   _http     (HttpOutNode — POST /command boundary; .client = CommandClient)
 *   rules:in  (Tee) → rules:view (RulesViewNode) — list/save/upsert/delete
 *
 * Each CRUD op builds a TM_COMMAND (FROM = `rules:in`, TO = `_http/rules`, verb
 * in VALUE.name) with a correlator in `message[ID]`, stashes a `{ resolve,
 * reject }` in the view's `replies` map under that ID, and fills the message
 * into the interpreter via the `_shell` Tap (observable at `connect _shell`).
 * The router peels `_http`, HttpOutNode POSTs, the server replies TO=FROM
 * TO=FROM, the router peels the receiver Tee, the Tee fans to the view, and the
 * view settles the Promise (and refreshes its render model on a `list` reply).
 *
 * Wire contract mirrors Rules_CI_Node: `save`/`upsert` pass the RAW JSON as the
 * whole command arguments string (the handler json_decodes it); `delete` passes
 * the id as a positional token; `list` takes no args. Mutations re-`list()` to
 * refresh the table.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` (assigned
 * to `_http.client`). Production lazily defaults to a fresh CommandClient.
 *
 * @param {Object} [opts]               Options (testing seams).
 * @param {Object} [opts.commandClient] CommandClient seam assigned to `_http.client`.
 * @return {{ rules: Array, loading: boolean, error: (string|null),
 *   list: Function, saveAll: Function, upsert: Function, remove: Function }}
 *   The render model plus CRUD callbacks.
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
	formatCommandArgs,
} from '@newspack-nodes/runtime';
import './nodes/register';

const HTTP = '_http';
const RECV = 'rules:in';
const VIEW = 'rules:view';

// Monotonic per-hook-instance ID counter — message[ID] is what the view uses to
// match a reply back to a pending Promise resolver.
let nextOpId = 0;
function makeOpId() {
	nextOpId += 1;
	return `rules-op-${ Date.now() }-${ nextOpId }`;
}

// Build a TM_COMMAND addressed at the `rules` CI. FROM = the receiver Tee so the
// server's TO=FROM reply lands there; TO = `_http/rules` so the router peels
// `_http` and HttpOutNode POSTs the bare command.
function buildCommand( verb, args, id ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND;
	m[ FROM ] = RECV;
	m[ TO ] = `${ HTTP }/rules`;
	m[ ID ] = id;
	m[ VALUE ] = { name: verb, arguments: args };
	return m;
}

export function useRulesGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	const interpreterRef = useRef( null );
	const shellRef = useRef( null );

	// Bumped on every (re)build so a consumer's useNodeState re-subscribes to the
	// freshly-registered view node.
	const [ , bumpBuild ] = useState( 0 );

	useEffect( () => {
		const build = ( { interpreter, shell, http } ) => {
			const data =
				( typeof window !== 'undefined' && window.NewspackNodesData ) ||
				{};

			http.client =
				optsRef.current.commandClient ||
				new CommandClient( {
					baseUrl: data.restUrl || '/wp-json/',
					nonce: data.nonce || '',
				} );

			const recv = interpreter.makeNode( 'Tee', RECV );
			interpreter.makeNode( 'RulesView', VIEW );
			recv.connectNode( VIEW );

			interpreterRef.current = interpreter;
			shellRef.current = shell;

			bumpBuild( ( n ) => n + 1 );

			// Fire one immediate list through `_shell` → interpreter. Uncorrelated
			// (no stashed resolver): its reply refreshes the view via the `list`
			// name branch.
			shell.fill( buildCommand( 'list', '', makeOpId() ) );

			return () => {
				interpreterRef.current = null;
			};
		};

		const { teardown } = mountExospine( build );
		return teardown;
	}, [] );

	// Dispatch a verb and return a Promise the view settles by matching
	// `message[ID]` against its `replies` map.
	const dispatch = useCallback( ( verb, args = '' ) => {
		const shell = shellRef.current;
		if ( ! shell ) {
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
		shell.fill( buildCommand( verb, args, id ) );
		return promise;
	}, [] );

	const list = useCallback( () => dispatch( 'list', '' ), [ dispatch ] );

	// Run a ruleset-mutating verb, then re-list to refresh the table. A failure
	// rejects to the caller; the view leaves its banner clean for a
	// pending-matched error (no extra control fill needed).
	const runMutation = useCallback(
		async ( verb, args ) => {
			const result = await dispatch( verb, args );
			dispatch( 'list', '' ).catch( () => {} );
			return result;
		},
		[ dispatch ]
	);

	// save/upsert pass the raw JSON as the whole arguments string (Rules_CI
	// json_decodes the arg verbatim); delete passes the id as a positional token.
	const saveAll = useCallback(
		( rules ) => runMutation( 'save', JSON.stringify( rules ) ),
		[ runMutation ]
	);

	const upsert = useCallback(
		( rule ) => runMutation( 'upsert', JSON.stringify( rule ) ),
		[ runMutation ]
	);

	const remove = useCallback(
		( id ) => runMutation( 'delete', formatCommandArgs( [ id ] ) ),
		[ runMutation ]
	);

	const model = useNodeState( VIEW, 'view' );
	return {
		rules: model?.rules ?? [],
		loading: model?.loading ?? true,
		error: model?.error ?? null,
		list,
		saveAll,
		upsert,
		remove,
	};
}
