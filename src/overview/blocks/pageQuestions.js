/**
 * The questions the Performance page's blocks and frame ask in common, each
 * read from page state so that blocks asking one question share one fetch:
 * the site's servers, the URL table's page, and the aggregate chart's
 * dimensions as the page can draw them; and the filter setter that starts
 * the URL table's new set on its first page.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { useSource } from '@newspack-nodes/shared/sources';
import { activeChart, answerSlots } from '../chartSlots';

/**
 * A filter's value and its setter, which starts the URL table's new set on
 * its first page in the same write.
 *
 * @param {string} key `server`, `chips` or `buckets`.
 * @return {[*, (next: *) => void]} The value and its setter.
 */
export function useFilter( key ) {
	const [ value, set ] = usePageState( key, { writes: true } );
	const [ , setPaged ] = usePageState( 'paged', { writes: true } );
	const narrow = useCallback(
		( next ) => {
			set( next );
			setPaged( 1 );
		},
		[ set, setPaged ]
	);
	return [ value, narrow ];
}

/**
 * The servers the overview names, by name, and the overview's answer; null
 * servers until it answers, which is neither one server nor several.
 *
 * @return {{servers: ?string[], overview: ?Object, loading: boolean, error: ?string}} The site.
 */
export function useSite() {
	const [ server ] = usePageState( 'server' );
	const { model, loading, error } = useSource( 'eln/overview', { server } );
	const overview = model?.data ?? null;
	const answered = overview?.servers;
	const servers = useMemo(
		() => ( answered ? [ ...answered ].sort() : null ),
		[ answered ]
	);
	return { servers, overview, loading, error };
}

/**
 * The URL table's question, as page state holds it, the one `eln/urls` key
 * the table and the headline stats read.
 *
 * @return {Object} Its params.
 */
export function useUrlsParams() {
	const [ server ] = usePageState( 'server' );
	const [ chips ] = usePageState( 'chips' );
	const [ buckets ] = usePageState( 'buckets' );
	const [ sort ] = usePageState( 'sort' );
	const [ order ] = usePageState( 'order' );
	const [ q ] = usePageState( 'q' );
	const [ workers ] = usePageState( 'workers' );
	const [ paged ] = usePageState( 'paged' );
	return useMemo(
		() => ( { server, chips, buckets, sort, order, q, workers, paged } ),
		[ server, chips, buckets, sort, order, q, workers, paged ]
	);
}

/**
 * The aggregate chart's question: the dimensions page state chose, falling
 * back where the page cannot draw `server` and dropping a Then by that
 * repeats the Breakdown, the server filter and the chips.
 *
 * @return {{params: Object, canBreakDownByServer: boolean}} The `eln/chart`
 *   params, and whether `server` is a dimension it can draw.
 */
export function useChartQuestion() {
	const [ server ] = usePageState( 'server' );
	const [ chips ] = usePageState( 'chips' );
	const [ breakdown ] = usePageState( 'breakdown' );
	const [ then ] = usePageState( 'then' );
	const { servers } = useSite();
	const active = activeChart( {
		breakdown,
		thenBy: then,
		serverFilter: server,
		serverNames: servers,
	} );
	// A Then by repeating the Breakdown does not add a dimension.
	const thenBy = active.thenBy === active.breakdown ? '' : active.thenBy;
	const params = useMemo(
		() => ( {
			server,
			chips,
			breakdown: active.breakdown,
			then: thenBy,
		} ),
		[ server, chips, active.breakdown, thenBy ]
	);
	return { params, canBreakDownByServer: active.canBreakDownByServer };
}

/**
 * The bucket keys the aggregate chart's answer read, newest first, which a
 * bucket selection is held to; null before it answers.
 *
 * @return {?string[]} The keys.
 */
export function useChartSlots() {
	const { params } = useChartQuestion();
	const answer = useSource( 'eln/chart', params ).model?.data ?? null;
	return useMemo( () => answerSlots( answer ), [ answer ] );
}
