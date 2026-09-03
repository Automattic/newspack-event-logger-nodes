import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import ThemedRoot from './ThemedRoot';

/**
 * Fixed full-viewport chrome for a standalone dashboard page — the skin, the
 * box and the debug overlay that the Gyroscope, Request Log and Error Log
 * dashboards all share.
 *
 * WordPress lays admin pages out in a padded, max-width column, which cramps a
 * wide monitoring surface. This box is positioned rather than flowed: `top`
 * clears the desktop admin bar and `left` tracks the admin menu's live width,
 * so folding the menu slides the page instead of reflowing it, and the eased
 * transition keeps the two in step.
 *
 * `ThemedRoot` wraps the box rather than sitting inside it, so the overlay
 * takes the page's skin: `DebugOverlay` renders a skin provider of its own
 * only when it finds no `.newspack-nodes-ui` ancestor, and this wrapper is
 * that ancestor. Both its launcher and its panel are `position: fixed`, so
 * mounting the overlay inside a box that may clip costs it nothing.
 *
 * `overflowY` is required, not defaulted: a dashboard whose body owns its own
 * scroller must clip here, and one without an inner scroller must scroll here.
 * Getting it wrong is a double scrollbar or a truncated page, so each caller
 * states which it is. `overflowX` is not a caller's choice — the box always
 * clips it, so anything wider than the viewport scrolls in its own container.
 *
 * @param {Object}                                     props            Component props.
 * @param {string}                                     props.storageKey Debug-overlay key; scopes the persisted panel layout to this page, so no two dashboards may share one.
 * @param {import('react').CSSProperties['overflowY']} props.overflowY  Vertical overflow for the shell box.
 * @param {import('react').ReactNode}                  props.children   Dashboard root(s) to frame.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function DashboardShell( { storageKey, overflowY, children } ) {
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
					overflowY,
				} }
			>
				{ children }
				<DebugOverlay storageKey={ storageKey } />
			</div>
		</ThemedRoot>
	);
}
