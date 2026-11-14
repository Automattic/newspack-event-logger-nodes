/**
 * The aggregate chart block: the site's requests as series by the
 * dimensions the breakdown bar chose, under its server filter and chips. A
 * click or a drag on it writes `buckets`, the selection that narrows the URL
 * table and the totals; the chart resumes a drag a poll's redraw cuts
 * across.
 *
 * It says what its answer is: a Loading pill while a read is out, the
 * refusal, and the empty-dimension line for an answer holding no series, a
 * null answer among them.
 */

import { useMemo } from '@wordpress/element';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { breakdownState } from '../AggregateTimeChart';
import { BreakdownChart, LoadingPill } from '../components/BreakdownControls';
import { answerSlots, chartDims, useBucketSelection } from '../chartSlots';
import {
	CHART_BREAKDOWN_OPTIONS,
	SCOPED_BREAKDOWN_OPTIONS,
} from '../constants';
import { useChartQuestion, useFilter } from './pageQuestions';
import { useSource } from '@newspack-nodes/shared/sources';

/** What a chart says of an answer holding nothing to draw. */
const NOTHING = { state: 'empty', series: null, groups: null };

/**
 * @return {import('react').ReactElement} The chart.
 */
function View() {
	const { params, canBreakDownByServer } = useChartQuestion();
	const [ metric ] = usePageState( 'metric' );
	const [ buckets, setBuckets ] = useFilter( 'buckets' );
	const { model, loading, error } = useSource( 'eln/chart', params );
	const answer = model?.data ?? null;
	const dims = useMemo(
		() => chartDims( { breakdown: params.breakdown, thenBy: params.then } ),
		[ params.breakdown, params.then ]
	);
	const read = useMemo( () => {
		if ( model && null === answer ) {
			return NOTHING;
		}
		return breakdownState( answer, dims );
	}, [ model, answer, dims ] );
	const slots = useMemo( () => answerSlots( answer ), [ answer ] );
	const [ selected, pick, pickRange ] = useBucketSelection(
		buckets,
		setBuckets,
		slots
	);
	return (
		<div className="event-logger-aggregate-chart">
			{ ( loading || ( ! error && 'pending' === read.state ) ) && (
				<LoadingPill />
			) }
			<BreakdownChart
				breakdownRead={ read }
				slots={ slots }
				storageKey="event-logger-overview-chart-aggregate"
				metric={ metric }
				dims={ dims }
				breakdownOptions={
					canBreakDownByServer
						? CHART_BREAKDOWN_OPTIONS
						: SCOPED_BREAKDOWN_OPTIONS
				}
				error={ error }
				serverFilter={ params.server }
				onSlotClick={ pick }
				onSlotRange={ pickRange }
				selectedBuckets={ selected }
			/>
		</div>
	);
}

export default { View };
