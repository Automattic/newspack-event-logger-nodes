/**
 * The URL window's blocks, each the section the window showed, over the
 * component that draws it: the narrowing note, the request chart with its
 * Metric, Breakdown and Then by selects and its chips, the category charts,
 * the response-time scatter, the aggregate flame graph, the flame lookup's
 * note, the averaged time breakdown with its owners, and the Recent
 * Requests list.
 *
 * Each reads the window's subject and its narrowing from page state and its
 * data from the window's sources (`./windowQuestions`), so they share one
 * fetch with the window's header. The chart writes `url_metric`,
 * `url_breakdown`, `url_then` and `url_chips`; a click or a drag on any
 * chart, and the list's Time field, write `url_buckets`, judged against the
 * buckets the request chart read. The chips narrow the chart, the header
 * and the list, the buckets the header and the list alone. The list's order
 * and a row or a scatter dot opening its request go through the page the
 * window stands on, the opening inert while the layout is edited.
 */

import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useEditing, usePage } from '@newspack-nodes/shared/composition/page';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { SCOPED_BREAKDOWN_OPTIONS, SUBJECT_CHIP_OPTIONS } from '../constants';
import { breakdownState } from '../AggregateTimeChart';
import BreakdownControls from '../components/BreakdownControls';
import CategoryTimeChart from '../CategoryTimeChart';
import ResponseTimeChart from '../ResponseTimeChart';
import RequestTrace, { FlameCutNote } from '../components/RequestTrace';
import RequestTable from '../components/RequestTable';
import { ProfileWithOwners } from '../OwnerTable';
import { profileOrder } from '../RequestProfile';
import {
	bucketLabel,
	breakdownSetter,
	chartDims,
	selectionLimit,
	useBucketSelection,
} from '../chartSlots';
import { useUrlCategories, useUrlChart, useUrlDetail } from './windowQuestions';

/** Does nothing, as a row does while its layout is edited. */
const nothing = () => {};

/**
 * The window's bucket selection, with the setter every chart and the list
 * write it through; each judges it against the buckets its chart read.
 *
 * @return {[string, (update: *) => void]} The selection's spelling and its
 *   setter.
 */
const useWindowBuckets = () => usePageState( 'url_buckets', { writes: true } );

/**
 * Whether the window asks its charts yet: the page says so unless a linked
 * request is still being found, so a link that opens straight onto a
 * request asks no chart, and otherwise the charts ask beside the detail.
 *
 * @return {boolean} Whether it does.
 */
const useWindowAsks = () =>
	/** @type {import('../performancePageData').PerformancePageValue} */ (
		usePage()
	).windowAsks;

/**
 * What opens a request from the window: the page's opener, nothing while edited.
 *
 * @return {(rid: string) => void} The opener.
 */
function useSelectRequest() {
	const { selectRequest } =
		/** @type {import('../performancePageData').PerformancePageValue} */ (
			usePage()
		);
	return useEditing() ? nothing : selectRequest;
}

/**
 * The banner over a narrowed window: what its chips and its bucket narrow,
 * and what still describes every request to the URL. No flame graph is
 * drawn from a narrowed list.
 *
 * @return {?import('react').ReactElement} The note, or nothing while nothing is narrowed.
 */
function NarrowingView() {
	const [ bucket ] = useWindowBuckets();
	const { slots } = useUrlChart( useWindowAsks() );
	const [ chips ] = usePageState( 'url_chips' );
	const span = bucket ? bucketLabel( bucket, selectionLimit( slots ) ) : '';
	let note = null;
	if ( chips.length ) {
		note = span
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
	} else if ( span ) {
		note = sprintf(
			// translators: %s: the bucket's local span, e.g. 7:05–7:10 AM.
			__(
				'The charts and the profile describe every request to this URL; the header and the list below hold %s.',
				'newspack-event-logger-nodes'
			),
			span
		);
	}
	return (
		note && (
			<p className="newspack-nodes-banner is-info" role="status">
				{ note }
			</p>
		)
	);
}

/**
 * The request chart, always mounted, so a refusal never strands the
 * selects that would undo it. A Breakdown taking the Then by's value clears
 * the Then by in the same event, before the chart asks again.
 *
 * @return {import('react').ReactElement} The chart and its controls.
 */
function ChartView() {
	const { subject, chart, answer, slots, loading, error } = useUrlChart(
		useWindowAsks()
	);
	const [ metric, setMetric ] = usePageState( 'url_metric', {
		writes: true,
	} );
	const [ , writeBreakdown ] = usePageState( 'url_breakdown', {
		writes: true,
	} );
	const [ , setThen ] = usePageState( 'url_then', { writes: true } );
	const [ , setChips ] = usePageState( 'url_chips', { writes: true } );
	const [ bucket, setBucket ] = useWindowBuckets();
	const [ selected, pick, pickRange ] = useBucketSelection(
		bucket,
		setBucket,
		slots
	);
	const read = useMemo(
		() => breakdownState( answer, chartDims( chart ) ),
		[ answer, chart ]
	);
	const setBreakdown = breakdownSetter(
		writeBreakdown,
		setThen,
		chart.thenBy
	);
	return (
		<BreakdownControls
			breakdownRead={ read }
			slots={ slots }
			storageKey="event-logger-url-detail-chart-aggregate"
			metric={ metric }
			setMetric={ setMetric }
			breakdown={ chart.breakdown }
			setBreakdown={ setBreakdown }
			thenBy={ chart.thenBy }
			setThenBy={ setThen }
			breakdownOptions={ SCOPED_BREAKDOWN_OPTIONS }
			chips={ chart.chips }
			setChips={ setChips }
			chipOptions={ SUBJECT_CHIP_OPTIONS[ subject?.field ?? 'url' ] }
			loading={ loading }
			error={ error }
			onSlotClick={ pick }
			onSlotRange={ pickRange }
			selectedBuckets={ selected }
		/>
	);
}

/**
 * Time, events and time per event by category, every request to the URL.
 *
 * @return {import('react').ReactElement} The charts.
 */
function CategoryView() {
	const asks = useWindowAsks();
	const { answer, slots: own } = useUrlCategories( asks );
	const [ bucket, setBucket ] = useWindowBuckets();
	const { slots } = useUrlChart( asks );
	const [ selected, pick, pickRange ] = useBucketSelection(
		bucket,
		setBucket,
		slots
	);
	return (
		<CategoryTimeChart
			storageKey="event-logger-url-detail-chart-category"
			data={ answer }
			slots={ own }
			onSlotClick={ pick }
			onSlotRange={ pickRange }
			selectedBuckets={ selected }
		/>
	);
}

/**
 * The scatter of the requests the list holds, each dot opening its request.
 *
 * @return {?import('react').ReactElement} The scatter, or nothing for none.
 */
function ResponseTimesView() {
	const { data } = useUrlDetail();
	const select = useSelectRequest();
	return data?.requests?.length > 0 ? (
		<ResponseTimeChart
			requests={ data.requests }
			onRequestClick={ select }
		/>
	) : null;
}

/**
 * The flame graph aggregated across the URL's requests, in the breakdown's
 * category order, so the two share CRT greens.
 *
 * @return {?import('react').ReactElement} The graph, or nothing for none.
 */
function TraceView() {
	const { data } = useUrlDetail();
	const categories = data?.aggregate_profiles?.categories;
	const order = useMemo( () => profileOrder( categories ), [ categories ] );
	return data?.aggregate_flame?.children?.length > 0 ? (
		<div>
			<RequestTrace
				flameData={ data.aggregate_flame }
				order={ order }
				title={ __(
					'Aggregate Flame Graph',
					'newspack-event-logger-nodes'
				) }
			/>
		</div>
	) : null;
}

/**
 * The note standing where a flame lookup that ran out of time left none.
 *
 * @return {?import('react').ReactElement} The note, or nothing.
 */
function FlameCutView() {
	const { data } = useUrlDetail();
	return data?.flame_cut ? <FlameCutNote /> : null;
}

/**
 * The time breakdown averaged across the profiled requests, with the Time
 * By Owner table beneath it.
 *
 * @return {?import('react').ReactElement} The profile, or nothing before an answer.
 */
function ProfileView() {
	const { data } = useUrlDetail();
	return data ? (
		<ProfileWithOwners
			profile={ data.aggregate_profiles }
			totalMs={ data.stats?.avg_ms }
			owners={ data.aggregate_owners }
		/>
	) : null;
}

/**
 * The URL's recent requests, in the order the page holds for them.
 *
 * @return {?import('react').ReactElement} The list, or nothing before an answer.
 */
function RequestsView() {
	const { data, loading, error } = useUrlDetail();
	const { requestSort, sortRequests } =
		/** @type {import('../performancePageData').PerformancePageValue} */ (
			usePage()
		);
	const [ metric ] = usePageState( 'url_metric' );
	const [ bucket, setBucket ] = useWindowBuckets();
	const { slots } = useUrlChart( useWindowAsks() );
	const select = useSelectRequest();
	if ( ! data ) {
		return null;
	}
	return (
		<RequestTable
			requests={ data.requests ?? [] }
			requestSort={ requestSort }
			onRequestSort={ sortRequests }
			onSelect={ select }
			metric={ metric }
			bucket={ bucket }
			slots={ slots }
			onBucketChange={ setBucket }
			listing={ loading }
			listError={ error }
		/>
	);
}

/**
 * Each URL window block, by block name.
 *
 * @type {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>}
 */
export const URL_WINDOW_VIEWS = {
	'newspack-event-logger-nodes/url-narrowing': { View: NarrowingView },
	'newspack-event-logger-nodes/url-chart': { View: ChartView },
	'newspack-event-logger-nodes/url-category-chart': { View: CategoryView },
	'newspack-event-logger-nodes/url-response-times': {
		View: ResponseTimesView,
	},
	'newspack-event-logger-nodes/url-trace': { View: TraceView },
	'newspack-event-logger-nodes/url-flame-cut': { View: FlameCutView },
	'newspack-event-logger-nodes/url-profile': { View: ProfileView },
	'newspack-event-logger-nodes/url-requests': { View: RequestsView },
};
