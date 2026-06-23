/**
 * Current-Request overlay tab bundle. Registers an `overlay`-scope devtools tab
 * (the same registry the substrate's overlay tabs use) so the debug overlay,
 * wherever it's mounted, gains a "Request" tab summarizing the page's own
 * request. ELN owns this — it owns the request lifecycle — and ships it as its
 * own bundle enqueued via the `newspack_nodes/devtools_tab_bundles` filter.
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
