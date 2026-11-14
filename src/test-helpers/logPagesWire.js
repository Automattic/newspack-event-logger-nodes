/**
 * The seeded server behind the three log pages' tests: a catalog of two
 * partitions under each glob, one segment per partition, and the records
 * each page's view draws, every value distinct from a default or fallback.
 */

/* eslint-env jest */

import {
	Core,
	ID,
	KEY,
	TM_STRUCT,
	TYPE,
	VALUE,
	newMessage,
} from '@newspack-nodes/runtime';

/** The partition dirs `list_logs` answers, two under each glob. */
export const PARTITIONS = [
	'completed.p2',
	'completed.p5',
	'errors.p1',
	'errors.p4',
	'gyroscope.p0',
];

/** The segment `dump_log` answers for every dir. */
export const SEGMENT = { id: 7913, size: 51234 };

/**
 * The fake wire's answer by verb.
 *
 * @param {Array} message A command.
 * @return {*} Its answer.
 */
export function replyFor( message ) {
	const verb = message[ VALUE ]?.name;
	if ( 'list_logs' === verb ) {
		return PARTITIONS.map( ( key ) => ( {
			key,
			label: key,
			available: true,
		} ) );
	}
	if ( 'dump_log' === verb ) {
		return { segments: [ SEGMENT ] };
	}
	return null;
}

/**
 * A struct record as the stream delivers it.
 *
 * @param {string} key   Its KEY, the request id.
 * @param {Object} value Its VALUE.
 * @param {string} id    Its `segment:offset:length` ID.
 * @return {Array} The envelope.
 */
function record( key, value, id ) {
	const message = newMessage();
	message[ TYPE ] = TM_STRUCT;
	message[ KEY ] = key;
	message[ VALUE ] = value;
	message[ ID ] = id; // contract-ok: a record's segment breadcrumb, not a reply id
	return message;
}

/** Three completed requests, oldest first. */
export const REQUESTS = [
	record(
		'kea-7901',
		{
			url: 'https://www.kea-7900.example/alpine/7901?ridge=1',
			method: 'GET',
			status_code: 503,
			duration_ms: 1234,
			end_time: 1767225601,
			remote_addr: '10.79.0.1',
			user_agent: 'Tui/7901',
		},
		'7913:100:60'
	),
	record(
		'kaka-7902',
		{
			url: 'https://www.kea-7900.example/forest/7902',
			method: 'POST',
			status_code: 201,
			duration_ms: 87,
			end_time: 1767225662,
			remote_addr: '10.79.0.2',
			user_agent: 'Kokako/7902',
		},
		'7913:160:60'
	),
	record(
		'weka-7903',
		{
			url: 'https://www.kea-7900.example/coast/7903',
			method: 'GET',
			status_code: 404,
			duration_ms: 4321,
			end_time: 1767225723,
			remote_addr: '10.79.0.3',
			user_agent: 'Pipipi/7903',
		},
		'7913:220:60'
	),
];

/** Three errors, oldest first: an error, a warning and one with no URL. */
export const ERRORS = [
	record(
		'kea-7911',
		{
			ts: 1767225611,
			k: 'php (error)',
			m: 'Undefined index: ridge7911',
			url: 'https://www.kea-7900.example/alpine/7911',
			method: 'GET',
		},
		'7913:300:60'
	),
	record(
		'kaka-7912',
		{
			ts: 1767225672,
			k: 'warning',
			m: 'Slow query 7912',
			url: 'https://www.kea-7900.example/forest/7912',
			method: 'POST',
		},
		'7913:360:60'
	),
	record(
		'weka-7913',
		{ ts: 1767225733, k: 'stderr', m: 'Worker restarted 7913' },
		'7913:420:60'
	),
];

/** Two in-flight requests and one completion. */
export const FLIGHTS = [
	record(
		'kea-7921',
		{
			state: 'mysql',
			what: 'SELECT ridge7921',
			url: 'https://www.kea-7900.example/alpine/7921',
			method: 'GET',
			time_ms: 640,
			est_ms: 2710,
			remote_addr: '10.79.0.21',
			user_agent: 'Tui/7921',
		},
		'7913:500:60'
	),
	record(
		'kaka-7922',
		{
			state: 'include template',
			what: 'Guide.html 7922',
			url: 'https://www.kea-7900.example/forest/7922',
			method: 'GET',
			time_ms: 120,
			est_ms: 905,
			remote_addr: '10.79.0.22',
			user_agent: 'Kokako/7922',
		},
		'7913:560:60'
	),
	record(
		'weka-7923',
		{
			state: 'complete',
			url: 'https://www.kea-7900.example/coast/7923',
			method: 'GET',
			status_code: 302,
			duration_ms: 1888,
		},
		'7913:620:60'
	),
];

/**
 * Fill records into the page's view, the one node of `ViewClass`.
 *
 * @param {Function} ViewClass The view's class.
 * @param {Array[]}  records   The envelopes, oldest first.
 * @throws {Error} When the page mounted no such view.
 */
export function feed( ViewClass, records ) {
	const view = [ ...Core.nodes.values() ].find(
		( node ) => node instanceof ViewClass
	);
	if ( ! view ) {
		throw new Error( `no ${ ViewClass.name } is mounted` );
	}
	records.forEach( ( one ) => view.fill( [ ...one ] ) );
}
