/**
 * Request Log dashboard entry point — the `build/requests` bundle.
 *
 * Mounts the real-time completed-request stream into `#event-logger-stream`,
 * the bare div the plugin's "Event Logger → Request Log" submenu page prints
 * (`newspack-event-logger-nodes.php`). Without that container the module does
 * nothing, so loading the bundle elsewhere is harmless. The mount runs at
 * module evaluation rather than on `DOMContentLoaded`, which is safe because
 * the substrate enqueues this bundle in the footer, below the container markup.
 *
 * Importing `./nodes/request-log-view-node` for its side effect merges
 * `RequestLogView` into the runtime's `includeNodes` map, the name surface the
 * console palette and `make_node` read. The graph does not depend on that
 * merge — `useGlobStreamGraph` takes a `viewClass` rather than a name (ADR-16),
 * and `useRequestLogGraph` reaches the same class through the module's `views`
 * export — so the side-effect import states the dependency at the entry point
 * rather than resting on the page tree's import chain.
 *
 * No stylesheet is imported here, deliberately: `RequestStream` pulls in
 * `./styles/request-stream.scss`, which reaches the shared tokens and mixins
 * through this dashboard's own `styles/base.scss`.
 */

import { createRoot } from '@wordpress/element';
import './nodes/request-log-view-node';
import RequestStreamPage from './RequestStreamPage';

const streamMount = document.getElementById( 'event-logger-stream' );
if ( streamMount ) {
	const root = createRoot( streamMount );
	root.render( <RequestStreamPage /> );
}
