/**
 * The log pages: each one's context, its shell's words, the group its
 * layout's commands go under, and what the Gyroscope stands beside its
 * blocks: its legend in the page header, and its cadence picker in its
 * stream's toolbar. Each stands on one admin screen of its own, so its edit
 * link names no view.
 */

import { __ } from '@wordpress/i18n';
import { GROUPS } from '../overview/constants';
import { CadencePicker, GyroscopeLegend } from './GyroscopeControls';

/**
 * One log page.
 *
 * @typedef  {Object}                       LogPageEntry
 * @property {string}                       context    The context it lays out.
 * @property {string}                       subtitle   Its shell's heading.
 * @property {string}                       storageKey Its debug overlay's own layout.
 * @property {string}                       group      The surface its layout's commands go under.
 * @property {import('react').ReactElement} [controls] What its page header holds beside the frame's own.
 * @property {Object}                       [standing] What its blocks stand on beside the frame's own.
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
		controls: <GyroscopeLegend />,
		standing: {
			sourceControls: { 'eln/gyroscope': <CadencePicker /> },
		},
	},
};
