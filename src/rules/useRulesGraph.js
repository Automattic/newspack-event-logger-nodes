/**
 * useRulesGraph — the per-URL logging-ruleset editor's node graph, clipped onto
 * the canonical rule-#2 backbone (`_command_interpreter` → `_router`) through
 * the substrate's HTTP boundary node:
 *
 *   _http       (HttpOutNode) — the POST /command egress; `.client` is the
 *               transport it POSTs through
 *   rules:fetch (Fetcher) → rules:shell/_http/rules, asking for `dump`
 *   rules:in    (Tee) → rules:view (a RulesView slice), repainted by every
 *               `dump`, then → rules:fetch, settling the ask
 *
 * Nothing here pairs a reply with its request, because the addressing already
 * is the correlation. Each MUTATING verb owns its own nodes — one
 * `useCommandOnce` each, scoped `rules:save`, `rules:upsert`, `rules:delete`
 * and `rules:reset` — and every scope mints FROM its own receiver Tee, so the
 * server's TO=FROM reply lands on exactly the Tee that asked. There is no id in
 * `message[ID]`, no `replies` map and nothing keyed by one; sending several
 * verbs in one tick means more nodes, never one node telling replies apart.
 *
 * `dump` is the odd one out, deliberately: it is a publish, not an await. The
 * `rules:fetch` Fetcher asks for it FROM the `rules:in` Tee, targeting the
 * editor's `rules:shell` Tap (observable at `connect rules:shell`), so its
 * reply lands back on that Tee and fans into `rules:view`, the render model
 * every consumer reads, and then into the Fetcher, which settles the ask. It
 * passes no `<receiver>:current` gate, because every dump asks the same `[]`:
 * a gate cannot tell a stale reply from a fresh one, and the last one wins.
 * That target is also what mounts the Tap: the exospine claims the group a
 * built node targets.
 *
 * Either way the Router peels `rules:shell` and `_http` off the TO, HttpOutNode
 * POSTs, and the reply routes home by the TO the server echoed.
 *
 * The wire contract mirrors `Rules_CI_Node`: `save` and `upsert` pass the raw
 * JSON as a single argument token (the handler `json_decode`s `$args[0]`),
 * `delete` passes the id as a positional token, and `dump` and `reset` take no
 * arguments. Every successful mutation re-`dump`s, so the table repaints from
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
	useNodeField,
	formatCommandArgs,
	ensureSession,
} from '@newspack-nodes/runtime';

import { views } from './nodes/register';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';
import { GROUPS } from '../overview/constants';

/** The server-side CI mount every verb here is addressed to. */
const RULES_CI = 'rules';

/** The Tee `dump` is asked FROM and, by TO=FROM, receives its reply. */
const RECV = 'rules:in';

/** The slice view holding the table's render model. */
const VIEW = 'rules:view';

/** The Fetcher that asks for `dump`, targeting the editor's egress. */
const FETCH = 'rules:fetch';

/**
 * Ask the `rules` CI to re-dump, FROM the table's own receiver Tee: the server
 * echoes TO=FROM, so the reply lands on `rules:in`, fans into `rules:view` and
 * repaints the table. That repaint IS the result — nothing is returned and no
 * caller awaits one. The ask supersedes one still standing, because a dump
 * after a mutation must go out even while an earlier dump is unanswered.
 */
function fireDump() {
	Core.node( FETCH )?.askNow( [] );
}

/**
 * Mount the ruleset editor's graph and return the table with its CRUD verbs.
 *
 * Each mutation's answer lands on the node that asked, and a successful one
 * re-dumps — so the TABLE repaints one round trip after the mutation settles.
 * Read the rules from the returned `rules`, never from a mutation's outcome.
 *
 * @param {Object}   [opts]            Options.
 * @param {Function} [opts.onMutation] `( { verb, error } ) => void`, fired once
 *                                     per mutation reply. A refusal arrives
 *                                     here rather than as a rejected promise:
 *                                     the answer lands a tick later, on the
 *                                     node that asked for it.
 * @return {{ rules: Object[], loading: boolean, error: (string|null),
 *   dump: () => void,
 *   saveAll: (rules: Object[]) => void,
 *   upsert: (rule: Object) => void,
 *   remove: (id: string) => void,
 *   reset: () => void }}
 *   The `rules:view` render model plus the CRUD callbacks. `loading` starts
 *   true and clears on the first `dump` reply; `error` carries a `dump`
 *   failure's banner — a mutation's failure goes to `onMutation` instead,
 *   leaving the banner for the caller to own.
 */
export function useRulesGraph( opts = {} ) {
	const { onMutation } = opts;
	const optsRef = useRef( opts );
	optsRef.current = opts;

	const interpreterRef = useRef( null );

	// Bumped on every rebuild so useNodeField re-subscribes to the fresh view.
	const [ , bumpBuild ] = useState( 0 );

	useEffect( () => {
		const build = ( { interpreter } ) => {
			const recv = interpreter.makeNode( 'Tee', RECV );
			interpreter.makeNode( views.RulesView, VIEW );
			interpreter
				.makeNode( 'Fetcher', FETCH, [ RECV, 'dump' ] )
				.connectNode( egressPath( GROUPS.rules, RULES_CI ) );
			// No answers() gate: every dump asks the same, and the last wins.
			recv.connectNode( VIEW );
			recv.connectNode( FETCH );

			interpreterRef.current = interpreter;

			bumpBuild( ( n ) => n + 1 );

			// One dump once the session is up; its reply repaints the table.
			ensureSession().then( () => {
				if ( interpreterRef.current !== interpreter ) {
					return; // unmounted or rebuilt while /auth was in flight
				}
				fireDump();
			} );

			return () => {
				interpreterRef.current = null;
			};
		};

		const { teardown } = mountExospine( build );
		return teardown;
	}, [] );

	// One one-shot per verb; a success re-dumps, a refusal only reports.
	const onMutationRef = useRef( onMutation );
	onMutationRef.current = onMutation;
	const settle = useCallback(
		( verb ) =>
			( { error } ) => {
				if ( ! error ) {
					fireDump();
				}
				onMutationRef.current?.( { verb, error } );
			},
		[]
	);

	// A document cannot address a reply: save sends no subject, upsert an id.
	const saveOnce = useCommandOnce( {
		group: GROUPS.rules,
		ci: RULES_CI,
		command: 'save',
		subjectOf: () => null,
		onDone: settle( 'save' ),
	} );
	const upsertOnce = useCommandOnce( {
		group: GROUPS.rules,
		ci: RULES_CI,
		command: 'upsert',
		subjectOf: ( [ rule ] ) => JSON.parse( rule ).id ?? null,
		onDone: settle( 'upsert' ),
	} );
	const deleteOnce = useCommandOnce( {
		group: GROUPS.rules,
		ci: RULES_CI,
		command: 'delete',
		onDone: settle( 'delete' ),
	} );
	const resetOnce = useCommandOnce( {
		group: GROUPS.rules,
		ci: RULES_CI,
		command: 'reset',
		onDone: settle( 'reset' ),
	} );

	const dump = useCallback( () => fireDump(), [] );

	// save/upsert: the JSON is ONE token, the verb's `rules` or `rule` arg.
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

	const model = useNodeField( VIEW, 'view' );
	return {
		rules: model?.rules ?? [],
		loading: model?.loading ?? true,
		error: model?.error ?? null,
		dump,
		saveAll,
		upsert,
		remove,
		reset,
	};
}
