/**
 * Constants for the Gyroscope dashboard's in-flight requests view.
 *
 * Only `Inflight.js` consumes this module; the Performance Dashboard keeps its
 * own options in `src/overview/constants.js`.
 */

/**
 * Refresh-interval choices for the in-flight view's dropdown.
 *
 * Each `value` is a number of SECONDS. It sets how often `Inflight` samples
 * `gyroscope:view.snapshot()` for rows and `.rps` — the render cadence, not the
 * SSE stream, which pushes every message regardless and carries no interval.
 * `Inflight` multiplies by 1000 for `useRouterTick`.
 *
 * The list doubles as the validation whitelist for the persisted
 * `event-logger-inflight-refresh` localStorage value: a saved number absent
 * here is discarded. It must therefore keep containing every value `Inflight`
 * can select on its own — the `2` default and the 0–9 keyboard map — or the
 * dropdown renders a selection it has no option for.
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
