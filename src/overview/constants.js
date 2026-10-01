/**
 * The Performance Dashboard's dropdown vocabularies.
 *
 * `PerformanceDashboard` reads the refresh cadences and the breakdown default,
 * `OverviewSection` the refresh cadences and the breakdown dimensions, and
 * `BreakdownControls` the metrics and the dimensions; the Gyroscope in-flight
 * view keeps its own list in `src/gyroscope/constants.js`. Each entry is a
 * `SelectControl` option, so the `value` is the token the rest of the dashboard
 * — and, for breakdowns, the server — matches on. The metric and dimension
 * labels are translated; the refresh cadences' unit abbreviations are not.
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
 * Every metric reads the same three per-bucket totals — `c`, `s` and `m`, the
 * request count, the summed milliseconds and the summed peak megabytes — and
 * the `value` decides how `AggregateTimeChart` reduces them: `volume` plots the
 * count, `avg` the mean millisecond, `cumulative` the summed seconds, `memory`
 * the mean megabyte.
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
 * Dimension choices for the aggregate time chart's "Breakdown" dropdown.
 *
 * Every `value` is a server-side dimension key, so this list must stay in step
 * with `Performance_CI_Node::DIMENSIONS` (which rejects anything else) and with
 * `Flame_Builder_Node::DIM_FIELDS` (which decides what gets accumulated in the
 * first place). A value in only one of the three yields an empty breakdown.
 *
 * Each caller hands `BreakdownControls` the list it offers. `OverviewSection`
 * offers this one while `canBreakDownByServer` holds and
 * `SCOPED_BREAKDOWN_OPTIONS` once it does not — a server filter is on, or a
 * landed server list names fewer than two; `PerformanceDashboard` computes
 * that flag, passes it down, and resolves the active dimension against the
 * same flag, so a list that has not landed yet keeps the default. The URL
 * modal offers the scoped list always.
 */
export const CHART_BREAKDOWN_OPTIONS = [
	{ label: __( 'Server', 'newspack-event-logger-nodes' ), value: 'server' },
	{
		label: __( 'Status Codes', 'newspack-event-logger-nodes' ),
		value: 'status',
	},
	{ label: __( 'Method', 'newspack-event-logger-nodes' ), value: 'method' },
	{
		label: __( 'Country', 'newspack-event-logger-nodes' ),
		value: 'country',
	},
	{ label: __( 'From', 'newspack-event-logger-nodes' ), value: 'from' },
	{ label: __( 'User Agent', 'newspack-event-logger-nodes' ), value: 'ua' },
	{ label: __( 'JA4 Hash', 'newspack-event-logger-nodes' ), value: 'ja4' },
];

/**
 * The dimensions that still split INSIDE one server's scope: the list above
 * minus `server`. `server_name` is the site's host, so a chart already scoped
 * to one server — a server filter, or one URL, whose hash is over the whole
 * URL and therefore its host — would draw one line under that axis. The
 * per-server aggregate skips the axis on the same reasoning.
 */
export const SCOPED_BREAKDOWN_OPTIONS = CHART_BREAKDOWN_OPTIONS.filter(
	( option ) => option.value !== 'server'
);

/**
 * What the aggregate chart breaks down by until someone chooses otherwise.
 *
 * `PerformanceDashboard` seeds its `chartBreakdown` state with this and
 * resolves `server` to `status` whenever `canBreakDownByServer` is false. That
 * fallback is DERIVED, never written back over the state, so a hub whose second
 * server reports late gets the axis back rather than staying on `status` for
 * the session.
 */
export const DEFAULT_CHART_BREAKDOWN = 'server';
