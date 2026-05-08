/* global localStorage */
/**
 * Worker Status Component
 *
 * Displays status of all registered log readers with segment visualization.
 * Dynamically renders pipelines based on registered workers and their inputs/outputs.
 * Shows cursor positions, bytes behind, and animates segment rotations.
 */

import {
	useState,
	useEffect,
	useRef,
	useCallback,
	useMemo,
	memo,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import usePageVisibility from './shared/hooks/usePageVisibility';
import './styles/worker-status.scss';

const REFRESH_OPTIONS = [
	{ label: '1s', value: '1000' },
	{ label: '2s', value: '2000' },
	{ label: '5s', value: '5000' },
	{ label: '10s', value: '10000' },
];

/**
 * Format bytes to human readable string.
 *
 * @param {number} bytes Byte count.
 * @return {string} Formatted string.
 */
function formatBytes( bytes ) {
	if ( bytes === 0 ) {
		return '0 B';
	}
	const k = 1024;
	const sizes = [ 'B', 'KB', 'MB', 'GB' ];
	const i = Math.floor( Math.log( bytes ) / Math.log( k ) );
	return (
		parseFloat( ( bytes / Math.pow( k, i ) ).toFixed( 1 ) ) +
		' ' +
		sizes[ i ]
	);
}

/**
 * Single segment bar visualization (horizontal bar layout).
 *
 * @param {Object}  props              Component props.
 * @param {Object}  props.segment      Segment data { id, size, mtime }.
 * @param {number}  props.maxSize      Max segment size for scaling.
 * @param {number}  props.cursorSeg    Current cursor segment ID.
 * @param {number}  props.cursorOffset Current cursor offset.
 * @param {number}  props.newestSegId  ID of the newest segment.
 * @param {boolean} props.isNew        Whether this segment is newly appeared.
 * @param {boolean} props.isRemoving   Whether this segment is being removed.
 * @return {JSX.Element} Rendered component.
 */
const SegmentBar = memo( function SegmentBar( {
	segment,
	maxSize,
	cursorSeg,
	cursorOffset,
	newestSegId,
	isNew,
	isRemoving,
} ) {
	const fillPercent = maxSize > 0 ? ( segment.size / maxSize ) * 100 : 0;
	// If no cursor (output-only log), treat all segments as processed (green).
	const hasReader = cursorSeg !== undefined;
	const isCurrent = hasReader && segment.id === cursorSeg;
	const isProcessed = ! hasReader || segment.id < cursorSeg;
	const isNewest = segment.id === newestSegId;

	// For current segment: green up to cursor, then yellow (if newest) or red (if old).
	const processedPercent =
		isCurrent && segment.size > 0
			? ( cursorOffset / segment.size ) * fillPercent
			: 0;
	const pendingPercent = isCurrent ? fillPercent - processedPercent : 0;
	const pendingClass = isNewest ? 'pending' : ''; // Yellow only for newest, red otherwise.

	const classNames = [
		'worker-segment-h',
		isNew ? 'segment-slide-in' : '',
		isRemoving ? 'segment-slide-out' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div
			className={ classNames }
			title={ `Segment ${ segment.id }: ${ formatBytes(
				segment.size
			) }` }
		>
			<div className="segment-label-h">{ segment.id }</div>
			<div className="segment-bar-h">
				{ isCurrent ? (
					<>
						<div
							className="segment-fill-h processed"
							style={ { width: `${ processedPercent }%` } }
						/>
						<div
							className={ `segment-fill-h ${ pendingClass }` }
							style={ { width: `${ pendingPercent }%` } }
						/>
					</>
				) : (
					<div
						className={ `segment-fill-h ${
							isProcessed ? 'processed' : ''
						}` }
						style={ { width: `${ fillPercent }%` } }
					/>
				) }
			</div>
			<div className="segment-size-h">
				{ formatBytes( segment.size ) }
			</div>
		</div>
	);
} );

/**
 * Format bytes per second to human readable string.
 *
 * @param {number} bytesPerSec Bytes per second.
 * @return {string} Formatted string.
 */
function formatByteRate( bytesPerSec ) {
	if ( ! bytesPerSec || bytesPerSec === 0 ) {
		return '0 B/s';
	}
	const k = 1024;
	const sizes = [ 'B/s', 'KB/s', 'MB/s', 'GB/s' ];
	const i = Math.floor( Math.log( bytesPerSec ) / Math.log( k ) );
	return (
		parseFloat( ( bytesPerSec / Math.pow( k, i ) ).toFixed( 1 ) ) +
		' ' +
		sizes[ i ]
	);
}

/**
 * Format age as human readable duration.
 *
 * @param {number} startedAt Unix timestamp when worker started.
 * @param {number} now       Current Unix timestamp.
 * @return {string} Formatted duration string.
 */
function formatAge( startedAt, now ) {
	if ( ! startedAt ) {
		return '-';
	}
	const seconds = now - startedAt;
	if ( seconds < 60 ) {
		return `${ seconds }s`;
	}
	if ( seconds < 3600 ) {
		const mins = Math.floor( seconds / 60 );
		return `${ mins }m`;
	}
	const hours = Math.floor( seconds / 3600 );
	const mins = Math.floor( ( seconds % 3600 ) / 60 );
	return `${ hours }h${ mins }m`;
}

/**
 * Format ETA as human readable duration.
 *
 * @param {number} bytesBehind Bytes remaining to process.
 * @param {number} readRate    Current read rate in bytes per second.
 * @return {string} Formatted ETA string or empty if not applicable.
 */
function formatEta( bytesBehind, readRate ) {
	if ( ! bytesBehind || bytesBehind <= 0 ) {
		return '';
	}
	if ( ! readRate || readRate <= 0 ) {
		return 'stalled';
	}
	const seconds = Math.ceil( bytesBehind / readRate );
	if ( seconds < 60 ) {
		return `${ seconds }s`;
	}
	if ( seconds < 3600 ) {
		const mins = Math.ceil( seconds / 60 );
		return `${ mins }m`;
	}
	const hours = Math.floor( seconds / 3600 );
	const mins = Math.ceil( ( seconds % 3600 ) / 60 );
	return `${ hours }h${ mins }m`;
}

/**
 * Log section showing segments for all partitions of a log.
 *
 * @param {Object} props                  Component props.
 * @param {string} props.name             Display name for the log.
 * @param {string} props.logKey           Key prefix for rate lookups (e.g., 'firehose').
 * @param {Array}  props.partitions       Array of partition data with segments.
 * @param {Object} props.writeRates       Write rates by log key.
 * @param {number} props.maxSize          Max segment size.
 * @param {Object} props.prevSegments     Previous segment IDs by key.
 * @param {Object} props.cursorData       Cursor data by partition (optional, for logs with readers).
 * @param {Object} props.removingSegments Segments being removed (animating out) by key.
 * @return {JSX.Element} Rendered component.
 */
const LogSection = memo( function LogSection( {
	name,
	logKey: logKeyPrefix,
	partitions,
	writeRates,
	maxSize,
	prevSegments,
	cursorData,
	removingSegments = {},
} ) {
	const sorted = [ ...partitions ].sort(
		( a, b ) => a.partition - b.partition
	);

	return (
		<div className="log-section">
			<div className="log-header">
				<span className="log-name">{ name }</span>
			</div>
			<div className="log-partitions">
				{ sorted.map( ( p ) => {
					const logKey = `${ logKeyPrefix }-${ p.partition }`;
					const cursor = cursorData?.[ p.partition ];
					const newestSegId =
						p.segments.length > 0
							? Math.max( ...p.segments.map( ( s ) => s.id ) )
							: 0;

					// Merge current segments with removing segments.
					const removing = removingSegments[ logKey ] || [];
					const allSegments = [ ...removing, ...p.segments ].sort(
						( a, b ) => a.id - b.id
					);
					const removingIds = new Set(
						removing.map( ( s ) => s.id )
					);

					return (
						<div key={ p.partition } className="log-partition-row">
							<div className="log-partition-info">
								<span className="partition-label-inline">
									P{ p.partition }
								</span>
								<span className="log-write-rate">
									W { formatByteRate( writeRates[ logKey ] ) }
								</span>
							</div>
							<div className="partition-segments">
								{ allSegments.map( ( segment ) => (
									<SegmentBar
										key={ segment.id }
										segment={ segment }
										maxSize={ maxSize }
										cursorSeg={ cursor?.seg }
										cursorOffset={ cursor?.offset }
										newestSegId={ newestSegId }
										isNew={
											prevSegments?.[ logKey ] &&
											! prevSegments[ logKey ].has(
												segment.id
											)
										}
										isRemoving={ removingIds.has(
											segment.id
										) }
									/>
								) ) }
								{ allSegments.length === 0 && (
									<div className="no-segments-h">
										No segments
									</div>
								) }
							</div>
						</div>
					);
				} ) }
			</div>
		</div>
	);
} );

/**
 * Worker connector between two logs.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.name        Worker name.
 * @param {Array}    props.workers     Workers of this type.
 * @param {Object}   props.readRates   Read rates by worker key.
 * @param {number}   props.currentTime Current timestamp for age calculation.
 * @param {Function} props.onRestart   Callback to restart worker(s).
 * @return {JSX.Element} Rendered component.
 */
const WorkerConnector = memo( function WorkerConnector( {
	name,
	workers,
	readRates,
	currentTime,
	onRestart,
	showArrows = true,
} ) {
	const sorted = [ ...workers ].sort( ( a, b ) => a.partition - b.partition );
	const allRunning = workers.every( ( w ) => w.status === 'running' );
	const allDead = workers.every( ( w ) => w.status === 'dead' );
	const anyPendingRestart = workers.some( ( w ) => w.restart_pending );
	const workerType = workers[ 0 ]?.type;

	return (
		<div className={ `worker-connector ${ allDead ? 'dead' : '' }` }>
			<div className="connector-arrow">{ showArrows && '↓' }</div>
			<div className="connector-content">
				<span className="connector-name">{ name }</span>
				{ sorted.map( ( w ) => {
					const key = `${ w.handler || w.type }-${ w.partition }`;
					return (
						<span
							key={ w.partition }
							className="connector-partition"
						>
							<span
								className={ `worker-status-badge compact ${ w.status }` }
							>
								P{ w.partition }
							</span>
							<span className="connector-age" title="Worker age">
								{ w.started_at && w.status === 'running'
									? formatAge( w.started_at, currentTime )
									: '' }
							</span>
							{ w.heartbeat_age !== null &&
								w.heartbeat_age !== undefined && (
									<span
										className={ `connector-heartbeat ${
											w.heartbeat_age > 30 ? 'stale' : ''
										}` }
										title="Heartbeat age"
									>
										{ w.heartbeat_age }s
									</span>
								) }
							<span
								className={ `connector-rate ${
									w.status === 'dead' ? 'dead' : ''
								}` }
							>
								R { formatByteRate( readRates[ key ] ) }
							</span>
							<span
								className={ `connector-behind ${
									w.behind > 1024 * 1024 ? 'warning' : ''
								}` }
							>
								{ w.behind > 0 ? formatBytes( w.behind ) : '' }
							</span>
							{ w.behind > 0 && (
								<span
									className={ `connector-eta ${
										! readRates[ key ] ||
										readRates[ key ] <= 0
											? 'stalled'
											: ''
									}` }
									title="Estimated time to catch up"
								>
									{ formatEta( w.behind, readRates[ key ] ) }
								</span>
							) }
							{ w.restart_pending && (
								<span
									className="connector-restart-pending"
									title="Restart pending"
								>
									⟳
								</span>
							) }
						</span>
					);
				} ) }
				<span className="connector-trailing">
					{ allRunning && (
						<span className="worker-status-badge running small">
							ALL RUN
						</span>
					) }
					{ allDead && (
						<span className="worker-status-badge dead small">
							ALL DEAD
						</span>
					) }
					{ onRestart && ! allDead && ! anyPendingRestart && (
						<button
							type="button"
							className="worker-restart-btn"
							onClick={ () => onRestart( workerType ) }
							title="Request graceful restart"
						>
							↻
						</button>
					) }
					{ anyPendingRestart && (
						<span className="worker-restart-pending-label">
							restarting...
						</span>
					) }
				</span>
			</div>
			<div className="connector-arrow">{ showArrows && '↓' }</div>
		</div>
	);
} );

/**
 * Worker Status component.
 *
 * @param {Object}  props           Component props.
 * @param {number}  props.refreshMs Refresh interval in milliseconds.
 * @param {boolean} props.fullPage  Whether rendering in full page mode.
 * @return {JSX.Element} Rendered component.
 */
/**
 * Standalone workers section (supervisor, stream-merger, health-check).
 *
 * @param {Object}   props             Component props.
 * @param {Array}    props.workers     Array of standalone worker status objects.
 * @param {number}   props.currentTime Current timestamp for age calculation.
 * @param {Function} props.onRestart   Callback to restart worker(s).
 * @return {JSX.Element} Rendered component.
 */
const StandaloneWorkers = memo( function StandaloneWorkers( {
	workers,
	currentTime,
	onRestart,
} ) {
	if ( ! workers || workers.length === 0 ) {
		return null;
	}

	// Group workers by type.
	const byType = {};
	workers.forEach( ( w ) => {
		if ( ! byType[ w.type ] ) {
			byType[ w.type ] = [];
		}
		byType[ w.type ].push( w );
	} );

	// Format type name for display.
	const formatTypeName = ( type ) => {
		return type
			.split( '-' )
			.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
			.join( ' ' );
	};

	return (
		<div className="standalone-workers-section">
			<div className="standalone-workers-header">
				<span className="standalone-workers-title">
					Standalone Workers
				</span>
			</div>
			<div className="standalone-workers-list">
				{ Object.entries( byType ).map( ( [ type, typeWorkers ] ) => {
					const allRunning = typeWorkers.every(
						( w ) => w.status === 'running'
					);
					const allDead = typeWorkers.every(
						( w ) => w.status === 'dead'
					);
					const anyPendingRestart = typeWorkers.some(
						( w ) => w.restart_pending
					);
					const isPartitioned = typeWorkers.some(
						( w ) => w.partition !== null
					);

					return (
						<div
							key={ type }
							className={ `standalone-worker-row ${
								allDead ? 'dead' : ''
							}` }
						>
							<span className="standalone-worker-name">
								{ formatTypeName( type ) }
							</span>
							<div className="standalone-worker-partitions">
								{ typeWorkers
									.sort(
										( a, b ) =>
											( a.partition ?? -1 ) -
											( b.partition ?? -1 )
									)
									.map( ( w, idx ) => (
										<span
											key={ w.partition ?? idx }
											className="standalone-worker-instance"
										>
											{ isPartitioned && (
												<span
													className={ `worker-status-badge compact ${ w.status }` }
												>
													P{ w.partition }
												</span>
											) }
											{ ! isPartitioned && (
												<span
													className={ `worker-status-badge compact ${ w.status }` }
												>
													{ w.status === 'running'
														? 'RUN'
														: 'DEAD' }
												</span>
											) }
											<span
												className="standalone-worker-age"
												title="Uptime"
											>
												{ w.started_at &&
												w.status === 'running'
													? formatAge(
															w.started_at,
															currentTime
													  )
													: '' }
											</span>
											{ w.heartbeat_age !== null &&
												w.heartbeat_age !==
													undefined && (
													<span
														className={ `connector-heartbeat ${
															w.heartbeat_age > 30
																? 'stale'
																: ''
														}` }
														title="Heartbeat age"
													>
														{ w.heartbeat_age }s
													</span>
												) }
											{ w.restart_pending && (
												<span
													className="connector-restart-pending"
													title="Restart pending"
												>
													⟳
												</span>
											) }
										</span>
									) ) }
							</div>
							<span className="connector-trailing">
								{ allRunning && isPartitioned && (
									<span className="worker-status-badge running small">
										ALL RUN
									</span>
								) }
								{ allDead && isPartitioned && (
									<span className="worker-status-badge dead small">
										ALL DEAD
									</span>
								) }
								{ onRestart &&
									! allDead &&
									! anyPendingRestart && (
										<button
											type="button"
											className="worker-restart-btn"
											onClick={ () => onRestart( type ) }
											title="Request graceful restart"
										>
											↻
										</button>
									) }
								{ anyPendingRestart && (
									<span className="worker-restart-pending-label">
										restarting...
									</span>
								) }
							</span>
						</div>
					);
				} ) }
			</div>
		</div>
	);
} );

export default function WorkerStatus( { refreshMs = 2000, fullPage = false } ) {
	const [ workers, setWorkers ] = useState( [] );
	const [ standalone, setStandalone ] = useState( [] ); // Standalone workers.
	const [ logs, setLogs ] = useState( [] ); // Output logs (flames) with no consumer.
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ refreshInterval, setRefreshInterval ] = useState( () => {
		// Load from localStorage with validation against allowed dropdown values.
		const validValues = REFRESH_OPTIONS.map( ( opt ) => opt.value );
		const saved = localStorage.getItem( 'event-logger-worker-refresh' );
		if ( saved && validValues.includes( saved ) ) {
			return saved;
		}
		return String( refreshMs );
	} );
	const [ byteRates, setByteRates ] = useState( {} ); // Read rates by worker key.
	const [ writeRates, setWriteRates ] = useState( {} ); // Write rates by log key.
	const [ segmentSize, setSegmentSize ] = useState( 64 * 1024 * 1024 ); // Default 64MB.
	const [ currentTime, setCurrentTime ] = useState( () =>
		Math.floor( Date.now() / 1000 )
	);
	const prevSegmentsRef = useRef( {} ); // Previous segment IDs by worker key.
	const prevSegmentDataRef = useRef( {} ); // Previous segment data by key for removal animation.
	const prevPositionsRef = useRef( {} ); // Read positions by worker key.
	const prevTotalSizesRef = useRef( {} ); // Total sizes by log key for write rates.
	const lastFetchTimeRef = useRef( null );
	const animationTimersRef = useRef( [] );
	const [ removingSegments, setRemovingSegments ] = useState( {} ); // Segments animating out.
	const isPageVisible = usePageVisibility();

	/**
	 * Request restart for workers of a given type.
	 *
	 * @param {string} workerType Worker group name (e.g., 'firehose-workers', 'request-workers').
	 */
	const handleRestart = useCallback( async ( workerType ) => {
		try {
			await apiFetch( {
				path: '/newspack-nodes/v1/performance/workers/restart',
				method: 'POST',
				data: {
					type: workerType,
					all_partitions: true,
					nonce: window.eventLoggerDashboards?.restartNonce || '',
				},
			} );
		} catch ( err ) {
			setError( `Failed to request restart: ${ err.message }` );
		}
	}, [] );

	// Save refresh interval to localStorage.
	useEffect( () => {
		localStorage.setItem( 'event-logger-worker-refresh', refreshInterval );
	}, [ refreshInterval ] );

	const fetchWorkers = useCallback( async () => {
		try {
			const now = Date.now();
			const data = await apiFetch( {
				path: '/newspack-nodes/v1/performance/workers',
			} );

			// Track segment changes for animation.
			const newPrevSegments = {};
			const newPrevSegmentData = {};
			const newPositions = {};
			const newByteRates = {};
			const newWriteRates = {};
			const newTotalSizes = {};
			const newRemoving = {};

			// Calculate time delta for rate calculation.
			const timeDelta = lastFetchTimeRef.current
				? ( now - lastFetchTimeRef.current ) / 1000
				: 0;

			data.workers.forEach( ( worker ) => {
				const key = `${ worker.handler || worker.type }-${
					worker.partition
				}`;
				// Use input_log from backend (strip .log suffix for logKey).
				const logName =
					worker.input_log?.replace( /\.log$/, '' ) || worker.type;
				const logKey = `${ logName }-${ worker.partition }`;
				const currentIds = new Set(
					worker.segments.map( ( s ) => s.id )
				);

				// Calculate total bytes processed (sum of all segment sizes up to cursor).
				let totalProcessed = 0;
				worker.segments.forEach( ( seg ) => {
					if ( seg.id < worker.cursor_seg ) {
						totalProcessed += seg.size;
					} else if ( seg.id === worker.cursor_seg ) {
						totalProcessed += worker.cursor_offset;
					}
				} );
				newPositions[ key ] = totalProcessed;

				// Track total size for write rate calculation.
				newTotalSizes[ logKey ] = worker.total_size;

				// Calculate read rate if we have previous data.
				if (
					timeDelta > 0 &&
					prevPositionsRef.current[ key ] !== undefined
				) {
					const bytesDelta =
						totalProcessed - prevPositionsRef.current[ key ];
					// Only show positive rates (handle segment rotation).
					if ( bytesDelta >= 0 ) {
						newByteRates[ key ] = bytesDelta / timeDelta;
					} else {
						// Segment rotated - keep previous rate or show 0.
						newByteRates[ key ] = 0;
					}
				}

				// Calculate write rate for the log this worker reads from.
				if (
					timeDelta > 0 &&
					prevTotalSizesRef.current[ logKey ] !== undefined
				) {
					const sizeDelta =
						worker.total_size - prevTotalSizesRef.current[ logKey ];
					if ( sizeDelta >= 0 ) {
						newWriteRates[ logKey ] = sizeDelta / timeDelta;
					} else {
						// Segment rotated - keep previous rate or show 0.
						newWriteRates[ logKey ] = 0;
					}
				}

				// Store current segment IDs and data for next comparison.
				newPrevSegments[ key ] = currentIds;
				newPrevSegmentData[ key ] = new Map(
					worker.segments.map( ( s ) => [ s.id, s ] )
				);

				// Detect removed segments (in prev but not in current).
				const prevIds = prevSegmentsRef.current[ key ];
				const prevData = prevSegmentDataRef.current[ key ];
				if ( prevIds && prevData ) {
					const removed = [];
					for ( const id of prevIds ) {
						if ( ! currentIds.has( id ) ) {
							const segData = prevData.get( id );
							if ( segData ) {
								removed.push( segData );
							}
						}
					}
					if ( removed.length > 0 ) {
						newRemoving[ logKey ] = removed;
					}
				}
			} );

			// Calculate write rates for output-only logs (flames).
			( data.logs || [] ).forEach( ( log ) => {
				const logKey = `${ log.name }-${ log.partition }`;
				newTotalSizes[ logKey ] = log.total_size;

				if (
					timeDelta > 0 &&
					prevTotalSizesRef.current[ logKey ] !== undefined
				) {
					const sizeDelta =
						log.total_size - prevTotalSizesRef.current[ logKey ];
					if ( sizeDelta >= 0 ) {
						newWriteRates[ logKey ] = sizeDelta / timeDelta;
					} else {
						newWriteRates[ logKey ] = 0;
					}
				}
			} );

			setWorkers( data.workers );
			setStandalone( data.standalone || [] );
			setLogs( data.logs || [] );
			setByteRates( newByteRates );
			setWriteRates( newWriteRates );
			if ( data.segment_size ) {
				setSegmentSize( data.segment_size );
			}
			if ( data.timestamp ) {
				setCurrentTime( data.timestamp );
			}
			setError( null );

			// Update refs.
			lastFetchTimeRef.current = now;
			prevPositionsRef.current = newPositions;
			prevTotalSizesRef.current = newTotalSizes;

			// Clear previous animation timers.
			animationTimersRef.current.forEach( clearTimeout );
			animationTimersRef.current = [];

			// Set removing segments for animation.
			if ( Object.keys( newRemoving ).length > 0 ) {
				setRemovingSegments( newRemoving );
				// Clear removing segments after animation completes.
				animationTimersRef.current.push(
					setTimeout( () => {
						setRemovingSegments( {} );
					}, 400 )
				);
			}

			// Clear "new" status after animation completes.
			animationTimersRef.current.push(
				setTimeout( () => {
					prevSegmentsRef.current = newPrevSegments;
					prevSegmentDataRef.current = newPrevSegmentData;
				}, 500 )
			);
		} catch ( err ) {
			setError( 'Server disconnected. Reconnecting...' );
		} finally {
			setLoading( false );
		}
	}, [] );

	// Fetch on mount.
	useEffect( () => {
		fetchWorkers();
	}, [ fetchWorkers ] );

	// Auto-refresh only when page is visible.
	useEffect( () => {
		if ( ! isPageVisible ) {
			return;
		}
		const intervalMs = parseInt( refreshInterval, 10 );
		const interval = setInterval( fetchWorkers, intervalMs );
		return () => {
			clearInterval( interval );
			animationTimersRef.current.forEach( clearTimeout );
			animationTimersRef.current = [];
		};
	}, [ fetchWorkers, refreshInterval, isPageVisible ] );

	// Group workers by handler (must be before early returns for React hooks rules).
	const workersByHandler = useMemo( () => {
		const byHandler = {};
		workers.forEach( ( worker ) => {
			const key = worker.handler || worker.type;
			if ( ! byHandler[ key ] ) {
				byHandler[ key ] = [];
			}
			byHandler[ key ].push( worker );
		} );
		return byHandler;
	}, [ workers ] );

	// Build dynamic pipeline structure from workers, sorted by data flow.
	// Each pipeline step: { inputLog, workerType, handlerName, outputLog, workers }
	const pipelineSteps = useMemo( () => {
		const steps = [];
		const handlerKeys = Object.keys( workersByHandler );

		handlerKeys.forEach( ( key ) => {
			const handlerWorkers = workersByHandler[ key ];
			if ( handlerWorkers.length === 0 ) {
				return;
			}
			const first = handlerWorkers[ 0 ];
			steps.push( {
				inputLog: first.input_log,
				workerType: first.type,
				handlerName: key,
				outputLog: first.output_log,
				workers: handlerWorkers,
			} );
		} );

		// Topological sort: step reading log X comes after step writing log X.
		const outputMap = {};
		steps.forEach( ( step, i ) => {
			if ( step.outputLog ) {
				outputMap[ step.outputLog ] = i;
			}
		} );

		const sorted = [];
		const visited = new Set();
		const visit = ( idx ) => {
			if ( visited.has( idx ) ) {
				return;
			}
			visited.add( idx );
			const step = steps[ idx ];
			const dep = outputMap[ step.inputLog ];
			if ( dep !== undefined ) {
				visit( dep );
			}
			sorted.push( steps[ idx ] );
		};
		steps.forEach( ( _, i ) => visit( i ) );

		return sorted;
	}, [ workersByHandler ] );

	// Helper to format worker type as display name.
	const formatWorkerName = ( type ) => {
		return type
			.split( '-' )
			.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
			.join( ' ' );
	};

	// Helper to get log key from log name (strip .log suffix).
	const getLogKey = ( logName ) => {
		return logName?.replace( /\.log$/, '' ) || '';
	};

	if ( loading && workers.length === 0 ) {
		return (
			<div className="worker-status-loading">
				Loading worker status...
			</div>
		);
	}

	const containerClass = fullPage ? 'worker-status-full' : 'worker-status';

	// Calculate total read rate across all partitions.
	const totalReadRate = Object.values( byteRates ).reduce(
		( sum, rate ) => sum + ( rate || 0 ),
		0
	);

	// Calculate total write rate across all logs.
	const totalWriteRate = Object.values( writeRates ).reduce(
		( sum, rate ) => sum + ( rate || 0 ),
		0
	);

	return (
		<div className={ containerClass }>
			{ ! fullPage && <h3>Worker Status</h3> }
			{ fullPage && (
				<div className="worker-status-header">
					<h2>Worker Status</h2>
					{ error && (
						<div className="worker-status-error-inline">
							{ error }
						</div>
					) }
					<div className="worker-status-total-rate">
						<span className="total-rate-write">
							<span className="total-rate-label">W</span>
							<span className="total-rate-value">
								{ formatByteRate( totalWriteRate ) }
							</span>
						</span>
						<span className="total-rate-read">
							<span className="total-rate-label">R</span>
							<span className="total-rate-value">
								{ formatByteRate( totalReadRate ) }
							</span>
						</span>
					</div>
					<div className="worker-status-controls">
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
			) }
			{ /* Standalone Workers Section */ }
			{ standalone.length > 0 && (
				<StandaloneWorkers
					workers={ standalone }
					currentTime={ currentTime }
					onRestart={ handleRestart }
				/>
			) }

			<div className="pipeline-flow">
				{ /* Dynamic pipeline rendering based on registered workers */ }
				{ pipelineSteps.map( ( step ) => {
					const logKey = getLogKey( step.inputLog );
					const hasOutput = !! step.outputLog;

					return (
						<div key={ step.handlerName }>
							{ /* Input log section */ }
							<LogSection
								name={ step.inputLog }
								logKey={ logKey }
								partitions={ step.workers.map( ( w ) => ( {
									partition: w.partition,
									segments: w.segments,
								} ) ) }
								writeRates={ writeRates }
								maxSize={ segmentSize }
								prevSegments={ Object.fromEntries(
									Object.entries( prevSegmentsRef.current )
										.filter( ( [ k ] ) =>
											k.startsWith(
												`${ step.handlerName }-`
											)
										)
										.map( ( [ k, v ] ) => {
											const parts = k.split( '-' );
											const partition =
												parts[ parts.length - 1 ];
											return [
												`${ logKey }-${ partition }`,
												v,
											];
										} )
								) }
								cursorData={ Object.fromEntries(
									step.workers.map( ( w ) => [
										w.partition,
										{
											seg: w.cursor_seg,
											offset: w.cursor_offset,
										},
									] )
								) }
								removingSegments={ removingSegments }
							/>

							{ /* Worker connector */ }
							<WorkerConnector
								name={ formatWorkerName( step.handlerName ) }
								workers={ step.workers }
								readRates={ byteRates }
								currentTime={ currentTime }
								onRestart={ handleRestart }
								showArrows={ hasOutput }
							/>
						</div>
					);
				} ) }

				{ /* Show terminal output logs (from API, deduplicated, errors last) */ }
				{ [ ...new Set( logs.map( ( l ) => l.name ) ) ]
					.sort(
						( a, b ) =>
							( a === 'errors' ? 1 : 0 ) -
							( b === 'errors' ? 1 : 0 )
					)
					.map( ( logName ) => (
						<LogSection
							key={ logName }
							name={ `${ logName }.log` }
							logKey={ logName }
							partitions={ logs.filter(
								( l ) => l.name === logName
							) }
							writeRates={ writeRates }
							maxSize={ segmentSize }
							prevSegments={ {} }
							removingSegments={ removingSegments }
						/>
					) ) }
			</div>
		</div>
	);
}
