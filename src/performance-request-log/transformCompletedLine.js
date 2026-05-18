/**
 * Transform a Message envelope from /messages/stream?subscribe=completed
 * into the row shape the Request Stream dashboard renders. Mirror of the
 * legacy `RequestsStreamController::transform_line()`, simplified — the
 * `completed.log` source is already filtered by the topology's
 * `completed:tee` node, so no completed-only check is needed.
 *
 * @param {Array} envelope 7-field Message array.
 * @return {Object|null} Row, or `null` if VALUE has no `url`.
 */
const VALUE = 6;

const MAX_URL_LENGTH = 2000;
const MAX_UA_LENGTH = 500;

function clip( s, max ) {
	if ( typeof s !== 'string' ) {
		return s;
	}
	return s.length > max ? s.substring( 0, max ) + '...' : s;
}

export default function transformCompletedLine( envelope ) {
	const req = envelope[ VALUE ];
	if ( ! req || typeof req !== 'object' || Array.isArray( req ) ) {
		return null;
	}
	if ( ! req.url ) {
		return null;
	}
	return {
		rid: req.rid || '',
		method: req.method || 'GET',
		url: clip( req.url, MAX_URL_LENGTH ),
		start_time: req.start_time || 0,
		end_time: req.end_time || 0,
		duration_ms: req.duration_ms || 0,
		status_code: req.status_code || 0,
		state: req.state || 'complete',
		error_status: req.error_status || '-',
		remote_addr: req.remote_addr || '',
		user_agent: clip( req.user_agent || '', MAX_UA_LENGTH ),
	};
}
