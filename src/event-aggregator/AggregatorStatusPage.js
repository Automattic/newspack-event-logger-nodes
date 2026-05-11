/**
 * Aggregator Status Page Component
 *
 * Full-page view for aggregator server monitoring.
 * Similar layout to Gyroscope page.
 */

import AggregatorStatus from './AggregatorStatus';
import useAdminMenuWidth from '../shared/hooks/useAdminMenuWidth';

/**
 * Aggregator Status page - dedicated view for monitoring remote servers.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function AggregatorStatusPage() {
	const menuWidth = useAdminMenuWidth();

	return (
		<div
			style={ {
				position: 'fixed',
				top: '32px',
				left: `${ menuWidth }px`,
				right: '0',
				bottom: '0',
				zIndex: 99,
				background: '#1e1e1e',
				transition: 'left 0.1s ease-in-out',
				margin: 0,
				padding: 0,
				boxSizing: 'border-box',
				overflowX: 'hidden',
				overflowY: 'auto',
			} }
		>
			<AggregatorStatus />
		</div>
	);
}
