/**
 * The URL table block: the pages the filters left, busiest first, under the
 * heading "Pages by Request Count". It reads the `eln/urls` key the headline
 * stats read, so one fetch serves both, and writes the table's own controls,
 * `sort`, `order`, `q`, `workers` and `paged`, each a new set from its first
 * page but the pager itself; its Time field writes `buckets`. Which pages
 * it expands it keeps as `?expand=`.
 *
 * A row opens its window through the page it stands on, which owns the URL
 * and request window, so a link opens that window on a layout without this
 * block, and the block shows which row the window is open on.
 */

import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usePage } from '@newspack-nodes/shared/composition/page';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { useQueryParamList } from '@newspack-nodes/shared/hooks/useQueryParamState';
import UrlTable from '../UrlTable';
import { NO_PAGE_KEYS, PAGE_KEY } from '../constants';
import { serverName } from '../../log-table/urlIdentity';
import { useChartSlots, useFilter, useUrlsParams } from './pageQuestions';
import { useSource } from '@newspack-nodes/shared/sources';

/**
 * The linked `?expand=` values that name a page, each once, its server
 * spelled as the list names it.
 *
 * @param {string[]} raw Every value the param holds.
 * @return {string[]} The page keys kept, or `NO_PAGE_KEYS` for none.
 */
const restoreExpanded = ( raw ) => {
	const kept = [
		...new Set(
			raw
				.filter( ( key ) => PAGE_KEY.test( key ) )
				.map( ( key ) => {
					const at = key.indexOf( ':' ) + 1;
					return key.slice( 0, at ) + serverName( key.slice( at ) );
				} )
		),
	];
	return kept.length ? kept : NO_PAGE_KEYS;
};

/** No rows, one array, so an unanswered table never re-lays out. */
const NO_ROWS = [];

/**
 * The list as it stands.
 *
 * @param {string[]} keys The page keys.
 * @return {string[]} They.
 */
const asWritten = ( keys ) => keys;

/**
 * The table's question and the setter its controls write it through: each
 * control a new set from its first page, the pager alone keeping its page.
 *
 * @return {[Object, (patch: Object) => void]} The `eln/urls` params and the setter.
 */
export function useUrlTableQuestion() {
	const question = useUrlsParams();
	const [ , setSort ] = usePageState( 'sort', { writes: true } );
	const [ , setOrder ] = usePageState( 'order', { writes: true } );
	const [ , setQ ] = usePageState( 'q', { writes: true } );
	const [ , setWorkers ] = usePageState( 'workers', { writes: true } );
	const [ , setPaged ] = usePageState( 'paged', { writes: true } );
	const onQuestion = useCallback(
		( patch ) => {
			const setters = {
				sort: setSort,
				order: setOrder,
				q: setQ,
				workers: setWorkers,
				paged: setPaged,
			};
			for ( const [ key, value ] of Object.entries( patch ) ) {
				setters[ key ]( value );
			}
			if ( ! Object.hasOwn( patch, 'paged' ) ) {
				setPaged( 1 );
			}
		},
		[ setSort, setOrder, setQ, setWorkers, setPaged ]
	);
	return [ question, onQuestion ];
}

/**
 * @return {import('react').ReactElement} The table.
 */
function View() {
	const page =
		/** @type {import('../performancePageData').PerformancePageValue} */ (
			usePage()
		);
	const [ question, onQuestion ] = useUrlTableQuestion();
	const [ metric ] = usePageState( 'metric' );
	const [ , setBuckets ] = useFilter( 'buckets' );
	const slots = useChartSlots();
	const { model, loading, error } = useSource( 'eln/urls', question );
	const [ expanded, setExpanded ] = useQueryParamList(
		'expand',
		restoreExpanded,
		asWritten
	);
	const toggleExpanded = useCallback(
		( key ) =>
			setExpanded( ( keys ) =>
				keys.includes( key )
					? keys.filter( ( other ) => other !== key )
					: [ ...keys, key ]
			),
		[ setExpanded ]
	);
	return (
		<div className="event-logger-performance-urls">
			<div className="newspack-nodes-card event-logger-card">
				<div className="event-logger-card__header">
					<h2 className="newspack-nodes-section-heading">
						{ __(
							'Pages by Request Count',
							'newspack-event-logger-nodes'
						) }
					</h2>
				</div>
				<div className="event-logger-card__body">
					<UrlTable
						urls={ model?.data ?? NO_ROWS }
						selectedSubject={ page.selectedSubject ?? null }
						onSelect={ page.openSubject }
						expanded={ expanded }
						onToggleExpand={ toggleExpanded }
						busy={ page.busySubject ?? null }
						question={ question }
						onQuestion={ onQuestion }
						slots={ slots }
						onBucketChange={ setBuckets }
						totalRows={ model ? model.rows : null }
						metric={ metric }
						error={ error }
						loading={ loading }
					/>
				</div>
			</div>
		</div>
	);
}

export default { View };
