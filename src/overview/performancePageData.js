/**
 * The Performance page's contexts and their edit-mode entries: the page's
 * own layout and its URL and request windows'. The page stands on one admin
 * screen of its own, so no edit link names a view, and a window's edit
 * names no subject, so the link it is opened from keeps the subject the
 * window stands on and the editor previews it.
 */

/** The context the page lays out. */
export const PERFORMANCE_CONTEXT = 'newspack-event-logger-nodes/performance';

/** The page's slug, which `?edit=` names to edit its layout. */
export const PERFORMANCE_PAGE = 'performance';

/** The context the URL window lays out. */
export const URL_WINDOW_CONTEXT = 'newspack-event-logger-nodes/url-detail';

/** The URL window's name, which `?edit=` names to edit its layout. */
export const URL_WINDOW = 'url-detail';

/** The context the request window lays out. */
export const REQUEST_WINDOW_CONTEXT =
	'newspack-event-logger-nodes/request-detail';

/** The request window's name, which `?edit=` names to edit its layout. */
export const REQUEST_WINDOW = 'request-detail';

/**
 * Each composed layout => the edit-mode entry the substrate's edit links read.
 *
 * @type {import('@newspack-nodes/shared/composition/page/editMode').ComposedPages}
 */
export const PERFORMANCE_PAGES = {
	[ PERFORMANCE_PAGE ]: { view: null, subject: null },
	[ URL_WINDOW ]: { view: null, subject: null },
	[ REQUEST_WINDOW ]: { view: null, subject: null },
};

/**
 * The page a Performance layout's blocks stand on: the composition layer's
 * page value; the window a URL table opens rows in, which this page owns;
 * how the URL window opens one of its requests, and the order its list
 * holds with the sort a heading asks for; the ref the request window's log
 * publishes its `reveal` on for the flame graph; and whether the URL
 * window asks its charts yet.
 *
 * @typedef {import('@newspack-nodes/shared/composition/page/pageContext').ComposedPageValue & {
 *   openSubject: (row: Object) => void,
 *   selectedSubject: ?Object,
 *   busySubject: ?Object,
 *   selectRequest: (rid: ?string) => void,
 *   requestSort: {field: string, dir: string},
 *   sortRequests: (field: string) => void,
 *   revealRef: {current: ?Function},
 *   windowAsks: boolean,
 * }} PerformancePageValue
 */
