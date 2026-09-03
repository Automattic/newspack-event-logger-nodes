/**
 * Request Log admin page — the chrome around the completed-request stream.
 *
 * The `event-logger-requests` submenu prints an empty `#event-logger-stream`
 * div; `index.js` roots this component into it. Nothing here fetches or renders
 * a row: the EventSource, the ring and the render cadence all live in
 * `RequestStream` and the `requestlog:*` node graph it mounts.
 */

import RequestStream from './RequestStream';
import DashboardShell from '../components/DashboardShell';

/**
 * Request Log page: a fixed viewport for the live completed-request stream.
 *
 * Vertical overflow clips here because `RequestStream`'s virtualized row list
 * owns the scrolling; `GyroscopePage` passes `auto` instead, since its table
 * carries no inner scroller. `maxEntries` sizes the `requestlog:view` ring:
 * 1000 is that view node's own default, stated here because `RequestStream`'s
 * prop default of 500 would otherwise reach it. `page-wrappers.test.js` pins
 * both pages against their shells.
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
