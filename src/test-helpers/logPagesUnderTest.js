/**
 * The three log pages the parity test mounts, each whole in its dashboard
 * shell on its default layout, and the view each page's stream fills.
 */

import { LogPageShell } from '../log-pages';
import { localizeLogPage } from './localizeLogPage';
// The lazy page's modules load with this one, before any test runs.
import '../log-pages/LogPage';

export { RequestLogViewNode } from '../log-pages/nodes/request-log-view-node';
export { PerfErrorsViewNode } from '../log-pages/nodes/perf-errors-view-node';
export { GyroscopeViewNode } from '../log-pages/nodes/gyroscope-view-node';

/**
 * A page's shell on its default layout.
 *
 * @param {NonNullable<Window['NewspackEventLoggerLogPage']>['page']} page The page.
 * @return {import('react').ReactElement} It.
 */
const onDefault = ( page ) => {
	localizeLogPage( page );
	return <LogPageShell page={ page } />;
};

/** Each page by its key. */
export const LOG_PAGES = {
	'request-log': () => onDefault( 'request-log' ),
	'error-log': () => onDefault( 'error-log' ),
	gyroscope: () => onDefault( 'gyroscope' ),
};
