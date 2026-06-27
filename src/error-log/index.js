/**
 * Error Log Dashboard entry point.
 *
 * Mounts the full-page dark Error Log view on its own admin page
 * (`#event-logger-errors`). Split out of the overview bundle (where it rode
 * along as a second React root) so the perf dashboard and the error log ship as
 * independent bundles — the error log is structurally the request log for
 * errors, not part of the performance overview.
 */

import { createRoot, lazy, Suspense } from '@wordpress/element';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth';
import ThemedRoot from '../components/ThemedRoot';
import LoadingFallback from '../components/LoadingFallback';
import './nodes/register';
import './styles/base.scss';

const ErrorLog = lazy( () => import( './ErrorLog' ) );

/**
 * Error Log page wrapper — full-page dark layout.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export function ErrorLogPage() {
	const menuWidth = useAdminMenuWidth();

	return (
		<ThemedRoot>
			<div
				className="newspack-nodes-theme"
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

// Mount the error log when DOM is ready.
document.addEventListener( 'DOMContentLoaded', () => {
	const errorsContainer = document.getElementById( 'event-logger-errors' );
	if ( errorsContainer ) {
		createRoot( errorsContainer ).render( <ErrorLogPage /> );
	}
} );
