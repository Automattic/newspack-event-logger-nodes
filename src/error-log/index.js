/**
 * Error Log dashboard entry point — the `build/error-log` bundle.
 *
 * Mounts the full-page Error Log view into `#event-logger-errors`, the bare div
 * the plugin's "Event Logger → Errors" submenu page prints
 * (`newspack-event-logger-nodes.php`). Without that container the module does
 * nothing, so loading the bundle elsewhere is harmless. The substrate enqueues
 * the bundle in the footer, below that markup, so the listener at the foot of
 * this file registers before `DOMContentLoaded` fires.
 *
 * Importing `./nodes/perf-errors-view-node` for its side effect merges
 * `PerfErrorsView` into the runtime's `includeNodes` map, the name surface the
 * console palette and `make_node` read. The graph does not depend on that
 * merge — `useErrorLogGraph` reaches the same class through the module's
 * `views` export — so the side-effect import states the dependency at the entry
 * point rather than resting on the page tree's import chain.
 *
 * `./styles/base.scss` emits only this page's scoped border-box reset, and
 * forwards the shared tokens and mixins that `error-log.scss` draws the page
 * with. No color is declared here at all — the surface takes the skin
 * `DashboardShell` applies, whichever one the console last persisted.
 *
 * esbuild builds this as its own bundle; the overview, gyroscope, requests,
 * settings and current-request trees are separate entries.
 */

import { createRoot, lazy, Suspense } from '@wordpress/element';
import DashboardShell from '../components/DashboardShell';
import LoadingFallback from '../components/LoadingFallback';
import './nodes/perf-errors-view-node';
import './styles/base.scss';

/**
 * The Error Log view, code-split into a chunk of its own.
 *
 * The split is what lets the shell — the skin, the box and the debug overlay —
 * paint under `LoadingFallback` rather than wait on the whole view.
 *
 * @type {import('react').LazyExoticComponent}
 */
const ErrorLog = lazy( () => import( './ErrorLog' ) );

/**
 * Error Log page: the shared fixed, full-viewport dashboard shell around the
 * lazily loaded view.
 *
 * `overflowY` is hidden because the virtualized log body inside owns the
 * scrolling, and a shell scroller beside it is the second scrollbar
 * `DashboardShell` makes every caller state its side of. `storageKey` scopes
 * the debug overlay's persisted panel layout to this page, so no sibling
 * dashboard shares it.
 *
 * @return {import('react').ReactElement} Rendered component.
 * @testonly Exported for index.test.js; the `DOMContentLoaded` listener below
 *           is the only production caller.
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
