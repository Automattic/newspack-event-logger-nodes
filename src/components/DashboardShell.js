import {
	useCallback,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth';
import { useContainerRefit } from '@newspack-nodes/shared/hooks/useContainerRefit';
import Header from '@newspack-nodes/shared/components/Header';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import ThemedRoot from './ThemedRoot';
import { ASK_PAGE_ATTR } from '@newspack-nodes/shared/hooks/useAskPicker';

/**
 * Fixed full-viewport chrome for a standalone dashboard page — the skin, the
 * box, the header and the debug overlay that the Performance Overview,
 * Gyroscope, Request Log and Error Log dashboards all share.
 *
 * The header is the substrate's shared one, so a dashboard here reads as the
 * same product as the station: one `NEWSPACK::NODES` wordmark, and a subtitle
 * naming which of these four surfaces you are on.
 *
 * It also owns the ONE controls slot, exactly as the station does, and hands
 * it to `children` — a function, because the state behind those controls lives
 * deep inside each dashboard rather than up here. A page portals its toolbar
 * in with `HeaderSlot`, so the toolbar sits beside the name of the page it
 * drives instead of in a second bar under it.
 *
 * WordPress lays admin pages out in a padded, max-width column, which cramps a
 * wide monitoring surface. This box is positioned rather than flowed: `top`
 * clears the desktop admin bar and `left` tracks the admin menu's live width,
 * so folding the menu slides the page instead of reflowing it, and the eased
 * transition keeps the two in step.
 *
 * It is a flex COLUMN, as the station's box is. A block box would leave a
 * dashboard root's `height: 100%` resolving against the whole box while the
 * header pushed it down by the header's height — clipped where the caller
 * clips, a permanent scrollbar where it scrolls. A root grows into what the
 * header leaves instead.
 *
 * The box paints its own backdrop, through the substrate's shared
 * `newspack-nodes-page-surface` class. It is positioned rather than flowed, so
 * the admin page keeps its place in the document underneath — an admin notice
 * included, which WordPress relocates to just above the mount. A transparent
 * box shows it straight through; the station paints through the same class.
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
 * @param {Object}                                        props                 Component props.
 * @param {string}                                        props.storageKey      Debug-overlay key; scopes the persisted panel layout to this page, so no two dashboards may share one.
 * @param {string}                                        props.subtitle        Names this dashboard in the header, beside the one shared wordmark.
 * @param {import('react').CSSProperties['overflowY']}    props.overflowY       Vertical overflow for the shell box.
 * @param {(slot: ?Element) => import('react').ReactNode} props.children        Called with the header's controls slot — null until it mounts — and returns the dashboard root(s) to frame.
 * @param {string}                                        [props.askDescriptor] The `?` picker descriptor for the page itself, on the box whose outline traces it. A page with no brief of its own passes none and stays unaskable.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function DashboardShell( {
	storageKey,
	subtitle,
	overflowY,
	askDescriptor = '',
	children,
} ) {
	const menuWidth = useAdminMenuWidth();
	// @longform One source for the box: the style below positions it, and the
	// custom properties hand the same numbers to the `?` picker's ring, which
	// is an overlay because an outline here is painted over by the children —
	// measured, not assumed.
	const pageTop = '32px';
	const pageLeft = `${ menuWidth }px`;
	const surfaceRef = useRef( null );
	const [ gutter, setGutter ] = useState( 0 );

	// The scrollbar's own width, measured; only a ringed page reads it.
	const measureGutter = useCallback( () => {
		const el = surfaceRef.current;
		if ( el ) {
			setGutter( el.offsetWidth - el.clientWidth );
		}
	}, [] );

	useLayoutEffect( () => {
		if ( askDescriptor ) {
			measureGutter();
		}
	}, [ askDescriptor, measureGutter ] );

	// The bar comes and goes with the content; 0ms measures in that frame.
	useContainerRefit(
		() => ( askDescriptor ? surfaceRef.current : null ),
		measureGutter,
		[ askDescriptor ],
		0
	);
	// Null until the header's slot div mounts; HeaderSlot withholds until then.
	const [ headerControlsSlot, setHeaderControlsSlot ] = useState( null );

	return (
		<ThemedRoot>
			{ /* @longform The page's own ask target is THIS box: it is fixed at
			     the dashboard's visible rectangle, so its outline traces what
			     the brief answers for. The scroller inside is content-height,
			     and an outline round that has its edges off screen. */ }
			<div
				ref={ surfaceRef }
				className="newspack-nodes-page-surface"
				{ ...( askDescriptor
					? {
							'data-ask': askDescriptor,
							[ ASK_PAGE_ATTR ]: '',
					  }
					: {} ) }
				style={ {
					position: 'fixed',
					top: pageTop,
					left: pageLeft,
					right: '0',
					bottom: '0',
					'--nodes-page-top': pageTop,
					'--nodes-page-left': pageLeft,
					'--nodes-page-gutter': `${ gutter }px`,
					zIndex: 99, // Below WP admin menu hover (9990+)
					transition: 'left 0.1s ease-in-out',
					margin: 0,
					padding: 0,
					boxSizing: 'border-box',
					display: 'flex',
					flexDirection: 'column',
					overflowX: 'hidden',
					overflowY,
				} }
			>
				<Header
					subtitle={ subtitle }
					controlsSlotRef={ setHeaderControlsSlot }
				/>
				{ children( headerControlsSlot ) }
				<DebugOverlay storageKey={ storageKey } />
			</div>
		</ThemedRoot>
	);
}
