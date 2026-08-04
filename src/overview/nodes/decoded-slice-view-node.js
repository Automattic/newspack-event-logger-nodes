import {
	Node,
	VALUE,
	TYPE,
	TM_ERROR,
	TM_STRUCT,
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
 * `fill()` handles these message kinds, in the order it tests them:
 *   - TM_STRUCT `{ action:'loading' }` → loading:true, error cleared, data kept;
 *   - TM_STRUCT `{ action:'clear' }`   → reset to `emptySlice()` (modal close);
 *   - TM_STRUCT `{ action:'error' }`   → a client-side validation failure:
 *     error set, loading cleared, prior data kept;
 *   - a TM_ERROR reply                 → error string, loading cleared, prior
 *     data KEPT;
 *   - a command reply (VALUE = `{ name, payload }`) → `storeResult( payload )`
 *     shapes the slice. A non-object VALUE is transport garbage and keeps the
 *     prior slice rather than blanking the widget.
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
		this.setState( 'view', this.model );
	}

	/**
	 * Apply one control or reply message to the slice, then publish it.
	 *
	 * @param {Array} message The 7-field envelope.
	 */
	fill( message ) {
		const value = message[ VALUE ];
		const type = message[ TYPE ] || 0;

		// Loading control: flip loading, clear error, keep data + siblings.
		if (
			TM_STRUCT === ( type & TM_STRUCT ) &&
			value &&
			'loading' === value.action
		) {
			this.model = { ...this.model, loading: true, error: null };
			this.setState( 'view', this.model );
			return;
		}

		// Clear control: reset to empty slice (modal close → next open fresh).
		if (
			TM_STRUCT === ( type & TM_STRUCT ) &&
			value &&
			'clear' === value.action
		) {
			this.model = this.emptySlice();
			this.setState( 'view', this.model );
			return;
		}

		// Client validation failure: surface error, clear loading, keep data.
		if (
			TM_STRUCT === ( type & TM_STRUCT ) &&
			value &&
			'error' === value.action
		) {
			this.model = {
				...this.model,
				loading: false,
				error: value.error || errorMessage( null ),
			};
			this.setState( 'view', this.model );
			return;
		}

		// TM_ERROR reply: surface the error, clear loading, KEEP prior data.
		if ( 0 !== ( type & TM_ERROR ) ) {
			const payload =
				value && 'object' === typeof value ? value.payload : value;
			this.model = {
				...this.model,
				loading: false,
				error: errorMessage( payload ),
			};
			this.setState( 'view', this.model );
			return;
		}

		// Success reply: only an object VALUE decodes; else keep prior slice.
		if ( ! value || 'object' !== typeof value ) {
			return;
		}
		this.storeResult( value.payload );
		this.setState( 'view', this.model );
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
