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

// Spy on the response-unwrap step. React 18 makes a setState on an unmounted
// component a silent no-op (no error, no DOM change), so the ONLY observable of
// the load()'s mountedRef guard is whether a late reply gets unwrapped/processed
// at all. The spy delegates to the real impl so every other test's unwrap
// behavior is unchanged.
jest.mock( '@newspack-nodes/shared/utils/unwrapCommandResponse', () => {
	const actual = jest.requireActual(
		'@newspack-nodes/shared/utils/unwrapCommandResponse'
	);
	return {
		__esModule: true,
		default: jest.fn( ( ...args ) => actual.default( ...args ) ),
	};
} );
import unwrapCommandResponse from '@newspack-nodes/shared/utils/unwrapCommandResponse';

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
		perfUrl: 'admin.php?page=event-logger-overview',
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

// A request that finished with an error_status renders the Status card with the
// human label from statusLabel(); the timestamp 0 path renders the "—" placeholder.
test.each( [
	[ 'F', 'fatal error' ],
	[ 'T', 'timed out' ],
	[ 'weird', 'weird' ], // an unrecognized code passes through unchanged
] )(
	'labels error_status %s as "%s" in the status card',
	async ( errorStatus, label ) => {
		setBlob( { rid: 'err1', perfUrl: 'admin.php?page=x' } );
		const client = fakeClient( {
			rid: 'err1',
			url: '/x',
			duration_ms: 1,
			status_code: 500,
			error_status: errorStatus,
			timestamp: 0,
		} );
		let view;
		await act( async () => {
			view = renderComponent(
				<CurrentRequestTab commandClient={ client } />
			);
		} );
		await act( async () => {} );
		expect( view.container.textContent ).toContain( label );
		// timestamp 0 → the Time field renders the "—" placeholder. Scope the
		// check to THAT field's <p>: the Status card's "500 — <label>" separator
		// is the same em-dash (U+2014), so a whole-container toContain('—') would
		// pass even if the placeholder regressed.
		const timeRow = Array.from(
			view.container.querySelectorAll( '.eln-current-request__info p' )
		).find( ( p ) =>
			p.querySelector( 'strong' )?.textContent.includes( 'Time' )
		);
		expect( timeRow ).toBeTruthy();
		expect( timeRow.textContent ).toContain( '—' );
	}
);

// A reply that resolves AFTER the tab unmounts must be swallowed by the mountedRef
// guard — no setState on a torn-down component.
test( 'ignores a request_detail reply that arrives after the tab unmounts', async () => {
	setBlob( { rid: 'late1', perfUrl: 'admin.php?page=x' } );
	let resolveReply;
	const reply = newMessage();
	reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
	reply[ VALUE ] = {
		name: 'request_detail',
		payload: { rid: 'late1', url: '/x', duration_ms: 1 },
	};
	const client = {
		send: jest.fn(
			() => new Promise( ( resolve ) => ( resolveReply = resolve ) )
		),
	};
	let view;
	await act( async () => {
		view = renderComponent(
			<CurrentRequestTab commandClient={ client } />
		);
	} );
	// load() fired its request; the reply is still in flight (send is pending).
	expect( client.send ).toHaveBeenCalled();
	// Scope the post-unmount assertion to THIS reply only.
	unwrapCommandResponse.mockClear();
	// Tear the tab down while the request_detail call is still in flight.
	view.unmount();
	// Now settle the in-flight reply. The mountedRef guard must drop it BEFORE
	// it is ever unwrapped — delete that guard and this late reply gets unwrapped
	// (and setState'd on a torn-down component), so this assertion goes red.
	await act( async () => {
		resolveReply( reply );
	} );
	expect( unwrapCommandResponse ).not.toHaveBeenCalled();
} );
