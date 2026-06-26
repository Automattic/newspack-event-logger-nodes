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
 * `fill()` handles three message kinds, mirroring the old performance-view-node
 * slice protocol but per-slice:
 *   - TM_STRUCT { action:'loading' }         → loading:true, error:null (data kept);
 *   - a command reply (VALUE = {name,payload}) → store the payload (subclass shapes
 *                                                it via storeResult), clear loading/error;
 *   - a TM_ERROR reply                        → error string, clear loading, KEEP prior data.
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

	// The shaped-but-empty slice; subclass override.
	emptySlice() {
		return { data: null, loading: false, error: null };
	}

	// Map a successful decoded payload onto the slice model; subclass override.
	storeResult( payload ) {
		this.model = { data: payload, loading: false, error: null };
	}

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
