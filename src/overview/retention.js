/**
 * The dashboards' retention window, owned here because it is this plugin's fact.
 *
 * `newspack-event-logger-nodes.php` localizes `window.eventLoggerDashboards`
 * before the bundle runs; the substrate's `useTimeChart` used to read that
 * global itself, which coupled the shared module to one consumer and gave every
 * other consumer the fallback in silence.
 */

import { DEFAULT_RETENTION_SECONDS } from '@newspack-nodes/shared/hooks/useTimeChart';

/**
 * Settings the plugin localizes onto `window` before the bundle runs.
 *
 * @typedef {Object} EventLoggerDashboards
 * @property {number} [retentionSeconds] Log retention window, in seconds.
 */

export const RETENTION_SECONDS =
	Number(
		/** @type {Window & { eventLoggerDashboards?: EventLoggerDashboards }} */ (
			window
		).eventLoggerDashboards?.retentionSeconds
	) || DEFAULT_RETENTION_SECONDS;
