/* eslint-env jest */
/**
 * Set the page data a Performance load sets, its context reading the default
 * layout as `tests/fixtures/performance-layout.json` holds it, which PHPUnit
 * holds to `Composition::validate()`, or the blocks a test lays out.
 */

import LAYOUTS from '../../tests/fixtures/performance-layout.json';

/** The Performance page's context. */
const CONTEXT = 'newspack-event-logger-nodes/performance';

/** The default layout's tree. */
export const DEFAULT_BLOCKS = LAYOUTS[ CONTEXT ];

/**
 * Localize the Performance page's data for a reader.
 *
 * @param {Object}                 [reader]           Who reads the page, and what it lays out.
 * @param {boolean}                [reader.canTune]   Whether they may curate.
 * @param {boolean}                [reader.canManage] Whether they are an operator.
 * @param {Object[]}               [reader.blocks]    The layout's tree; the default's unless named.
 * @param {number|{error: string}} [reader.window]    The stats window in buckets, or its refusal.
 */
export function localizePerformancePage( {
	canTune = false,
	canManage = false,
	blocks = DEFAULT_BLOCKS,
	window: buckets = 24,
} = {} ) {
	window.NewspackEventLoggerPerformancePage = {
		canTune,
		canManage,
		window: buckets,
		compositions: {
			[ CONTEXT ]: {
				context: CONTEXT,
				source: 'default',
				blocks,
				saved_ts: null,
				actor: null,
			},
		},
	};
}
