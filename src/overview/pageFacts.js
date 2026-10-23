/**
 * The page's facts, for the discovery block.
 *
 * Anything reading this dashboard — a browser assistant, an operator's script —
 * otherwise has to scrape a rendered table and infer what the numbers mean.
 * This is those numbers, named.
 *
 * FACTS ONLY. It carries no instructions, and every field measures the
 * selection the operator already has open, read off the replies the panels
 * render. The environment, the remote address and the user agent stay out,
 * because a discovery block is a convenience for a reader, not a second
 * export path.
 */

/**
 * The number, or the fallback. Pass `null` where "no answer yet" has to stay
 * distinguishable from "measured zero" — the distinction the headline stats
 * already render; the default floors a missing measurement so a reader can
 * index without guarding. Only a finite number survives: a numeric string,
 * `NaN` and `Infinity` all take the fallback.
 *
 * @param {*}       value        A candidate measurement.
 * @param {?number} [fallback=0] What an absent measurement reads as.
 * @return {?number} The finite number, or the fallback.
 */
function num( value, fallback = 0 ) {
	return 'number' === typeof value && Number.isFinite( value )
		? value
		: fallback;
}

/**
 * The open detail window's subject as the facts name it: under its own field,
 * `url` or `page`, its hash and the name it is titled by.
 *
 * @param {?Object} subject The window's `{ field, hash, url }`, or null.
 * @return {Object} `{ <field>: { hash, url } }`, or `{ url: null }` for none.
 */
const subjectFacts = ( subject ) =>
	subject
		? { [ subject.field ]: { hash: subject.hash, url: subject.url } }
		: { url: null };

/**
 * What the dashboard is currently showing, innermost selection first: a
 * selected request describes the page better than the URL or page window it
 * sits under, and that better than the totals of the URL set on screen.
 * `surface` says which it is — `request`, `url`, `page` or `overview` — and
 * `filters` rides on every one, because a narrowed number that does not say
 * what narrowed it reads as the site's.
 *
 * @param {Object}   [state]                 The dashboard's current selection.
 * @param {?Object}  [state.urlTotals]       Totals for the URL set the filters selected.
 * @param {?Array}   [state.urlSlowest]      The slowest pages of that same set.
 * @param {?Object}  [state.urlFilters]      The filters both are of.
 * @param {?Object}  [state.selectedSubject] The open `{ field, hash, url }`.
 * @param {?Object}  [state.urlDetail]       Its detail payload.
 * @param {string[]} [state.detailChips]     The modal chart's chips, each
 *                                           `field=value`, which narrow its
 *                                           header and list as well.
 * @param {?Object}  [state.detailFilters]   The modal's `{ bucket }`: the
 *                                           bucket selection its header and
 *                                           list narrow to, '' for the whole
 *                                           window.
 * @param {?string}  [state.selectedRequest] The selected request id.
 * @param {?Object}  [state.requestDetail]   Its detail payload.
 * @return {Object} The facts block, discriminated by `surface`.
 */
export function pageFacts( {
	urlTotals,
	urlSlowest,
	urlFilters,
	selectedSubject,
	urlDetail,
	detailChips = [],
	detailFilters,
	selectedRequest,
	requestDetail,
} = {} ) {
	// What the numbers are OF: the verb's echo, absent until it answers.
	const filters = urlFilters ?? null;

	if ( selectedRequest && requestDetail ) {
		return {
			surface: 'request',
			filters,
			request: { rid: selectedRequest },
			...subjectFacts( selectedSubject ),
			duration_ms: num( requestDetail.duration_ms ),
			status_code: num( requestDetail.status_code ),
			findings: requestDetail.findings,
			caveat: requestDetail.caveat ?? '',
		};
	}

	if ( selectedSubject ) {
		const stats = urlDetail?.stats ?? {};
		// Shaped as the url: brief is, a page's as well.
		return {
			surface: selectedSubject.field,
			filters,
			...subjectFacts( selectedSubject ),
			filter: detailChips,
			bucket: detailFilters?.bucket,
			stats: {
				count: num( stats.count ),
				errors: num( stats.errors ),
				// Null where nothing timed reached the URL: unmeasured.
				avg_ms: num( stats.avg_ms, null ),
				max_ms: num( stats.max_ms, null ),
				max_peak_mb: num( stats.max_peak_mb ),
			},
		};
	}

	return {
		surface: 'overview',
		filters,
		// @longform The panel's own numbers, so the brief describes the page
		// as shown, under whatever the operator applied. Absent reads as
		// absent: the header renders an em dash for these, and a reader
		// handed 0 during first paint would report an idle site.
		totals: {
			urls: num( urlTotals?.urls, null ),
			pages: num( urlTotals?.pages, null ),
			requests: num( urlTotals?.requests, null ),
			errors: num( urlTotals?.errors, null ),
			avg_ms: num( urlTotals?.avg_ms, null ),
			avg_peak_mb: num( urlTotals?.avg_peak_mb, null ),
		},
		// @longform The SAME set's pages the totals are of, ranked by `avg_ms`
		// server-side; `hash` names the one URL of a one-shape page, else null.
		slowest: ( urlSlowest ?? [] ).slice( 0, 10 ).map( ( u ) => ( {
			page: u.page,
			server: u.server,
			shapes: num( u.shapes ),
			hash: u.hash,
			avg_ms: num( u.avg_ms, null ),
			count: num( u.count ),
		} ) ),
	};
}

/**
 * Escape `<` as `\u003C`, the spelling that keeps a value from opening a tag
 * wherever it lands: a script element, a `<site-data>` fence.
 *
 * @param {string} text Any text.
 * @return {string} The text with every `<` escaped.
 */
export function escapeLt( text ) {
	return text.replace( /</g, '\\u003C' );
}

/**
 * The facts as a string safe to sit inside a `<script type="application/json">`
 * element.
 *
 * `JSON.stringify` does not escape `<`, so a `</script>` anywhere in the data
 * ENDS THE ELEMENT and everything after it parses as HTML. The data here is
 * attacker-controllable — a request to `/</script><img src=x onerror=...>` is
 * logged verbatim and would otherwise be injected straight into wp-admin.
 * U+2028 and U+2029 go too: both are valid JSON and neither is valid
 * JavaScript.
 *
 * @param {Object} facts The facts block.
 * @return {string} JSON, escaped for a script element.
 */
export function factsJson( facts ) {
	return escapeLt( JSON.stringify( facts ) )
		.replace( /\u2028/g, '\\u2028' )
		.replace( /\u2029/g, '\\u2029' );
}
