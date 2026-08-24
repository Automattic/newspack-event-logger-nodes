/**
 * Gyroscope admin page shell — the chrome around the in-flight request table.
 *
 * The `event-logger-gyroscope` submenu prints an empty mount div; `index.js`
 * roots this component into it. Nothing here owns data: the SSE connection, the
 * in-flight model, and the render cadence all live in `Inflight` and the
 * `gyroscope:*` node graph it mounts.
 *
 * `DashboardShell` supplies the fixed full-viewport box; this page only says
 * what goes in it. `RequestStreamPage` is the same shell over the Request Log,
 * and the two are tested together in `src/__tests__/page-wrappers.test.js`.
 */

import Inflight from './Inflight';
import DashboardShell from '../components/DashboardShell';

/**
 * Gyroscope page: a fixed viewport for the live in-flight request table.
 *
 * Vertical overflow scrolls here because the table carries no inner scroller of
 * its own; the Request Log's shell clips, since its log pane scrolls itself.
 * `maxRows` caps the snapshot `Inflight` pulls off `gyroscope:view` on every
 * refresh tick.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function GyroscopePage() {
	return (
		<DashboardShell
			storageKey="newspack-nodes:debug:gyroscope"
			overflowY="auto"
		>
			<Inflight maxRows={ 100 } />
		</DashboardShell>
	);
}
