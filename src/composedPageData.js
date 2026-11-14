/**
 * The page data a composed page's load sets as
 * `NewspackEventLoggerComposedPage` (`Admin\Compositions::page_data()`):
 * which page it is, who may curate it, its layout, and what its row adds,
 * the Performance page's stats window.
 */

/**
 * The page data the load set, as `types/globals.d.ts` declares it.
 *
 * @return {NonNullable<Window['NewspackEventLoggerComposedPage']>} It.
 * @throws {Error} On a page that set none.
 */
export function composedPage() {
	const data = window.NewspackEventLoggerComposedPage;
	if ( ! data ) {
		throw new Error(
			'NewspackEventLoggerComposedPage is not set: Compositions::enqueue() sets it before a composed page bundle'
		);
	}
	return data;
}
