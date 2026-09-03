/**
 * The dashboards' retention window, owned here because it is this plugin's fact.
 *
 * The substrate's `buildTimeSlots()` takes the window as an argument and knows
 * no host's retention, so the module that reads it belongs beside the plugin
 * that localizes it. `newspack-event-logger-nodes.php` prints
 * `window.eventLoggerDashboards` in a `before` inline script, which puts the
 * value in place ahead of the bundle; the charts import this constant rather
 * than each reaching for the global.
 */

import { DEFAULT_RETENTION_SECONDS } from '@newspack-nodes/shared/hooks/useTimeChart';

/**
 * The field this module reads off `window.eventLoggerDashboards`. The global
 * carries the REST root and the nonce as well; neither belongs to the axis.
 *
 * @typedef {Object} EventLoggerDashboards
 * @property {number} [retentionSeconds] Log retention window, in seconds.
 */

/**
 * Seconds of history the time axes span, from `Config::stats_retention_seconds()`.
 *
 * Module evaluation freezes the value, so a settings change reaches the charts
 * on the next page load. Every falsy reading takes the substrate's 24-hour
 * default: an absent global, an absent key and a non-numeric one all give
 * `NaN`, and a literal 0 would ask the charts for an axis of no buckets.
 *
 * @type {number}
 */
export const RETENTION_SECONDS =
	Number(
		/** @type {Window & { eventLoggerDashboards?: EventLoggerDashboards }} */ (
			window
		).eventLoggerDashboards?.retentionSeconds
	) || DEFAULT_RETENTION_SECONDS;
