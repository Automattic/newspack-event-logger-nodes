/**
 * The Performance page's page state, its sources and the blocks its layout
 * is composed of, for both the page bundle and its layout editor, which each
 * import this module, so each bundle declares what it reads.
 *
 * Page state is what several blocks read and some write, each key under the
 * query param it always had: the filters `server`, `chips` and `buckets`,
 * which narrow the charts, the URL table and the totals; the chart's
 * `metric`, `breakdown` and `then`; the URL table's `sort`, `order`, `q`,
 * `workers` and `paged`; the window's subject, `url` or `page_hash`, and its
 * `request`, each a Back entry; and the cadence every poll asks at,
 * `refresh`, which a first visit reads from the choice the browser kept.
 *
 * Four polls answer the page, in the `overview` group, at that cadence:
 * `eln/overview`, the site's servers, leaderboard and owners; `eln/urls`,
 * one page of the URL table or one expanded page's shapes, with the set's
 * own totals, which the headline stats show from the table's one fetch
 * under a caption naming the stats window the server localized; `eln/chart`,
 * a chart's series by its dimensions; and `eln/categories`, the category
 * charts' totals. Three polls in the `url` group answer the URL window at the
 * same cadence: `eln/url-detail`, keyed by its subject and its own
 * narrowing, and its two charts, `eln/url-chart` and `eln/url-categories`;
 * each keeps nothing of another subject. A command answers the request
 * window, `eln/request-detail`, keyed by its request. A key naming nothing a
 * command may carry asks nothing. Every stats poll asks once however long
 * its answer takes, a chipped page answering in tens of seconds, and the
 * poll that retires the ask asks again.
 */

import { __, sprintf } from '@wordpress/i18n';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { SUBSTRATE_VIEWS } from '@newspack-nodes/shared/blocks';
import { definePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { registerSource } from '@newspack-nodes/shared/sources';
import { readStorage } from '@newspack-nodes/shared/utils/storage';
import { formatGroupedCount } from '@newspack-nodes/shared/utils/formatters';
import { serverName } from '../log-table/urlIdentity';
import { chipRefusal, chipsOf } from './components/BreakdownControls';
import { windowSpan } from './chartSlots';
import { performancePage } from './performancePageData';
import {
	CHART_BREAKDOWN_OPTIONS,
	CHART_METRIC_OPTIONS,
	DASHBOARD_REFRESH_OPTIONS,
	DEFAULT_CHART_BREAKDOWN,
	DEFAULT_CHART_METRIC,
	FILTER_FIELD_OPTIONS,
	GROUPS,
	SORT_ORDERS,
	wholeMs,
} from './constants';
import {
	categoryArgs,
	chartArgs,
	detailArgs,
	isValidHash,
	isValidRequestId,
	overviewArgs,
	urlsArgs,
} from './questions';
import breakdownBar from './blocks/breakdown-bar';
import aggregateChart from './blocks/aggregate-chart';
import categoryChart from './blocks/category-chart';
import profile from './blocks/profile';
import urlTable from './blocks/url-table';
import { SORT_FIELDS } from './UrlTable';

/** The server CI every command this page sends goes to. */
export const SERVER = 'performance';

/** The ruleset CI the window's "Log this URL" editor reads and writes. */
export const RULES_CI = 'rules';

/** The localStorage key the chosen cadence outlives the page under. */
export const REFRESH_KEY = 'event-logger-refresh-interval';

/** The cadence a first visit polls at. */
const DEFAULT_REFRESH_MS = '15000';

/** No filter chips: one array, so an unset chip list never re-asks. */
export const NO_CHIPS = [];

/** The URL table's question until someone moves a control. */
const URL_PARAMS = [ 'sort', 'order', 'q', 'workers', 'paged' ];

/** The filters that narrow the charts, the URL table and the totals. */
const FILTERS = [ 'server', 'chips', 'buckets' ];

/**
 * A value one of `values`, else `fallback`.
 *
 * @param {string[]} values   What the param may hold.
 * @param {string}   fallback What it holds otherwise.
 * @return {(raw: string) => string} The parse.
 */
const oneOf = ( values, fallback ) => ( raw ) =>
	values.includes( raw ) ? raw : fallback;

/** Every dimension the chart offers. */
const BREAKDOWNS = CHART_BREAKDOWN_OPTIONS.map( ( option ) => option.value );

definePageState( {
	server: {
		default: '',
		query: 'server',
		parse: serverName,
	},
	chips: {
		default: NO_CHIPS,
		query: 'chip',
		parse: ( raw ) => {
			const kept = chipsOf(
				/** @type {string[]} */ ( raw ).filter(
					( chip ) =>
						null === chipRefusal( chip, FILTER_FIELD_OPTIONS )
				)
			);
			return kept.length ? kept : NO_CHIPS;
		},
	},
	buckets: { default: '', query: 'bucket' },
	metric: {
		default: DEFAULT_CHART_METRIC,
		query: 'metric',
		parse: oneOf(
			CHART_METRIC_OPTIONS.map( ( option ) => option.value ),
			DEFAULT_CHART_METRIC
		),
	},
	breakdown: {
		default: DEFAULT_CHART_BREAKDOWN,
		query: 'breakdown',
		parse: oneOf( BREAKDOWNS, DEFAULT_CHART_BREAKDOWN ),
	},
	then: { default: '', query: 'then', parse: oneOf( BREAKDOWNS, '' ) },
	sort: {
		default: 'count',
		query: 'sort',
		parse: oneOf( SORT_FIELDS, 'count' ),
	},
	order: {
		default: 'desc',
		query: 'order',
		parse: oneOf( SORT_ORDERS, 'desc' ),
	},
	q: { default: '', query: 'q' },
	workers: {
		default: false,
		query: 'workers',
		parse: ( raw ) => '1' === raw,
		format: () => '1',
	},
	paged: {
		default: 1,
		query: 'paged',
		// Safe, or `1e21` reaches the wire as an exponent the command refuses.
		parse: ( raw ) => {
			const n = Number( raw );
			return Number.isSafeInteger( n ) && n > 0 ? n : 1;
		},
	},
	url: { default: '', query: 'url', push: true },
	page_hash: { default: '', query: 'page_hash', push: true },
	request: {
		default: '',
		query: 'request',
		push: true,
		// Echoed into the bar and sent to the server, so only the id alphabet.
		parse: ( raw ) => ( isValidRequestId( raw ) ? raw : '' ),
	},
	refresh: {
		default:
			DASHBOARD_REFRESH_OPTIONS.find(
				( option ) => option.value === readStorage( REFRESH_KEY )
			)?.value ?? DEFAULT_REFRESH_MS,
	},
} );

/**
 * A figure: a number as `format` shows it, and one that has not arrived as
 * absent, because a plausible zero beside a real total reads as a
 * measurement.
 *
 * @param {string}                label  The tile's caption.
 * @param {(n: number) => string} format How a number shows.
 * @return {Object} The field.
 */
const figure = ( label, format ) => ( {
	label,
	kind: 'value',
	format: ( n ) => ( 'number' === typeof n ? format( n ) : '—' ),
} );

/**
 * A rate in requests a second.
 *
 * @param {number} n The rate.
 * @return {string} It, to two places.
 */
const perSecond = ( n ) => n.toFixed( 2 );

/**
 * A peak in megabytes.
 *
 * @param {number} n The peak.
 * @return {string} It, to one place, with its unit.
 */
const megabytes = ( n ) => `${ n.toFixed( 1 ) }MB`;

/**
 * Peak memory shows only where the install samples it.
 *
 * @param {number} n The peak.
 * @return {boolean} Whether it was sampled.
 */
const sampled = ( n ) => n > 0;

/**
 * Each answer whole, an envelope a block reads its answer from: an answer
 * that is null is still an answer, which a block shows as empty rather than
 * as loading.
 *
 * @param {*} answer The command's answer.
 * @return {{data: *}} The model.
 */
const answered = ( answer ) => ( { data: answer ?? null } );

/**
 * The subject a window's source is read for, so its model never stands in
 * for another subject's.
 *
 * @param {{subject: ?{field: string, hash: string}}} params The params.
 * @return {string} `<field>:<hash>`, or '' for none.
 */
const subjectScope = ( { subject } ) =>
	subject ? `${ subject.field }:${ subject.hash }` : '';

/** What every poll on the page declares beside its question. */
const POLL = {
	kind: 'poll',
	ci: SERVER,
	intervalMs: Number( DEFAULT_REFRESH_MS ),
	cadence: 'refresh',
	retry: false,
};

registerSource( 'eln/overview', {
	...POLL,
	label: __( 'Performance overview', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'overview',
	args: overviewArgs,
	transform: answered,
	params: [ 'server' ],
} );

/**
 * A headline figure: one of the `urls` totals, under its long label.
 *
 * @param {string}                 key     The total.
 * @param {string}                 label   Its label.
 * @param {(n: number) => string}  format  How it shows.
 * @param {(n: number) => boolean} [shown] Whether it shows at all.
 * @return {Object} The field.
 */
const total = ( key, label, format, shown ) => ( {
	...figure( label, format ),
	value: ( { totals } ) => totals?.[ key ],
	...( shown && { shown } ),
} );

registerSource( 'eln/urls', {
	...POLL,
	label: __( 'Pages', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'urls',
	args: urlsArgs,
	// @longform The envelope less `limit`, which this page's own args set:
	// `rows` counts what the filters left, 0 for an answer counting none,
	// and `totals` is the same set's figures, null rather than zero for a
	// malformed envelope, since a zero there reads as a measurement.
	transform: ( answer ) => ( {
		data: answer?.data ?? [],
		totals: answer?.totals ?? null,
		rows: answer?.rows ?? 0,
		offset: answer?.offset ?? 0,
		slowest: answer?.slowest ?? [],
		filters: answer?.filters ?? null,
	} ),
	params: [ ...FILTERS, ...URL_PARAMS ],
	caption: () => {
		const { window: buckets } = performancePage();
		if ( 'number' !== typeof buckets ) {
			return buckets?.error ?? null;
		}
		return sprintf(
			// translators: %s: the span the window covers, e.g. 2 hours.
			__(
				'The last %s in five-minute buckets, the current one so far.',
				'newspack-event-logger-nodes'
			),
			windowSpan( buckets )
		);
	},
	fields: {
		urls: total(
			'urls',
			__( 'Unique URLs', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		requests: total(
			'requests',
			__( 'Total Requests', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		errors: total(
			'errors',
			__( 'Total Errors', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		avg_ms: total(
			'avg_ms',
			__( 'Avg Response', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		requests_per_second: total(
			'requests_per_second',
			__( 'Req/s', 'newspack-event-logger-nodes' ),
			perSecond
		),
		avg_peak_mb: total(
			'avg_peak_mb',
			__( 'Avg Peak Memory', 'newspack-event-logger-nodes' ),
			megabytes,
			sampled
		),
	},
} );

registerSource( 'eln/chart', {
	...POLL,
	label: __( 'Request chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'query',
	args: chartArgs,
	transform: answered,
} );

registerSource( 'eln/categories', {
	...POLL,
	label: __( 'Category chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'query',
	args: categoryArgs,
	transform: answered,
	params: [ 'server' ],
} );

/**
 * A URL window's header figure: one of `dump_url`'s stats, under the short
 * label the window's header reads.
 *
 * @param {string}                 key     The stat.
 * @param {string}                 label   Its short label.
 * @param {(n: number) => string}  format  How it shows.
 * @param {(n: number) => boolean} [shown] Whether it shows at all.
 * @return {Object} The field.
 */
const windowFigure = ( key, label, format, shown ) => ( {
	...figure( label, format ),
	value: ( { data } ) => data?.stats?.[ key ],
	...( shown && { shown } ),
} );

registerSource( 'eln/url-detail', {
	...POLL,
	label: __( 'URL detail', 'newspack-event-logger-nodes' ),
	group: GROUPS.url,
	command: 'dump_url',
	args: ( params ) =>
		isValidHash( params.subject?.hash ) ? detailArgs( params ) : null,
	transform: answered,
	scope: subjectScope,
	fields: {
		requests_per_second: windowFigure(
			'requests_per_second',
			__( 'req/s', 'newspack-event-logger-nodes' ),
			perSecond
		),
		errors: windowFigure(
			'errors',
			__( 'errors', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		error_rate: windowFigure(
			'error_rate',
			__( 'err', 'newspack-event-logger-nodes' ),
			( n ) => `${ ( n * 100 ).toFixed( 2 ) }%`
		),
		avg_ms: windowFigure(
			'avg_ms',
			__( 'avg', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		p50_ms: windowFigure(
			'p50_ms',
			__( 'p50', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		p95_ms: windowFigure(
			'p95_ms',
			__( 'p95', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		p99_ms: windowFigure(
			'p99_ms',
			__( 'p99', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		avg_peak_mb: windowFigure(
			'avg_peak_mb',
			__( 'mem', 'newspack-event-logger-nodes' ),
			megabytes,
			sampled
		),
	},
} );

registerSource( 'eln/url-chart', {
	...POLL,
	label: __( 'URL request chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.url,
	command: 'query',
	args: chartArgs,
	transform: answered,
	scope: subjectScope,
} );

registerSource( 'eln/url-categories', {
	...POLL,
	label: __( 'URL category chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.url,
	command: 'query',
	args: categoryArgs,
	transform: answered,
	scope: subjectScope,
} );

registerSource( 'eln/request-detail', {
	label: __( 'Request detail', 'newspack-event-logger-nodes' ),
	kind: 'command',
	group: GROUPS.request,
	ci: SERVER,
	command: 'dump_request',
	args: ( { request } ) =>
		isValidRequestId( request ) ? formatCommandArgs( [ request ] ) : null,
} );

/**
 * Every block the Performance layout may hold, by block name.
 *
 * @type {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>}
 */
export const BLOCK_VIEWS = {
	...SUBSTRATE_VIEWS,
	'newspack-event-logger-nodes/breakdown-bar': breakdownBar,
	'newspack-event-logger-nodes/aggregate-chart': aggregateChart,
	'newspack-event-logger-nodes/category-chart': categoryChart,
	'newspack-event-logger-nodes/profile': profile,
	'newspack-event-logger-nodes/url-table': urlTable,
};
