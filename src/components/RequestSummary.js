/**
 * One logged request's summary — URL, time, duration, peak memory, object
 * cache, HTTP status — for the performance dashboard's detail modal and the
 * current-request overlay tab alike. It emits bare `<p>` rows and no
 * wrapper, so each dashboard keeps the container that positions them, and
 * wording that differs between the two arrives already translated in
 * `statusNote` and `errorRow`.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * A hit ratio as the row appends it: ` (<label>)` with the share of lookups
 * that hit, to one place, or '' where nothing was looked up.
 *
 * @param {number}                   hits   Lookups that found the key.
 * @param {number}                   misses Lookups that missed.
 * @param {function(string): string} label  Writes the translated ratio around the percentage.
 * @return {string} The suffix, or ''.
 */
const ratioSuffix = ( hits, misses, label ) =>
	hits + misses > 0
		? ` (${ label( ( ( hits / ( hits + misses ) ) * 100 ).toFixed( 1 ) ) })`
		: '';

/**
 * The object-cache row's text for each kind `Object_Cache_Counts` writes:
 * core's true hits and misses with their ratio; the memcached drop-in's
 * local answers, its single-key memcached gets with the ratio of those that
 * found the key, and the keys `get_multiple` asked for, whose results it
 * keeps none of. Null for a kind it does not write.
 *
 * @param {Object} counts The record's `object_cache` map.
 * @return {?string} The row's text.
 */
const cacheText = ( counts ) => {
	if ( 'core' === counts.kind ) {
		return (
			sprintf(
				/* translators: 1: runtime object-cache hits, 2: misses. */
				__( '%1$d hits, %2$d misses', 'newspack-event-logger-nodes' ),
				counts.hits,
				counts.misses
			) +
			ratioSuffix( counts.hits, counts.misses, ( pct ) =>
				sprintf(
					/* translators: %s: the share of lookups that hit, as a percentage. */
					__( '%s%% hit ratio', 'newspack-event-logger-nodes' ),
					pct
				)
			)
		);
	}
	if ( 'memcached' !== counts.kind ) {
		return null;
	}
	return [
		sprintf(
			/* translators: %d: lookups answered from the request's own copy. */
			__( '%d answered locally', 'newspack-event-logger-nodes' ),
			counts.local
		),
		sprintf(
			/* translators: 1: single-key memcached gets, 2: that found the key, 3: that missed. */
			__(
				'%1$d memcached gets: %2$d found, %3$d missed',
				'newspack-event-logger-nodes'
			),
			counts.gets,
			counts.hits,
			counts.misses
		) +
			ratioSuffix( counts.hits, counts.misses, ( pct ) =>
				sprintf(
					/* translators: %s: the share of memcached gets that found the key, as a percentage. */
					__(
						'%s%% memcached hit ratio',
						'newspack-event-logger-nodes'
					),
					pct
				)
			),
		sprintf(
			/* translators: %d: keys asked for in get_multiple batches. */
			__( '%d keys by get_multiple', 'newspack-event-logger-nodes' ),
			counts.multi_keys
		),
	].join( '; ' );
};

/**
 * @param {Object}                    props              Component props.
 * @param {Object}                    props.request      Decoded request body: `request_url`, the URL the request fetched with its query, set from the request line that also sets the `url` the builder files a record by, so a body without one is not a record the builder emits and renders `not recorded`; the HTTP verb as `request_method`, `timestamp` in seconds, `duration_ms`, `peak_mb`, `status_code`, and `object_cache`, the counts `Object_Cache_Counts` wrote for the cache it knows.
 * @param {string}                    [props.statusNote] Already-translated note appended to the status code. Its presence marks the row as an error, because the code cannot: `Request_Builder_Node` stamps `error_status` for a fatal, a timeout, an abort or a gap in the log, and any of those can accompany a 200.
 * @param {import('react').ReactNode} [props.errorRow]   Row rendered after the status, for a caller that shows the verdict as its own badge; one that folds the verdict into `statusNote` instead passes nothing.
 * @return {import('react').ReactElement} The summary rows.
 */
export default function RequestSummary( {
	request,
	statusNote = '',
	errorRow = null,
} ) {
	const timestamp = Number( request.timestamp ) || 0;
	const peakMb = Number( request.peak_mb ) || 0;
	const statusCode = Number( request.status_code ) || 0;
	const cache = request.object_cache
		? cacheText( request.object_cache )
		: null;

	return (
		<>
			<p>
				<strong>{ __( 'URL:', 'newspack-event-logger-nodes' ) }</strong>{ ' ' }
				{ request.request_url
					? `${ request.request_method } ${ request.request_url }`
					: __( 'not recorded', 'newspack-event-logger-nodes' ) }
			</p>
			<p>
				<strong>
					{ __( 'Time:', 'newspack-event-logger-nodes' ) }
				</strong>{ ' ' }
				{ timestamp
					? new Date( timestamp * 1000 ).toLocaleString()
					: '—' }
			</p>
			<p>
				<strong>
					{ __( 'Duration:', 'newspack-event-logger-nodes' ) }
				</strong>{ ' ' }
				{ ( Number( request.duration_ms ) || 0 ).toFixed( 2 ) } ms
			</p>
			{ peakMb > 0 && (
				<p>
					<strong>
						{ __( 'Memory:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ peakMb } MB
				</p>
			) }
			{ cache && (
				<p>
					<strong>
						{ __( 'Object cache:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ cache }
				</p>
			) }
			{ statusCode > 0 && (
				<p
					className={ `newspack-nodes-status${
						statusNote ? ' is-error' : ''
					}` }
				>
					<strong>
						{ __( 'Status:', 'newspack-event-logger-nodes' ) }
					</strong>{ ' ' }
					{ statusCode }
					{ statusNote ? ` — ${ statusNote }` : '' }
				</p>
			) }
			{ errorRow }
		</>
	);
}
