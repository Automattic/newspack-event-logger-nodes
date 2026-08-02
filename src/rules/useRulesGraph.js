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
 * Wire contract mirrors Rules_CI_Node: `save`/`upsert` pass the RAW JSON as a
 * single arg token (the handler json_decodes `$args[0]`); `delete` passes the id
 * as a positional token; `list` takes no args (`[]`). Mutations re-`list()` to
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
	TO,
	formatCommandArgs,
	ensureSession,
} from '@newspack-nodes/runtime';

import './nodes/register';
import useRequestNode from '@newspack-nodes/shared/hooks/useRequestNode';

const HTTP = '_http';
const RECV = 'rules:in';
const VIEW = 'rules:view';

/**
 * Ask the `rules` CI to re-list, FROM the table's own receiver Tee — its reply
 * repaints `rules:view`, and that IS the result.
 *
 * @param {Object} shell The `_shell` Tap every command routes through.
 */
function fireList( shell ) {
	const m = Core.node( RECV )?.command( 'list', [] ) ?? null;
	if ( null === m ) {
		return; // unauthenticated; re-auth is under way
	}
	m[ TO ] = `${ HTTP }/rules`;
	shell.fill( m );
}

export function useRulesGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	const interpreterRef = useRef( null );
	const shellRef = useRef( null );

	// Bumped on every rebuild so useNodeState re-subscribes to the fresh view.
	const [ , bumpBuild ] = useState( 0 );

	useEffect( () => {
		const build = ( { interpreter, shell, http } ) => {
			http.client =
				optsRef.current.commandClient || CommandClient.fromGlobal();

			const recv = interpreter.makeNode( 'Tee', RECV );
			interpreter.makeNode( 'RulesView', VIEW );
			recv.connectNode( VIEW );

			interpreterRef.current = interpreter;
			shellRef.current = shell;

			bumpBuild( ( n ) => n + 1 );

			// One list once authed; its reply repaints the table.
			ensureSession().then( () => {
				if ( shellRef.current !== shell ) {
					return; // unmounted while /auth was in flight
				}
				fireList( shell );
			} );

			return () => {
				interpreterRef.current = null;
				shellRef.current = null;
			};
		};

		const { teardown } = mountExospine( build );
		return teardown;
	}, [] );

	// A node per mutating verb; the table refresh is a publish, not an await.
	const saveNode = useRequestNode( 'rules:save', 'rules' );
	const upsertNode = useRequestNode( 'rules:upsert', 'rules' );
	const deleteNode = useRequestNode( 'rules:delete', 'rules' );

	const list = useCallback( () => {
		if ( ! shellRef.current ) {
			return Promise.reject( new Error( 'graph not mounted' ) );
		}
		fireList( shellRef.current );
		return Promise.resolve();
	}, [] );

	// Run a mutating verb, then re-list to refresh the table; failure rejects.
	const runMutation = useCallback( async ( request, verb, args ) => {
		const result = await request( verb, args );
		if ( shellRef.current ) {
			fireList( shellRef.current );
		}
		return result;
	}, [] );

	// save/upsert: raw JSON is ONE arg token (CI json_decodes $args[0]).
	const saveAll = useCallback(
		( rules ) =>
			runMutation( saveNode, 'save', [ JSON.stringify( rules ) ] ),
		[ saveNode, runMutation ]
	);

	const upsert = useCallback(
		( rule ) =>
			runMutation( upsertNode, 'upsert', [ JSON.stringify( rule ) ] ),
		[ upsertNode, runMutation ]
	);

	const remove = useCallback(
		( id ) =>
			runMutation( deleteNode, 'delete', formatCommandArgs( [ id ] ) ),
		[ deleteNode, runMutation ]
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
