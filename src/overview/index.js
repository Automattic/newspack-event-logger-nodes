/**
 * Performance Dashboard entry point — the `build/overview` bundle.
 *
 * Registers this dashboard's node classes (`./nodes/register`), then mounts
 * `AdminApp` into `#event-logger-admin`, the bare div the plugin's top-level
 * "Event Logger → Performance" menu page prints. Without that container the
 * module does nothing, so loading the bundle elsewhere is harmless.
 *
 * esbuild builds this as its own bundle; the error-log, gyroscope, requests,
 * settings, and current-request trees are separate entries. `PerformanceDashboard`
 * splits again at runtime, so the page chrome paints under `LoadingFallback`
 * while that chunk resolves.
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

// Code-split: the shell paints under LoadingFallback until this chunk lands.
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
 * surrounding WP-admin gutters to match. `DebugOverlay` carries this page's own
 * `storageKey`, which keeps its panel layout separate from every sibling
 * dashboard's.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export function AdminApp() {
	const [ error, setError ] = useState( null );

	/**
	 * Raise a dashboard failure into the page's error notice.
	 *
	 * @param {Error} err Error object; a blank message falls back to a generic string.
	 */
	const handleError = ( err ) => {
		setError(
			err.message ||
				__( 'An error occurred', 'newspack-event-logger-nodes' )
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

// Mount once the DOM is ready; no container means no render, and no error.
document.addEventListener( 'DOMContentLoaded', () => {
	const dashboardContainer = document.getElementById( 'event-logger-admin' );
	if ( dashboardContainer ) {
		createRoot( dashboardContainer ).render( <AdminApp /> );
	}
} );
