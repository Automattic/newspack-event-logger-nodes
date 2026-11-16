/**
 * The questions the URL and request windows ask, each read from the page
 * state its source declares, so the window's blocks and the page around
 * them share one fetch per source: the URL's `dump_url`, its two charts and
 * the buckets its request chart read, and the request's `dump_request` with
 * the entry rows its trace and its log read.
 */

import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { sourceDeclaration, useSource } from '@newspack-nodes/shared/sources';
import { answerSlots } from '../chartSlots';
import { subjectOfLink } from '../detailSubject';
import { isValidHash } from '../questions';
import {
	computeIndentedEntries,
	spliceFoldedSpans,
} from '../utils/logEntryUtils';

/** The page state every URL window source is read with: its subject's. */
export const WINDOW_PARAMS = [ 'url', 'page_hash', 'server' ];

/** A read that asks nothing, for a window not yet open or not yet settled. */
const SHUT = {};

/**
 * Each chart answer's buckets, derived once however many blocks and mounts
 * read them.
 *
 * @type {WeakMap<Object, ?string[]>}
 */
const SLOTS = new WeakMap();

/**
 * The buckets a chart answer read, newest first; null for none.
 *
 * @param {?Object} answer The answer.
 * @return {?string[]} The keys.
 */
function slotsOf( answer ) {
	if ( ! answer ) {
		return answerSlots( answer );
	}
	if ( ! SLOTS.has( answer ) ) {
		SLOTS.set( answer, answerSlots( answer ) );
	}
	return SLOTS.get( answer );
}

/**
 * A window source's params: each page-state key its declaration names, so
 * what a window reads it with is what a shared block on the layout reads.
 *
 * @param {string} source A window source.
 * @return {Object<string,*>} Key => value.
 */
function useWindowParams( source ) {
	const params = {};
	for ( const key of sourceDeclaration( source ).params ) {
		// eslint-disable-next-line react-hooks/rules-of-hooks -- keys fixed per source
		params[ key ] = usePageState( key )[ 0 ];
	}
	return params;
}

/**
 * The subject a window source's params name, and whether a command may
 * carry its hash; null for none.
 *
 * @param {Object<string,*>} params The params, `url` and `page_hash` among them.
 * @return {?{field: string, hash: string, valid: boolean}} The subject.
 */
export function windowSubject( params ) {
	const subject = subjectOfLink( params.url, params.page_hash );
	return subject && { ...subject, valid: isValidHash( subject.hash ) };
}

/**
 * The URL window's `dump_url`, as one slice: its answer, which stays on
 * screen while a narrowed one is asked but never under another subject's,
 * and a hash no command may carry as the window's refusal.
 *
 * @param {boolean} [asking] Whether to ask at all; false reads nothing.
 * @return {{model: ?Object, data: ?Object, loading: boolean, error: ?string}} The slice.
 */
export function useUrlDetail( asking = true ) {
	const params = useWindowParams( 'eln/url-detail' );
	const read = useSource( 'eln/url-detail', asking ? params : SHUT );
	const badHash = asking && false === windowSubject( params )?.valid;
	return useMemo(
		() => ( {
			model: badHash ? null : read.model,
			data: badHash ? null : read.model?.data ?? null,
			loading: read.loading,
			error: badHash
				? __( 'Invalid URL hash format', 'newspack-event-logger-nodes' )
				: read.error,
		} ),
		[ badHash, read.model, read.loading, read.error ]
	);
}

/**
 * The URL window's request chart, read once: its subject, its question —
 * the dimensions and the chips page state holds — its answer, and the
 * buckets that answer read.
 *
 * @param {boolean} asking Whether the window asks its charts yet.
 * @return {{subject: ?Object, chart: {breakdown: string, thenBy: string, chips: string[]}, answer: ?Object, slots: ?string[], loading: boolean, error: ?string}} The chart.
 */
export function useUrlChart( asking ) {
	const params = useWindowParams( 'eln/url-chart' );
	const { model, loading, error } = useSource(
		'eln/url-chart',
		asking ? params : SHUT
	);
	const answer = model?.data ?? null;
	const {
		url_breakdown: breakdown,
		url_then: thenBy,
		url_chips: chips,
	} = params;
	const chart = useMemo(
		() => ( { breakdown, thenBy, chips } ),
		[ breakdown, thenBy, chips ]
	);
	return {
		subject: windowSubject( params ),
		chart,
		answer,
		slots: slotsOf( answer ),
		loading,
		error,
	};
}

/**
 * The URL window's category charts' answer, the buckets it read, and its
 * status.
 *
 * @param {boolean} asking Whether the window asks its charts yet.
 * @return {{answer: ?Object, slots: ?string[], loading: boolean, error: ?string}} The answer.
 */
export function useUrlCategories( asking ) {
	const params = useWindowParams( 'eln/url-categories' );
	const { model, loading, error } = useSource(
		'eln/url-categories',
		asking ? params : SHUT
	);
	const answer = model?.data ?? null;
	return { answer, slots: slotsOf( answer ), loading, error };
}

/**
 * The request window's `dump_request`. The `request` key reads only the id
 * alphabet, so every id it holds is one a command may carry.
 *
 * @param {boolean} [asking] Whether to ask at all; false reads nothing.
 * @return {{data: ?Object, error: ?string}} The slice.
 */
export function useRequestDetail( asking = true ) {
	const params = useWindowParams( 'eln/request-detail' );
	const { model, error } = useSource(
		'eln/request-detail',
		asking ? params : SHUT
	);
	return useMemo( () => ( { data: model, error } ), [ model, error ] );
}

/**
 * Each answer's entry rows, built once however many blocks read them.
 *
 * @type {WeakMap<Object, {entries: Array, realCount: number}>}
 */
const ENTRIES = new WeakMap();

/** No request, no rows. */
const NO_ENTRIES = { entries: [], realCount: 0 };

/**
 * The entry rows the request's trace and log render, and how many are real.
 *
 * Every stored entry is numbered `i` first, which the table finds rows by,
 * and a folded request's merged tree splices in where its entries were, so
 * the indent walk reads one list either way. `realCount` excludes the
 * placeholder rows `computeIndentedEntries` inserts to span time gaps,
 * which is what the log's heading counts.
 *
 * @param {?Object} detail The `dump_request` answer.
 * @return {{entries: Array, realCount: number}} The rows.
 */
export function requestEntries( detail ) {
	if ( ! detail ) {
		return NO_ENTRIES;
	}
	if ( ! ENTRIES.has( detail ) ) {
		const { entries, realCount } = computeIndentedEntries(
			spliceFoldedSpans( detail.entries, detail.flame )
		);
		ENTRIES.set( detail, { entries, realCount } );
	}
	return ENTRIES.get( detail );
}
