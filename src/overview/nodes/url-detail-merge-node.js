import { Node, VALUE, payloadOf } from '@newspack-nodes/runtime';
import { isControl } from '@newspack-nodes/shared/helpers/controlMsg';

/**
 * Retained request rows, matching the server's own per-URL cap
 * (`Performance_CI_Node::RECENT_REQUEST_LIMIT`), so an accumulation across
 * replies holds no more rows than one reply could have carried.
 */
const MERGED_REQUEST_LIMIT = 500;

/**
 * The reply fields the stats writer moves on its own clock, apart from the
 * request rows and the flame stamp: a refresh carrying a change in any of
 * them is news even with no new row.
 */
const LIVE_FIELDS = [
	'stats',
	'slots',
	'breakdown_time_series',
	'requests_window_start',
];

/**
 * The live fields of a payload in one comparable string.
 *
 * @param {Object|null} payload A dump_url payload.
 * @return {string} Its LIVE_FIELDS, serialized.
 */
const liveOf = ( payload ) =>
	JSON.stringify( LIVE_FIELDS.map( ( field ) => payload?.[ field ] ) );

/**
 * `url-detail:transform` — the dump_url incremental merge and the tail cursor,
 * hosted on the receiver-Tee → view graph EDGE rather than inside the view.
 * `usePerformanceGraph` declares it in the optional `transform` slot of
 * `addSliceFetcher`, which builds the edge `url-detail:in` (Tee) →
 * `url-detail:transform` → `url-detail:view` and stamps `controlFrom` from the
 * same declaration.
 *
 * It receives the raw command reply — VALUE is `{ name, payload }`, the payload
 * being the dump_url object the server returned — merges that payload against
 * the one it last forwarded, and forwards a message whose VALUE.payload is the
 * MERGED object.
 *
 * A reply is news when it carries a request this node does not hold, when
 * the aggregate it shows moved, which its `last_modified` says (the newest
 * flush of any partition's blob for the URL), or when a LIVE_FIELDS value
 * moved: the header stats, the chart slots, the breakdown series and the
 * window start, which the stats writer moves on its own clock. It DROPS a
 * reply where none moved, so an idle auto-refresh tick never re-renders the
 * modal. The three come from different stages — the request indexes, the
 * flame builders' flushes and the stats writer — so none stands in for
 * another.
 *
 * The merge is one rule. An empty payload forwards nothing. Anything else
 * discards the requests whose rid is already retained, sorts the union
 * newest-first by completion, caps it at MERGED_REQUEST_LIMIT, and forwards it
 * unless it is not news. A first reply is that rule with nothing retained.
 *
 * A null aggregate means "keep yours": a cold URL's flame is rebuilt on a full
 * read only, so a tail answers none, and the held flame keeps its stamp too.
 *
 * `scan_stopped_early` describes the LIST, not the last walk, so it carries
 * across merged replies: a walk that stopped short leaves rows missing from
 * the accumulation, and a later complete walk does not put them back. Only a
 * `clear` drops the note, with the list it described.
 *
 * A `clear` control from `controlFrom` resets the retained state and the
 * cursor. `usePerformanceGraph` sends one when the modal opens, when it closes
 * and whenever the server scope changes, so the next reply counts as fresh.
 * A `relist`, which it sends when Errors Only flips, does the same but keeps
 * the held flame and profiles: an errors-only reply rebuilds no flame, and the
 * one held still describes the URL.
 *
 * Only the answer to a question the `url-detail:fetch` Fetcher still asks
 * reaches it: the substrate's `url-detail:in:current` gate ahead of this edge
 * drops a reply the open, a rescope or an Errors Only flip superseded before
 * it can push the newer list's rows out.
 *
 * A refusal never arrives: the slice's gate sends it to the view, around this
 * node, so the retained list survives a refresh that failed.
 *
 * Forwarding runs through the base `fill()`, which stamps TO from `target` (the
 * view) and hands the message to the sink `makeNode` wired — the interpreter.
 * It does NOT stamp FROM: a transform is an internal edge, not an I/O boundary.
 */
export class UrlDetailMergeNode extends Node {
	/**
	 * Start with nothing retained, so the first reply through this edge counts
	 * as fresh and forwards as-is.
	 *
	 * Nothing is published here: this node sits on the graph edge and owns no
	 * view state — the model belongs to `url-detail:view` downstream.
	 */
	constructor() {
		super();
		// Last forwarded payload — the view holds it too; do not mutate.
		this._merged = null;
		// Partition => the { segment, offset } its walk last reached.
		this._cursor = {};
		// FROM the graph stamps controls with; the minter refuses an empty one.
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

		// A control never forwards, VALUE or none; `action` picks the verb.
		if ( isControl( this, message ) ) {
			this._control( value );
			return;
		}

		const payload = payloadOf( value );
		const next = this._merge( payload );
		if ( null === next ) {
			// Empty, or nothing new — drop, no republish.
			return;
		}
		// Forward the merged payload; base fill() stamps TO from target.
		message[ VALUE ] = { ...value, payload: next };
		super.fill( message );
	}

	/**
	 * Apply one control verb: `clear` drops the retained payload and the
	 * cursor, so the next reply counts as fresh; `relist` does the same but
	 * keeps the held flame, profiles and their stamp. An unrecognised or
	 * absent verb is a no-op.
	 *
	 * @param {?{action?: string}} control The control's VALUE.
	 */
	_control( control ) {
		const action = control?.action;
		if ( 'clear' !== action && 'relist' !== action ) {
			return;
		}
		const held = this._merged;
		this._merged =
			'relist' === action && held
				? {
						aggregate_flame: held.aggregate_flame,
						aggregate_profiles: held.aggregate_profiles,
						last_modified: held.last_modified,
				  }
				: null;
		this._cursor = {};
	}

	/**
	 * Merge one reply's payload into the retained payload, replacing what is
	 * retained whenever the result is forwardable, and take the position each
	 * partition's walk reached. A row never moves the cursor, because a walk the budget
	 * cut returned its newest rows and left older lines unread.
	 *
	 * @param {Object|null} data The dump_url payload this reply carried.
	 * @return {Object|null} The payload to forward, or null to drop the message
	 *                       (empty payload, or no row, stamp or live field
	 *                       moved).
	 */
	_merge( data ) {
		if ( ! data ) {
			return null;
		}
		const incoming = data.requests ?? [];
		// Each walk's reach; a partition the walk cut keeps its held place.
		Object.assign( this._cursor, data.positions );
		const prev = this._merged;
		const held = prev?.requests ?? [];
		const heldRids = new Set( held.map( ( r ) => r.rid ) );
		const fresh = incoming.filter( ( r ) => ! heldRids.has( r.rid ) );
		const merged = {
			...data,
			requests: [ ...fresh, ...held ]
				// The server's order: completion, start plus the raw duration.
				.sort( ( a, b ) => b.finished_at - a.finished_at )
				.slice( 0, MERGED_REQUEST_LIMIT ),
		};
		// The note belongs to the list, so it unions the way the list does.
		if ( prev?.scan_stopped_early ) {
			merged.scan_stopped_early = true;
		}
		// Null means "keep yours": a cold URL is rebuilt on a full read only.
		for ( const field of [ 'aggregate_flame', 'aggregate_profiles' ] ) {
			if ( null === ( data[ field ] ?? null ) && prev?.[ field ] ) {
				merged[ field ] = prev[ field ];
			}
		}
		if (
			null === ( data.aggregate_flame ?? null ) &&
			prev?.aggregate_flame
		) {
			merged.last_modified = prev.last_modified;
		}
		if (
			null !== prev &&
			0 === fresh.length &&
			merged.last_modified === prev.last_modified &&
			liveOf( merged ) === liveOf( prev )
		) {
			return null;
		}
		this._merged = merged;
		return merged;
	}

	/**
	 * The browser's tail cursor, the position each partition's walk last
	 * reached, whether or not it held the URL. `dump_url --after` hands it to
	 * the server, which reads a partition only past its own position, and
	 * reads a partition it does not name whole.
	 *
	 * @return {Object|null} Partition => `{ segment, offset }`; null with
	 *                       nothing seen, so a reopened modal reads the
	 *                       whole window.
	 */
	cursor() {
		return 0 === Object.keys( this._cursor ).length
			? null
			: { ...this._cursor };
	}

	/**
	 * Console/palette metadata. `Hidden` keeps this edge transform out of the
	 * palette: it answers no verbs, and takes its target from the graph
	 * `usePerformanceGraph` builds.
	 *
	 * @return {Object} The node schema.
	 */
	static nodeSchema() {
		return {
			category: 'Hidden',
			description:
				'Merges dump_url replies incrementally on the receiver→view edge.',
			arguments: [],
			commands: [],
		};
	}
}
