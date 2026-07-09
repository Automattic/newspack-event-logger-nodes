import { Node, TYPE, VALUE, TM_ERROR } from '@newspack-nodes/runtime';
import {
	errorMessage,
	PendingReplies,
} from '@newspack-nodes/shared/pendingReplies';

/**
 * `rules:view` — owns the per-URL logging-ruleset editor view model.
 *
 * `fill()` receives the raw reply Messages HttpOutNode feeds back from POST
 * /command (the router peels the reply's TO = `rules:view`, stamped from the
 * outbound FROM by the server's TO=FROM reply). VALUE is the `{ name, payload }`
 * envelope.
 *
 * A `list` reply refreshes the table (`payload.rules`). The `replies` registry
 * lets the hook await `list` / `save` / `upsert` / `delete`: it stashes
 * `{ resolve, reject }` under each outbound `message[ID]`, and a matching reply
 * settles it. On a `list` reply the model ALSO refreshes even when the settle
 * path consumed it, so a mutation's awaited re-list repaints. An un-correlated
 * TM_ERROR surfaces as the table banner (prior rules preserved); a
 * pending-matched TM_ERROR is owned by the caller's catch and leaves the banner
 * clean. Every change publishes via `setState('view', model)`, read by
 * `useNodeState('rules:view','view')`.
 */
export class RulesViewNode extends Node {
	constructor() {
		super();
		this.model = {
			rules: [],
			loading: true,
			error: null,
		};
		this.replies = new PendingReplies();
		this._publish();
	}

	fill( message ) {
		const settled = this.replies.settle( message );
		if ( ! settled && 0 !== ( ( message[ TYPE ] || 0 ) & TM_ERROR ) ) {
			this._applyError( message[ VALUE ] );
			this._publish();
			return;
		}
		const value = message[ VALUE ];
		if ( value && 'object' === typeof value && 'list' === value.name ) {
			this._applyRules( value.payload );
			this._publish();
		}
	}

	// Turn the `{ rules: [...] }` payload into the render model.
	_applyRules( payload ) {
		const rules =
			payload &&
			'object' === typeof payload &&
			Array.isArray( payload.rules )
				? payload.rules
				: [];
		this.model = { rules, loading: false, error: null };
	}

	// Surface an un-correlated failure as the table banner: keep prior rules
	// (a transient mutation/list failure must not blank the table), clear loading.
	_applyError( value ) {
		const payload =
			value && 'object' === typeof value ? value.payload : value;
		this.model = {
			...this.model,
			error: errorMessage( payload ),
			loading: false,
		};
	}

	_publish() {
		this.setState( 'view', this.model );
	}

	// Reject every in-flight pending promise before the node is removed so a
	// graph teardown / Reset-Graph reinit doesn't strand a caller awaiting a
	// reply that will now never land on this (removed) node.
	removeNode() {
		this.replies.rejectAll( 'View removed before reply' );
		super.removeNode();
	}

	static nodeSchema() {
		return {
			category: 'Hidden',
			description: 'Owns the per-URL logging-ruleset editor view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}
}
