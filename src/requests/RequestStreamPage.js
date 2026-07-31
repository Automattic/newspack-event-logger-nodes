/**
 * Request Stream Page Component
 *
 * Full-page view for real-time request log streaming.
 */

import RequestStream from './RequestStream';
import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import ThemedRoot from '../components/ThemedRoot';

/**
 * Request Stream page - dedicated view for real-time request log.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function RequestStreamPage() {
	const menuWidth = useAdminMenuWidth();

	return (
		<ThemedRoot>
			<div
				style={ {
					position: 'fixed',
					top: '32px',
					left: `${ menuWidth }px`,
					right: '0',
					bottom: '0',
					zIndex: 99,
					transition: 'left 0.1s ease-in-out',
					margin: 0,
					padding: 0,
					boxSizing: 'border-box',
					overflowX: 'hidden',
					overflowY: 'hidden',
				} }
			>
				<RequestStream maxEntries={ 1000 } />
				<DebugOverlay storageKey="newspack-nodes:debug:request-stream" />
			</div>
		</ThemedRoot>
	);
}
