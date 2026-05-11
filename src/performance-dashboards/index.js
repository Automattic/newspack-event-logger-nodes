/**
 * Performance Dashboards Entry Point
 *
 * Performance Dashboard page only. Settings UI moved to newspack-performance-logger.
 */

import {
	render,
	useState,
	useEffect,
	lazy,
	Suspense,
} from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';

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
function LoadingFallback( { message = 'Loading...' } ) {
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
function AdminApp() {
	const [ error, setError ] = useState( null );

	/**
	 * Handle errors.
	 *
	 * @param {Error} err Error object.
	 */
	const handleError = ( err ) => {
		setError( err.message || 'An error occurred' );
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
		<div className="event-logger-admin-wrap">
			<h1>Event Logger - Performance Dashboard</h1>

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
						<LoadingFallback message="Loading dashboard..." />
					}
				>
					<PerformanceDashboard onError={ handleError } />
				</Suspense>
			</div>
		</div>
	);
}

// Lazy load error log (only needed on its page).
const ErrorLog = lazy( () => import( './ErrorLog' ) );
import useAdminMenuWidth from '../shared/hooks/useAdminMenuWidth';

/**
 * Error Log page wrapper — full-page dark layout.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
function ErrorLogPage() {
	const menuWidth = useAdminMenuWidth();

	return (
		<div
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
			<Suspense fallback={ <LoadingFallback message="Loading..." /> }>
				<ErrorLog />
			</Suspense>
		</div>
	);
}

// Mount the dashboard when DOM is ready.
document.addEventListener( 'DOMContentLoaded', () => {
	const dashboardContainer = document.getElementById( 'event-logger-admin' );
	if ( dashboardContainer ) {
		render( <AdminApp />, dashboardContainer );
	}

	const errorsContainer = document.getElementById( 'event-logger-errors' );
	if ( errorsContainer ) {
		render( <ErrorLogPage />, errorsContainer );
	}
} );
