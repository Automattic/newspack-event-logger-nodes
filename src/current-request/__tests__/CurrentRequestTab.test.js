/**
 * Current-Request overlay tab — summarizes THIS page's request (the one the
 * overlay is riding), renders its flame graph + profile breakdown, and
 * deep-links to the full performance trace. Data comes from the `performance`
 * CI's `request_detail` verb, addressed by the rid + partition the page
 * localizes into `window.NewspackEventLoggerNodes.currentRequest`.
 */

import {
	newMessage,
	TYPE,
	VALUE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
} from '@newspack-nodes/runtime';
import { renderComponent, act } from '../../test-helpers/renderHook';
import CurrentRequestTab from '../CurrentRequestTab';

// The flame graph (d3-heavy, lazy) + profiles breakdown are reused from the
// performance dashboard; mock them to sentinels so the tab's wiring is the unit
// under test, not d3.
jest.mock( '../../performance-dashboards/FlameGraph', () => ( {
	__esModule: true,
	default: ( { data } ) => (
		<div data-testid="flame">{ data && data.name }</div>
	),
} ) );
jest.mock( '../../performance-dashboards/RequestProfile', () => ( {
	__esModule: true,
	default: ( { profiles } ) => (
		<div data-testid="profiles">
			{ profiles && Object.keys( profiles ).join( ',' ) }
		</div>
	),
} ) );

// A one-shot CommandClient seam (matches getCommandClient().send): resolves the
// next reply Message. `error: true` returns a TM_ERROR reply (verb threw, e.g.
// the request isn't in requests.log yet — request-builder hasn't processed it).
function fakeClient( payload, { error = false } = {} ) {
	const reply = newMessage();
	reply[ TYPE ] = error
		? TM_COMMAND | TM_RESPONSE | TM_ERROR
		: TM_COMMAND | TM_RESPONSE;
	// VALUE is the `{ name, payload }` envelope unwrapCommandResponse reads.
	reply[ VALUE ] = { name: 'request_search', payload };
	return { send: jest.fn().mockResolvedValue( reply ) };
}

function setBlob( blob ) {
	window.NewspackEventLoggerNodes = { currentRequest: blob };
}

afterEach( () => {
	delete window.NewspackEventLoggerNodes;
} );

test( 'renders the request summary cards + full-trace deep link when found', async () => {
	setBlob( {
		rid: 'abc123',
		partition: 2,
		perfUrl: 'admin.php?page=newspack-nodes-performance',
	} );
	// `request_detail` returns the full request envelope ($decoded[VALUE]) — the
	// real shape this fixture mirrors (url + duration_ms + status_code + … ).
	const client = fakeClient( {
		rid: 'abc123',
		url: '/wp-admin/index.php',
		duration_ms: 432,
		status_code: 200,
		error_status: '-',
		peak_mb: 64,
		timestamp: 1700000000,
		flame_data: {
			name: 'request',
			value: 432,
			children: [ { name: 'x' } ],
		},
		profiles: { db: 10, hooks: 20 },
	} );

	let view;
	await act( async () => {
		view = renderComponent(
			<CurrentRequestTab commandClient={ client } />
		);
	} );
	// Flush the lazy FlameGraph import (Suspense) after the request_detail fetch.
	await act( async () => {} );

	expect( client.send ).toHaveBeenCalledWith( {
		to: 'performance',
		verb: 'request_detail',
		args: 'abc123 --partition=2',
	} );
	const text = view.container.textContent;
	expect( text ).toContain( '432' ); // duration ms
	expect( text ).toContain( '200' ); // status code
	expect( text ).toContain( '/wp-admin/index.php' ); // url
	expect( text ).toContain( 'Time' ); // the timestamp card label
	const link = view.container.querySelector( 'a[href*="request=abc123"]' );
	expect( link ).not.toBeNull();
	// Flame graph + profiles wired from the verb's flame_data / profiles.
	expect(
		view.container.querySelector( '[data-testid="flame"]' )
	).not.toBeNull();
	expect(
		view.container.querySelector( '[data-testid="profiles"]' )
	).not.toBeNull();
} );

test( 'shows a still-processing state (with retry) when the request is not in the log yet', async () => {
	setBlob( { rid: 'pending9', perfUrl: 'admin.php?page=x' } );
	const client = fakeClient( 'Request not found: rid=pending9', {
		error: true,
	} );

	let view;
	await act( async () => {
		view = renderComponent(
			<CurrentRequestTab commandClient={ client } />
		);
	} );

	expect( view.container.textContent.toLowerCase() ).toContain(
		'still processing'
	);
	expect( view.container.querySelector( 'button' ) ).not.toBeNull(); // a Refresh/retry control
} );

test( 'renders an idle hint when no request id is localized', async () => {
	let view;
	await act( async () => {
		view = renderComponent( <CurrentRequestTab commandClient={ null } /> );
	} );
	expect( view.container.textContent.toLowerCase() ).toContain(
		'no request'
	);
} );
