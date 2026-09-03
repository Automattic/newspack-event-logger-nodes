/**
 * Current-Request overlay tab bundle — the entrypoint esbuild compiles into
 * `build/current-request/`. It exports nothing: importing it registers an
 * `overlay`-host tab in the substrate's window-singleton devtools registry, the
 * same one the substrate's own overlay tabs register into, so the debug
 * overlay gains a "Request" tab summarizing the page's own request wherever it
 * is mounted. Order 2 seats that tab after the substrate's Overview (0) and
 * Console (1).
 *
 * ELN owns the tab because ELN owns the request lifecycle: `Log_Manager` mints
 * the request id and `Current_Request_Overlay` injects it into
 * `window.NewspackEventLoggerNodes.currentRequest`, which `CurrentRequestTab`
 * reads. That same PHP class loads this bundle by two paths — the substrate's
 * `newspack_nodes/devtools_tab_bundles` filter covers the Nodes hub page, and
 * an `admin_enqueue_scripts` callback covers every other page that mounts the
 * overlay. The SCSS import carries the tab's styles: esbuild emits it as the
 * sibling `index.css` that class enqueues under the same handle.
 */

import { __ } from '@wordpress/i18n';
import { registerDevtoolsTab } from '@newspack-nodes/shared/devtools/tabRegistry';
import CurrentRequestTab from './CurrentRequestTab';
import './current-request.scss';

registerDevtoolsTab( {
	id: 'eln-current-request',
	label: __( 'Request', 'newspack-event-logger-nodes' ),
	host: 'overlay',
	order: 2,
	component: CurrentRequestTab,
} );
