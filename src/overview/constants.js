/**
 * Dropdown option lists for the Performance Dashboard.
 *
 * `PerformanceDashboard`, `OverviewSection`, and `UrlDetailView` share these
 * three lists; the Gyroscope in-flight view keeps its own in
 * `src/gyroscope/constants.js`. Each entry is a `SelectControl` option, so the
 * `label` is translated and the `value` is the token the rest of the dashboard
 * — and, for breakdowns, the server — matches on.
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
 * discards a saved string absent here and falls back to `'15000'`. Dropping
 * that entry would therefore leave the dashboard defaulting to a value the
 * dropdown cannot render.
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
 * The `value` drives both the shape and the units `AggregateTimeChart` draws:
 * `avg` and `memory` render as lines, `volume` and `cumulative` as stacked
 * bars, and `memory` reads the `peak_mb` field where the others read
 * `duration_ms`. `PerformanceDashboard` and `UrlDetailView` each default their
 * own state to `'volume'` — the list order does not set the default.
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
 * The full list. `PerformanceDashboard` is the only consumer that narrows it:
 * it offers `server` while `canBreakDownByServer` holds — no server filter, and
 * two or more servers known — and resolves the active dimension against the
 * same flag, so a reply that has not landed yet keeps the default. The URL
 * modal passes no `breakdownOptions` and therefore offers every entry.
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
 * What the aggregate chart breaks down by until someone chooses otherwise.
 *
 * `PerformanceDashboard` resolves it to `status` whenever `canBreakDownByServer`
 * is false. That fallback is DERIVED, never written back over this default, so
 * a hub whose second server reports late gets the axis back rather than staying
 * on `status` for the session.
 */
export const DEFAULT_CHART_BREAKDOWN = 'server';
