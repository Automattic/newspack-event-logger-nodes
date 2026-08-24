/**
 * Error Log dashboard entry point.
 *
 * Registers this dashboard's node class (`./nodes/perf-errors-view-node`), then
 * mounts the full-page Error Log view into `#event-logger-errors` once the DOM
 * is ready. Nothing here paints: the surface takes its colors from the skin the
 * shared `DashboardShell` applies, whichever one the console last persisted.
 *
 * esbuild builds it as its own bundle, separate from the performance overview
 * (`src/overview`). The error log wears the same streaming chrome as the request
 * log but tails the `errors.*` partitions, so it belongs to neither tree.
 */

import { createRoot, lazy, Suspense } from '@wordpress/element';
import DashboardShell from '../components/DashboardShell';
import LoadingFallback from '../components/LoadingFallback';
import './nodes/perf-errors-view-node';
import './styles/base.scss';

// Code-split: the shell paints under LoadingFallback until this chunk lands.
const ErrorLog = lazy( () => import( './ErrorLog' ) );

/**
 * Error Log page wrapper — the shared fixed, full-viewport dashboard shell.
 *
 * Both scroll axes stay hidden because the virtualized log body inside owns the
 * scrolling, and the overlay's `storageKey` keeps this page's panel layout
 * separate from every sibling dashboard's.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export function ErrorLogPage() {
	return (
		<DashboardShell
			storageKey="newspack-nodes:debug:error-log"
			overflowY="hidden"
		>
			<Suspense fallback={ <LoadingFallback /> }>
				<ErrorLog />
			</Suspense>
		</DashboardShell>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const errorsContainer = document.getElementById( 'event-logger-errors' );
	if ( errorsContainer ) {
		createRoot( errorsContainer ).render( <ErrorLogPage /> );
	}
} );
