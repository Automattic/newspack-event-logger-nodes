/**
 * The Performance Dashboard's dropdown vocabularies, and the groups its
 * commands travel.
 *
 * `PerformanceDashboard` reads the refresh cadences and the breakdown default,
 * `OverviewSection` the refresh cadences and the breakdown dimensions,
 * `BreakdownControls` the metrics, the dimensions and the chip fields, and
 * `usePerformanceGraph` the series count every chart asks for; the Gyroscope
 * in-flight view keeps its own list in `src/gyroscope/constants.js`. Each
 * option is a `SelectControl` option, so the `value` is the token the rest of
 * the dashboard — and, for fields, the server — matches on. The metric and
 * field labels are translated; the refresh cadences' unit abbreviations are
 * not.
 */

import { __ } from '@wordpress/i18n';

/**
 * Auto-refresh cadences for the dashboard's "Refresh:" dropdown.
 *
 * Each `value` is a number of MILLISECONDS held as a string, because
 * `SelectControl` compares option values as strings; `usePerformanceGraph`
 * parses it back with `parseInt` to arm the poll timer.
 *
 * The list doubles as the validation whitelist for the persisted
 * `event-logger-refresh-interval` localStorage value: `PerformanceDashboard`
 * hands it to `usePersistedChoice`, which discards a saved string matching no
 * option here and falls back to `'15000'`. Dropping that entry would leave the
 * dashboard defaulting to a value the dropdown cannot render.
 */
export const DASHBOARD_REFRESH_OPTIONS = [
	{ label: '1s', value: '1000' },
	{ label: '2s', value: '2000' },
	{ label: '5s', value: '5000' },
	{ label: '15s', value: '15000' },
	{ label: '30s', value: '30000' },
	{ label: '1m', value: '60000' },
];

/**
 * Metric choices for the aggregate time chart's "Metric" dropdown.
 *
 * Every metric reads the same per-bucket sums, `SERIES_MEASURES`, and the
 * `value` decides how `AggregateTimeChart` reduces them: `volume` plots the
 * count, `avg` the mean millisecond of the timed requests, `cumulative` the
 * summed seconds, `memory` the mean megabyte.
 *
 * `PerformanceDashboard` and `UrlDetailView` each seed their own state with
 * `'volume'`. The list order sets no default.
 */
export const CHART_METRIC_OPTIONS = [
	{
		label: __( 'Request Volume', 'newspack-event-logger-nodes' ),
		value: 'volume',
	},
	{
		label: __( 'Avg Response Time', 'newspack-event-logger-nodes' ),
		value: 'avg',
	},
	{
		label: __( 'Cumulative Response Time', 'newspack-event-logger-nodes' ),
		value: 'cumulative',
	},
	{
		label: __( 'Avg Peak Memory', 'newspack-event-logger-nodes' ),
		value: 'memory',
	},
];

/**
 * The `requests` fields `Stats_Query::FIELDS` declares past its time fields
 * (`Stats_Query::TIME_FIELDS`, which every chart already draws as its axis),
 * in its order; a jest test holds `server` and the chip fields, in order, to
 * the PHP by reading it. `kind` says where a field is offered: `series` in
 * the breakdown selects and, `server` aside, the filter chips; `filter` in
 * the chips alone.
 *
 * `worker` is a chip, never a series: the Overview charts ask `worker=0`
 * unless a chip names the workers, so a series by it would draw one line.
 * `url` is a chip by hash; the URL modal is the place to chart one URL.
 *
 * @type {Array<{value: string, label: string, kind: 'series'|'filter'}>}
 */
const REQUEST_FIELDS = [
	{
		value: 'server',
		label: __( 'Server', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'url',
		label: __( 'URL Hash', 'newspack-event-logger-nodes' ),
		kind: 'filter',
	},
	{
		value: 'status',
		label: __( 'Status Code', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'status_class',
		label: __( 'Status Class', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'method',
		label: __( 'Method', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'country',
		label: __( 'Country', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'ua',
		label: __( 'User Agent', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'ja4',
		label: __( 'JA4 Hash', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'from',
		label: __( 'From', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'worker',
		label: __( 'Worker', 'newspack-event-logger-nodes' ),
		kind: 'filter',
	},
	{
		value: 'profiled',
		label: __( 'Profiled', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
	{
		value: 'error',
		label: __( 'Error', 'newspack-event-logger-nodes' ),
		kind: 'series',
	},
];

/**
 * A field as a `SelectControl` option.
 *
 * @param {{value: string, label: string}} field A `REQUEST_FIELDS` entry.
 * @return {{label: string, value: string}} The option.
 */
const asOption = ( { value, label } ) => ( { label, value } );

/**
 * Dimension choices for the aggregate time chart's two breakdown selects:
 * every `series` field.
 *
 * Each caller hands `BreakdownControls` the list it offers. `OverviewSection`
 * offers this one while `canBreakDownByServer` holds and
 * `SCOPED_BREAKDOWN_OPTIONS` once it does not — a server filter is on, or a
 * landed server list names fewer than two; `PerformanceDashboard` computes
 * that flag, passes it down, and resolves the active dimension against the
 * same flag, so a list that has not landed yet keeps the default. The URL
 * modal offers the scoped list always.
 */
export const CHART_BREAKDOWN_OPTIONS = REQUEST_FIELDS.filter(
	( field ) => 'series' === field.kind
).map( asOption );

/**
 * The dimensions that still split INSIDE one server's scope: the list above
 * minus `server`. The server is a URL's host, so a chart already scoped
 * to one server — a server filter, or one URL, whose hash is over the whole
 * URL and therefore its host — would draw one line under that axis.
 */
export const SCOPED_BREAKDOWN_OPTIONS = CHART_BREAKDOWN_OPTIONS.filter(
	( option ) => option.value !== 'server'
);

/**
 * The fields a filter chip may name: every one but `server`. The Server
 * select is the one place a server is chosen, and the `urls`, `dump_url` and
 * `ask` verbs refuse a `server=` filter as `--server`'s, so a chip on it
 * would refuse the table the chart's chips ride.
 */
export const FILTER_FIELD_OPTIONS = REQUEST_FIELDS.filter(
	( field ) => 'server' !== field.value
).map( asOption );

/**
 * What the aggregate chart breaks down by until someone chooses otherwise.
 *
 * `PerformanceDashboard` seeds its `chartBreakdown` state with this and
 * resolves `server` to `status_class` whenever `canBreakDownByServer` is
 * false. That fallback is DERIVED, never written back over the state, so a
 * hub whose second server reports late gets the axis back rather than
 * staying on `status_class` for the session.
 */
export const DEFAULT_CHART_BREAKDOWN = 'server';

/**
 * The series a chart asks `query` for, ranked by `top_by`:
 * `Stats_Query::MAX_TOP`, the most a top answer holds.
 */
export const CHART_TOP = 20;

/**
 * What the aggregate chart asks `query` to measure per bucket and series:
 * every metric reduces these four (`AggregateTimeChart`'s `slotValue()`).
 */
export const SERIES_MEASURES = [ 'count', 'sum_ms', 'sum_peak_mb', 'timed' ];

/**
 * What the category charts ask `query` to measure per bucket and category:
 * its milliseconds and calls, which the three views read.
 */
export const CATEGORY_MEASURES = [ 'ms', 'calls' ];

/**
 * The groups this plugin's commands travel, each through its own
 * `<group>:shell` Tap so `connect <group>:shell` watches one family alone.
 * `usePerformanceGraph` polls under `overview`, `url` and `request`;
 * `PerformanceDashboard`, `UrlDetailView` and `AskPanel` send their one-shot
 * commands under `url`, `request`, `performance` and `rules`; the rules editor
 * sends under `rules`, the hook picker under `hooks`, the current-request tab
 * under `currentRequest`, and the request and error streams under `requestLog`
 * and `errorLog`.
 */
export const GROUPS = {
	overview: 'overview',
	performance: 'performance',
	request: 'request',
	url: 'url',
	rules: 'rules',
	hooks: 'hooks',
	currentRequest: 'current-request',
	requestLog: 'request-log',
	errorLog: 'error-log',
};

/**
 * The partition glob each streaming dashboard tails: its graph rides it, and
 * its skipped-line notice sums the stamps it carries.
 */
export const STREAM_GLOBS = {
	errorLog: 'errors.*',
	requestLog: 'completed.*',
	gyroscope: 'gyroscope.*',
};
