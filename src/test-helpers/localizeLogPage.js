/* eslint-env jest */
/**
 * Set the page data a log page's load sets, its context reading the default
 * layout as `tests/fixtures/log-page-layouts.json` holds it, which PHPUnit
 * holds to `Composition::validate()`, or the blocks a test lays out.
 */

import LAYOUTS from '../../tests/fixtures/log-page-layouts.json';
import { LOG_PAGES } from '../log-pages/logPageData';

/**
 * Localize one log page's data for a reader.
 *
 * @param {NonNullable<Window['NewspackEventLoggerLogPage']>['page']} page               The page, as `?edit=` names it.
 * @param {Object}                                                    [reader]           Who reads the page, and what it lays out.
 * @param {boolean}                                                   [reader.canTune]   Whether they may curate.
 * @param {boolean}                                                   [reader.canManage] Whether they are an operator.
 * @param {Object[]}                                                  [reader.blocks]    The layout's tree; the default's unless named.
 */
export function localizeLogPage(
	page,
	{ canTune = false, canManage = false, blocks } = {}
) {
	const { context } = LOG_PAGES[ page ];
	window.NewspackEventLoggerLogPage = {
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
	};
}
