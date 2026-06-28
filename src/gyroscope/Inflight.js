/* global localStorage */
/**
 * Inflight Requests Component
 *
 * Real-time view of active (in-flight) requests, similar to Tachikoma's Gyroscope.
 *
 * This is a THIN view over the `gyroscope:*` node graph (mounted by
 * `useGyroscopeGraph`). The graph owns all data: `gyroscope:stream` holds the SSE
 * connection, `gyroscope:transform` turns envelopes into inflight/complete
 * dispatches, and `gyroscope:view` holds the in-flight model (the rid-keyed map +
 * the reap/sort/cap snapshot + RPS). This component only renders.
 *
 * The refresh-interval timer (user-controllable, 0-9 keys + dropdown) drives the
 * render cadence: each tick it calls `Core.node('gyroscope:view').snapshot(maxRows)`
 * — which reaps completed entries (shown one tick then dropped), sorts by est_ms
 * desc, caps to maxRows — and reads `.rps` / `.lastEventTime` off the node. A busy
 * stream never re-renders React per message; only the cheap snapshot is pushed at
 * the refresh cadence.
 *
 * The low-frequency `{ connectionError }` model (the reconnect banner) is read
 * separately via `useNodeState('gyroscope:view','view')`.
 */

import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useGyroscopeGraph } from './hooks/useGyroscopeGraph';
import { INFLIGHT_REFRESH_OPTIONS } from './constants';
import {
	formatDuration,
	getDurationClass,
	getStateColor,
	getStatusClass,
} from '@newspack-nodes/shared/utils/formatUtils';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import ConnectionBanner from '@newspack-nodes/shared/components/ConnectionBanner';
import './styles/inflight.scss';
import './styles/request-stream.scss';

// The view node the refresh tick reads the in-flight snapshot + rps off of.
const VIEW_NODE = 'gyroscope:view';
// The shared SSE connector owns stream liveness — it stamps lastEventTime on
// every frame AND on the server's idle heartbeats — so the "Xs ago" staleness
// reads it, not the view node (which only advances on row arrivals).
const SSE_NODE = '_sse';
// The low-frequency view model before the view node publishes one.
const EMPTY_VIEW = { connectionError: false };

/**
 * Column definitions with tooltips.
 */
const COLUMNS = {
	rid: {
		label: __( 'Request ID', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Unique request identifier - click to view in Performance Dashboard',
			'newspack-event-logger-nodes'
		),
		width: '240px',
	},
	url: {
		label: __( 'URL', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Request method and URL - click to view URL stats',
			'newspack-event-logger-nodes'
		),
		width: 'auto',
	},
	status_code: {
		label: __( 'Status', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'HTTP response status code',
			'newspack-event-logger-nodes'
		),
		width: '50px',
	},
	state: {
		label: __( 'State', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'What the request is currently doing',
			'newspack-event-logger-nodes'
		),
		width: '100px',
	},
	what: {
		label: __( 'What', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Details: query text, template name, hook name, etc.',
			'newspack-event-logger-nodes'
		),
		width: '200px',
	},
	remote_addr: {
		label: __( 'IP', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Client IP address', 'newspack-event-logger-nodes' ),
		width: '100px',
	},
	user_agent: {
		label: __( 'UA', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Browser/client identifier',
			'newspack-event-logger-nodes'
		),
		width: '200px',
	},
	est: {
		label: __( 'Est', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Estimated request duration (accounts for display delay)',
			'newspack-event-logger-nodes'
		),
		width: '70px',
	},
	time: {
		label: __( 'Time', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Request duration from server logs only (ignores display delay)',
			'newspack-event-logger-nodes'
		),
		width: '50px',
	},
	age: {
		label: __( 'Age', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Display delay - how far behind real-time this view is',
			'newspack-event-logger-nodes'
		),
		width: '50px',
	},
	lag: {
		label: __( 'Lag', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Server processing delay - high values mean the log processor is backed up',
			'newspack-event-logger-nodes'
		),
		width: '50px',
	},
};

/**
 * Generate URL hash (must match PHP url_hash function).
 *
 * @param {string} url URL to hash.
 * @return {string} 8-character FNV-1a hash.
 */
const urlHash = ( url ) => {
	// Remove query string (matches PHP's strtok($url, '?')).
	const urlPath = url?.split( '?' )[ 0 ] || '';
	return fnv1a( urlPath );
};

/**
 * Default visible columns.
 */
const DEFAULT_COLUMNS = [ 'rid', 'url', 'status_code', 'state', 'what', 'est' ];

/**
 * Inflight Requests Component.
 *
 * @param {Object} props         Component props.
 * @param {number} props.maxRows Maximum rows to display.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function Inflight( { maxRows = 20 } ) {
	// Mount the node graph (SSE → transform → in-flight model). It owns the data;
	// this component only renders the snapshot it reads off the view node.
	useGyroscopeGraph();

	// Low-frequency view model (the reconnect banner). The high-frequency in-flight
	// rows / rps are read off the node directly in the refresh tick below.
	const { connectionError } = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;

	const [ requests, setRequests ] = useState( [] );
	const [ lastEventTime, setLastEventTime ] = useState( null );
	const [ refreshInterval, setRefreshInterval ] = useState( () => {
		// Load from localStorage with validation against allowed dropdown values.
		const validValues = INFLIGHT_REFRESH_OPTIONS.map(
			( opt ) => opt.value
		);
		const saved = localStorage.getItem( 'event-logger-inflight-refresh' );
		if ( saved ) {
			const parsed = parseFloat( saved );
			if ( ! isNaN( parsed ) && validValues.includes( parsed ) ) {
				return parsed;
			}
		}
		return 2;
	} );
	const [ visibleColumns, setVisibleColumns ] = useState( () => {
		// Load from localStorage with validation.
		const validColumns = Object.keys( COLUMNS );
		try {
			const saved = localStorage.getItem( 'event-logger-columns' );
			const parsed = saved ? JSON.parse( saved ) : null;
			if (
				Array.isArray( parsed ) &&
				parsed.every( ( col ) => validColumns.includes( col ) )
			) {
				return parsed;
			}
		} catch {
			// Fall through to default.
		}
		return DEFAULT_COLUMNS;
	} );
	const [ showColumnPicker, setShowColumnPicker ] = useState( false );
	const [ requestsPerSecond, setRequestsPerSecond ] = useState( 0 );

	const totalCount = requests.length;

	// Save column selection to localStorage.
	useEffect( () => {
		localStorage.setItem(
			'event-logger-columns',
			JSON.stringify( visibleColumns )
		);
	}, [ visibleColumns ] );

	// Save refresh interval to localStorage.
	useEffect( () => {
		localStorage.setItem(
			'event-logger-inflight-refresh',
			String( refreshInterval )
		);
	}, [ refreshInterval ] );

	// Memoize grid template to avoid recomputation on every row.
	const gridTemplate = useMemo(
		() =>
			visibleColumns
				.map( ( col ) => COLUMNS[ col ]?.width || 'auto' )
				.join( ' ' ),
		[ visibleColumns ]
	);

	// Read the render snapshot off the view node (like Gyroscope.pm fire()): the
	// node's snapshot() reaps completed entries, sorts by est_ms desc and caps to
	// maxRows; we also read the node's rps + lastEventTime. The graph owns the
	// accumulation — this just samples it at the refresh cadence.
	const renderRequests = useCallback( () => {
		const node = Core.node( VIEW_NODE );
		if ( ! node ) {
			return;
		}
		setRequests( node.snapshot( maxRows ) );
		setRequestsPerSecond( node.rps );
		setLastEventTime( Core.node( SSE_NODE )?.lastEventTime ?? null );
	}, [ maxRows ] );

	// Ticking "Xs ago" display.
	const [ now, setNow ] = useState( Date.now() );
	useEffect( () => {
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [] );
	const staleSec = lastEventTime
		? Math.max( 0, Math.floor( ( now - lastEventTime ) / 1000 ) )
		: null;

	// Keyboard shortcuts: 0-9 to set refresh interval.
	useEffect( () => {
		const keyMap = {
			0: 0.1,
			1: 1,
			2: 2,
			3: 3,
			4: 3,
			5: 5,
			6: 5,
			7: 5,
			8: 10,
			9: 10,
		};
		const handleKeyDown = ( e ) => {
			if (
				e.target.tagName === 'INPUT' ||
				e.target.tagName === 'TEXTAREA'
			) {
				return;
			}
			const interval = keyMap[ e.key ];
			if ( interval !== undefined ) {
				setRefreshInterval( interval );
			}
		};
		window.addEventListener( 'keydown', handleKeyDown );
		return () => window.removeEventListener( 'keydown', handleKeyDown );
	}, [] );

	// Separate display refresh interval - independent of data collection.
	useEffect( () => {
		const intervalMs = refreshInterval * 1000;
		const displayTimer = setInterval( () => {
			renderRequests();
		}, intervalMs );

		return () => clearInterval( displayTimer );
	}, [ refreshInterval, renderRequests ] );

	/**
	 * Toggle a column's visibility.
	 *
	 * @param {string} col Column key.
	 */
	const toggleColumn = ( col ) => {
		setVisibleColumns( ( prev ) => {
			if ( prev.includes( col ) ) {
				return prev.filter( ( c ) => c !== col );
			}
			// Add in original order.
			const allCols = Object.keys( COLUMNS );
			return allCols.filter( ( c ) => prev.includes( c ) || c === col );
		} );
	};

	/**
	 * Render a cell value based on column type.
	 *
	 * @param {string} col   Column key.
	 * @param {Object} req   Request object.
	 * @param {number} ageMs Calculated age in ms.
	 * @return {import('react').ReactElement} Cell content.
	 */
	const renderCell = ( col, req, ageMs ) => {
		switch ( col ) {
			case 'rid':
				return (
					<span key={ col } role="cell">
						<a
							className="entry-rid"
							href={ `admin.php?page=event-logger-overview&request=${ encodeURIComponent(
								req.rid
							) }` }
							title={ __(
								'View request trace',
								'newspack-event-logger-nodes'
							) }
						>
							{ req.rid }
						</a>
					</span>
				);

			case 'time':
				return (
					<span
						key={ col }
						role="cell"
						className={ `entry-duration entry-duration--${ getDurationClass(
							req.time_ms
						) }` }
					>
						{ formatDuration( req.time_ms ) }
					</span>
				);

			case 'est':
				return (
					<span
						key={ col }
						role="cell"
						className={ `entry-duration entry-duration--${ getDurationClass(
							req.est_ms || req.time_ms || 0
						) }` }
					>
						{ formatDuration( req.est_ms || req.time_ms || 0 ) }
					</span>
				);

			case 'age':
				return (
					<span
						key={ col }
						role="cell"
						className={ `entry-duration entry-timing--${
							ageMs > 5000 ? 'warning' : 'normal'
						}` }
					>
						{ formatDuration( ageMs ) }
					</span>
				);

			case 'lag':
				return (
					<span
						key={ col }
						role="cell"
						className={ `entry-duration entry-timing--${
							req.lag_ms > 1000 ? 'warning' : 'normal'
						}` }
					>
						{ formatDuration( req.lag_ms || 0 ) }
					</span>
				);

			case 'state':
				return (
					<span key={ col } role="cell">
						<span
							className="event-logger-state-badge"
							style={ {
								backgroundColor: getStateColor( req.state ),
							} }
						>
							{ req.state === 'include template'
								? 'template'
								: req.state }
						</span>
					</span>
				);

			case 'what':
				return (
					<span
						key={ col }
						role="cell"
						className="entry-url"
						title={ req.what }
					>
						{ req.what }
					</span>
				);

			case 'status_code':
				return (
					<span
						key={ col }
						role="cell"
						className={ `entry-status entry-status--${ getStatusClass(
							req.status_code
						) }` }
					>
						{ req.status_code }
					</span>
				);

			case 'url':
				return (
					<span
						key={ col }
						role="cell"
						className="entry-url"
						title={ req.url }
					>
						<span className="entry-method">{ req.method }</span>{ ' ' }
						<a
							href={ `admin.php?page=event-logger-overview&url=${ urlHash(
								req.url
							) }` }
							className="entry-url-link"
							title={ __(
								'View URL stats',
								'newspack-event-logger-nodes'
							) }
						>
							{ req.url }
						</a>
					</span>
				);

			case 'remote_addr':
				return (
					<span key={ col } role="cell" className="entry-ip">
						{ req.remote_addr || '-' }
					</span>
				);

			case 'user_agent':
				return (
					<span
						key={ col }
						role="cell"
						className="entry-ua"
						title={ req.user_agent }
					>
						{ req.user_agent || '-' }
					</span>
				);

			default:
				return (
					<span key={ col } role="cell" className="entry-default">
						-
					</span>
				);
		}
	};

	return (
		<div
			className="event-logger-inflight"
			role="table"
			aria-label="In-flight requests"
		>
			<div className="event-logger-inflight-header">
				<h1 className="newspack-dashboard-title">
					{ __(
						'In-Flight Requests',
						'newspack-event-logger-nodes'
					) }
				</h1>
				<div className="event-logger-inflight-legend">
					{ [
						'Lifecycle',
						'Query & Posts',
						'Content Rendering',
						'Theme',
						'Scripts & Styles',
						'REST API',
					].map( ( category ) => (
						<span
							key={ category }
							className="event-logger-state-badge"
							style={ {
								backgroundColor:
									window.eventLoggerHookCategories?._colors?.[
										category
									] || '#9e9e9e',
							} }
						>
							{ category }
						</span>
					) ) }
					{ [ 'process', 'complete' ].map( ( state ) => (
						<span
							key={ state }
							className="event-logger-state-badge"
							style={ {
								backgroundColor: getStateColor( state ),
							} }
						>
							{ state }
						</span>
					) ) }
				</div>
				<span className="event-logger-inflight-meta">
					<span className="event-logger-inflight-stats">
						<span className="event-logger-inflight-count">
							{ sprintf(
								// translators: %d: number of in-flight requests.
								_n(
									'%d request',
									'%d requests',
									totalCount,
									'newspack-event-logger-nodes'
								),
								totalCount
							) }
						</span>
						<span className="event-logger-inflight-rps">
							{ sprintf(
								// translators: %s: requests-per-second rate, formatted to one decimal place.
								__( '%s req/s', 'newspack-event-logger-nodes' ),
								requestsPerSecond.toFixed( 1 )
							) }
						</span>
						{ staleSec !== null && (
							<span
								style={ {
									color:
										staleSec > 10 ? '#dba617' : '#757575',
									fontSize: '11px',
									marginLeft: '8px',
								} }
							>
								{ sprintf(
									// translators: %d: seconds since the last event was received.
									__(
										'%ds ago',
										'newspack-event-logger-nodes'
									),
									staleSec
								) }
							</span>
						) }
					</span>
					<select
						className="event-logger-refresh-select"
						value={ refreshInterval }
						onChange={ ( e ) =>
							setRefreshInterval( parseFloat( e.target.value ) )
						}
						title={ __(
							'Refresh interval (also press 0–9 keys)',
							'newspack-event-logger-nodes'
						) }
					>
						{ INFLIGHT_REFRESH_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
					<button
						className={ `event-logger-inflight-btn${
							showColumnPicker ? ' active' : ''
						}` }
						onClick={ () =>
							setShowColumnPicker( ! showColumnPicker )
						}
						title={ __(
							'Select columns',
							'newspack-event-logger-nodes'
						) }
					>
						{ __( 'Cols', 'newspack-event-logger-nodes' ) }
					</button>
				</span>
			</div>

			<ConnectionBanner
				connectionError={ connectionError }
				message={ __(
					'Connection lost. Reconnecting…',
					'newspack-event-logger-nodes'
				) }
			/>

			{ showColumnPicker && (
				<div className="event-logger-inflight-column-picker">
					{ Object.entries( COLUMNS ).map( ( [ key, col ] ) => (
						<label
							key={ key }
							htmlFor={ `inflight-col-${ key }` }
							title={ col.tooltip }
						>
							<input
								id={ `inflight-col-${ key }` }
								type="checkbox"
								checked={ visibleColumns.includes( key ) }
								onChange={ () => toggleColumn( key ) }
							/>
							{ col.label }
						</label>
					) ) }
				</div>
			) }

			<div
				role="row"
				className="event-logger-request-stream-header-row"
				style={ { gridTemplateColumns: gridTemplate } }
			>
				{ visibleColumns.map( ( col ) => (
					<span
						key={ col }
						role="columnheader"
						className="event-logger-request-stream-th"
						title={ COLUMNS[ col ]?.tooltip }
					>
						{ COLUMNS[ col ]?.label || col }
					</span>
				) ) }
			</div>
			<div role="rowgroup" className="event-logger-request-stream-list">
				<div className="event-logger-request-stream-content">
					{ requests.length === 0 ? (
						<div className="event-logger-request-stream-empty">
							{ __(
								'No active requests.',
								'newspack-event-logger-nodes'
							) }
						</div>
					) : (
						requests.map( ( req, index ) => {
							const nowSec = Date.now() / 1000;
							const ageSec = req.last_log_ts
								? Math.max( 0, nowSec - req.last_log_ts )
								: 0;
							const ageMs = ageSec * 1000;

							return (
								<div
									key={ req.rid }
									role="row"
									className={ `event-logger-request-stream-entry ${
										index % 2 === 0 ? 'row-even' : 'row-odd'
									}` }
									style={ {
										gridTemplateColumns: gridTemplate,
									} }
								>
									{ visibleColumns.map( ( col ) =>
										renderCell( col, req, ageMs )
									) }
								</div>
							);
						} )
					) }
				</div>
			</div>
		</div>
	);
}
