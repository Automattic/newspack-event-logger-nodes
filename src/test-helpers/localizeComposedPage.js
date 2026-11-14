/* eslint-env jest */
/**
 * Set the page data a composed page's load sets, its context reading the
 * default layout as `tests/fixtures/` holds it, which PHPUnit holds to
 * `Composition::validate()`, or the blocks a test lays out.
 */

import PERFORMANCE_LAYOUT from '../../tests/fixtures/performance-layout.json';
import LOG_PAGE_LAYOUTS from '../../tests/fixtures/log-page-layouts.json';

/** Each page's default tree, by its context. */
const LAYOUTS = { ...PERFORMANCE_LAYOUT, ...LOG_PAGE_LAYOUTS };

/**
 * A page's context, as `Compositions` names it.
 *
 * @param {string} page The page, as `?edit=` names it.
 * @return {string} Its context.
 */
export const contextOf = ( page ) => `newspack-event-logger-nodes/${ page }`;

/** The Performance page's default tree. */
export const PERFORMANCE_BLOCKS = LAYOUTS[ contextOf( 'performance' ) ];

/**
 * Localize one page's data for a reader.
 *
 * @param {NonNullable<Window['NewspackEventLoggerComposedPage']>['page']} page               The page, as `?edit=` names it.
 * @param {Object}                                                         [reader]           Who reads the page, and what it lays out.
 * @param {boolean}                                                        [reader.canTune]   Whether they may curate.
 * @param {boolean}                                                        [reader.canManage] Whether they are an operator.
 * @param {Object[]}                                                       [reader.blocks]    The layout's tree; the default's unless named.
 * @param {number|{error: string}}                                         [reader.window]    The Performance page's stats window, 24 buckets unless named.
 */
export function localizeComposedPage(
	page,
	{ canTune = false, canManage = false, blocks, window: buckets } = {}
) {
	const context = contextOf( page );
	window.NewspackEventLoggerComposedPage = {
		page,
		canTune,
		canManage,
		compositions: {
			[ context ]: {
				context,
				source: 'default',
				blocks: blocks ?? LAYOUTS[ context ],
				saved_ts: null,
				actor: null,
			},
		},
		...( 'performance' === page ? { window: buckets ?? 24 } : {} ),
	};
}
