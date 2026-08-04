/**
 * Gyroscope dashboard entry point.
 *
 * Mounts the full-page live in-flight request monitor into the
 * `#event-logger-gyroscope` container printed by the Gyroscope admin page
 * (`newspack-event-logger-nodes.php`). The bundle is enqueued only for the
 * `event-logger-gyroscope` page slug, so an absent container means the module
 * runs somewhere it does not belong; it then does nothing.
 *
 * `./nodes/register` is imported for its side effect alone: it registers the
 * `GyroscopeView` class with the runtime `CommandInterpreterNode`, which
 * `useGyroscopeGraph` needs before its `makeNode( 'GyroscopeView', … )` call.
 * Dropping the import breaks graph construction, not this file.
 *
 * The mount runs at module evaluation instead of on `DOMContentLoaded`. That
 * is safe because the substrate enqueues the bundle in the footer, below the
 * container markup.
 */

import { createRoot } from '@wordpress/element';
import './nodes/register';
import GyroscopePage from './GyroscopePage';

const gyroscopeMount = document.getElementById( 'event-logger-gyroscope' );
if ( gyroscopeMount ) {
	const root = createRoot( gyroscopeMount );
	root.render( <GyroscopePage /> );
}
