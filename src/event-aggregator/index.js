/**
 * Aggregator Dashboard Entry Point
 *
 * Aggregator server status monitoring.
 */

import { createRoot } from '@wordpress/element';
import './nodes/register';
import AggregatorStatusPage from './AggregatorStatusPage';

// Mount to aggregator status page container.
const statusMount = document.getElementById( 'event-aggregator-status' );
if ( statusMount ) {
	const root = createRoot( statusMount );
	root.render( <AggregatorStatusPage /> );
}
