/**
 * The Performance page's context and its edit-mode entry. The page stands on
 * one admin screen of its own, so its edit link names no view.
 */

/** The context the page lays out. */
export const PERFORMANCE_CONTEXT = 'newspack-event-logger-nodes/performance';

/** The page's slug, which `?edit=` names to edit its layout. */
export const PERFORMANCE_PAGE = 'performance';

/**
 * The composed page => the edit-mode entry the substrate's edit links read.
 *
 * @type {import('@newspack-nodes/shared/composition/page/editMode').ComposedPages}
 */
export const PERFORMANCE_PAGES = {
	[ PERFORMANCE_PAGE ]: { view: null, subject: null },
};

/**
 * The page a Performance layout's blocks stand on: the composition layer's
 * page value, and the window a URL table opens rows in, which this page owns.
 *
 * @typedef {import('@newspack-nodes/shared/composition/page/pageContext').ComposedPageValue & {
 *   openSubject: (row: Object) => void,
 *   selectedSubject: ?Object,
 *   busySubject: ?Object,
 * }} PerformancePageValue
 */
