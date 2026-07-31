/**
 * Performance Dashboards Entry Point
 *
 * Performance Dashboard page only. Settings UI moved to newspack-performance-logger.
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

// Lazy load heavy performance components for code splitting.
const PerformanceDashboard = lazy( () => import( './PerformanceDashboard' ) );

import './styles/base.scss';

/**
 * Admin App component for Performance Dashboard.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export function AdminApp() {
	const [ error, setError ] = useState( null );

	/**
	 * Handle errors.
	 *
	 * @param {Error} err Error object.
	 */
	const handleError = ( err ) => {
		setError(
			err.message ||
				__( 'An error occurred', 'newspack-event-logger-nodes' )
		);
	};

	// Auto-clear errors after 5 seconds.
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

// Mount the dashboard when DOM is ready (Error Log is its own bundle now).
document.addEventListener( 'DOMContentLoaded', () => {
	const dashboardContainer = document.getElementById( 'event-logger-admin' );
	if ( dashboardContainer ) {
		createRoot( dashboardContainer ).render( <AdminApp /> );
	}
} );
