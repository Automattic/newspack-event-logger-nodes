/**
 * One logged request's summary — URL, time, duration, peak memory, HTTP status
 * — for the performance dashboard's detail modal and the current-request
 * overlay tab alike. It emits bare `<p>` rows and no wrapper, so each dashboard
 * keeps the container that positions them, and wording that differs between
 * the two arrives already translated in `statusNote` and `errorRow`.
 */

import { __ } from '@wordpress/i18n';

/**
 * @param {Object}                    props              Component props.
 * @param {Object}                    props.request      Decoded request body: `url`, the HTTP verb as `request_method` (durable body) or `method` (compact summary), `timestamp` in seconds, `duration_ms`, `peak_mb`, `status_code`.
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

	return (
		<>
			<p>
				<strong>{ __( 'URL:', 'newspack-event-logger-nodes' ) }</strong>{ ' ' }
				{ request.request_method || request.method || '' }{ ' ' }
				{ request.url }
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
