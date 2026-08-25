import { Node, VALUE, FROM, payloadOf } from '@newspack-nodes/runtime';

/**
 * `urldetail:merge` — the url_detail incremental merge and `last_modified`
 * dedup, hosted on the receiver-Tee → view graph EDGE rather than inside the
 * view (D1b de-god). `usePerformanceGraph` wires it by hand as `urldetail:in`
 * (Tee) → `urldetail:merge` → `urldetail:view`, the edge shape
 * `addSliceFetcher` standardizes in its optional `transform` slot.
 *
 * It receives the raw command reply (VALUE = `{ name, payload }`, the payload
 * being the url_detail object the server returned), merges that payload against
 * the one it last forwarded, and forwards a message whose VALUE.payload is the
 * MERGED object — or DROPS the message when `last_modified` is unchanged, so an
 * idle auto-refresh tick never re-renders the modal.
 *
 * The merge:
 *   - empty payload                   → drop (no forward);
 *   - first reply (no retained state) → forward as-is, record last_modified;
 *   - unchanged last_modified         → drop;
 *   - changed last_modified           → discard requests whose rid is already
 *                                       retained, sort the union newest-first
 *                                       by timestamp, cap at 500, forward.
 *
 * `scan_stopped_early` describes the LIST, not the last walk, so it unions with
 * `||` across merged replies: a walk that ran out of budget leaves rows missing
 * from the accumulation, and a later complete walk does not put them back.
 *
 * A `clear` control from `controlFrom` resets the retained state so the next
 * reply counts as fresh. `usePerformanceGraph` sends one when the modal closes,
 * which is what lets a reopened modal republish an unchanged `last_modified`.
 *
 * Forwarding runs through the base `fill()`, which stamps TO from `target` (the
 * view) and hands the message to the sink `makeNode` wired — the interpreter. It
 * does NOT stamp FROM: a transform is an internal edge, not an I/O boundary.
 */
export class UrlDetailMergeNode extends Node {
	/**
	 * Start with nothing retained, so the first reply through this edge counts
	 * as fresh and forwards as-is.
	 *
	 * Nothing is published here: this node sits on the graph edge and owns no
	 * view state — the model belongs to `urldetail:view` downstream.
	 */
	constructor() {
		super();
		// Last forwarded url_detail payload + last_modified (reset on clear).
		this._merged = null;
		this._lastModified = null;
		// True once any walk feeding the retained list was cut short.
		this._scanStoppedEarly = false;
		// FROM of controls; unset loses them silently (see LogStreamViewNode).
		this.controlFrom = '';
	}

	/**
	 * Merge this reply's payload into the retained one and forward the result,
	 * or consume the message when it carries nothing new.
	 *
	 * @param {Array} message Positional Message — a control from `controlFrom`,
	 *                        or a command reply whose VALUE is
	 *                        `{ name, payload }`.
	 */
	fill( message ) {
		const value = message[ VALUE ];

		// A control never forwards; `action` picks the verb once inside.
		if ( '' !== this.controlFrom && message[ FROM ] === this.controlFrom ) {
			this._control( value?.action );
			return;
		}

		const payload = payloadOf( value );
		const next = this._merge( payload );
		if ( null === next ) {
			// No-op (empty or unchanged last_modified) — drop, no republish.
			return;
		}
		// Forward merged payload to the view via sink (TO stamped from target).
		message[ VALUE ] = { ...value, payload: next };
		super.fill( message );
	}

	/**
	 * Apply one control verb: `clear` drops the retained payload so the next
	 * reply counts as fresh. An unrecognised verb is a no-op.
	 *
	 * @param {?string} action The verb.
	 */
	_control( action ) {
		if ( 'clear' === action ) {
			this._merged = null;
			this._lastModified = null;
			this._scanStoppedEarly = false;
		}
	}

	/**
	 * Merge one reply's payload into the retained payload, updating both
	 * retained fields when the result is forwardable.
	 *
	 * @param {Object|null} data The url_detail payload this reply carried.
	 * @return {Object|null} The payload to forward, or null to drop the message
	 *                       (empty payload, or `last_modified` unchanged).
	 */
	_merge( data ) {
		if ( ! data ) {
			return null;
		}
		// Unchanged last_modified: the server has nothing newer to render.
		if (
			null !== this._merged &&
			data.last_modified === this._lastModified
		) {
			return null;
		}
		this._lastModified = data.last_modified;
		// The note belongs to the list, so it unions the way the list does.
		this._scanStoppedEarly =
			this._scanStoppedEarly || !! data.scan_stopped_early;
		const prev = this._merged;
		let merged;
		if ( ! prev || ! prev.requests || ! prev.requests.length ) {
			merged = data;
		} else {
			const existingRids = new Set( prev.requests.map( ( r ) => r.rid ) );
			const newRequests =
				( data.requests || [] ).filter(
					( r ) => ! existingRids.has( r.rid )
				) || [];
			if ( 0 === newRequests.length ) {
				merged = { ...data, requests: prev.requests };
			} else {
				merged = {
					...data,
					requests: [ ...newRequests, ...prev.requests ]
						.sort(
							( a, b ) =>
								( b.timestamp || 0 ) - ( a.timestamp || 0 )
						)
						.slice( 0, 500 ),
				};
			}
		}
		if ( this._scanStoppedEarly ) {
			merged = { ...merged, scan_stopped_early: true };
		}
		this._merged = merged;
		return merged;
	}

	/**
	 * Console/palette metadata. `Hidden` keeps this edge transform out of the
	 * palette: it takes no constructor arguments, answers no verbs, and gets its
	 * target from the graph `usePerformanceGraph` builds.
	 *
	 * @return {Object} The node schema.
	 */
	static nodeSchema() {
		return {
			category: 'Hidden',
			description:
				'Merges url_detail replies incrementally on the receiver→view edge.',
			arguments: [],
			commands: [],
		};
	}
}
