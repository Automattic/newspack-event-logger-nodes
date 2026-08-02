/**
 * Current-Request overlay tab — summarizes THIS page's request (the one the
 * overlay is riding), renders its flame graph + profile breakdown, and
 * deep-links to the full performance trace. Data comes from the `performance`
 * CI's `request_detail` verb, addressed by the rid + partition the page
 * localizes into `window.NewspackEventLoggerNodes.currentRequest`.
 */

import { Core, TO, FROM, ID, KEY, VALUE } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { renderComponent, act } from '../../test-helpers/renderHook';
import CurrentRequestTab from '../CurrentRequestTab';

// Mock the d3-heavy FlameGraph + RequestProfile to sentinels — test the wiring.
jest.mock( '../../overview/FlameGraph', () => ( {
	__esModule: true,
	default: ( { data } ) => (
		<div data-testid="flame">{ data && data.name }</div>
	),
} ) );
jest.mock( '../../overview/RequestProfile', () => ( {
	__esModule: true,
	default: ( { profiles } ) => (
		<div data-testid="profiles">
			{ profiles && Object.keys( profiles ).join( ',' ) }
		</div>
	),
} ) );

// The tab's command rides the graph; this answers the wire, replying
// TO = FROM the way the server does.
let seen;
function answerWith( payload, { error = false } = {} ) {
	seen = jest.fn( () =>
		error ? new Error( String( payload ) ) : payload
	);
	return installFakeCommandWire( ( m ) => seen( m ) );
}

function setBlob( blob ) {
	window.NewspackEventLoggerNodes = { currentRequest: blob };
}

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
} );

afterEach( () => {
	delete window.NewspackEventLoggerNodes;
} );

// Poll until `assert` holds; the reply crosses a real async wire.
const waitFor = async ( assert ) => {
	for ( let i = 0; i < 50; i++ ) {
		try {
			assert();
			return;
		} catch ( e ) {
			await new Promise( ( r ) => setTimeout( r, 10 ) );
		}
	}
	assert();
};

test( 'renders the request summary cards + full-trace deep link when found', async () => {
	setBlob( {
		rid: 'abc123',
		partition: 2,
		perfUrl: 'admin.php?page=event-logger-overview',
	} );
	// `request_detail` returns the request envelope this fixture mirrors.
	answerWith( {
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
		view = renderComponent( <CurrentRequestTab /> );
	} );
	// Flush the lazy FlameGraph import (Suspense) after the fetch.
	await act( async () => {} );

	const sent = seen.mock.calls[ 0 ][ 0 ];
	expect( sent[ TO ] ).toBe( 'performance' );
	expect( sent[ VALUE ].name ).toBe( 'request_detail' );
	expect( sent[ VALUE ].arguments ).toEqual( [ 'abc123', '--partition=2' ] );
	// Addressed, not correlated: the reply routes back on FROM alone.
	expect( sent[ FROM ] ).toBe( 'performance:request_detail' );
	expect( sent[ ID ] ).toBe( '' );
	expect( sent[ KEY ] ).toBe( '' );
	const text = view.container.textContent;
	expect( text ).toContain( 'Request:' ); // the rid heading
	expect( text ).toContain( 'abc123' ); // the rid itself
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
	expect(
		view.container.querySelector( '.eln-current-request' ).className
	).toBe( 'eln-current-request' );
} );

test( 'shows a still-processing state (with retry) when the request is not in the log yet', async () => {
	setBlob( { rid: 'pending9', perfUrl: 'admin.php?page=x' } );
	answerWith( 'Request not found: rid=pending9', {
		error: true,
	} );

	let view;
	await act( async () => {
		view = renderComponent( <CurrentRequestTab /> );
	} );

	expect( view.container.textContent.toLowerCase() ).toContain(
		'still processing'
	);
	expect( view.container.querySelector( 'button' ) ).not.toBeNull();
} );

test( 'renders an idle hint when no request id is localized', async () => {
	let view;
	await act( async () => {
		view = renderComponent( <CurrentRequestTab /> );
	} );
	expect( view.container.textContent.toLowerCase() ).toContain(
		'no request'
	);
} );

// error_status renders the Status card via statusLabel(); ts 0 → placeholder.
test.each( [
	[ 'F', 'fatal error' ],
	[ 'T', 'timed out' ],
	[ 'weird', 'weird' ], // an unrecognized code passes through unchanged
] )(
	'labels error_status %s as "%s" in the status card',
	async ( errorStatus, label ) => {
		setBlob( { rid: 'err1', perfUrl: 'admin.php?page=x' } );
		answerWith( {
			rid: 'err1',
			url: '/x',
			duration_ms: 1,
			status_code: 500,
			error_status: errorStatus,
			timestamp: 0,
		} );
		let view;
		await act( async () => {
			view = renderComponent( <CurrentRequestTab /> );
		} );
		await act( async () => {} );
		expect( view.container.textContent ).toContain( label );
		// Scope the — check to the Time <p>: Status uses the same em-dash.
		const timeRow = Array.from(
			view.container.querySelectorAll( '.eln-current-request__info p' )
		).find( ( p ) =>
			p.querySelector( 'strong' )?.textContent.includes( 'Time' )
		);
		expect( timeRow ).toBeTruthy();
		expect( timeRow.textContent ).toContain( '—' );
	}
);

// A reply resolving after unmount must be swallowed by the mountedRef guard.
test( 'ignores a request_detail reply that arrives after the tab unmounts', async () => {
	setBlob( { rid: 'late1', perfUrl: 'admin.php?page=x' } );
	// The reply outlives the node it was addressed to; the Router says so.
	expectConsoleWarn( '_router: WARNING: message not addressed' );
	// Hold the wire open so the reply lands only after the unmount.
	const wire = answerWith( { rid: 'late1', url: '/x', duration_ms: 1 } );
	let resolveReply;
	global.fetch = jest.fn(
		( ...args ) =>
			new Promise( ( resolve ) => {
				resolveReply = () => resolve( wire( ...args ) );
			} )
	);
	let view;
	await act( async () => {
		view = renderComponent( <CurrentRequestTab /> );
	} );
	// load() fired; the reply is in flight.
	await waitFor( () => expect( global.fetch ).toHaveBeenCalled() );
	// Tear the tab down while the request_detail call is still in flight.
	view.unmount();
	// The guard drops the late reply: a setState here would fail the suite's
	// console gate with React's update-after-unmount warning.
	await act( async () => {
		resolveReply();
	} );
	expect( view.container.textContent ).toBe( '' );
} );
