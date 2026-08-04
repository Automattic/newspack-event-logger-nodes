/**
 * Error Log dashboard entry point.
 *
 * Registers this dashboard's node classes (`./nodes/register`), then mounts the
 * full-page Error Log view into `#event-logger-errors` once the DOM is ready.
 * Nothing here paints: the surface takes its colors from the skin `ThemedRoot`
 * applies, whichever one the console last persisted.
 *
 * esbuild builds it as its own bundle, separate from the performance overview
 * (`src/overview`). The error log wears the same streaming chrome as the request
 * log but tails the `errors.*` partitions, so it belongs to neither tree.
 */

import { createRoot, lazy, Suspense } from '@wordpress/element';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth';
import ThemedRoot from '../components/ThemedRoot';
import LoadingFallback from '../components/LoadingFallback';
import './nodes/register';
import './styles/base.scss';

// Code-split: the shell paints under LoadingFallback until this chunk lands.
const ErrorLog = lazy( () => import( './ErrorLog' ) );

/**
 * Error Log page wrapper — a fixed, full-viewport shell.
 *
 * The shell is positioned, not flowed: `top: 32px` clears the desktop WP admin
 * bar, and `left` tracks the admin menu's live width, so folding the menu slides
 * the page instead of reflowing it. Both scroll axes stay hidden because the
 * virtualized log body inside owns the scrolling.
 *
 * The debug overlay carries this page's own `storageKey`, which keeps its panel
 * layout separate from every sibling dashboard's.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export function ErrorLogPage() {
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
				<Suspense fallback={ <LoadingFallback /> }>
					<ErrorLog />
				</Suspense>
				<DebugOverlay storageKey="newspack-nodes:debug:error-log" />
			</div>
		</ThemedRoot>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const errorsContainer = document.getElementById( 'event-logger-errors' );
	if ( errorsContainer ) {
		createRoot( errorsContainer ).render( <ErrorLogPage /> );
	}
} );
