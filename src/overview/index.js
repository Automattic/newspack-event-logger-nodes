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
import DebugOverlay from '@newspack-nodes/debug-overlay';
import ThemedRoot from '../components/ThemedRoot';
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
 * `ThemedRoot` supplies the console-selected skin tokens and paints the
 * surrounding WP-admin gutters to match. The Gyroscope, Request Log and Error
 * Log pages reach it through `DashboardShell`; this page does not, because that
 * shell is a fixed full-viewport box and Performance flows in WP-admin's own
 * padded column. `DebugOverlay` carries this page's own `storageKey`, which
 * keeps its panel layout separate from every sibling dashboard's.
 *
 * Both wrappers carry paired class names: the `newspack-nodes-` half brings the
 * substrate's shared appearance, and `event-logger-admin-wrap` adds this page's
 * padding and min-height. `dashboard-theme-root.test.js` pins both pairs
 * exactly, so a rename fails a test rather than quietly reshaping the page.
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
		<ThemedRoot>
			<div className="event-logger-admin-wrap newspack-nodes-admin-wrap">
				<h1 className="newspack-dashboard-title">
					{ __(
						'Event Logger - Performance Dashboard',
						'newspack-event-logger-nodes'
					) }
				</h1>

				{ error && (
					<Notice
						status="error"
						isDismissible
						onDismiss={ () => setError( null ) }
					>
						{ error }
					</Notice>
				) }

				<div className="event-logger-admin-app newspack-nodes-admin-app">
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
						<PerformanceDashboard onError={ handleError } />
					</Suspense>
				</div>
				<DebugOverlay storageKey="newspack-nodes:debug:performance" />
			</div>
		</ThemedRoot>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const dashboardContainer = document.getElementById( 'event-logger-admin' );
	if ( dashboardContainer ) {
		createRoot( dashboardContainer ).render( <AdminApp /> );
	}
} );
