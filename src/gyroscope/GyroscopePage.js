/**
 * Gyroscope admin page shell — the chrome around the in-flight request table.
 *
 * The `event-logger-gyroscope` submenu prints an empty mount div; `index.js`
 * roots this component into it. Nothing here owns data: the SSE connection, the
 * in-flight model, and the render cadence all live in `Inflight` and the
 * `gyroscope:*` node graph it mounts.
 *
 * This shell exists to hand that dashboard the whole viewport. WordPress lays
 * admin pages out in a padded, max-width column, which cramps a wide monitoring
 * table; a fixed-position box escapes the column and tracks the admin menu's
 * fold state instead. `RequestStreamPage` is the same shell over the Request
 * Log, and the two are tested together in `src/__tests__/page-wrappers.test.js`.
 */

import Inflight from './Inflight';
import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import ThemedRoot from '../components/ThemedRoot';

/**
 * Gyroscope page: a fixed viewport for the live in-flight request table.
 *
 * The box is pinned below the desktop admin bar (32px) and to the right of the
 * admin menu, whose current width `useAdminMenuWidth` reports; the eased `left`
 * transition keeps the dashboard in step with the menu's fold animation.
 * Vertical overflow scrolls here because the table carries no inner scroller of
 * its own; the Request Log's shell clips, since its log pane scrolls itself.
 *
 * `maxRows` caps the snapshot `Inflight` pulls off `gyroscope:view` on every
 * refresh tick. The overlay's `storageKey` scopes its persisted panel layout to
 * this page; reusing another dashboard's key would share that layout.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function GyroscopePage() {
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
					zIndex: 99, // Below WP admin menu hover (9990+)
					transition: 'left 0.1s ease-in-out',
					margin: 0,
					padding: 0,
					boxSizing: 'border-box',
					overflowX: 'hidden',
					overflowY: 'auto',
				} }
			>
				<Inflight maxRows={ 100 } />
				<DebugOverlay storageKey="newspack-nodes:debug:gyroscope" />
			</div>
		</ThemedRoot>
	);
}
