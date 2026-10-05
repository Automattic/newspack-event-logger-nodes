/**
 * URL Detail View — the body of the Performance dashboard's URL modal.
 *
 * `PerformanceDashboard` opens a modal for one URL and renders this view inside
 * it. The view owns no slice: everything but the breakdown series draws a
 * payload the parent already fetched from the `performance` CI's `dump_url`
 * verb. Top to bottom:
 *
 *   1. Aggregate time chart of the breakdown series, with the Metric and
 *      Breakdown dropdowns that drive it.
 *   2. Category time charts — time, count, and average per profile category.
 *   3. Response-time scatter of the individual requests.
 *   4. Aggregate flame graph, drawn by `RequestTrace`, which holds
 *      d3-flame-graph behind `lazy()` so the sections above it paint first.
 *   5. Aggregate profile breakdown, averaged across the profiled requests.
 *   6. Virtualized recent-requests table with an "Errors Only" toggle.
 *
 * The breakdown series is the one read this view issues for itself, because it
 * is a separate round trip from the `dump_url` payload: `url_breakdown` goes
 * whenever the Breakdown dropdown or the URL changes, and again on a
 * router-tick timer.
 *
 * Footgun: the requests table virtualizes against the modal's scroll container
 * (`.components-modal__content`). Mounted outside a `Modal`, `useVirtualization`
 * finds no such ancestor and throws — which is why the tests mock that hook.
 */

import {
	useRef,
	memo,
	useMemo,
	useState,
	useEffect,
	useCallback,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import { SERVER } from '../hooks/usePerformanceGraph';
import { SCOPED_BREAKDOWN_OPTIONS } from '../constants';

/**
 * How often the breakdown series is re-fetched, in milliseconds.
 *
 * The series aggregates a long retention window, so it moves slowly; five
 * minutes keeps the chart current without a round-trip per router tick.
 */
const BREAKDOWN_REFRESH_MS = 300000;

import ResponseTimeChart from '../ResponseTimeChart';
import RequestTrace from './RequestTrace';
import CategoryTimeChart from '../CategoryTimeChart';
import { ProfileWithCaption } from '../RequestProfile';
import BreakdownControls from './BreakdownControls';
import { breakdownState } from '../AggregateTimeChart';
import { errorStatus } from '../../components/errorStatus';
import { wholeMs } from './HeadlineStats';
import SortHeaderButton from './SortHeaderButton';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';
import useRouterTick from '@newspack-nodes/shared/hooks/useRouterTick';

/**
 * Request-row height in pixels.
 *
 * The virtualizer's arithmetic and each row's inline style both read this one
 * constant. Let them disagree and the padding spacers mis-size the runway,
 * which drifts the visible window away from the scroll position.
 */
const ROW_HEIGHT = 40;

/**
 * What a row's bar measures: its peak memory under the memory metric, else
 * its duration, which `dump_url` nulls where none was measured (decision 24).
 *
 * @param {Object} req    A request row.
 * @param {string} metric The chart metric.
 * @return {number} The bar's value; 0 draws no bar.
 */
const barValue = ( req, metric ) => {
	if ( 'memory' === metric ) {
		return req.peak_mb || 0;
	}
	return req.duration_ms ?? 0;
};

/**
 * One status code and its count, as the errors line names it.
 *
 * @param {[string, number]} entry `[ code, count ]`.
 * @return {string} The pair; code 0 is a request that answered nothing.
 */
const codeCount = ( [ code, count ] ) =>
	`${
		'0' === code ? __( 'no status', 'newspack-event-logger-nodes' ) : code
	} \u00d7${ count }`;

/**
 * The one line under an errors-only list: how many of the URL's exact errors
 * it holds, when the first and the last of them started, and what they
 * answered.
 *
 * @param {Object}  props
 * @param {?number} props.total   The URL's exact error count, `stats.errors`.
 * @param {Object}  props.summary The `errorSummary()` of the list.
 * @return {import('react').ReactElement} The line.
 */
function ErrorsLine( { total, summary } ) {
	const parts = [
		sprintf(
			// translators: 1: errors listed, 2: the URL's errors in the window.
			__( '%1$d of %2$s errors listed', 'newspack-event-logger-nodes' ),
			summary.listed,
			'number' === typeof total ? String( total ) : '—'
		),
	];
	if ( summary.first_at ) {
		parts.push(
			sprintf(
				// translators: %s: when the earliest listed error started.
				__( 'first %s', 'newspack-event-logger-nodes' ),
				new Date( summary.first_at * 1000 ).toLocaleString()
			),
			sprintf(
				// translators: %s: when the latest listed error started.
				__( 'last %s', 'newspack-event-logger-nodes' ),
				new Date( summary.last_at * 1000 ).toLocaleString()
			)
		);
	}
	const codes = Object.entries( summary.status_codes ?? {} );
	if ( codes.length ) {
		parts.push( codes.map( codeCount ).join( ', ' ) );
	}
	return <p className="newspack-nodes-status">{ parts.join( ' · ' ) }</p>;
}

// JSDoc rides the inner function: on the const, memo() infers props as `{}`.
const RequestRow = memo(
	/**
	 * One row of the recent-requests table, memoized so scrolling re-renders
	 * only the rows that entered the window.
	 *
	 * The row is a button: click or Enter/Space hands rid + partition to
	 * `onSelect`. It also carries the `?` picker's `request:` descriptor as
	 * `data-ask`, so a picker click on a row asks about that request rather
	 * than about the URL the modal is open on. Its request-id cell carries a
	 * bar background whose width is the row's value as a fraction of `maxBar`,
	 * and its status cell reads `error_status` through `errorStatus()`, falling
	 * back to the HTTP status code for a request that ended nominally. A
	 * duration nobody measured shows as a dash and bars nothing.
	 *
	 * @param {Object}                                   props          Component props.
	 * @param {Object}                                   props.req      Request index entry: rid, partition, timestamp, method, status_code, error_status, duration_ms, peak_mb.
	 * @param {(rid: string, partition: number) => void} props.onSelect Receives the row's rid and partition on click or keyboard activation.
	 * @param {number}                                   props.maxBar   Largest bar value across the filtered rows; 0 draws no bar.
	 * @param {string}                                   props.metric   Chart metric; 'memory' bars peak_mb, every other value bars duration_ms.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function RequestRow( { req, onSelect, maxBar, metric } ) {
		const barPct =
			maxBar > 0 ? ( barValue( req, metric ) / maxBar ) * 100 : 0;
		const status = errorStatus( req.error_status );
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				onSelect( req.rid, req.partition );
			}
		};

		return (
			<div
				role="button"
				tabIndex={ 0 }
				data-ask={ `request:${ req.rid }:${ req.partition ?? 0 }` }
				className="event-logger-table__row newspack-nodes-table__row"
				style={ { height: ROW_HEIGHT } }
				onClick={ () => onSelect( req.rid, req.partition ) }
				onKeyDown={ handleKeyDown }
			>
				<div className="event-logger-table__cell newspack-nodes-table__cell">
					{ new Date( req.timestamp * 1000 ).toLocaleString() }
				</div>
				<div className="event-logger-table__cell newspack-nodes-table__cell">
					{ req.method || '-' }
				</div>
				<div
					className="event-logger-table__cell newspack-nodes-table__cell event-logger-table__cell--mono"
					style={ {
						background: `linear-gradient(to right, rgba(100, 181, 246, 0.15) ${ barPct }%, transparent ${ barPct }%)`,
					} }
				>
					<code>{ req.rid }</code>
				</div>
				<div
					className={ `event-logger-table__cell newspack-nodes-table__cell event-logger-table__cell--status entry-status${
						status ? ` newspack-nodes-status ${ status.tone }` : ''
					}` }
					data-status={ status ? undefined : req.status_code }
				>
					{ status ? (
						<span title={ status.label }>{ req.error_status }</span>
					) : (
						req.status_code || '-'
					) }
				</div>
				<div className="event-logger-table__cell newspack-nodes-table__cell event-logger-table__cell--numeric">
					{ 'number' === typeof req.duration_ms
						? wholeMs( req.duration_ms )
						: '—' }
				</div>
				<div className="event-logger-table__cell newspack-nodes-table__cell event-logger-table__cell--numeric">
					{ req.peak_mb > 0 ? `${ req.peak_mb }MB` : '-' }
				</div>
			</div>
		);
	}
);

/**
 * URL Detail View component.
 *
 * Sorting lives upstream: the parent sorts and hands back `sortedRequests`,
 * and `requestSort` only tells the headers which arrow to draw. "Errors Only"
 * lives upstream too: the server answers it, walking past the clean requests
 * that bury a busy URL's errors, so the list, the heading count, the
 * bar-scaling maximum and the response-time scatter all show those errors.
 *
 * @param {Object}                                   props                    Component props.
 * @param {Object}                                   props.urlDetail          The fields this view reads off the `dump_url` payload: stats, requests, scan_stopped_early, aggregate_flame, aggregate_profiles, last_modified, and optional category_time_series.
 * @param {Array}                                    props.sortedRequests     Recent requests, already sorted by the parent.
 * @param {Object}                                   props.requestSort        Current sort as `{ field, dir }`; drives the header arrows only.
 * @param {(field: string) => void}                  props.onRequestSort      Receives a field name when a sortable header is clicked.
 * @param {(rid: string, partition: number) => void} props.onSelectRequest    Receives a rid AND its partition from a row click or a scatter-plot dot.
 * @param {string}                                   props.urlHash            The URL's 12-char hash, which addresses the `url_breakdown` read below.
 * @param {(on: boolean) => void}                    props.onErrorsOnlyChange Receives the flipped value when the toggle is clicked.
 * @param {?Object}                                  [props.detailErrors]     Under Errors Only, the `errorSummary()` of the list; null while every request is listed. The dashboard owns the toggle, so it survives a trip to a request and back.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function UrlDetailView( {
	urlDetail,
	sortedRequests,
	requestSort,
	onRequestSort,
	onSelectRequest,
	urlHash,
	onErrorsOnlyChange,
	detailErrors = null,
} ) {
	const listRef = useRef( null );
	const errorsOnly = null !== detailErrors;

	// A list the walk cut short reads exactly like a URL with no traffic.
	const scanNote = urlDetail.scan_stopped_early
		? __(
				'The request index scan stopped early, so requests for this URL may be missing.',
				'newspack-event-logger-nodes'
		  )
		: null;

	const { startIndex, endIndex, paddingTop, paddingBottom } =
		useVirtualization(
			listRef,
			ROW_HEIGHT,
			sortedRequests.length,
			'.components-modal__content'
		);
	const visibleRequests = sortedRequests.slice( startIndex, endIndex );

	const [ chartMetric, setChartMetric ] = useState( 'volume' );

	const maxBar = useMemo(
		() =>
			sortedRequests.reduce(
				( max, r ) => Math.max( max, barValue( r, chartMetric ) ),
				0
			),
		[ sortedRequests, chartMetric ]
	);
	const [ chartBreakdown, setChartBreakdown ] = useState( 'status' );
	const [ breakdownData, setBreakdownData ] = useState( null );
	const [ breakdownSlots, setBreakdownSlots ] = useState( null );
	const [ breakdownLoading, setBreakdownLoading ] = useState( false );
	const [ breakdownError, setBreakdownError ] = useState( null );
	// Once per reply: the modal re-renders on every scroll event.
	const breakdownRead = useMemo(
		() => breakdownState( breakdownData ),
		[ breakdownData ]
	);

	/**
	 * `url_breakdown` for one dimension; `onDone` is the chart's only series
	 * source. `run` takes the command's token array, so `loadBreakdown` below
	 * is what the rest of the component calls.
	 *
	 * A READ in the substrate's sense: idempotent, and keyed on a subject the
	 * operator changes by flipping the dropdown. Sent as a write, three fast
	 * flips would be three commands answered in any order, so the chart could
	 * draw one dimension's data under another's label, and a dropped reply
	 * would leave "Loading…" standing forever, because a write never re-asks.
	 * Retried, the newest pick supersedes. Asking `dump_url` for the series
	 * instead would drag a full request-index walk the chart keeps nothing of.
	 */
	const { run: fetchBreakdown } = useCommandOnce( {
		ci: SERVER,
		command: 'url_breakdown',
		retry: true,
		// Subject is the PAIR, not the hash: a superseded reply fills nothing.
		subjectOf: ( args ) => args.join( ' ' ),
		onDone: ( { result, error } ) => {
			setBreakdownError( error );
			setBreakdownData( result?.breakdown_time_series ?? null );
			setBreakdownSlots( result?.slots ?? null );
			setBreakdownLoading( false );
		},
	} );

	/**
	 * Ask for one breakdown dimension, and mark the panel loading until it
	 * lands.
	 *
	 * @param {string} breakdown Dimension to break the series down by.
	 */
	const loadBreakdown = useCallback(
		( breakdown ) => {
			setBreakdownLoading( true );
			fetchBreakdown( formatCommandArgs( [ urlHash ], { breakdown } ) );
		},
		[ fetchBreakdown, urlHash ]
	);

	// Clear on a SWITCH only: old numbers under the new label would read true.
	useEffect( () => {
		setBreakdownData( null );
		loadBreakdown( chartBreakdown );
	}, [ chartBreakdown, loadBreakdown ] );

	/**
	 * Re-ask for the dimension currently on screen, on the router tick.
	 *
	 * The tick passes no arguments, so the callback has to close over the
	 * dropdown's value rather than be handed it.
	 */
	const reloadBreakdown = useCallback( () => {
		loadBreakdown( chartBreakdown );
	}, [ chartBreakdown, loadBreakdown ] );
	useRouterTick( {
		name: 'url-breakdown:timer',
		onTick: reloadBreakdown,
		intervalMs: BREAKDOWN_REFRESH_MS,
	} );

	/**
	 * The direction a column's header draws: the sort's own on the sorted
	 * column, none on every other.
	 *
	 * @param {string} field Sort field the header stands for.
	 * @return {?string} 'asc', 'desc' or null.
	 */
	const sortDir = ( field ) =>
		requestSort.field === field ? requestSort.dir : null;

	return (
		// The picker root: a body click outside a row asks about the URL.
		<div data-ask={ urlHash ? `url:${ urlHash }` : undefined }>
			{ errorsOnly && (
				<p className="newspack-nodes-banner is-info" role="status">
					{ __(
						'The charts, the flame graph and the profile describe every request to this URL; the list below holds its errors.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }
			{ /* Always mounted: a gate here can strand the operator. */ }
			<BreakdownControls
				breakdownRead={ breakdownRead }
				slots={ breakdownSlots }
				metric={ chartMetric }
				setMetric={ setChartMetric }
				breakdown={ chartBreakdown }
				setBreakdown={ setChartBreakdown }
				breakdownOptions={ SCOPED_BREAKDOWN_OPTIONS }
				loading={ breakdownLoading }
				error={ breakdownError }
			/>

			<CategoryTimeChart
				data={ urlDetail?.category_time_series }
				slots={ urlDetail?.slots ?? null }
			/>

			{ urlDetail.requests?.length > 0 && (
				<ResponseTimeChart
					requests={ urlDetail.requests }
					onRequestClick={ onSelectRequest }
				/>
			) }

			{ urlDetail.aggregate_flame &&
				urlDetail.aggregate_flame.children?.length > 0 && (
					<div style={ { marginTop: '20px' } }>
						<RequestTrace
							flameData={ urlDetail.aggregate_flame }
							title={ __(
								'Aggregate Flame Graph',
								'newspack-event-logger-nodes'
							) }
							lastModified={ urlDetail.last_modified }
						/>
					</div>
				) }

			{ urlDetail.aggregate_profiles?.categories && (
				<ProfileWithCaption
					profiles={ urlDetail.aggregate_profiles.categories }
					totalMs={ urlDetail.stats?.avg_ms }
					totalProfiledTime={
						urlDetail.aggregate_profiles?.total_time
					}
					count={ urlDetail.aggregate_profiles?.count || 0 }
				/>
			) }

			<div className="event-logger-table event-logger-table--requests">
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: '12px',
						marginBottom: '8px',
					} }
				>
					<h3 style={ { margin: 0 } }>
						{ sprintf(
							// translators: %d: number of recent requests shown.
							__(
								'Recent Requests (%d)',
								'newspack-event-logger-nodes'
							),
							sortedRequests.length
						) }
					</h3>
					<button
						type="button"
						className={ errorsOnly ? 'button is-active' : 'button' }
						onClick={ () => onErrorsOnlyChange( ! errorsOnly ) }
					>
						{ errorsOnly
							? __(
									'Showing Errors',
									'newspack-event-logger-nodes'
							  )
							: __(
									'Errors Only',
									'newspack-event-logger-nodes'
							  ) }
					</button>
				</div>
				{ detailErrors && (
					<ErrorsLine
						total={ urlDetail.stats?.errors }
						summary={ detailErrors }
					/>
				) }

				{ /* Outside listRef, so it is never a virtualized row. */ }
				<div className="event-logger-table__header newspack-nodes-table__header">
					<SortHeaderButton
						field="timestamp"
						label={ __( 'Time', 'newspack-event-logger-nodes' ) }
						dir={ sortDir( 'timestamp' ) }
						onSort={ onRequestSort }
					/>
					<div className="event-logger-table__cell newspack-nodes-table__cell">
						{ __( 'Method', 'newspack-event-logger-nodes' ) }
					</div>
					<div className="event-logger-table__cell newspack-nodes-table__cell">
						{ __( 'Request ID', 'newspack-event-logger-nodes' ) }
					</div>
					<SortHeaderButton
						field="status_code"
						label={ __( 'Status', 'newspack-event-logger-nodes' ) }
						dir={ sortDir( 'status_code' ) }
						variant="center"
						onSort={ onRequestSort }
					/>
					<SortHeaderButton
						field="duration_ms"
						label={ __(
							'Duration',
							'newspack-event-logger-nodes'
						) }
						dir={ sortDir( 'duration_ms' ) }
						variant="numeric"
						onSort={ onRequestSort }
					/>
					<SortHeaderButton
						field="peak_mb"
						label={ __( 'Mem', 'newspack-event-logger-nodes' ) }
						dir={ sortDir( 'peak_mb' ) }
						variant="numeric"
						onSort={ onRequestSort }
					/>
				</div>

				<div
					ref={ listRef }
					className="event-logger-table__list newspack-nodes-table"
				>
					{ sortedRequests.length === 0 ? (
						<div className="event-logger-table__empty newspack-nodes-empty-state">
							{ scanNote ||
								__(
									'No requests to display',
									'newspack-event-logger-nodes'
								) }
						</div>
					) : (
						<>
							<div style={ { height: paddingTop } } />
							{ visibleRequests.map( ( req ) => (
								<RequestRow
									key={ req.rid }
									req={ req }
									onSelect={ onSelectRequest }
									maxBar={ maxBar }
									metric={ chartMetric }
								/>
							) ) }
							<div style={ { height: paddingBottom } } />
						</>
					) }
				</div>
				{ scanNote && sortedRequests.length > 0 && (
					<p className="newspack-nodes-status">{ scanNote }</p>
				) }
			</div>
		</div>
	);
}
