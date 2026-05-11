/**
 * Gyroscope Page Component
 *
 * Full-page view for live In-Flight Requests monitoring.
 * Similar to Tachikoma's Gyroscope view.
 */

import Inflight from './Inflight';
import useAdminMenuWidth from '../shared/hooks/useAdminMenuWidth';

/**
 * Gyroscope page - dedicated view for real-time request monitoring.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function GyroscopePage() {
	const menuWidth = useAdminMenuWidth();

	return (
		<div
			style={ {
				position: 'fixed',
				top: '32px',
				left: `${ menuWidth }px`,
				right: '0',
				bottom: '0',
				zIndex: 99, // Below WP admin menu hover (9990+)
				background: '#1e1e1e',
				transition: 'left 0.1s ease-in-out',
				margin: 0,
				padding: 0,
				boxSizing: 'border-box',
				overflowX: 'hidden',
				overflowY: 'auto',
			} }
		>
			<Inflight maxRows={ 100 } />
		</div>
	);
}
