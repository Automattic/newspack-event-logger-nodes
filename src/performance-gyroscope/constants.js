/**
 * Shared constants for performance monitoring components.
 */

/**
 * Refresh interval options for dashboard auto-refresh (longer intervals).
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
 * Refresh interval options for inflight real-time view (shorter intervals).
 * Values are numbers in seconds for SSE streaming interval.
 */
export const INFLIGHT_REFRESH_OPTIONS = [
	{ value: 0.1, label: '100ms' },
	{ value: 0.5, label: '500ms' },
	{ value: 1, label: '1s' },
	{ value: 2, label: '2s' },
	{ value: 3, label: '3s' },
	{ value: 5, label: '5s' },
	{ value: 10, label: '10s' },
];
