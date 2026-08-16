import {
	Node,
	TYPE,
	VALUE,
	TM_ERROR,
	payloadOf,
} from '@newspack-nodes/runtime';
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
	/**
	 * Seeds the pre-reply model — no rules, no error, `loading` true — and
	 * publishes it at once, so a React subscriber that mounts before the first
	 * `list` reply lands reads a defined model rather than undefined.
	 */
	constructor() {
		super();
		this.model = {
			rules: [],
			loading: true,
			error: null,
		};
		this._publish();
	}

	/**
	 * Fold one reply into the model and republish it.
	 *
	 * TM_ERROR becomes the table banner over the rules already on screen. A
	 * mutation's failure is addressed to the node that minted it, so what
	 * lands here is the table's own `list` — no correlation is needed or done.
	 * Every other reply is ignored, leaving the current table alone: `save` /
	 * `upsert` / `delete` repaint by re-`list`ing, not by replying here.
	 *
	 * @param {Array} message The 7-field positional message; VALUE is the
	 *                        `{ name, payload }` reply envelope.
	 */
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

	/**
	 * Replace the table with the `list` reply's rules, clearing loading and any
	 * prior error.
	 *
	 * The whole list is replaced, never merged — `Rules_CI_Node`'s `list`
	 * always answers with the complete ruleset, so a rule deleted on the server
	 * has to disappear here. A payload without a `rules` array empties the
	 * table rather than throwing.
	 *
	 * @param {?{rules?: Object[]}} payload The `list` reply's `VALUE.payload`;
	 *                                      each rule is a `Rule::to_array()`
	 *                                      shape with `hooks` resolved.
	 */
	_applyRules( payload ) {
		const rules =
			payload &&
			'object' === typeof payload &&
			Array.isArray( payload.rules )
				? payload.rules
				: [];
		this.model = { rules, loading: false, error: null };
	}

	/**
	 * Raise a failure into the table banner, keeping the rules already on
	 * screen and clearing loading.
	 *
	 * The rules are deliberately preserved: the banner reports that the last
	 * refresh failed, and blanking the table would lose the (still accurate)
	 * ruleset the user is reading. Accepts either the `{ name, payload }`
	 * envelope or a bare payload, since a transport-level error carries no
	 * verb name.
	 *
	 * @param {*} value The TM_ERROR reply's VALUE; `errorMessage()` coerces a
	 *                  string, a `{ message }` object, or anything else to text.
	 */
	_applyError( value ) {
		const payload = payloadOf( value );
		this.model = {
			...this.model,
			error: errorMessage( payload ),
			loading: false,
		};
	}

	/**
	 * Push the current model onto the `view` state key React subscribes to via
	 * `useNodeState('rules:view','view')`.
	 */
	_publish() {
		this.setState( 'view', this.model );
	}

	/**
	 * Hidden from the node palette: `useRulesGraph` wires this sink itself, and
	 * it takes no arguments, answers no verbs, and forwards nothing.
	 *
	 * @return {Object} The `node_schema()` descriptor the console and `help` read.
	 */
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
