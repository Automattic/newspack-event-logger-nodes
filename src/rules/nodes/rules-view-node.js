import { Node, TYPE, VALUE, TM_ERROR } from '@newspack-nodes/runtime';
import { errorMessage } from '@newspack-nodes/shared/errorMessage';

/**
 * `rules:view` — owns the per-URL logging-ruleset editor view model.
 *
 * `fill()` receives the raw reply Messages HttpOutNode feeds back from POST
 * /command (the router peels the reply's TO = `rules:view`, stamped from the
 * outbound FROM by the server's TO=FROM reply). VALUE is the `{ name, payload }`
 * envelope.
 *
 * A `list` reply refreshes the table (`payload.rules`).
 * TM_ERROR surfaces as the table banner (prior rules preserved); a mutation's
 * failure lands on ITS node, never here, so the caller's catch owns it and the
 * banner stays clean. Every change publishes via `setState('view', model)`, read by
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
		this._publish();
	}

	fill( message ) {
		if ( 0 !== ( ( message[ TYPE ] || 0 ) & TM_ERROR ) ) {
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

	// Uncorrelated failure → table banner; keep prior rules, clear loading.
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
