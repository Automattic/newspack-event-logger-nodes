/**
 * The breakdown bar block: the Server, Metric, Breakdown and Then by selects
 * and the filter chips. It writes `server`, `chips`, `metric`, `breakdown`
 * and `then`, which the charts, the URL table and the totals read; a new
 * server or chip set starts the URL table on its first page.
 *
 * The Server select stands only where it offers a choice: two servers or
 * more, or an applied filter, which needs a way out. The Breakdown offers
 * `server` only where the page can draw it, and the selects show the
 * dimensions the chart draws, not a choice it fell back from. A Breakdown
 * taking the Then by's value clears the Then by.
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { BreakdownBar } from '../components/BreakdownControls';
import {
	CHART_BREAKDOWN_OPTIONS,
	FILTER_FIELD_OPTIONS,
	SCOPED_BREAKDOWN_OPTIONS,
} from '../constants';
import { useChartQuestion, useFilter, useSite } from './pageQuestions';

/**
 * The Server select's choices: every server the overview names and an
 * applied filter it does not, or none where there is nothing to choose.
 *
 * @param {?string[]} names  The servers, null before the overview answers.
 * @param {string}    server The applied filter; '' for none.
 * @return {?Array<{label: string, value: string}>} The choices.
 */
export function serverChoices( names, server ) {
	const named = names ?? [];
	if ( named.length < 2 && '' === server ) {
		return null;
	}
	const offered =
		'' === server || named.includes( server )
			? named
			: [ ...named, server ];
	return [
		{
			label: __( 'All Servers', 'newspack-event-logger-nodes' ),
			value: '',
		},
		...offered.map( ( name ) => ( { label: name, value: name } ) ),
	];
}

/**
 * @return {import('react').ReactElement} The bar.
 */
function View() {
	const [ server, setServer ] = useFilter( 'server' );
	const [ chips, setChips ] = useFilter( 'chips' );
	const [ metric, setMetric ] = usePageState( 'metric', { writes: true } );
	const [ , writeBreakdown ] = usePageState( 'breakdown', { writes: true } );
	const [ then, setThen ] = usePageState( 'then', { writes: true } );
	// A Breakdown taking the Then by's value leaves it no second dimension.
	const setBreakdown = ( next ) => {
		writeBreakdown( next );
		if ( next === then ) {
			setThen( '' );
		}
	};
	const { servers } = useSite();
	const { params, canBreakDownByServer } = useChartQuestion();
	const serverOptions = useMemo(
		() => serverChoices( servers, server ),
		[ servers, server ]
	);
	return (
		<BreakdownBar
			metric={ metric }
			setMetric={ setMetric }
			breakdown={ params.breakdown }
			setBreakdown={ setBreakdown }
			thenBy={ params.then }
			setThenBy={ setThen }
			breakdownOptions={
				canBreakDownByServer
					? CHART_BREAKDOWN_OPTIONS
					: SCOPED_BREAKDOWN_OPTIONS
			}
			chips={ chips }
			setChips={ setChips }
			chipOptions={ FILTER_FIELD_OPTIONS }
			serverOptions={ serverOptions }
			serverFilter={ server }
			setServerFilter={ setServer }
		/>
	);
}

export default { View };
