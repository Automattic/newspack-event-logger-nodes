/* eslint-env jest */
/**
 * Set the page data a composed page's load sets, each of its contexts
 * reading the default layout as `tests/fixtures/` holds it, which PHPUnit
 * holds to `Composition::validate()`, or the blocks a test lays out.
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

/** Each page's layouts beside its own: the Performance page's windows. */
const WINDOWS = { performance: [ 'url-detail', 'request-detail' ] };

/**
 * A layout as `Composition_Store::read()` answers it.
 *
 * @param {string}   context The context.
 * @param {Object[]} blocks  Its tree.
 * @return {Object} The layout.
 */
const layoutOf = ( context, blocks ) => ( {
	context,
	source: 'default',
	blocks,
	saved_ts: null,
	actor: null,
} );

/**
 * Localize one page's data for a reader.
 *
 * @param {NonNullable<Window['NewspackEventLoggerComposedPage']>['page']} page               The page, as `?edit=` names it.
 * @param {Object}                                                         [reader]           Who reads the page, and what it lays out.
 * @param {boolean}                                                        [reader.canTune]   Whether they may curate.
 * @param {boolean}                                                        [reader.canManage] Whether they are an operator.
 * @param {Object[]}                                                       [reader.blocks]    The page's own layout's tree; the default's unless named.
 * @param {Object<string,Object[]>}                                        [reader.layouts]   A window's tree by its layout; the default's unless named.
 * @param {number|{error: string}}                                         [reader.window]    The Performance page's stats window, 24 buckets unless named.
 */
export function localizeComposedPage(
	page,
	{
		canTune = false,
		canManage = false,
		blocks,
		layouts = {},
		window: buckets,
	} = {}
) {
	const context = contextOf( page );
	const windows = ( WINDOWS[ page ] ?? [] ).map( ( name ) => [
		contextOf( name ),
		layoutOf(
			contextOf( name ),
			layouts[ name ] ?? LAYOUTS[ contextOf( name ) ]
		),
	] );
	window.NewspackEventLoggerComposedPage = {
		page,
		canTune,
		canManage,
		compositions: {
			[ context ]: layoutOf( context, blocks ?? LAYOUTS[ context ] ),
			...Object.fromEntries( windows ),
		},
		...( 'performance' === page ? { window: buckets ?? 24 } : {} ),
	};
}
