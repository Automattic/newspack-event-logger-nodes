/**
 * Shared constants for performance monitoring components.
 */

/**
 * Refresh interval options for dashboard auto-refresh.
 * Values are strings in milliseconds for SelectControl compatibility.
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
 * Metric options for the aggregate time chart dropdown.
 */
export const CHART_METRIC_OPTIONS = [
	{ label: 'Request Volume', value: 'volume' },
	{ label: 'Avg Response Time', value: 'avg' },
	{ label: 'Cumulative Response Time', value: 'cumulative' },
	{ label: 'Avg Peak Memory', value: 'memory' },
];

/**
 * Breakdown options for the aggregate time chart dropdown.
 */
export const CHART_BREAKDOWN_OPTIONS = [
	{ label: 'Status Codes', value: 'status' },
	{ label: 'Method', value: 'method' },
	{ label: 'Server', value: 'server' },
	{ label: 'Country', value: 'country' },
	{ label: 'From', value: 'from' },
	{ label: 'User Agent', value: 'ua' },
	{ label: 'JA4 Hash', value: 'ja4' },
];
