/**
 * Shared constants for performance monitoring components.
 */

import { __ } from '@wordpress/i18n';

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
 * Breakdown options for the aggregate time chart dropdown.
 */
export const CHART_BREAKDOWN_OPTIONS = [
	{
		label: __( 'Status Codes', 'newspack-event-logger-nodes' ),
		value: 'status',
	},
	{ label: __( 'Method', 'newspack-event-logger-nodes' ), value: 'method' },
	{ label: __( 'Server', 'newspack-event-logger-nodes' ), value: 'server' },
	{
		label: __( 'Country', 'newspack-event-logger-nodes' ),
		value: 'country',
	},
	{ label: __( 'From', 'newspack-event-logger-nodes' ), value: 'from' },
	{ label: __( 'User Agent', 'newspack-event-logger-nodes' ), value: 'ua' },
	{ label: __( 'JA4 Hash', 'newspack-event-logger-nodes' ), value: 'ja4' },
];
