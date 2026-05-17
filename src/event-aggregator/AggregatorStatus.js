/**
 * Aggregator Status Component
 *
 * Real-time view of remote server connection status.
 * Shows connection state, heartbeat timing, and errors.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';

import { getCommandClient } from '../shared/utils/commandClient';
import unwrapCommandResponse from '../shared/utils/unwrapCommandResponse';
import './styles/aggregator-status.scss';

/**
 * Refresh interval options.
 */
const REFRESH_OPTIONS = [
	{ label: '1s', value: '1000' },
	{ label: '2s', value: '2000' },
	{ label: '5s', value: '5000' },
	{ label: '10s', value: '10000' },
];

const DEFAULT_REFRESH_MS = '2000';

/**
 * Format a Unix timestamp as relative time or absolute.
 *
 * @param {number} timestamp Unix timestamp in seconds.
 * @return {string} Formatted time string.
 */
const formatTime = ( timestamp ) => {
	if ( ! timestamp ) {
		return '-';
	}

	const now = Date.now() / 1000;
	const diff = now - timestamp;

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
 * @return {import('react').ReactElement} Rendered component.
 */
function PartitionStatus( { partition, status } ) {
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
						{ formatTime( status.last_connection_attempt ) }
					</span>
				</div>
				<div className="aggregator-partition-row">
					<span className="aggregator-partition-stat-label">
						Server HB
					</span>
					<span className="aggregator-partition-stat-value">
						{ formatTime( status.last_sse_heartbeat ) }
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
						{ formatTime( status.last_heartbeat_response ) }
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
 * @return {import('react').ReactElement} Rendered component.
 */
function ServerCard( { server } ) {
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
	const [ servers, setServers ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ lastRefresh, setLastRefresh ] = useState( null );
	const [ , setTick ] = useState( 0 );
	const [ refreshInterval, setRefreshInterval ] = useState( () => {
		const validValues = REFRESH_OPTIONS.map( ( opt ) => opt.value );
		const saved = window.localStorage.getItem(
			'aggregator-status-refresh'
		);
		if ( saved && validValues.includes( saved ) ) {
			return saved;
		}
		return DEFAULT_REFRESH_MS;
	} );

	// Tick every second to update relative timestamps.
	useEffect( () => {
		const timer = setInterval( () => setTick( ( t ) => t + 1 ), 1000 );
		return () => clearInterval( timer );
	}, [] );

	// Save refresh interval to localStorage.
	useEffect( () => {
		window.localStorage.setItem(
			'aggregator-status-refresh',
			refreshInterval
		);
	}, [ refreshInterval ] );

	/**
	 * Fetch server status via the substrate's CommandClient.
	 *
	 * Dispatches `aggregator.status` through `/newspack-nodes/v1/command`.
	 * The response is a raw 7-field Message tuple; `unwrapCommandResponse`
	 * peels it down to the verb's payload (a `{ server_id: {...} }` map,
	 * same shape the legacy `/newspack-nodes-aggregator/v1/status` REST
	 * route returned — confirmed by the M3 schema-parity audit).
	 */
	const fetchStatus = useCallback( async ( isInitial = false ) => {
		if ( ! isInitial ) {
			setRefreshing( true );
		}

		try {
			const client = getCommandClient();
			const message = await client.send( {
				to: 'aggregator',
				verb: 'status',
			} );
			const data = unwrapCommandResponse( message );

			// Convert object to array for easier rendering.
			const serverList = Object.values( data || {} );
			setServers( serverList );
			setError( null );
			setLastRefresh( Date.now() );
		} catch ( err ) {
			setError( err.message || 'Failed to fetch status' );
		} finally {
			setLoading( false );
			setRefreshing( false );
		}
	}, [] );

	// Initial fetch and auto-refresh.
	useEffect( () => {
		fetchStatus( true );

		const intervalMs = parseInt( refreshInterval, 10 );
		const interval = setInterval( () => {
			fetchStatus( false );
		}, intervalMs );

		return () => clearInterval( interval );
	}, [ fetchStatus, refreshInterval ] );

	// Count servers where at least one partition is connected.
	const connectedCount =
		servers?.filter( ( s ) => {
			const partitions = s.partitions || {};
			return Object.values( partitions ).some(
				( p ) => p?.last_connection_status === 'connected'
			);
		} ).length || 0;
	const totalCount = servers?.length || 0;

	return (
		<div className="aggregator-status-dashboard">
			{ /* Header */ }
			<div className="aggregator-status-header">
				<h2>Aggregator Status</h2>
				<div className="aggregator-status-meta">
					<div
						className={ `aggregator-status-refresh-indicator${
							refreshing ? ' refreshing' : ''
						}` }
					>
						<span
							className={ `aggregator-status-refresh-dot${
								refreshing ? ' refreshing' : ''
							}` }
						/>
						<span>
							{ refreshing && 'Refreshing...' }
							{ ! refreshing &&
								lastRefresh &&
								`Updated ${ formatTime(
									lastRefresh / 1000
								) }` }
							{ ! refreshing && ! lastRefresh && 'Loading...' }
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
							<ServerCard key={ server.id } server={ server } />
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
