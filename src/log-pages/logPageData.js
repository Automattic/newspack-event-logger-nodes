/**
 * The log pages: each one's context, its shell's words and the group its
 * layout's commands go under, and the page data its load sets as
 * `NewspackEventLoggerLogPage` (`Log_Pages::page_data()`). Each stands on one
 * admin screen of its own, so its edit link names no view.
 */

import { __ } from '@wordpress/i18n';
import { GROUPS } from '../overview/constants';

/**
 * One log page.
 *
 * @typedef  {Object} LogPageEntry
 * @property {string} context    The context it lays out.
 * @property {string} subtitle   Its shell's heading.
 * @property {string} storageKey Its debug overlay's own layout.
 * @property {string} group      The surface its layout's commands go under.
 */

/**
 * Each page, as `?edit=` names it.
 *
 * @type {Object<string, LogPageEntry>}
 */
export const LOG_PAGES = {
	'request-log': {
		context: 'newspack-event-logger-nodes/request-log',
		subtitle: __( 'Request Log', 'newspack-event-logger-nodes' ),
		storageKey: 'newspack-nodes:debug:request-stream',
		group: GROUPS.requestLog,
	},
	'error-log': {
		context: 'newspack-event-logger-nodes/error-log',
		subtitle: __( 'Error Log', 'newspack-event-logger-nodes' ),
		storageKey: 'newspack-nodes:debug:error-log',
		group: GROUPS.errorLog,
	},
	gyroscope: {
		context: 'newspack-event-logger-nodes/gyroscope',
		subtitle: __( 'Gyroscope', 'newspack-event-logger-nodes' ),
		storageKey: 'newspack-nodes:debug:gyroscope',
		group: GROUPS.gyroscope,
	},
};

/**
 * The page data a log page's load set, as `types/globals.d.ts` declares it.
 *
 * @return {NonNullable<Window['NewspackEventLoggerLogPage']>} It.
 * @throws {Error} On a page that set none.
 */
export function logPage() {
	const data = window.NewspackEventLoggerLogPage;
	if ( ! data ) {
		throw new Error(
			'NewspackEventLoggerLogPage is not set: Log_Pages::enqueue() sets it before the log pages bundle'
		);
	}
	return data;
}
