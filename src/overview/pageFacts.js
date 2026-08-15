/**
 * The page's facts, for the discovery block.
 *
 * Anything reading this dashboard — a browser assistant, an operator's script —
 * otherwise has to scrape a rendered table and infer what the numbers mean.
 * This is those numbers, named.
 *
 * FACTS ONLY. It carries no instructions, and nothing here is a field the
 * request record would not already have shown on screen: the environment,
 * the remote address and the user agent are not in it, because a discovery
 * block is a convenience for a reader, not a second export path.
 */

/**
 * A number, or 0 — never undefined, so a reader can index without guarding.
 *
 * @param {*} value Anything numeric-ish.
 * @return {number} The number, or 0.
 */
function num( value ) {
	return 'number' === typeof value && Number.isFinite( value ) ? value : 0;
}

/**
 * What the dashboard is currently showing, innermost selection first: a
 * selected request describes the page better than the URL it sits under, and
 * that better than the site-wide totals.
 *
 * @param {Object}  state                    The dashboard's current selection.
 * @param {?Object} [state.overview]         The overview slice.
 * @param {?Object} [state.selectedUrl]      The selected URL row.
 * @param {?Object} [state.urlDetail]        Its detail payload.
 * @param {?string} [state.selectedRequest]  The selected request id.
 * @param {?number} [state.requestPartition] Its partition.
 * @param {?Object} [state.requestDetail]    Its detail payload.
 * @return {Object} The facts block.
 */
export function pageFacts( {
	overview,
	selectedUrl,
	urlDetail,
	selectedRequest,
	requestPartition,
	requestDetail,
} = {} ) {
	if ( selectedRequest && requestDetail ) {
		return {
			surface: 'request',
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
			url: { hash: selectedUrl.hash, url: selectedUrl.url },
			stats: {
				count: num( stats.count ),
				avg_ms: num( stats.avg_ms ),
				p95_ms: num( stats.p95_ms ),
				max_peak_mb: num( stats.max_peak_mb ),
			},
		};
	}

	return {
		surface: 'overview',
		totals: {
			urls: num( overview?.total_urls ),
			requests: num( overview?.total_requests ),
			avg_ms: num( overview?.global_avg_ms ),
			avg_peak_mb: num( overview?.global_avg_peak_mb ),
		},
		slowest: ( overview?.slowest_urls ?? [] )
			.slice( 0, 10 )
			.map( ( u ) => ( {
				hash: u.hash,
				url: u.url,
				p95_ms: num( u.p95_ms ),
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
