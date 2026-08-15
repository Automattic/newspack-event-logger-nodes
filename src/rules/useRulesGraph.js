/**
 * useRulesGraph — mounts the per-URL logging-ruleset editor node graph onto the
 * canonical rule-#2 backbone (`_command_interpreter → _router`) using the
 * substrate's HTTP I/O boundary node. Modeled on useVaultGraph, single-concern:
 *
 *   _http     (HttpOutNode — POST /command boundary; .client = the transport)
 *   rules:in  (Tee) → rules:view (RulesViewNode) — list/save/upsert/delete/reset
 *
 * Nothing here is correlated, because the addressing already is the
 * correlation. Each MUTATING verb owns its own node — `rules:save`,
 * `rules:upsert`, `rules:delete`, `rules:reset`, one per verb via
 * `useCommandOnce` — and a node stamps FROM with its own name, so the server's
 * TO=FROM reply lands back on exactly the node that minted it. There is no id in `message[ID]`, no
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
 * as a positional token; `list` and `reset` take no args (`[]`). Mutations
 * re-`list()` to refresh the table.
 *
 * Nothing is injected: HttpOut lazily defaults its own client, and tests seam
 * at `fetch` (`installFakeCommandWire`) so the whole egress runs for real.
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
	reservedNames as names,
} from '@newspack-nodes/runtime';

import './nodes/register';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';

const RULES_CI = 'rules';
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
	m[ TO ] = `${ names.HTTP }/rules`;
	shell.fill( m );
}

/**
 * Each mutation's answer lands on the node that asked, and re-lists — so the
 * TABLE repaints one round trip after the mutation settles. Read the table from
 * the returned `rules`, never from a mutation's outcome.
 *
 * @param {Object}   [opts]            Options (testing seams).
 * @param {Function} [opts.onMutation] `( { verb, error } ) => void`, fired once
 *                                     per mutation reply. A refusal arrives
 *                                     here rather than as a rejected promise:
 *                                     the answer lands a tick later, on the
 *                                     node that asked for it.
 * @return {{ rules: Object[], loading: boolean, error: (string|null),
 *   list: () => void,
 *   saveAll: (rules: Object[]) => void,
 *   upsert: (rule: Object) => void,
 *   remove: (id: string) => void,
 *   reset: () => void }}
 *   The `rules:view` render model plus the CRUD callbacks. `loading` starts
 *   true and clears on the first `list` reply; `error` carries a `list`
 *   failure's banner — a mutation's failure goes to `onMutation` instead,
 *   leaving the banner clean for the caller to own.
 */
export function useRulesGraph( opts = {} ) {
	const { onMutation } = opts;
	const optsRef = useRef( opts );
	optsRef.current = opts;

	const interpreterRef = useRef( null );
	const shellRef = useRef( null );

	// Bumped on every rebuild so useNodeState re-subscribes to the fresh view.
	const [ , bumpBuild ] = useState( 0 );

	useEffect( () => {
		const build = ( { interpreter, shell } ) => {
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

	// One one-shot per verb; each re-lists on its own answer.
	const onMutationRef = useRef( onMutation );
	onMutationRef.current = onMutation;
	const settle = useCallback(
		( verb ) =>
			( { error } ) => {
				if ( ! error && shellRef.current ) {
					fireList( shellRef.current );
				}
				onMutationRef.current?.( { verb, error } );
			},
		[]
	);

	const saveOnce = useCommandOnce( {
		ci: RULES_CI,
		command: 'save',
		onDone: settle( 'save' ),
	} );
	const upsertOnce = useCommandOnce( {
		ci: RULES_CI,
		command: 'upsert',
		onDone: settle( 'upsert' ),
	} );
	const deleteOnce = useCommandOnce( {
		ci: RULES_CI,
		command: 'delete',
		onDone: settle( 'delete' ),
	} );
	const resetOnce = useCommandOnce( {
		ci: RULES_CI,
		command: 'reset',
		onDone: settle( 'reset' ),
	} );

	const list = useCallback( () => {
		if ( shellRef.current ) {
			fireList( shellRef.current );
		}
	}, [] );

	// save/upsert: raw JSON is ONE arg token (CI json_decodes $args[0]).
	const saveAll = useCallback(
		( rules ) => saveOnce.run( [ JSON.stringify( rules ) ] ),
		[ saveOnce.run ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const upsert = useCallback(
		( rule ) => upsertOnce.run( [ JSON.stringify( rule ) ] ),
		[ upsertOnce.run ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const remove = useCallback(
		( id ) => deleteOnce.run( formatCommandArgs( [ id ] ) ),
		[ deleteOnce.run ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const reset = useCallback(
		() => resetOnce.run( [] ),
		[ resetOnce.run ] // eslint-disable-line react-hooks/exhaustive-deps
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
		reset,
	};
}
