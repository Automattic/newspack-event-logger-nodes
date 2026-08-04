/**
 * Loading fallback — a centered spinner + message, shown while a lazy dashboard
 * chunk resolves. The overview and error-log entries both pass it as their
 * `<Suspense fallback>`.
 *
 * `newspack-nodes-performance-loading` is the canonical loading-container class:
 * the substrate owns its appearance in `@newspack-nodes/shared/styles/_components.scss`,
 * scoped under `.newspack-nodes-ui`. This component declares no styling of its
 * own, and `src/__tests__/appearance-ownership.test.js` pins that contract — add
 * a local class here and the test fails.
 */

import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * @param {Object} props           Component props.
 * @param {string} [props.message] Loading message; defaults to "Loading…".
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
