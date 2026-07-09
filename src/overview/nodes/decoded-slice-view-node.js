import {
	Node,
	VALUE,
	TYPE,
	TM_ERROR,
	TM_STRUCT,
} from '@newspack-nodes/runtime';
import { errorMessage } from '@newspack-nodes/shared/pendingReplies';

/**
 * DecodedSliceViewNode — the focused custom slice-view base for the Performance
 * Dashboard's always-on poll slices (D1b de-god). Each subclass owns ONE slice
 * `{ data, loading, error }` and publishes it via setState('view', …) for one
 * small React widget (useNodeState).
 *
 * This is the D4 "decoded-object" pattern, deliberately NOT the substrate's
 * SliceViewNode: the Performance verbs return LIVE array/object payloads (the
 * server kept array returns — the D1 server half preserved them), so the wire
 * payload arrives ALREADY decoded as `value.payload`. SliceViewNode._parse
 * expects a JSON STRING and would JSON.parse a live object — forcing it here is
 * false symmetry. So this base reads `value.payload` as an object directly.
 *
 * `fill()` handles these message kinds (per-slice):
 *   - TM_STRUCT { action:'loading' }         → loading:true, error:null (data kept);
 *   - TM_STRUCT { action:'clear' }           → reset to emptySlice() (modal close);
 *   - a command reply (VALUE = {name,payload}) → store the payload (subclass shapes
 *                                                it via storeResult), clear loading/error;
 *   - a TM_ERROR reply                        → error string, clear loading, KEEP prior data.
 *
 * Optional awaited-verb path: a subclass that ALSO awaits a verb (the on-demand
 * url_detail/request_detail views await fetchUrlBreakdown/resolveRequest) assigns
 * `this.replies = new PendingReplies()` and stashes `{ resolve, reject }` under
 * each outbound `message[ID]`. `fill()` then settles a matching reply FIRST and
 * returns — the awaited reply never touches the data slice. With no match (or no
 * `replies`) it falls through to the slice path below.
 *
 * Subclasses supply `emptySlice()` (the shaped-but-empty initial model) and
 * `storeResult(payload)` (how a successful payload becomes the slice). A
 * non-object reply payload (transport garbage) keeps the prior slice.
 */
export class DecodedSliceViewNode extends Node {
	constructor() {
		super();
		this.model = this.emptySlice();
		this.setState( 'view', this.model );
	}

	fill( message ) {
		// Optional awaited-verb path: a settled reply is fully consumed here and
		// never falls through to the slice logic.
		if ( this.replies && this.replies.settle( message ) ) {
			return;
		}

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

		// Clear control: reset to the empty slice (modal close → next open fresh).
		if (
			TM_STRUCT === ( type & TM_STRUCT ) &&
			value &&
			'clear' === value.action
		) {
			this.model = this.emptySlice();
			this.setState( 'view', this.model );
			return;
		}

		// Error control: a client-side validation failure (the hook emits this for
		// an invalid hash / rid before any network call). Surface the error, clear
		// loading, KEEP prior data — same as a TM_ERROR reply.
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

		// Success reply: only an object VALUE carries a decoded payload. A
		// non-object reply (transport garbage) keeps the prior slice.
		if ( ! value || 'object' !== typeof value ) {
			return;
		}
		this.storeResult( value.payload );
		this.setState( 'view', this.model );
	}

	// The shaped-but-empty slice; subclass override.
	emptySlice() {
		return { data: null, loading: false, error: null };
	}

	// Map a successful decoded payload onto the slice model; subclass override.
	storeResult( payload ) {
		this.model = { data: payload, loading: false, error: null };
	}

	_publish() {
		this.setState( 'view', this.model );
	}

	// Consume-and-publish terminal: fill() mutates + publishes, never forwards.
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
