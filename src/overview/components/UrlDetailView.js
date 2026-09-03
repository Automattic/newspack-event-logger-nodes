/**
 * URL Detail View — the body of the Performance dashboard's URL modal.
 *
 * `PerformanceDashboard` opens a modal for one URL and renders this view inside
 * it. The view owns no slice: everything but the breakdown series draws a
 * payload the parent already fetched from the `performance` CI's `url_detail`
 * verb. Top to bottom:
 *
 *   1. Aggregate time chart of the breakdown series, with the Metric and
 *      Breakdown dropdowns that drive it.
 *   2. Category time charts — time, count, and average per profile category.
 *   3. Response-time scatter of the individual requests.
 *   4. Aggregate flame graph, drawn by `RequestTrace`, which holds
 *      d3-flame-graph behind `lazy()` so the sections above it paint first.
 *   5. Aggregate profile breakdown, averaged across the profiled requests.
 *   6. Virtualized recent-requests table with an "Errors Only" filter.
 *
 * The breakdown series is the one read this view issues for itself, because it
 * is a separate round trip from the `url_detail` payload: `url_breakdown` goes
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
import { errorStatus } from '../../components/errorStatus';
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
	 * back to the HTTP status code for a request that ended nominally.
	 *
	 * @param {Object}                                   props          Component props.
	 * @param {Object}                                   props.req      Request index entry: rid, partition, timestamp, method, status_code, error_status, duration_ms, peak_mb.
	 * @param {(rid: string, partition: number) => void} props.onSelect Receives the row's rid and partition on click or keyboard activation.
	 * @param {number}                                   props.maxBar   Largest bar value across the filtered rows; 0 draws no bar.
	 * @param {string}                                   props.metric   Chart metric; 'memory' bars peak_mb, every other value bars duration_ms.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function RequestRow( { req, onSelect, maxBar, metric } ) {
		const barField = metric === 'memory' ? 'peak_mb' : 'duration_ms';
		const barValue = req[ barField ] || 0;
		const barPct = maxBar > 0 ? ( barValue / maxBar ) * 100 : 0;
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
					{ req.duration_ms?.toFixed( 0 ) || 0 }ms
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
 * and `requestSort` only tells the headers which arrow to draw. The filter is
 * the exception — "Errors Only" is local state and narrows the list, the
 * heading count, and the bar-scaling maximum alike.
 *
 * @param {Object}                                   props                 Component props.
 * @param {Object}                                   props.urlDetail       The fields this view reads off the `url_detail` payload: stats, requests, scan_stopped_early, aggregate_flame, aggregate_profiles, last_modified, and optional category_time_series.
 * @param {Array}                                    props.sortedRequests  Recent requests, already sorted by the parent.
 * @param {Object}                                   props.requestSort     Current sort as `{ field, dir }`; drives the header arrows only.
 * @param {(field: string) => void}                  props.onRequestSort   Receives a field name when a sortable header is clicked.
 * @param {(rid: string, partition: number) => void} props.onSelectRequest Receives a rid AND its partition from a row click or a scatter-plot dot.
 * @param {string}                                   props.urlHash         The URL's 12-char hash, which addresses the `url_breakdown` read below.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function UrlDetailView( {
	urlDetail,
	sortedRequests,
	requestSort,
	onRequestSort,
	onSelectRequest,
	urlHash,
} ) {
	const listRef = useRef( null );
	const [ errorsOnly, setErrorsOnly ] = useState( false );

	// A list the walk cut short reads exactly like a URL with no traffic.
	const scanNote = urlDetail.scan_stopped_early
		? __(
				'The request index scan stopped early, so requests for this URL may be missing.',
				'newspack-event-logger-nodes'
		  )
		: null;

	const filteredRequests = useMemo( () => {
		if ( ! errorsOnly ) {
			return sortedRequests;
		}
		return sortedRequests.filter(
			( r ) => null !== errorStatus( r.error_status )
		);
	}, [ sortedRequests, errorsOnly ] );

	const { startIndex, endIndex, paddingTop, paddingBottom } =
		useVirtualization(
			listRef,
			ROW_HEIGHT,
			filteredRequests.length,
			'.components-modal__content'
		);
	const visibleRequests = filteredRequests.slice( startIndex, endIndex );

	const [ chartMetric, setChartMetric ] = useState( 'volume' );

	// Row bars scale against the filtered rows, not the whole result set.
	const maxBar = useMemo( () => {
		const field = chartMetric === 'memory' ? 'peak_mb' : 'duration_ms';
		return filteredRequests.reduce(
			( max, r ) => Math.max( max, r[ field ] || 0 ),
			0
		);
	}, [ filteredRequests, chartMetric ] );
	const [ chartBreakdown, setChartBreakdown ] = useState( 'status' );
	const [ breakdownData, setBreakdownData ] = useState( null );
	const [ breakdownLoading, setBreakdownLoading ] = useState( false );
	const [ breakdownError, setBreakdownError ] = useState( null );

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
	 * Retried, the newest pick supersedes. Asking `url_detail` for the series
	 * instead would drag a full request-index walk the chart keeps nothing of.
	 */
	const { run: fetchBreakdown } = useCommandOnce( {
		ci: SERVER,
		command: 'url_breakdown',
		scope: `${ SERVER }:url_breakdown`,
		retry: true,
		// Subject is the PAIR, not the hash: a superseded reply fills nothing.
		subjectOf: ( args ) => args.join( ' ' ),
		onDone: ( { result, error } ) => {
			setBreakdownError( error );
			setBreakdownData( result?.breakdown_time_series ?? null );
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
		name: 'urldetail:breakdown',
		onTick: reloadBreakdown,
		intervalMs: BREAKDOWN_REFRESH_MS,
	} );

	/**
	 * A column header that sorts, carrying the arrow for the current sort.
	 *
	 * Sorting is the parent's: this reports the click and reads `requestSort`
	 * to decide which arrow, if any, to draw.
	 *
	 * @param {string} field   Sort field this header stands for.
	 * @param {string} label   Already-translated column label.
	 * @param {string} variant Cell modifier: 'center', 'numeric' or ''.
	 * @return {import('react').ReactElement} Header element.
	 */
	const renderSortHeader = ( field, label, variant = '' ) => (
		<button
			type="button"
			className={ `newspack-nodes-sortable-header-button event-logger-table__header-btn newspack-nodes-table__cell${
				variant ? ` event-logger-table__header-btn--${ variant }` : ''
			}` }
			onClick={ () => onRequestSort( field ) }
		>
			{ label }
			{ requestSort.field === field && (
				<span style={ { marginLeft: '4px' } }>
					{ requestSort.dir === 'desc' ? '\u25BC' : '\u25B2' }
				</span>
			) }
		</button>
	);

	return (
		<>
			{ /* Always mounted: a gate here can strand the operator. */ }
			<BreakdownControls
				breakdownData={ breakdownData }
				metric={ chartMetric }
				setMetric={ setChartMetric }
				breakdown={ chartBreakdown }
				setBreakdown={ setChartBreakdown }
				loading={ breakdownLoading }
				error={ breakdownError }
			/>

			<CategoryTimeChart data={ urlDetail?.category_time_series } />

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
					totalMs={ urlDetail.stats?.avg_ms || 0 }
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
							filteredRequests.length
						) }
					</h3>
					<button
						type="button"
						className={ errorsOnly ? 'button is-active' : 'button' }
						onClick={ () => setErrorsOnly( ! errorsOnly ) }
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

				{ /* Outside listRef, so it is never a virtualized row. */ }
				<div className="event-logger-table__header newspack-nodes-table__header">
					{ renderSortHeader(
						'timestamp',
						__( 'Time', 'newspack-event-logger-nodes' )
					) }
					<div className="event-logger-table__cell newspack-nodes-table__cell">
						{ __( 'Method', 'newspack-event-logger-nodes' ) }
					</div>
					<div className="event-logger-table__cell newspack-nodes-table__cell">
						{ __( 'Request ID', 'newspack-event-logger-nodes' ) }
					</div>
					{ renderSortHeader(
						'status_code',
						__( 'Status', 'newspack-event-logger-nodes' ),
						'center'
					) }
					{ renderSortHeader(
						'duration_ms',
						__( 'Duration', 'newspack-event-logger-nodes' ),
						'numeric'
					) }
					{ renderSortHeader(
						'peak_mb',
						__( 'Mem', 'newspack-event-logger-nodes' ),
						'numeric'
					) }
				</div>

				<div
					ref={ listRef }
					className="event-logger-table__list newspack-nodes-table"
				>
					{ filteredRequests.length === 0 ? (
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
				{ scanNote && filteredRequests.length > 0 && (
					<p className="newspack-nodes-status">{ scanNote }</p>
				) }
			</div>
		</>
	);
}
