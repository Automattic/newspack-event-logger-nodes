/**
 * URL Detail View — the body of the Performance dashboard's URL modal.
 *
 * `PerformanceDashboard` opens a modal for one URL and renders this view inside
 * it. The view owns no slice and asks nothing: it draws the three slices the
 * parent's graph asks each tick — the `dump_url` payload, and the URL's two
 * `query` answers. Top to bottom:
 *
 *   1. Aggregate time chart of the URL's series by its dimensions, with the
 *      Metric, Breakdown and Then by dropdowns and the filter chips that
 *      drive it, the chips narrowing the header and the list too; their
 *      edits go to the parent, whose graph asks afresh.
 *   2. Category time charts — time, count, and average per profile category.
 *   3. Response-time scatter of the individual requests.
 *   4. Aggregate flame graph, drawn by `RequestTrace`, which holds
 *      d3-flame-graph behind `lazy()` so the sections above it paint first.
 *   5. Aggregate profile breakdown, averaged across the profiled requests.
 *   6. Virtualized recent-requests table with the Time field holding the
 *      buckets the list narrows to.
 *
 * Footgun: the requests table virtualizes against the modal's scroll container
 * (`.components-modal__content`). Mounted outside a `Modal`, `useVirtualization`
 * finds no such ancestor and throws — which is why the tests mock that hook.
 */

import { useRef, memo, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	CELL_BASE,
	SCOPED_BREAKDOWN_OPTIONS,
	URL_CHIP_OPTIONS,
	cellClass,
} from '../constants';

import ResponseTimeChart from '../ResponseTimeChart';
import RequestTrace, { FlameCutNote } from './RequestTrace';
import CategoryTimeChart from '../CategoryTimeChart';
import { ProfileWithCaption, profileOrder } from '../RequestProfile';
import BreakdownControls from './BreakdownControls';
import { breakdownState } from '../AggregateTimeChart';
import { errorStatus } from '../../components/errorStatus';
import { wholeMs } from './HeadlineStats';
import SortHeaderButton from './SortHeaderButton';
import BucketField from './BucketField';
import {
	answerSlots,
	bucketLabel,
	chartDims,
	selectionLimit,
	useBucketSelection,
} from '../chartSlots';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';

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
 * The banner over a narrowed modal: what its chips and its bucket narrow, and
 * what still describes every request to the URL. The chips narrow the chart
 * they sit over, the header and the list; the bucket the header and the list
 * alone. No flame graph is drawn from a narrowed list.
 *
 * @param {string}   bucket The bucket selection the list narrows to; '' for none.
 * @param {string[]} chips  The chart's chips, each `field=value`.
 * @param {number}   max    Most buckets the selection may hold: `selectionLimit()` of the slots charted.
 * @return {?string} The note, or null while nothing is narrowed.
 */
const narrowedNote = ( bucket, chips, max ) => {
	const span = bucket ? bucketLabel( bucket, max ) : '';
	if ( chips.length ) {
		return span
			? sprintf(
					// translators: %s: the bucket's local span, e.g. 7:05–7:10 AM.
					__(
						'The filters narrow the chart beneath them, the header and the list, which hold %s; the category charts and the profile describe every request to this URL.',
						'newspack-event-logger-nodes'
					),
					span
			  )
			: __(
					'The filters narrow the chart beneath them, the header and the list; the category charts and the profile describe every request to this URL.',
					'newspack-event-logger-nodes'
			  );
	}
	return span
		? sprintf(
				// translators: %s: the bucket's local span, e.g. 7:05–7:10 AM.
				__(
					'The charts and the profile describe every request to this URL; the header and the list below hold %s.',
					'newspack-event-logger-nodes'
				),
				span
		  )
		: null;
};

/**
 * The URL modal's Recent Requests columns, in order: its header and every
 * row render from this. A `sortable` column's heading is a sort button
 * aligned by `variant`, the rest plain headings. A cell takes its `kind`'s
 * classes (`cellClass()`), the `tone` classes a column adds, the
 * content `render` draws, and the `style` and `dataStatus` a column declares;
 * each reads the row's `barPct` and its `errorStatus()` reading.
 *
 * @type {Array<{field: string, label: string, sortable?: boolean, variant?: string, kind?: string, render: Function, tone?: Function, style?: Function, dataStatus?: Function}>}
 */
const REQUEST_COLUMNS = [
	{
		field: 'timestamp',
		label: __( 'Time', 'newspack-event-logger-nodes' ),
		sortable: true,
		render: ( req ) => new Date( req.timestamp * 1000 ).toLocaleString(),
	},
	{
		field: 'method',
		label: __( 'Method', 'newspack-event-logger-nodes' ),
		render: ( req ) => req.method,
	},
	{
		field: 'rid',
		label: __( 'Request ID', 'newspack-event-logger-nodes' ),
		kind: 'mono',
		render: ( req ) => <code>{ req.rid }</code>,
		style: ( req, { barPct } ) => ( {
			background: `linear-gradient(to right, rgba(100, 181, 246, 0.15) ${ barPct }%, transparent ${ barPct }%)`,
		} ),
	},
	{
		field: 'status_code',
		label: __( 'Status', 'newspack-event-logger-nodes' ),
		sortable: true,
		variant: 'center',
		kind: 'status',
		render: ( req, { status } ) =>
			status ? (
				<span title={ status.label }>{ req.error_status }</span>
			) : (
				req.status_code || '-'
			),
		tone: ( req, { status } ) =>
			status ? `newspack-nodes-status ${ status.tone }` : null,
		dataStatus: ( req, { status } ) =>
			status ? undefined : req.status_code,
	},
	{
		field: 'duration_ms',
		label: __( 'Duration', 'newspack-event-logger-nodes' ),
		sortable: true,
		variant: 'numeric',
		kind: 'numeric',
		render: ( req ) =>
			'number' === typeof req.duration_ms
				? wholeMs( req.duration_ms )
				: '—',
	},
	{
		field: 'peak_mb',
		label: __( 'Mem', 'newspack-event-logger-nodes' ),
		sortable: true,
		variant: 'numeric',
		kind: 'numeric',
		render: ( req ) => ( req.peak_mb > 0 ? `${ req.peak_mb }MB` : '-' ),
	},
];

/** The fields a request header sorts on, and so the `?url_sort=` whitelist. */
export const REQUEST_SORT_FIELDS = REQUEST_COLUMNS.filter(
	( col ) => col.sortable
).map( ( col ) => col.field );

/** The URL modal's request order until a header is clicked: newest first. */
export const DEFAULT_REQUEST_SORT = { field: 'timestamp', dir: 'desc' };

// JSDoc rides the inner function: on the const, memo() infers props as `{}`.
const RequestRow = memo(
	/**
	 * One row of the recent-requests table, memoized so scrolling re-renders
	 * only the rows that entered the window.
	 *
	 * The row is a button: click or Enter/Space hands its rid to
	 * `onSelect`. It also carries the `?` picker's `request:` descriptor as
	 * `data-ask`, so a picker click on a row asks about that request rather
	 * than about the URL the modal is open on. Its request-id cell carries a
	 * bar background whose width is the row's value as a fraction of `maxBar`,
	 * and its status cell reads `error_status` through `errorStatus()`, falling
	 * back to the HTTP status code for a request that ended nominally. A
	 * duration nobody measured shows as a dash and bars nothing.
	 *
	 * @param {Object}                props          Component props.
	 * @param {Object}                props.req      Request row: rid, timestamp, method (`Other` for an undeclared one), status_code, error_status, duration_ms, peak_mb.
	 * @param {(rid: string) => void} props.onSelect Receives the row's rid on click or keyboard activation.
	 * @param {number}                props.maxBar   Largest bar value across the filtered rows; 0 draws no bar.
	 * @param {string}                props.metric   Chart metric; 'memory' bars peak_mb, every other value bars duration_ms.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function RequestRow( { req, onSelect, maxBar, metric } ) {
		const row = {
			barPct: maxBar > 0 ? ( barValue( req, metric ) / maxBar ) * 100 : 0,
			status: errorStatus( req.error_status ),
		};
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				onSelect( req.rid );
			}
		};

		return (
			<div
				role="button"
				tabIndex={ 0 }
				data-ask={ `request:${ req.rid }` }
				className="event-logger-table__row newspack-nodes-table__row"
				style={ { height: ROW_HEIGHT } }
				onClick={ () => onSelect( req.rid ) }
				onKeyDown={ handleKeyDown }
			>
				{ REQUEST_COLUMNS.map( ( col ) => (
					<div
						key={ col.field }
						className={ [ cellClass( col ), col.tone?.( req, row ) ]
							.filter( Boolean )
							.join( ' ' ) }
						style={ col.style?.( req, row ) }
						data-status={ col.dataStatus?.( req, row ) }
					>
						{ col.render( req, row ) }
					</div>
				) ) }
			</div>
		);
	}
);

/**
 * URL Detail View component.
 *
 * Sorting lives upstream: the parent sorts and hands back `sortedRequests`,
 * and `requestSort` only tells the headers which arrow to draw. The chart's
 * question lives upstream too, its dimensions and its chips, and the server
 * answers the chips over the header and the list as well as the chart, so
 * `Status Class = 5xx` lists the URL's 5xx responses past the clean ones
 * that bury them. The bucket selection the charts and the Time field edit
 * lives upstream on the same terms, and the server answers it the same way.
 * So does the Metric, which only redraws what the chart already holds.
 *
 * @param {Object}                                               props                   Component props.
 * @param {Object}                                               props.urlDetail         The fields this view reads off the `dump_url` payload: stats, requests, aggregate_flame, flame_cut and aggregate_profiles.
 * @param {Array}                                                props.sortedRequests    Recent requests, already sorted by the parent.
 * @param {Object}                                               props.requestSort       Current sort as `{ field, dir }`; drives the header arrows only.
 * @param {(field: string) => void}                              props.onRequestSort     Receives a field name when a sortable header is clicked.
 * @param {(rid: string) => void}                                props.onSelectRequest   Receives a rid from a row click or a scatter-plot dot.
 * @param {string}                                               props.urlHash           The URL's 12-char hash, which the `?` picker asks about.
 * @param {{bucket: string}}                                     props.filters           The bucket selection's spelling the header and list narrow to, '' for none. The dashboard owns it, so it survives a trip to a request and back.
 * @param {(update: *) => void}                                  props.onBucketChange    Receives the bucket selection's new spelling, or an updater of the one held: the Time field's selection, or a chart click's updater.
 * @param {boolean}                                              [props.listing]         Whether the list is being asked afresh under new filters; the rows it holds answer the old ones, so the list alone shows it loading.
 * @param {?string}                                              [props.listError]       Why the last ask for the list was refused, or null; the list keeps what it held.
 * @param {?Object}                                              props.chartSlice        The `url-chart:view` slice: its `query` answer, `loading` and `error`.
 * @param {?Object}                                              props.categoriesSlice   The `url-categories:view` slice, likewise.
 * @param {{breakdown: string, thenBy: string, chips: string[]}} props.chart             The chart's question: its dimensions and chips, the chips narrowing the header and list too.
 * @param {(breakdown: string) => void}                          props.onBreakdownChange Receives the Breakdown select's new value.
 * @param {(thenBy: string) => void}                             props.onThenByChange    Receives the Then by select's new value.
 * @param {(chips: string[]) => void}                            props.onChipsChange     Receives the chips an addition or a removal leaves.
 * @param {string}                                               props.metric            The chart's metric, a `CHART_METRIC_OPTIONS` value; `memory` also bars each row by its peak memory.
 * @param {(metric: string) => void}                             props.onMetricChange    Receives the Metric select's new value.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function UrlDetailView( {
	urlDetail,
	sortedRequests,
	requestSort,
	onRequestSort,
	onSelectRequest,
	urlHash,
	filters,
	onBucketChange,
	listing = false,
	listError = null,
	chartSlice,
	categoriesSlice,
	chart,
	onBreakdownChange,
	onThenByChange,
	onChipsChange,
	metric,
	onMetricChange,
} ) {
	const listRef = useRef( null );

	const { startIndex, endIndex, paddingTop, paddingBottom } =
		useVirtualization(
			listRef,
			ROW_HEIGHT,
			sortedRequests.length,
			'.components-modal__content'
		);
	const visibleRequests = sortedRequests.slice( startIndex, endIndex );

	const maxBar = useMemo(
		() =>
			sortedRequests.reduce(
				( max, r ) => Math.max( max, barValue( r, metric ) ),
				0
			),
		[ sortedRequests, metric ]
	);
	const chartAnswer = chartSlice?.data ?? null;
	const categoryAnswer = categoriesSlice?.data ?? null;
	// Once per answer: the modal re-renders on every scroll event.
	const breakdownRead = useMemo(
		() => breakdownState( chartAnswer, chartDims( chart ) ),
		[ chartAnswer, chart ]
	);
	const axis = useMemo( () => answerSlots( chartAnswer ), [ chartAnswer ] );
	const note = narrowedNote(
		filters.bucket,
		chart.chips,
		selectionLimit( axis )
	);
	const [ selectedBuckets, pickBucket, pickRange ] = useBucketSelection(
		filters.bucket,
		onBucketChange,
		axis
	);
	const categoryAxis = useMemo(
		() => answerSlots( categoryAnswer ),
		[ categoryAnswer ]
	);
	// Sorted once a reply: a scroll re-renders the modal.
	const categories = urlDetail.aggregate_profiles?.categories;
	const flameOrder = useMemo(
		() => profileOrder( categories ),
		[ categories ]
	);

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
			{ note && (
				<p className="newspack-nodes-banner is-info" role="status">
					{ note }
				</p>
			) }
			{ /* Always mounted: a gate here can strand the operator. */ }
			<BreakdownControls
				breakdownRead={ breakdownRead }
				slots={ axis }
				storageKey="event-logger-url-detail-chart-aggregate"
				metric={ metric }
				setMetric={ onMetricChange }
				breakdown={ chart.breakdown }
				setBreakdown={ onBreakdownChange }
				thenBy={ chart.thenBy }
				setThenBy={ onThenByChange }
				breakdownOptions={ SCOPED_BREAKDOWN_OPTIONS }
				chips={ chart.chips }
				setChips={ onChipsChange }
				chipOptions={ URL_CHIP_OPTIONS }
				loading={ !! chartSlice?.loading }
				error={ chartSlice?.error ?? null }
				onSlotClick={ pickBucket }
				onSlotRange={ pickRange }
				selectedBuckets={ selectedBuckets }
			/>

			<CategoryTimeChart
				storageKey="event-logger-url-detail-chart-category"
				data={ categoryAnswer }
				slots={ categoryAxis }
				onSlotClick={ pickBucket }
				onSlotRange={ pickRange }
				selectedBuckets={ selectedBuckets }
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
							order={ flameOrder }
							title={ __(
								'Aggregate Flame Graph',
								'newspack-event-logger-nodes'
							) }
						/>
					</div>
				) }

			{ urlDetail.flame_cut && <FlameCutNote /> }

			{ urlDetail.aggregate_profiles?.count > 0 && (
				<ProfileWithCaption
					profiles={ urlDetail.aggregate_profiles.categories }
					totalMs={ urlDetail.stats?.avg_ms }
					totalProfiledTime={
						urlDetail.aggregate_profiles.total_time
					}
					count={ urlDetail.aggregate_profiles.count }
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
						{ listing
							? __(
									'Recent Requests',
									'newspack-event-logger-nodes'
							  )
							: sprintf(
									// translators: %d: number of recent requests shown.
									__(
										'Recent Requests (%d)',
										'newspack-event-logger-nodes'
									),
									sortedRequests.length
							  ) }
					</h3>
					<BucketField
						value={ filters.bucket }
						slots={ axis }
						onChange={ onBucketChange }
					/>
				</div>
				{ listError && (
					<p className="newspack-nodes-status is-error">
						{ sprintf(
							// translators: %s: the error message.
							__(
								'Could not list requests: %s',
								'newspack-event-logger-nodes'
							),
							listError
						) }
					</p>
				) }

				{ /* Outside listRef, so it is never a virtualized row. */ }
				<div className="event-logger-table__header newspack-nodes-table__header">
					{ REQUEST_COLUMNS.map( ( col ) =>
						col.sortable ? (
							<SortHeaderButton
								key={ col.field }
								field={ col.field }
								label={ col.label }
								dir={ sortDir( col.field ) }
								variant={ col.variant }
								onSort={ onRequestSort }
							/>
						) : (
							<div key={ col.field } className={ CELL_BASE }>
								{ col.label }
							</div>
						)
					) }
				</div>

				<div
					ref={ listRef }
					className="event-logger-table__list newspack-nodes-table"
				>
					{ listing || sortedRequests.length === 0 ? (
						<div className="event-logger-table__empty newspack-nodes-empty-state">
							{ listing
								? __(
										'Loading requests…',
										'newspack-event-logger-nodes'
								  )
								: __(
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
									metric={ metric }
								/>
							) ) }
							<div style={ { height: paddingBottom } } />
						</>
					) }
				</div>
			</div>
		</div>
	);
}
