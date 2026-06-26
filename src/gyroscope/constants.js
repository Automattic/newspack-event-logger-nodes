/**
 * Shared constants for performance monitoring components.
 */

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
