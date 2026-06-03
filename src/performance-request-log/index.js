/**
 * Request Log Entry Point
 *
 * Real-time completed request stream viewer.
 */

import { createRoot } from '@wordpress/element';
import './nodes/register';
import RequestStreamPage from './RequestStreamPage';

// Mount to request stream page container.
const streamMount = document.getElementById( 'event-logger-stream' );
if ( streamMount ) {
	const root = createRoot( streamMount );
	root.render( <RequestStreamPage /> );
}
