import { Node, VALUE } from '@newspack-nodes/runtime';

/**
 * `aggregator/view` — owns the Aggregator Status view model.
 *
 * `fill()` accepts the two TM_STRUCT controls the poll node emits:
 * - `{ action:'status', status, now }`: the raw `{ server_id:{} }` snapshot. The
 *   node turns it into the render model — `servers` (Object.values → array),
 *   `serverNow` (the hub's serve clock for "ago"), `connectedCount` (servers with
 *   ≥1 connected partition) / `totalCount`, clears `loading` + `error`, and
 *   stamps `lastRefresh` (browser-clock ms).
 * - `{ action:'error', error }`: stores the error and clears `loading`; the prior
 *   `servers` are preserved (matches the old fetchStatus catch, which set error
 *   only).
 *
 * The map→array + connected-count derivation migrated verbatim from
 * AggregatorStatus's render. Every change publishes via `setState('view', model)`,
 * consumed by `useNodeState('aggregator/view','view')` — this is a low-frequency
 * poll (1–10s), so there's no per-message React concern like the request stream.
 */
class AggregatorViewNode extends Node {
	constructor() {
		super();
		this.model = {
			servers: null,
			serverNow: null,
			connectedCount: 0,
			totalCount: 0,
			error: null,
			loading: true,
			lastRefresh: null,
		};
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( ! value || ! value.action ) {
			return;
		}
		if ( 'status' === value.action ) {
			this._applyStatus( value );
			this._publish();
		} else if ( 'error' === value.action ) {
			this._applyError( value );
			this._publish();
		}
	}

	// Turn the raw status map into the render model (matches the old fetchStatus
	// success path + the render-time connected-count computation).
	_applyStatus( { status, now } ) {
		const servers = Object.values( status || {} );
		const connectedCount = servers.filter( ( s ) => {
			const partitions = s.partitions || {};
			return Object.values( partitions ).some(
				( p ) => p?.last_connection_status === 'connected'
			);
		} ).length;
		this.model = {
			...this.model,
			servers,
			serverNow: now ?? null,
			connectedCount,
			totalCount: servers.length,
			error: null,
			loading: false,
			lastRefresh: Date.now(),
		};
	}

	// Store the error + clear loading; keep prior servers (old catch behavior).
	_applyError( { error } ) {
		this.model = {
			...this.model,
			error: error || 'Failed to fetch status',
			loading: false,
		};
	}

	_publish() {
		this.setState( 'view', this.model );
	}
}

/**
 * Create and register the Aggregator Status view-model node.
 *
 * @param {string} name Node name.
 * @return {AggregatorViewNode} The view-model node.
 */
export function createAggregatorView( name ) {
	const node = new AggregatorViewNode();
	node.setName( name );
	return node;
}
