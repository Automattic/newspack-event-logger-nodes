/**
 * useRulesGraph — mounts the per-URL logging-ruleset editor node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's HTTP I/O boundary node. Modeled on useVaultGraph, single-concern:
 *
 *   _http     (HttpOutNode — POST /command boundary; .client = the transport)
 *   rules:in  (Tee) → rules:view (RulesViewNode) — list/save/upsert/delete
 *
 * Nothing here is correlated, because the addressing already is the
 * correlation. Each MUTATING verb owns its own node — `rules:save`,
 * `rules:upsert`, `rules:delete`, one per verb via `useRequestNode` — and a
 * node stamps FROM with its own name, so the server's TO=FROM reply lands back
 * on exactly the node that minted it. There is no id in `message[ID]`, no
 * `replies` map, and nothing keyed by one; batching several verbs in a tick
 * would mean more nodes, never one node telling replies apart.
 *
 * `list` is the odd one out and deliberately so: it is a publish, not an await.
 * It fills through the `_shell` Tap (observable at `connect _shell`) addressed
 * to the `rules:in` Tee, whose reply fans into `rules:view`, which refreshes
 * the render model every consumer reads.
 *
 * Either way the router peels `_http`, HttpOutNode POSTs, and the reply routes
 * home by its TO.
 *
 * Wire contract mirrors Rules_CI_Node: `save`/`upsert` pass the RAW JSON as a
 * single arg token (the handler json_decodes `$args[0]`); `delete` passes the id
 * as a positional token; `list` takes no args (`[]`). Mutations re-`list()` to
 * refresh the table.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` (assigned
 * to `_http.client`). Production lets HttpOut default it.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Core,
	mountExospine,
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

/**
 * The three mutations resolve their own CI reply, but the TABLE they repaint is
 * refreshed by a separate `list` whose reply lands on `rules:in` — so an awaited
 * mutation settles BEFORE `rules` reflects it. `list` itself resolves as soon as
 * the command is on the wire, not when the table has repainted; read the table
 * from the returned `rules`, never from a `list()` resolution.
 *
 * @param {Object} [opts]               Options (testing seams).
 * @param {Object} [opts.commandClient] Transport seam assigned to `_http.client`.
 * @return {{ rules: Object[], loading: boolean, error: (string|null),
 *   list: () => Promise<void>,
 *   saveAll: (rules: Object[]) => Promise<Object>,
 *   upsert: (rule: Object) => Promise<Object>,
 *   remove: (id: string) => Promise<Object> }}
 *   The `rules:view` render model plus the CRUD callbacks. `loading` starts
 *   true and clears on the first `list` reply; `error` carries a `list`
 *   failure's banner — a mutation's failure REJECTS its own promise instead,
 *   leaving the banner clean for the caller's catch to own.
 */
export function useRulesGraph( opts = {} ) {
	const optsRef = useRef( opts );
	optsRef.current = opts;

	const interpreterRef = useRef( null );
	const shellRef = useRef( null );

	// Bumped on every rebuild so useNodeState re-subscribes to the fresh view.
	const [ , bumpBuild ] = useState( 0 );

	useEffect( () => {
		const build = ( { interpreter, shell, http } ) => {
			if ( optsRef.current.commandClient ) {
				http.client = optsRef.current.commandClient;
			}

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
