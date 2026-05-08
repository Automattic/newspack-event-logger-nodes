/**
 * Gyroscope Entry Point
 *
 * Real-time in-flight request monitoring.
 */

import { createRoot } from '@wordpress/element';
import GyroscopePage from './GyroscopePage';

// Mount to gyroscope page container.
const gyroscopeMount = document.getElementById( 'event-logger-gyroscope' );
if ( gyroscopeMount ) {
	const root = createRoot( gyroscopeMount );
	root.render( <GyroscopePage /> );
}
