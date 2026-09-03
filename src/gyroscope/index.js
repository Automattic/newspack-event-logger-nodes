/**
 * Gyroscope dashboard entry point — the `build/gyroscope` bundle.
 *
 * Mounts the live in-flight request monitor into `#event-logger-gyroscope`, the
 * bare div the plugin's "Event Logger → Gyroscope" submenu page prints
 * (`newspack-event-logger-nodes.php`). Without that container the module does
 * nothing, so loading the bundle elsewhere is harmless. The mount runs at
 * module evaluation rather than on `DOMContentLoaded`, which is safe because
 * the substrate enqueues this bundle in the footer, below the container markup.
 *
 * Importing `./nodes/gyroscope-view-node` for its side effect merges
 * `GyroscopeView` into the runtime's `includeNodes` map, the name surface the
 * console palette and `make_node` read. `useGyroscopeGraph` imports the same
 * module for the class itself — `useStreamGraph` takes a `viewClass` rather
 * than a name (ADR-16) — so the side-effect import states the dependency at the
 * entry point rather than resting on the page tree's import chain.
 *
 * This entry imports no stylesheet, unlike its siblings: `Inflight` pulls in
 * `./styles/inflight.scss`, which reaches the shared tokens and mixins through
 * this dashboard's own `styles/base.scss`.
 */

import { createRoot } from '@wordpress/element';
import './nodes/gyroscope-view-node';
import GyroscopePage from './GyroscopePage';

const gyroscopeMount = document.getElementById( 'event-logger-gyroscope' );
if ( gyroscopeMount ) {
	const root = createRoot( gyroscopeMount );
	root.render( <GyroscopePage /> );
}
