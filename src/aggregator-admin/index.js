/**
 * Aggregator Admin entry — mounts the React <ServersAdmin> app into the
 * "Configured Servers" settings section.
 *
 * Full-React conversion (M5.2 follow-up): the old jQuery IIFE over a PHP-rendered
 * `<table>` (which `window.location.reload()`-ed after every mutation) is replaced
 * by a React view driven by the `servers:*` node graph. The PHP
 * `configured_servers_callback` now emits ONLY the mount `<div>`; React owns the
 * table + add form. CRUD still dispatches the same four `servers` verbs through the
 * shared CommandClient — the transport (api.js) is unchanged. Mirrors
 * `src/event-aggregator/index.js`'s mount mechanism.
 */

import { createRoot } from '@wordpress/element';
import './nodes/register';
import ServersAdmin from './ServersAdmin';
import DebugOverlay from '@newspack-nodes/debug-overlay';

// Mount into the PHP-emitted container (Admin::configured_servers_callback).
const mount = document.getElementById( 'event-aggregator-servers' );
if ( mount ) {
	const root = createRoot( mount );
	root.render(
		<>
			<ServersAdmin />
			<DebugOverlay storageKey="newspack-nodes:debug:aggregator-admin" />
		</>
	);
}
