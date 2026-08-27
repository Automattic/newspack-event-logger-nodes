import { Node, VALUE, FROM, payloadOf } from '@newspack-nodes/runtime';

/**
 * Retained request rows, matching the server's own per-URL cap
 * (`Performance_CI_Node::RECENT_REQUEST_LIMIT`).
 */
const MERGED_REQUEST_LIMIT = 500;

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
 *   - unchanged last_modified         → drop;
 *   - anything else                   → discard requests whose rid is already
 *                                       retained, sort the union newest-first
 *                                       by timestamp, cap at MERGED_REQUEST_LIMIT,
 *                                       forward. A first reply is that rule with
 *                                       nothing retained, not a case of its own.
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
		// Last forwarded payload; its own fields are the only copies.
		this._merged = null;
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
		const prev = this._merged;
		// Explicit null test: two undefined stamps would drop the first reply.
		if ( null !== prev && data.last_modified === prev.last_modified ) {
			return null;
		}
		const held = prev?.requests ?? [];
		const heldRids = new Set( held.map( ( r ) => r.rid ) );
		const merged = {
			...data,
			requests: [
				...( data.requests ?? [] ).filter(
					( r ) => ! heldRids.has( r.rid )
				),
				...held,
			]
				.sort( ( a, b ) => ( b.timestamp || 0 ) - ( a.timestamp || 0 ) )
				.slice( 0, MERGED_REQUEST_LIMIT ),
		};
		// The note belongs to the list, so it unions the way the list does.
		if ( prev?.scan_stopped_early ) {
			merged.scan_stopped_early = true;
		}
		this._merged = merged;
		return merged;
	}

	/**
	 * The browser's watermark: the newest request this node holds. `url_detail
	 * --since` hands it to the server, whose reverse scan stops below it — so a
	 * poll reads the entries since the last one rather than the whole window.
	 *
	 * Exactly the newest, with no slack: the server's stop is exclusive, so the
	 * same-second sibling is still read and `_merge` discards the overlap by
	 * rid. A second subtracted here would guess at the index's resolution.
	 *
	 * @return {number} Epoch seconds; 0 with nothing retained reads the whole
	 *                  window, which is what a reopened modal wants.
	 */
	watermark() {
		// The list is sorted newest-first, by the server and by `_merge`.
		const newest = this._merged?.requests?.[ 0 ]?.timestamp;
		return Number.isFinite( newest ) ? Math.floor( newest ) : 0;
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
