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
 * What the dashboard is currently showing, innermost selection first: a
 * selected request describes the page better than the URL it sits under, and
 * that better than the totals of the URL set on screen. `surface` says which
 * of the three it is — `request`, `url` or `overview` — and `filters` rides
 * on all three, because a narrowed number that does not say what narrowed it
 * reads as the site's.
 *
 * @param {Object}  [state]                  The dashboard's current selection.
 * @param {?Object} [state.urlTotals]        Totals for the URL set the filters selected.
 * @param {?Array}  [state.urlSlowest]       The slowest URLs of that same set.
 * @param {?Object} [state.urlFilters]       The filters both are of.
 * @param {?Object} [state.selectedUrl]      The selected URL row.
 * @param {?Object} [state.urlDetail]        Its detail payload.
 * @param {?string} [state.selectedRequest]  The selected request id.
 * @param {?number} [state.requestPartition] Its partition. Absent reads as 0,
 *                                           the `request_detail` verb's own
 *                                           default.
 * @param {?Object} [state.requestDetail]    Its detail payload.
 * @return {Object} The facts block, discriminated by `surface`.
 */
export function pageFacts( {
	urlTotals,
	urlSlowest,
	urlFilters,
	selectedUrl,
	urlDetail,
	selectedRequest,
	requestPartition,
	requestDetail,
} = {} ) {
	// What the numbers are OF: the verb's echo, absent until it answers.
	const filters = urlFilters ?? null;

	if ( selectedRequest && requestDetail ) {
		return {
			surface: 'request',
			filters,
			request: { rid: selectedRequest, partition: requestPartition ?? 0 },
			url: selectedUrl
				? { hash: selectedUrl.hash, url: selectedUrl.url }
				: null,
			duration_ms: num( requestDetail.duration_ms ),
			status_code: num( requestDetail.status_code ),
			findings: requestDetail.findings ?? [],
			caveat: requestDetail.caveat ?? '',
		};
	}

	if ( selectedUrl ) {
		const stats = urlDetail?.stats ?? {};
		return {
			surface: 'url',
			filters,
			url: { hash: selectedUrl.hash, url: selectedUrl.url },
			stats: {
				count: num( stats.count ),
				avg_ms: num( stats.avg_ms ),
				max_ms: num( stats.max_ms ),
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
			requests: num( urlTotals?.requests, null ),
			avg_ms: num( urlTotals?.avg_ms, null ),
			avg_peak_mb: num( urlTotals?.avg_peak_mb, null ),
		},
		// The SAME set the totals are of, ranked by `avg_ms` server-side.
		slowest: ( urlSlowest ?? [] ).slice( 0, 10 ).map( ( u ) => ( {
			hash: u.hash,
			url: u.url,
			avg_ms: num( u.avg_ms ),
			count: num( u.count ),
		} ) ),
	};
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
	return JSON.stringify( facts )
		.replace( /</g, '\\u003C' )
		.replace( /\u2028/g, '\\u2028' )
		.replace( /\u2029/g, '\\u2029' );
}
