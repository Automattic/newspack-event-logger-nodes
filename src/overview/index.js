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
import { Notice, Spinner } from '@wordpress/components';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import ThemedRoot from '../components/ThemedRoot';
import './nodes/register';

// Lazy load heavy performance components for code splitting.
const PerformanceDashboard = lazy( () => import( './PerformanceDashboard' ) );

import './styles/base.scss';

/**
 * Loading fallback component.
 *
 * @param {Object} props         Component props.
 * @param {string} props.message Loading message to display.
 * @return {import('react').ReactElement} Rendered component.
 */
function LoadingFallback( {
	message = __( 'Loading…', 'newspack-event-logger-nodes' ),
} ) {
	return (
		<div className="event-logger-performance-loading">
			<Spinner />
			<p>{ message }</p>
		</div>
	);
}

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
			<div className="event-logger-admin-wrap newspack-nodes-theme">
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

				<div className="event-logger-admin-app">
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

// Lazy load error log (only needed on its page).
const ErrorLog = lazy( () => import( './ErrorLog' ) );
import useAdminMenuWidth from '@newspack-nodes/shared/hooks/useAdminMenuWidth';

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
				<Suspense
					fallback={
						<LoadingFallback
							message={ __(
								'Loading…',
								'newspack-event-logger-nodes'
							) }
						/>
					}
				>
					<ErrorLog />
				</Suspense>
				<DebugOverlay storageKey="newspack-nodes:debug:error-log" />
			</div>
		</ThemedRoot>
	);
}

// Mount the dashboard when DOM is ready.
document.addEventListener( 'DOMContentLoaded', () => {
	const dashboardContainer = document.getElementById( 'event-logger-admin' );
	if ( dashboardContainer ) {
		createRoot( dashboardContainer ).render( <AdminApp /> );
	}

	const errorsContainer = document.getElementById( 'event-logger-errors' );
	if ( errorsContainer ) {
		createRoot( errorsContainer ).render( <ErrorLogPage /> );
	}
} );
