/**
 * Loading fallback — a centered spinner + message, shown while a lazy dashboard
 * chunk resolves. Shared by the dashboard entries (overview, error log).
 */

import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * @param {Object} props         Component props.
 * @param {string} props.message Loading message to display.
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
