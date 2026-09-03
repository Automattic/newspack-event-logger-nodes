/**
 * useRulesGraph — the per-URL logging-ruleset editor's node graph, clipped onto
 * the canonical rule-#2 backbone (`_command_interpreter` → `_router`) through
 * the substrate's HTTP boundary node:
 *
 *   _http     (HttpOutNode) — the POST /command egress; `.client` is the
 *             transport it POSTs through
 *   rules:in  (Tee) → rules:view (a RulesView slice), repainted by every `list`
 *
 * Nothing here pairs a reply with its request, because the addressing already
 * is the correlation. Each MUTATING verb owns its own nodes — one
 * `useCommandOnce` each, scoped `rules:save`, `rules:upsert`, `rules:delete`
 * and `rules:reset` — and every scope mints FROM its own receiver Tee, so the
 * server's TO=FROM reply lands on exactly the Tee that asked. There is no id in
 * `message[ID]`, no `replies` map and nothing keyed by one; sending several
 * verbs in one tick means more nodes, never one node telling replies apart.
 *
 * `list` is the odd one out, deliberately: it is a publish, not an await. It is
 * minted FROM the `rules:in` Tee and filled through the `_shell` Tap
 * (observable at `connect _shell`), so its reply lands back on that Tee and
 * fans into `rules:view`, the render model every consumer reads.
 *
 * Either way the Router peels `_http` off the TO, HttpOutNode POSTs, and the
 * reply routes home by the TO the server echoed.
 *
 * The wire contract mirrors `Rules_CI_Node`: `save` and `upsert` pass the raw
 * JSON as a single argument token (the handler `json_decode`s `$args[0]`),
 * `delete` passes the id as a positional token, and `list` and `reset` take no
 * arguments. Every successful mutation re-`list`s, so the table repaints from
 * the server rather than from a locally patched copy; a refusal leaves the
 * server unchanged, so it repaints nothing.
 *
 * Nothing is injected: HttpOutNode defaults its own client lazily, and tests
 * seam at `fetch` (`installFakeCommandWire`), so packing, the POST, the Router
 * and the interpreter all run for real.
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

import { views } from './nodes/register';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';

/** The server-side CI mount every verb here is addressed to. */
const RULES_CI = 'rules';

/** The Tee that mints `list` and, by TO=FROM, receives its reply. */
const RECV = 'rules:in';

/** The slice view holding the table's render model. */
const VIEW = 'rules:view';

/**
 * Ask the `rules` CI to re-list, minted FROM the table's own receiver Tee: the
 * server echoes TO=FROM, so the reply lands on `rules:in`, fans into
 * `rules:view` and repaints the table. That repaint IS the result — nothing is
 * returned and no caller awaits one.
 *
 * The Tee carries no target, so the egress address is stamped on the message
 * here rather than configured on the node.
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
 * Mount the ruleset editor's graph and return the table with its CRUD verbs.
 *
 * Each mutation's answer lands on the node that asked, and a successful one
 * re-lists — so the TABLE repaints one round trip after the mutation settles.
 * Read the rules from the returned `rules`, never from a mutation's outcome.
 *
 * @param {Object}   [opts]            Options.
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
 *   leaving the banner for the caller to own.
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
			interpreter.makeNode( views.RulesView, VIEW );
			recv.connectNode( VIEW );

			interpreterRef.current = interpreter;
			shellRef.current = shell;

			bumpBuild( ( n ) => n + 1 );

			// One list once the session is up; its reply repaints the table.
			ensureSession().then( () => {
				if ( shellRef.current !== shell ) {
					return; // unmounted or rebuilt while /auth was in flight
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

	// One one-shot per verb; a success re-lists, a refusal only reports.
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

	// A document cannot address a reply: save sends no subject, upsert an id.
	const saveOnce = useCommandOnce( {
		ci: RULES_CI,
		command: 'save',
		subjectOf: () => null,
		onDone: settle( 'save' ),
	} );
	const upsertOnce = useCommandOnce( {
		ci: RULES_CI,
		command: 'upsert',
		subjectOf: ( [ rule ] ) => JSON.parse( rule ).id ?? null,
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
