/**
 * current-request/index.js — the overlay-tab bundle entrypoint. It has no
 * exports; importing it is a side effect that registers an `overlay`-host
 * station tab into the substrate's shared tab registry. This test imports the
 * module and asserts the registration landed (id, host, order, component).
 */

import { getTabs, resetTabs } from '@newspack-nodes/shared/tabs/tabRegistry';
import CurrentRequestTab from '../CurrentRequestTab';

beforeEach( () => resetTabs() );

test( 'importing the bundle registers an overlay-host "Request" tab', () => {
	require( '../index' );
	const tab = getTabs( 'overlay' ).find(
		( t ) => t.id === 'eln-current-request'
	);
	expect( tab ).toBeTruthy();
	expect( tab.label ).toBe( 'Request' );
	expect( tab.order ).toBe( 2 );
	expect( tab.component ).toBe( CurrentRequestTab );
} );
