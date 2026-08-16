import {
	Node,
	VALUE,
	TYPE,
	FROM,
	TM_ERROR,
	payloadOf,
} from '@newspack-nodes/runtime';
import { errorMessage } from '@newspack-nodes/shared/errorMessage';

/**
 * DecodedSliceViewNode — the slice-view base every Performance Dashboard slice
 * extends. Each subclass owns ONE slice `{ data, loading, error }` and
 * publishes it via `setState('view', …)` for one small React widget
 * (`useNodeState`). Four subclasses exist: the polled `overview:view` and
 * `urls:view`, and the on-demand modal slices `urldetail:view` and
 * `requestdetail:view`.
 *
 * It is deliberately NOT the substrate's `SliceViewNode`. The `performance` CI
 * verbs return live PHP arrays, so a reply's payload arrives here ALREADY
 * decoded as `value.payload`; `SliceViewNode._parse` expects a JSON STRING and
 * would `JSON.parse` a live object. This base reads `value.payload` as an
 * object directly.
 *
 * `fill()` tests the ORIGIN first: a message from `controlFrom` is a control,
 * and its `action` picks the verb — `loading` (loading:true, error cleared,
 * data kept), `clear` (reset to `emptySlice()`, the modal close) or `error` (a
 * client-side validation failure: error set, loading cleared, prior data kept).
 * A control is never recognised by what its payload looks like; a reply shaped
 * that way is still a reply. Everything else is one:
 *   - a TM_ERROR reply → error string, loading cleared, prior data KEPT;
 *   - a command reply (VALUE = `{ name, payload }`) → `storeResult( payload )`
 *     shapes the slice. A VALUE that is not an object, or carries no payload,
 *     is transport garbage and keeps the prior slice rather than blanking the
 *     widget.
 *
 * `usePerformanceGraph` mints both halves. `sendControl()` fills the TM_STRUCT
 * controls straight into the view. A command stamps FROM with the node the
 * reply should land on — a receiver Tee that forwards here for `overview`,
 * `urls`, and `url_detail`, the view itself for `request_detail` — and the
 * server replies TO=FROM. An AWAITED verb is minted from its own Request node
 * and its reply is addressed there, so what lands here is only this slice's.
 *
 * `fill()` consumes and publishes; it never forwards, so the node needs no
 * sink. Subclasses supply `emptySlice()` (the shaped-but-empty initial model)
 * and `storeResult( payload )` (how a successful payload becomes the slice).
 */
export class DecodedSliceViewNode extends Node {
	/**
	 * Publish the subclass's `emptySlice()` immediately, so the widget reading
	 * `useNodeState` renders its shaped-but-empty state instead of undefined
	 * while the first reply is still in flight.
	 *
	 * `emptySlice()` is called on the SUBCLASS here — the subclass's own
	 * constructor body has not run yet, so an override must not depend on
	 * fields it assigns after `super()`.
	 */
	constructor() {
		super();
		this.model = this.emptySlice();
		// FROM of controls; unset loses them silently (see LogStreamViewNode).
		this.controlFrom = '';
		this.setState( 'view', this.model );
	}

	/**
	 * Apply one control or reply message to the slice, then publish it.
	 *
	 * A control is recognised by WHO SENT IT, never by what its payload looks
	 * like — a reply whose payload happens to carry an `action` field is a
	 * reply, and must still decode into the slice.
	 *
	 * @param {Array} message The 7-field envelope.
	 */
	fill( message ) {
		const value = message[ VALUE ];
		const type = message[ TYPE ] || 0;

		if ( '' !== this.controlFrom && message[ FROM ] === this.controlFrom ) {
			this._control( value?.action, value );
			this.setState( 'view', this.model );
			return;
		}

		// TM_ERROR reply: surface the error, clear loading, KEEP prior data.
		if ( 0 !== ( type & TM_ERROR ) ) {
			const payload = payloadOf( value );
			this.model = {
				...this.model,
				loading: false,
				error: errorMessage( payload ),
			};
			this.setState( 'view', this.model );
			return;
		}

		// A payload decodes; anything else keeps the prior slice.
		if (
			! value ||
			'object' !== typeof value ||
			undefined === value.payload
		) {
			return;
		}
		this.storeResult( value.payload );
		this.setState( 'view', this.model );
	}

	/**
	 * Apply one control verb to the model: `loading` flips the spinner and
	 * clears the error (keeping data and siblings), `clear` resets to the empty
	 * slice (modal close → next open fresh), and `error` surfaces a client-side
	 * validation failure while keeping the data already on screen.
	 *
	 * @param {?string} action The verb; anything else leaves the model alone.
	 * @param {?Object} value  The control payload (`error` carries `error`).
	 */
	_control( action, value ) {
		if ( 'loading' === action ) {
			this.model = { ...this.model, loading: true, error: null };
		} else if ( 'clear' === action ) {
			this.model = this.emptySlice();
		} else if ( 'error' === action ) {
			this.model = {
				...this.model,
				loading: false,
				error: value.error || errorMessage( null ),
			};
		}
	}

	/**
	 * The shaped-but-empty slice — published before the first reply lands, and
	 * what a `clear` control resets to. An override keeps `loading` and
	 * `error`, which `fill()` spreads over. Subclass override.
	 *
	 * @return {Object} The empty slice model.
	 */
	emptySlice() {
		return { data: null, loading: false, error: null };
	}

	/**
	 * Map a successful decoded payload onto the slice model. Subclass override.
	 *
	 * An override ASSIGNS `this.model`; its return value is ignored, and
	 * `fill()` publishes whatever the assignment left behind.
	 *
	 * @param {*} payload The reply's decoded `VALUE.payload`.
	 */
	storeResult( payload ) {
		this.model = { data: payload, loading: false, error: null };
	}

	/**
	 * Publish the current model to the `view` subscribers — the hook for a
	 * subclass that mutates `this.model` outside `fill()`. No subclass here
	 * does; the sibling dashboards' view nodes each carry their own copy.
	 */
	_publish() {
		this.setState( 'view', this.model );
	}

	/**
	 * The console-palette schema. Hidden, argument-less, verb-less, and
	 * target-less: `fill()` mutates and publishes, never forwards.
	 *
	 * @return {Object} The node schema.
	 */
	static nodeSchema() {
		return {
			category: 'Hidden',
			description:
				'Owns one decoded-object dashboard slice for its widget.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}
}
