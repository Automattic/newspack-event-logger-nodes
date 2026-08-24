/**
 * Request Stream Page Component
 *
 * Full-page view for real-time request log streaming.
 */

import RequestStream from './RequestStream';
import DashboardShell from '../components/DashboardShell';

/**
 * Request Stream page - dedicated view for real-time request log.
 *
 * The shell clips both axes: the virtualized log pane inside owns the scrolling.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function RequestStreamPage() {
	return (
		<DashboardShell
			storageKey="newspack-nodes:debug:request-stream"
			overflowY="hidden"
		>
			<RequestStream maxEntries={ 1000 } />
		</DashboardShell>
	);
}
