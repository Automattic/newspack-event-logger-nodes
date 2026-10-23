/**
 * What the detail window is about: one URL, or a whole page.
 *
 * The URL window and the page window are one window asked two ways. Its
 * subject is `{ field, hash, url }`: the `requests` field it keys on, `url`
 * or `page`, that field's hash, and the name it is titled by. Every surface
 * that spells the window derives from the field: `dump_url` takes the hash
 * as `--<field>=`, a chart filters `<field>=<hash>`, the `?` picker names it
 * `<field>:<hash>`, and the address bar carries it under `SUBJECT_PARAMS`.
 */

import { formatCommandArgs } from '@newspack-nodes/runtime';
import { getQueryParam } from '@newspack-nodes/shared/utils/queryParams';

/**
 * Each subject field, to the address-bar param that links its window. A page
 * links as `?page_hash=`, because `?page=` names the admin screen.
 *
 * @type {{url: string, page: string}}
 */
export const SUBJECT_PARAMS = { url: 'url', page: 'page_hash' };

/**
 * The subject a URL-table row opens: a page of several shapes opens its
 * page, by its page hash; a one-shape page and a shape row open their URL.
 *
 * @param {Object} row A page row, or a shape's URL row.
 * @return {{field: string, hash: string, url: string}} The subject.
 */
export const subjectOfRow = ( row ) =>
	1 < row.shapes
		? { field: 'page', hash: row.page_hash, url: row.url }
		: { field: 'url', hash: row.hash, url: row.url };

/**
 * Whether two subjects name one window: the same field and the same hash. A
 * page and a URL can hash alike, so the hash alone never decides.
 *
 * @param {?Object} a A subject, or null.
 * @param {?Object} b Another.
 * @return {boolean} Whether both are present and name one window.
 */
export const sameSubject = ( a, b ) =>
	!! a && !! b && a.field === b.field && a.hash === b.hash;

/**
 * The subject of the loaded row that opens the window `subject` names.
 *
 * @param {Object[]} rows    One page of the URL table's rows.
 * @param {?Object}  subject The subject sought, `{ field, hash }`.
 * @return {?Object} That row's subject, titled; null when none opens it.
 */
export const findSubject = ( rows, subject ) =>
	( subject &&
		rows
			.map( subjectOfRow )
			.find( ( found ) => sameSubject( found, subject ) ) ) ||
	null;

/**
 * The address-bar params that link `subject`: its own param holds its hash,
 * and every other subject param is dropped.
 *
 * @param {?Object} subject The subject, or null for none.
 * @return {Object<string, ?string>} Param name to its hash, or null.
 */
export const subjectLink = ( subject ) =>
	Object.fromEntries(
		Object.entries( SUBJECT_PARAMS ).map( ( [ field, param ] ) => [
			param,
			field === subject?.field ? subject.hash : null,
		] )
	);

/**
 * The subject the address bar links, the first of `SUBJECT_PARAMS` it holds.
 *
 * @return {?{field: string, hash: string}} The subject, or null for none.
 */
export const linkedSubject = () => {
	for ( const [ field, param ] of Object.entries( SUBJECT_PARAMS ) ) {
		const hash = getQueryParam( param );
		if ( hash ) {
			return { field, hash };
		}
	}
	return null;
};

/**
 * The `dump_url` tokens that ask for `subject`: its hash under its own field,
 * `--url=` or `--page=`, then `options`. The first token is what a reply
 * names as its subject.
 *
 * @param {{field: string, hash: string}} subject   The subject.
 * @param {Object}                        [options] Named args to add.
 * @return {string[]} The command tokens.
 */
export const subjectArgs = ( { field, hash }, options = {} ) =>
	formatCommandArgs( [], { [ field ]: hash, ...options } );

/**
 * The `?` picker's descriptor for `subject`: `url:<hash>` or `page:<hash>`.
 *
 * @param {{field: string, hash: string}} subject The subject.
 * @return {string} The descriptor.
 */
export const subjectDescriptor = ( { field, hash } ) => `${ field }:${ hash }`;
