/**
 * Loading fallback: a centered spinner above a message, painted while a
 * `lazy()` module resolves. Every `<Suspense>` boundary in the plugin uses it —
 * the overview and error-log page shells, and the flame graph inside
 * `RequestTrace` — so pending work always looks the same.
 *
 * `newspack-nodes-performance-loading` is the canonical loading-container
 * class: the substrate owns its appearance in
 * `@newspack-nodes/shared/styles/_components.scss`, scoped under
 * `.newspack-nodes-ui`, and this component declares no styling of its own.
 * Naming the shared class is also what lets a caller reshape the fallback from
 * outside — `request-trace.scss` flattens the full-height centering for the
 * inline flame-graph slot. `src/__tests__/appearance-ownership.test.js` pins
 * both halves: one assertion fails if this file stops naming that class,
 * another if any source under `src/` carries an `event-logger-` prefixed copy
 * of it. That second scan is textual, so no file may spell the legacy name,
 * comments included.
 */

import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * @param {Object} props           Component props.
 * @param {string} [props.message] Translated loading message; defaults to "Loading…".
 * @return {import('react').ReactElement} Rendered component.
 */
export default function LoadingFallback( {
	message = __( 'Loading…', 'newspack-event-logger-nodes' ),
} ) {
	return (
		<div className="newspack-nodes-performance-loading">
			<Spinner />
			<p>{ message }</p>
		</div>
	);
}
