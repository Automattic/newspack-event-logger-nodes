/* global localStorage */
/**
 * In-Flight Requests — the Gyroscope dashboard's live request table, modeled on
 * Tachikoma's Gyroscope.
 *
 * A THIN view over the `gyroscope:*` node graph (mounted by
 * `useGyroscopeGraph`). The graph owns all data: `gyroscope:link` — a substrate
 * `RemoteLink` — holds the SSE connection and targets the `gyroscope:stream`
 * Tee, which copies every frame to `gyroscope:view`. That view node dispatches
 * the inflight/complete envelopes itself and owns the model: the rid-keyed map,
 * the reap/age-out/sort/cap snapshot, and RPS. This component only renders.
 *
 * The refresh-interval timer (user-controllable, 0-9 keys + dropdown) drives the
 * render cadence: each tick it calls `Core.node('gyroscope:view').snapshot(maxRows)`
 * — which reaps completed entries (shown one tick then dropped), drops in-flight
 * rows unseen for 15 minutes, sorts by est_ms desc, caps to maxRows — and reads
 * `.rps` off the node. A busy stream never re-renders React per message; only
 * the cheap snapshot is pushed at the refresh cadence.
 *
 * The low-frequency `{ connectionError }` model (the reconnect banner) is read
 * separately via `useNodeState('gyroscope:view','view')`.
 */

import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import useRouterTick from '@newspack-nodes/shared/hooks/useRouterTick';
import { useGyroscopeGraph } from './hooks/useGyroscopeGraph';
import { INFLIGHT_REFRESH_OPTIONS } from './constants';
import {
	formatDuration,
	getDurationClass,
	getStateColor,
	getTextColor,
} from '@newspack-nodes/shared/utils/formatUtils';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import ConnectionBanner from '@newspack-nodes/shared/components/ConnectionBanner';
import './styles/inflight.scss';
import './styles/request-stream.scss';

// The view node the refresh tick reads the in-flight snapshot + rps off of.
const VIEW_NODE = 'gyroscope:view';
// Banner fallback for the window before the view node exists.
const EMPTY_VIEW = { connectionError: false };

/**
 * Inline style for a colored badge, ink and all.
 *
 * Hook-category colors are operator-editable and many are pale, so the
 * foreground follows the chip rather than assuming white reads on it.
 *
 * @param {string} background Background hex color.
 * @return {Object} Style object for the badge span.
 */
const badgeStyle = ( background ) => ( {
	backgroundColor: background,
	color: getTextColor( background ),
} );

/**
 * Column definitions, keyed by the field each renders.
 *
 * `label` is the header text, `tooltip` the header's `title`, and `width` one
 * track of the row's `grid-template-columns`. The key set doubles as the
 * validation whitelist for the persisted `event-logger-columns` selection and
 * as the column picker's contents, so a key added here becomes selectable at
 * once. Only completion records carry `status_code`, so that column stays blank
 * while a request is still running.
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
 * URL hash for deep-linking to URL detail — must match PHP
 * `Log_Manager::url_hash`, which hashes the FULL url. The real query is
 * already stripped upstream, so the only `?` left is the intentional
 * `?worker_type` marker on nodes/ELN URLs (e.g. `/jobs/x?supervisor`); keeping
 * it is what makes those URLs deep-link correctly.
 *
 * @param {string} url URL to hash.
 * @return {string} FNV-1a hash.
 */
const urlHash = ( url ) => fnv1a( url || '' );

/**
 * Columns shown until the operator picks otherwise, and whenever the persisted
 * selection is absent or fails validation.
 */
const DEFAULT_COLUMNS = [ 'rid', 'url', 'status_code', 'state', 'what', 'est' ];

/**
 * In-flight requests table.
 *
 * Renders the snapshot the `gyroscope:view` node hands over each refresh tick.
 * Column selection and refresh interval persist in localStorage under
 * `event-logger-columns` and `event-logger-inflight-refresh`; both are
 * revalidated on load, since a stale or hand-edited value would otherwise
 * render a header the picker cannot reach or a cadence the dropdown cannot show.
 *
 * @param {Object} props         Component props.
 * @param {number} props.maxRows Maximum rows to display; the cap `snapshot()` applies.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function Inflight( { maxRows = 20 } ) {
	// Mount the node graph; it owns the data, this only renders the snapshot.
	useGyroscopeGraph();

	// Low-freq view model (reconnect banner); rows/rps read off the node.
	const { connectionError } = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;

	const [ requests, setRequests ] = useState( [] );
	const [ refreshInterval, setRefreshInterval ] = useState( () => {
		// Load from localStorage; validate against allowed dropdown values.
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

	// Sample the view node's snapshot() (reaps, sorts, caps) each refresh.
	const renderRequests = useCallback( () => {
		const node = Core.node( VIEW_NODE );
		if ( ! node ) {
			return;
		}
		setRequests( node.snapshot( maxRows ) );
		setRequestsPerSecond( node.rps );
	}, [ maxRows ] );

	// Keyboard 0-9 → refresh interval; every value must exist in the dropdown.
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

	// Display refresh: sub-second takes its own slot, 1s+ rides the Router.
	useRouterTick( {
		name: 'gyroscope:display',
		onTick: renderRequests,
		intervalMs: refreshInterval * 1000,
	} );

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
					<span
						key={ col }
						role="cell"
						className="newspack-nodes-table__cell"
					>
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
						className={ `newspack-nodes-table__cell entry-duration entry-duration--${ getDurationClass(
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
						className={ `newspack-nodes-table__cell entry-duration entry-duration--${ getDurationClass(
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
						className={ `newspack-nodes-table__cell entry-duration newspack-nodes-status${
							ageMs > 5000 ? ' is-warning' : ''
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
						className={ `newspack-nodes-table__cell entry-duration newspack-nodes-status${
							req.lag_ms > 1000 ? ' is-warning' : ''
						}` }
					>
						{ formatDuration( req.lag_ms || 0 ) }
					</span>
				);

			case 'state':
				return (
					<span
						key={ col }
						role="cell"
						className="newspack-nodes-table__cell"
					>
						<span
							className="event-logger-state-badge newspack-nodes-badge"
							style={ badgeStyle( getStateColor( req.state ) ) }
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
						className="newspack-nodes-table__cell entry-url"
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
						className="newspack-nodes-table__cell entry-status"
						data-status={ req.status_code }
					>
						{ req.status_code }
					</span>
				);

			case 'url':
				return (
					<span
						key={ col }
						role="cell"
						className="newspack-nodes-table__cell entry-url"
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
					<span
						key={ col }
						role="cell"
						className="newspack-nodes-table__cell entry-ip"
					>
						{ req.remote_addr || '-' }
					</span>
				);

			case 'user_agent':
				return (
					<span
						key={ col }
						role="cell"
						className="newspack-nodes-table__cell entry-ua"
						title={ req.user_agent }
					>
						{ req.user_agent || '-' }
					</span>
				);

			default:
				return (
					<span
						key={ col }
						role="cell"
						className="newspack-nodes-table__cell entry-default"
					>
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
			<div className="event-logger-inflight-header newspack-nodes-inflight-header">
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
							className="event-logger-state-badge newspack-nodes-badge"
							style={ badgeStyle(
								window.eventLoggerHookCategories?._colors?.[
									category
								] || '#9e9e9e'
							) }
						>
							{ category }
						</span>
					) ) }
					{ [ 'process', 'complete' ].map( ( state ) => (
						<span
							key={ state }
							className="event-logger-state-badge newspack-nodes-badge"
							style={ badgeStyle( getStateColor( state ) ) }
						>
							{ state }
						</span>
					) ) }
				</div>
				<span className="newspack-nodes-toolbar">
					<span className="newspack-nodes-toolbar-stats">
						<span className="newspack-nodes-toolbar-stats__count">
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
						<span className="newspack-nodes-toolbar-stats__rps">
							{ sprintf(
								// translators: %s: requests-per-second rate, formatted to one decimal place.
								__( '%s req/s', 'newspack-event-logger-nodes' ),
								requestsPerSecond.toFixed( 1 )
							) }
						</span>
					</span>
					<select
						className="newspack-nodes-select"
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
						className={ `button${
							showColumnPicker ? ' is-active' : ''
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
				<div className="newspack-nodes-column-picker">
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
				className="event-logger-request-stream-header-row newspack-nodes-table__header"
				style={ { gridTemplateColumns: gridTemplate } }
			>
				{ visibleColumns.map( ( col ) => (
					<span
						key={ col }
						role="columnheader"
						className="event-logger-request-stream-th newspack-nodes-table__cell"
						title={ COLUMNS[ col ]?.tooltip }
					>
						{ COLUMNS[ col ]?.label || col }
					</span>
				) ) }
			</div>
			<div
				role="rowgroup"
				className="event-logger-request-stream-list newspack-nodes-table"
			>
				<div className="event-logger-request-stream-content">
					{ requests.length === 0 ? (
						<div className="event-logger-request-stream-empty newspack-nodes-empty-state">
							{ __(
								'No active requests.',
								'newspack-event-logger-nodes'
							) }
						</div>
					) : (
						requests.map( ( req, index ) => {
							// last_log_ts is epoch seconds, not ms.
							const nowSec = Date.now() / 1000;
							const ageSec = req.last_log_ts
								? Math.max( 0, nowSec - req.last_log_ts )
								: 0;
							const ageMs = ageSec * 1000;

							return (
								<div
									key={ req.rid }
									role="row"
									className={ `event-logger-request-stream-entry newspack-nodes-table__row ${
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
