/**
 * Performance Dashboard entry point — the `build/overview` bundle.
 *
 * Registers this dashboard's node classes (`./nodes/register`), then mounts
 * `AdminApp` into `#event-logger-admin`, the bare div the plugin's top-level
 * "Event Logger → Performance" menu page prints. Without that container the
 * module does nothing, so loading the bundle elsewhere is harmless.
 *
 * esbuild builds this as its own bundle; the error-log, gyroscope, requests,
 * settings and current-request trees are separate entries. The
 * `styles/base.scss` import below is what makes the kit emit
 * `build/overview/index.css`, the stylesheet `enqueue_react_page()` pairs with
 * the script; without it the page loses its scoped reset and overview layout.
 */

import {
	createRoot,
	useState,
	useEffect,
	lazy,
	Suspense,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import DashboardShell from '../components/DashboardShell';
import LoadingFallback from '../components/LoadingFallback';
import './nodes/register';

/**
 * The dashboard body, code-split into a chunk of its own.
 *
 * The split is what lets the page chrome — heading, error notice and debug
 * overlay — paint under `LoadingFallback` rather than wait on the whole
 * dashboard.
 */
const PerformanceDashboard = lazy( () => import( './PerformanceDashboard' ) );

import './styles/base.scss';

/**
 * Performance Dashboard page chrome: heading, error notice, the Suspense
 * boundary around the lazy dashboard, and the debug overlay.
 *
 * `PerformanceDashboard` renders no failure banner of its own; it reports
 * upward through `onError`. This component holds that message and clears it
 * five seconds later, so a transient poll failure leaves no stuck notice. The
 * reader can also dismiss it.
 *
 * `DashboardShell` supplies the skin, the fixed full-viewport box and the
 * debug overlay, exactly as the Gyroscope, Request Log and Error Log pages do.
 * The box is what keeps an admin notice off the page: WordPress relocates
 * every notice to just above the mount, and only a painted, positioned box
 * covers it. `storageKey` keeps this page's overlay layout separate from every
 * sibling's, and `overflowY` scrolls here because the dashboard owns no inner
 * scroller of its own.
 *
 * @return {import('react').ReactElement} Rendered component.
 * @testonly Exported for index.test.js and dashboard-theme-root.test.js; the
 *           mount below is the only production caller.
 */
export function AdminApp() {
	const [ error, setError ] = useState( null );

	/**
	 * Raise a dashboard failure into the page's error notice.
	 *
	 * @param {string} reason Why the ask failed, as `useAsk` reports it: the
	 *                        reply's `error` string, or the message it builds
	 *                        for a reply that carried no brief. An empty
	 *                        reason renders the generic string instead.
	 */
	const handleError = ( reason ) => {
		setError(
			reason || __( 'An error occurred', 'newspack-event-logger-nodes' )
		);
	};

	useEffect( () => {
		if ( error ) {
			const timer = setTimeout( () => {
				setError( null );
			}, 5000 );
			return () => clearTimeout( timer );
		}
	}, [ error ] );

	return (
		<DashboardShell
			storageKey="newspack-nodes:debug:performance"
			// The page's own brief; every other target here is a row inside.
			askDescriptor="overview:site"
			subtitle={ __(
				'Performance Overview',
				'newspack-event-logger-nodes'
			) }
			overflowY="auto"
		>
			{ ( headerControlsSlot ) => (
				<div>
					{ error && (
						<Notice
							status="error"
							isDismissible
							onDismiss={ () => setError( null ) }
						>
							{ error }
						</Notice>
					) }

					<div>
						<Suspense
							fallback={
								<LoadingFallback
									message={ __(
										'Loading dashboard…',
										'newspack-event-logger-nodes'
									) }
								/>
							}
						>
							<PerformanceDashboard
								onError={ handleError }
								headerControlsSlot={ headerControlsSlot }
							/>
						</Suspense>
					</div>
				</div>
			) }
		</DashboardShell>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const dashboardContainer = document.getElementById( 'event-logger-admin' );
	if ( dashboardContainer ) {
		createRoot( dashboardContainer ).render( <AdminApp /> );
	}
} );
