/**
 * Aggregator Status Component
 *
 * THIN view over the `aggregator/*` node graph (mounted by
 * useAggregatorStatusGraph). The graph owns all data: `aggregator/poll` runs the
 * status command on the hook's interval and `aggregator/view` turns the raw
 * snapshot into the render model (map→array, connected count, serverNow). This
 * component only reads that model (via useNodeState) and renders — the pure
 * presentation helpers below (formatTime / formatRtt / getRttClass /
 * PartitionStatus / ServerCard) are unchanged. The 1s "ago" tick stays here: it's
 * pure display, re-rendering the relative timestamps without re-polling.
 */

import { useState, useEffect } from '@wordpress/element';

import { useNodeState } from '@newspack-nodes/runtime';
import {
	useAggregatorStatusGraph,
	REFRESH_OPTIONS,
} from './hooks/useAggregatorStatusGraph';
import './styles/aggregator-status.scss';

// The view model before the first poll publishes one — drives the loading gate.
const EMPTY_MODEL = {
	servers: null,
	serverNow: null,
	connectedCount: 0,
	totalCount: 0,
	error: null,
	loading: true,
	lastRefresh: null,
};

/**
 * Format a Unix timestamp as relative time or absolute.
 *
 * @param {number} timestamp Unix timestamp in seconds.
 * @param {number} now       Reference clock (the server's snapshot time); falls
 *                           back to the browser clock when omitted.
 * @return {string} Formatted time string.
 */
const formatTime = ( timestamp, now ) => {
	if ( ! timestamp ) {
		return '-';
	}

	// `now` is the server's clock at the moment it built this status snapshot
	// (the response Message's TIMESTAMP). Computing "ago" against it — not the
	// browser clock — means the value reflects what the aggregator itself saw
	// and stays fixed between dashboard refreshes (no client-side drift). Falls
	// back to the browser clock only for callers without a server time (header).
	const ref = now ?? Date.now() / 1000;
	const diff = ref - timestamp;

	if ( diff < 60 ) {
		return `${ Math.round( diff ) }s ago`;
	}
	if ( diff < 3600 ) {
		return `${ Math.round( diff / 60 ) }m ago`;
	}

	const date = new Date( timestamp * 1000 );
	return date.toLocaleTimeString();
};

/**
 * Format RTT value with appropriate precision.
 *
 * @param {number} rtt Round-trip time in milliseconds.
 * @return {string} Formatted RTT.
 */
const formatRtt = ( rtt ) => {
	if ( rtt === null || rtt === undefined ) {
		return null;
	}
	if ( rtt < 1 ) {
		return rtt.toFixed( 2 );
	}
	if ( rtt < 100 ) {
		return rtt.toFixed( 1 );
	}
	return Math.round( rtt ).toString();
};

/**
 * Get RTT color class based on value.
 *
 * @param {number} rtt Round-trip time in milliseconds.
 * @return {string} CSS class name.
 */
const getRttClass = ( rtt ) => {
	if ( rtt === null || rtt === undefined ) {
		return 'muted';
	}
	if ( rtt > 500 ) {
		return 'error';
	}
	if ( rtt > 200 ) {
		return 'warning';
	}
	return 'success';
};

/**
 * Partition Status Component.
 *
 * @param {Object} props           Component props.
 * @param {number} props.partition Partition number.
 * @param {Object} props.status    Partition status data.
 * @param {number} props.now       Server snapshot clock for relative-time calc.
 * @return {import('react').ReactElement} Rendered component.
 */
function PartitionStatus( { partition, status, now } ) {
	const connectionStatus = status.last_connection_status || 'disconnected';
	const heartbeatStatus = status.last_heartbeat_response_status || 'pending';
	const errorMessage =
		status.last_connection_error || status.last_heartbeat_error;
	const rtt = status.last_heartbeat_rtt;
	const rttFormatted = formatRtt( rtt );

	return (
		<div className="aggregator-partition">
			<div className="aggregator-partition-header">
				<span className="aggregator-partition-label">
					p{ partition }
				</span>
				<span
					className={ `aggregator-status-badge small ${ connectionStatus }` }
				>
					{ connectionStatus.replace( '_', ' ' ) }
				</span>
			</div>
			<div className="aggregator-partition-stats">
				<div className="aggregator-partition-row">
					<span className="aggregator-partition-stat-label">
						{ connectionStatus === 'connected'
							? 'Connected'
							: 'Attempt' }
					</span>
					<span className="aggregator-partition-stat-value">
						{ formatTime( status.last_connection_attempt, now ) }
					</span>
				</div>
				<div className="aggregator-partition-row">
					<span className="aggregator-partition-stat-label">
						Server HB
					</span>
					<span className="aggregator-partition-stat-value">
						{ formatTime( status.last_sse_heartbeat, now ) }
					</span>
				</div>
				<div className="aggregator-partition-row">
					<span className="aggregator-partition-stat-label">
						Client HB
					</span>
					<span className="aggregator-partition-stat-value">
						{ rttFormatted && (
							<span
								className={ `aggregator-heartbeat-rtt small ${ getRttClass(
									rtt
								) }` }
							>
								{ rttFormatted }ms
							</span>
						) }
						{ formatTime( status.last_heartbeat_response, now ) }
					</span>
				</div>
				<div className="aggregator-partition-row">
					<span className="aggregator-partition-stat-label">
						Status
					</span>
					<span
						className={ `aggregator-heartbeat-badge small ${ heartbeatStatus }` }
					>
						{ heartbeatStatus.replace( '_', ' ' ) }
					</span>
				</div>
			</div>
			{ ( status.last_connection_response || errorMessage ) && (
				<div
					className="aggregator-partition-error"
					title={ errorMessage }
				>
					{ status.last_connection_response && (
						<span
							className={ `aggregator-http-code${
								status.last_connection_response === 200
									? ' success'
									: ''
							}` }
						>
							HTTP { status.last_connection_response }
						</span>
					) }
					{ status.last_connection_response && errorMessage && ' ' }
					{ errorMessage }
				</div>
			) }
		</div>
	);
}

/**
 * Server Card Component.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.server Server status data.
 * @param {number} props.now    Server snapshot clock for relative-time calc.
 * @return {import('react').ReactElement} Rendered component.
 */
function ServerCard( { server, now } ) {
	const partitions = server.partitions || {};
	const partitionKeys = Object.keys( partitions ).sort(
		( a, b ) => Number( a ) - Number( b )
	);

	// Count connected partitions.
	const connectedPartitions = partitionKeys.filter(
		( p ) => partitions[ p ]?.last_connection_status === 'connected'
	).length;

	return (
		<div
			className={ `aggregator-server-card${
				! server.enabled ? ' disabled' : ''
			}` }
		>
			{ /* Server Identity */ }
			<div className="aggregator-server-identity">
				<div className="aggregator-server-id">{ server.id }</div>
				<div className="aggregator-server-url" title={ server.url }>
					{ server.url }
				</div>
				<div className="aggregator-server-partition-count">
					{ connectedPartitions }/{ partitionKeys.length } partitions
				</div>
			</div>

			{ /* Partition Status Grid */ }
			<div className="aggregator-partitions">
				{ partitionKeys.map( ( p ) => (
					<PartitionStatus
						key={ p }
						partition={ Number( p ) }
						status={ partitions[ p ] || {} }
						now={ now }
					/>
				) ) }
			</div>
		</div>
	);
}

/**
 * Aggregator Status Dashboard Component.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function AggregatorStatus() {
	// Mount the node graph; it owns the poll, the map→array + connected-count
	// derivation, and the interval. It returns the thin refresh control + the
	// current interval.
	const { setRefreshInterval, refreshInterval } = useAggregatorStatusGraph();

	// The single read surface: the render model the graph publishes.
	const model = useNodeState( 'aggregator/view', 'view' ) ?? EMPTY_MODEL;
	const {
		servers,
		serverNow,
		connectedCount,
		totalCount,
		error,
		loading,
		lastRefresh,
	} = model;

	const [ , setTick ] = useState( 0 );

	// Tick every second to update relative timestamps (pure display — no poll).
	useEffect( () => {
		const timer = setInterval( () => setTick( ( t ) => t + 1 ), 1000 );
		return () => clearInterval( timer );
	}, [] );

	return (
		<div className="aggregator-status-dashboard">
			{ /* Header */ }
			<div className="aggregator-status-header">
				<h2>Aggregator Status</h2>
				<div className="aggregator-status-meta">
					<div className="aggregator-status-refresh-indicator">
						<span className="aggregator-status-refresh-dot" />
						<span>
							{ lastRefresh
								? `Updated ${ formatTime(
										lastRefresh / 1000
								  ) }`
								: 'Loading...' }
						</span>
					</div>
					{ servers && (
						<div className="aggregator-status-server-count">
							<strong>{ connectedCount }</strong> / { totalCount }{ ' ' }
							connected
						</div>
					) }
					<select
						className="event-logger-refresh-select"
						value={ refreshInterval }
						onChange={ ( e ) =>
							setRefreshInterval( e.target.value )
						}
						title="Refresh interval"
					>
						{ REFRESH_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
				</div>
			</div>

			{ /* Loading State */ }
			{ loading && (
				<div className="aggregator-status-loading">
					<div className="spinner" />
					<span>Loading server status...</span>
				</div>
			) }

			{ /* Error State */ }
			{ error && ! loading && (
				<div className="aggregator-status-error">{ error }</div>
			) }

			{ /* Server List */ }
			{ ! loading && ! error && (
				<div className="aggregator-status-servers">
					{ servers && servers.length > 0 ? (
						servers.map( ( server ) => (
							<ServerCard
								key={ server.id }
								server={ server }
								now={ serverNow }
							/>
						) )
					) : (
						<div className="aggregator-status-empty">
							No servers configured. Add servers in Event Logger
							settings.
						</div>
					) }
				</div>
			) }
		</div>
	);
}
