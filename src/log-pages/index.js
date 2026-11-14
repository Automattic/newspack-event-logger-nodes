/**
 * The log pages' entry point — the `build/log-pages` bundle the Request Log,
 * Error Log and Gyroscope screens each load.
 *
 * Mounts the page the load's data names into `#event-logger-log-page`, the
 * bare div each of the three submenu pages prints
 * (`newspack-event-logger-nodes.php`). Without that container the module does
 * nothing, so loading the bundle elsewhere is harmless. The page's sources,
 * page state and blocks are declared in `./sources`, which the lazy page
 * imports. The substrate enqueues this bundle in the footer, below the
 * container, so the mount runs at module evaluation.
 *
 * Importing the three view nodes for their side effect registers their names
 * before the lazy page loads, so the debug overlay's `make_node RequestLogView`
 * resolves from the first paint. `./styles/base.scss` emits only the page's
 * scoped border-box reset and its own chrome, forwarding the shared tokens
 * and mixins. The surface takes the
 * skin `DashboardShell` applies, whichever one the console last persisted.
 */

import { createRoot, lazy, Suspense } from '@wordpress/element';
import DashboardShell from '@newspack-nodes/shared/components/DashboardShell';
import LoadingFallback from '../components/LoadingFallback';
import { LOG_PAGES, logPage } from './logPageData';
import './nodes/request-log-view-node';
import './nodes/perf-errors-view-node';
import './nodes/gyroscope-view-node';
import './styles/base.scss';

/**
 * The page, code-split into a chunk of its own, so the shell — the skin, the
 * box and the debug overlay — paints under `LoadingFallback` rather than wait
 * on the whole page.
 *
 * @type {import('react').LazyExoticComponent<typeof import('./LogPage').default>}
 */
const LogPage = lazy( () => import( './LogPage' ) );

/**
 * A log page in the shared fixed, full-viewport dashboard shell. `overflowY`
 * is hidden because the log list scrolls itself, and `storageKey` keeps the
 * page's debug overlay layout apart from every sibling's.
 *
 * @param {{page: string}} props The page, as `?edit=` names it.
 * @return {import('react').ReactElement} Rendered component.
 * @testonly Exported for its test; the mount below is the only production caller.
 */
export function LogPageShell( { page } ) {
	const { subtitle, storageKey } = LOG_PAGES[ page ];
	return (
		<DashboardShell
			storageKey={ storageKey }
			subtitle={ subtitle }
			overflowY="hidden"
		>
			{ ( headerControlsSlot ) => (
				<Suspense fallback={ <LoadingFallback /> }>
					<LogPage
						page={ page }
						headerControlsSlot={ headerControlsSlot }
					/>
				</Suspense>
			) }
		</DashboardShell>
	);
}

const mount = document.getElementById( 'event-logger-log-page' );
if ( mount ) {
	createRoot( mount ).render( <LogPageShell page={ logPage().page } /> );
}
