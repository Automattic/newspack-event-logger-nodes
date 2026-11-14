/**
 * The category chart block: time, events and time per event by category,
 * under the page's server filter. A click or a drag on any of its charts
 * writes `buckets`, as the aggregate chart's does. An answer holding no
 * category says so.
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import CategoryTimeChart from '../CategoryTimeChart';
import {
	answerSlots,
	decodeRows,
	hasRows,
	useBucketSelection,
} from '../chartSlots';
import { CATEGORY_MEASURES } from '../constants';
import { useSource } from '@newspack-nodes/shared/sources';
import { useFilter } from './pageQuestions';

/**
 * @return {import('react').ReactElement} The charts.
 */
function View() {
	const [ server ] = usePageState( 'server' );
	const [ buckets, setBuckets ] = useFilter( 'buckets' );
	const { model, error } = useSource( 'eln/categories', { server } );
	const answer = model?.data ?? null;
	const slots = useMemo( () => answerSlots( answer ), [ answer ] );
	const [ selected, pick, pickRange ] = useBucketSelection(
		buckets,
		setBuckets,
		slots
	);
	const empty = useMemo(
		() =>
			! answer ||
			! hasRows(
				decodeRows( answer, [ 'category' ], CATEGORY_MEASURES )
			),
		[ answer ]
	);
	if ( error ) {
		return <p className="newspack-nodes-status is-error">{ error }</p>;
	}
	if ( ! model ) {
		return <Skeleton shape="line" count={ 1 } />;
	}
	if ( empty ) {
		return (
			<p className="newspack-nodes-status">
				{ __(
					'No category data in this window.',
					'newspack-event-logger-nodes'
				) }
			</p>
		);
	}
	return (
		<div>
			<CategoryTimeChart
				storageKey="event-logger-overview-chart-category"
				data={ answer }
				slots={ slots }
				onSlotClick={ pick }
				onSlotRange={ pickRange }
				selectedBuckets={ selected }
			/>
		</div>
	);
}

export default { View };
