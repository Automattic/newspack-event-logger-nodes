/* global localStorage */
/**
 * Inflight Requests Component
 *
 * Real-time view of active (in-flight) requests, similar to Tachikoma's Gyroscope.
 * Receives pre-processed request objects from the server-side InflightTracker.
 * Shows both in-flight and recently completed requests.
 */

import {
	useState,
	useEffect,
	useRef,
	useCallback,
	useMemo,
} from '@wordpress/element';

import usePageVisibility from '../shared/hooks/usePageVisibility';
import useFirehoseConnection from '../shared/hooks/useFirehoseConnection';
import { INFLIGHT_REFRESH_OPTIONS } from './constants';
import {
	formatDuration,
	getDurationClass,
	getStateColor,
	getStatusClass,
} from '../shared/utils/formatUtils';
import fnv1a from '../shared/utils/fnv1a';
import './styles/inflight.scss';
import './styles/request-stream.scss';

/**
 * Column definitions with tooltips.
 */
const COLUMNS = {
	rid: {
		label: 'Request ID',
		tooltip:
			'Unique request identifier - click to view in Performance Dashboard',
		width: '240px',
	},
	url: {
		label: 'URL',
		tooltip: 'Request method and URL - click to view URL stats',
		width: 'auto',
	},
	status_code: {
		label: 'Status',
		tooltip: 'HTTP response status code',
		width: '50px',
	},
	state: {
		label: 'State',
		tooltip: 'What the request is currently doing',
		width: '100px',
	},
	what: {
		label: 'What',
		tooltip: 'Details: query text, template name, hook name, etc.',
		width: '200px',
	},
	remote_addr: {
		label: 'IP',
		tooltip: 'Client IP address',
		width: '100px',
	},
	user_agent: {
		label: 'UA',
		tooltip: 'Browser/client identifier',
		width: '200px',
	},
	est: {
		label: 'Est',
		tooltip: 'Estimated request duration (accounts for display delay)',
		width: '70px',
	},
	time: {
		label: 'Time',
		tooltip:
			'Request duration from server logs only (ignores display delay)',
		width: '50px',
	},
	age: {
		label: 'Age',
		tooltip: 'Display delay - how far behind real-time this view is',
		width: '50px',
	},
	lag: {
		label: 'Lag',
		tooltip:
			'Server processing delay - high values mean the log processor is backed up',
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
	const [ requests, setRequests ] = useState( [] );
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
	const completedHistoryRef = useRef( [] ); // Track completed requests with timestamps for rps calculation.
	const requestsRef = useRef( new Map() ); // All requests keyed by rid.
	const isPageVisible = usePageVisibility();

	const totalCount = requests.length;

	// Calculate requests per second from completed requests over 10-second window.
	const updateRequestsPerSecond = useCallback( ( completedCount ) => {
		const now = Date.now();
		const windowMs = 10000; // 10-second window.

		// Add current batch to history.
		if ( completedCount > 0 ) {
			completedHistoryRef.current.push( {
				time: now,
				count: completedCount,
			} );
		}

		// Remove entries older than window.
		completedHistoryRef.current = completedHistoryRef.current.filter(
			( entry ) => now - entry.time < windowMs
		);

		// Sum requests in window.
		const totalInWindow = completedHistoryRef.current.reduce(
			( sum, entry ) => sum + entry.count,
			0
		);

		// Calculate rps (requests per 10s / 10).
		setRequestsPerSecond( totalInWindow / ( windowMs / 1000 ) );
	}, [] );

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

	// Render requests and cleanup completed ones (like Gyroscope.pm fire()).
	const renderRequests = useCallback( () => {
		const allRequests = [];
		let completedCount = 0;

		// Collect all requests, delete completed ones.
		for ( const [ rid, req ] of requestsRef.current ) {
			if ( req.state === 'complete' ) {
				completedCount++;
				requestsRef.current.delete( rid );
			}
			allRequests.push( req );
		}

		updateRequestsPerSecond( completedCount );

		// Sort by est_ms descending (longest first) and limit.
		const sorted = allRequests
			.sort( ( a, b ) => ( b.est_ms || 0 ) - ( a.est_ms || 0 ) )
			.slice( 0, maxRows );

		setRequests( sorted );
	}, [ maxRows, updateRequestsPerSecond ] );

	// Handle SSE events from firehose (single multiplexed stream).
	const handleSource = useCallback( ( source ) => {
		// Server sends pre-processed in-flight requests at interval.
		source.addEventListener( 'inflight', ( e ) => {
			try {
				const data = JSON.parse( e.data );
				const inflight = data.requests || [];

				// Upsert each request by rid, but don't overwrite completed requests.
				for ( const req of inflight ) {
					const existing = requestsRef.current.get( req.rid );
					if ( ! existing || existing.state !== 'complete' ) {
						requestsRef.current.set( req.rid, req );
					}
				}
			} catch ( err ) {
				// Ignore parse errors.
			}
		} );

		// Server sends completed requests in batches.
		source.addEventListener( 'complete_batch', ( e ) => {
			try {
				const batch = JSON.parse( e.data );
				for ( const req of batch ) {
					const existing = requestsRef.current.get( req.rid );
					// Update the request - complete is just another state.
					requestsRef.current.set( req.rid, {
						...existing,
						...req,
						state: 'complete',
						time_ms: req.duration_ms || 0,
						est_ms: req.duration_ms || 0,
					} );
				}
			} catch ( err ) {
				// Ignore parse errors.
			}
		} );
	}, [] );

	// Reset state on reconnect.
	const handleBeforeConnect = useCallback( () => {
		completedHistoryRef.current = [];
		requestsRef.current.clear();
	}, [] );

	// Use shared firehose connection hook.
	// Fixed 100ms SSE batches - display refresh is independent.
	const {
		error,
		connect,
		close: closeSource,
		lastEventTime,
	} = useFirehoseConnection( {
		intervalMs: 100,
		onSource: handleSource,
		onBeforeConnect: handleBeforeConnect,
	} );

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

	// Handle page visibility - reconnect when tab becomes visible, disconnect when hidden.
	useEffect( () => {
		if ( isPageVisible ) {
			connect();
		} else {
			closeSource();
		}
		return () => closeSource();
	}, [ isPageVisible, connect, closeSource ] );

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
							href={ `admin.php?page=newspack-nodes-performance&request=${ encodeURIComponent(
								req.rid
							) }` }
							title="View request trace"
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
							href={ `admin.php?page=newspack-nodes-performance&url=${ urlHash(
								req.url
							) }` }
							className="entry-url-link"
							title="View URL stats"
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
				<h3>In-Flight Requests</h3>
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
							{ totalCount } requests
						</span>
						{ requestsPerSecond > 0 && (
							<span className="event-logger-inflight-rps">
								{ requestsPerSecond.toFixed( 1 ) } req/s
							</span>
						) }
						{ staleSec !== null && (
							<span
								style={ {
									color:
										staleSec > 10 ? '#dba617' : '#757575',
									fontSize: '11px',
									marginLeft: '8px',
								} }
							>
								{ staleSec }s ago
							</span>
						) }
					</span>
					<select
						className="event-logger-refresh-select"
						value={ refreshInterval }
						onChange={ ( e ) =>
							setRefreshInterval( parseFloat( e.target.value ) )
						}
						title="Refresh interval (also press 0-9 keys)"
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
						title="Select columns"
					>
						Cols
					</button>
				</span>
			</div>

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

			{ error && (
				<div className="event-logger-inflight-error">{ error }</div>
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
							No active requests.
						</div>
					) : (
						requests.map( ( req, index ) => {
							const nowSec = Date.now() / 1000;
							const ageSec = req.last_log_ts
								? nowSec - req.last_log_ts
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
