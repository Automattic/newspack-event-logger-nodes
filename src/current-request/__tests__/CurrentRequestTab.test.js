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

// @longform
// Every render is torn down: Core.reset() clears the node registry but stops
// no timers, so a tab left mounted keeps its reconcile loop running and can
// mint into the NEXT test's node — which is the same name every time.
const views = [];
const render = ( ...args ) => {
	const view = renderComponent( ...args );
	views.push( view );
	return view;
};

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
} );

afterEach( () => {
	while ( views.length ) {
		views.pop().unmount();
	}
	delete window.NewspackEventLoggerNodes;
	delete global.fetch;
} );

// One router heartbeat — the tick the tab's poll rides — plus the microtask
// turns the reply needs to cross the wire and land on the view node.
const tick = async () => {
	await act( async () => {
		Core.node( '_router' ).fireCb();
	} );
	await act( async () => {} );
};

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
		view = render( <CurrentRequestTab /> );
	} );
	// Flush the lazy FlameGraph import (Suspense) after the fetch.
	await act( async () => {} );

	const sent = seen.mock.calls[ 0 ][ 0 ];
	expect( sent[ TO ] ).toBe( 'performance' );
	expect( sent[ VALUE ].name ).toBe( 'request_detail' );
	expect( sent[ VALUE ].arguments ).toEqual( [ 'abc123', '--partition=2' ] );
	// Addressed, not correlated: the reply routes back on FROM alone.
	expect( sent[ FROM ] ).toBe( 'currentrequest:in' );
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

// The overlay host re-renders the tab on every drag and resize, and a found
// request must survive every one of them — it lives on the view node, which
// stays mounted for as long as the tab does.
test( 'keeps the found request across a re-render from the host', async () => {
	setBlob( {
		rid: 'sticky77',
		partition: 5,
		perfUrl: 'admin.php?page=event-logger-overview',
	} );
	answerWith( {
		rid: 'sticky77',
		url: '/wp-admin/edit.php',
		duration_ms: 987.65,
		status_code: 503,
		error_status: 'T',
		peak_mb: 128,
		timestamp: 1750000000,
	} );

	let view;
	await act( async () => {
		view = render( <CurrentRequestTab /> );
	} );
	await act( async () => {} );
	expect( view.container.textContent ).toContain( 'sticky77' );
	// The node that owns the answer is still there to answer with.
	expect( Core.node( 'currentrequest:view' ) ).not.toBeNull();

	// One re-render from the host — what a drag or a tab switch delivers.
	view.rerender( <CurrentRequestTab /> );

	const text = view.container.textContent;
	expect( text.toLowerCase() ).not.toContain( 'still processing' );
	expect( text ).toContain( 'sticky77' );
	expect( text ).toContain( '987.65' );
	expect( text ).toContain( '503' );
} );

// `Flame_Builder_Node` consumes requests.log AFTER `Request_Builder_Node` wrote
// the record, so the reply that finds a request almost always predates its
// flame. Settling on that first reply forfeits the trace until a reload.
test( 'renders the flame graph written after the record was found', async () => {
	setBlob( {
		rid: 'lateflame42',
		partition: 3,
		perfUrl: 'admin.php?page=event-logger-overview',
	} );
	const record = {
		rid: 'lateflame42',
		url: '/wp-admin/upload.php',
		duration_ms: 314.15,
		status_code: 207,
		error_status: '-',
		peak_mb: 96,
		timestamp: 1760000000,
	};
	// Nothing has folded a flame yet; the builder does that a beat later.
	let flame = null;
	installFakeCommandWire( () =>
		flame ? { ...record, flame_data: flame } : record
	);

	let view;
	await act( async () => {
		view = render( <CurrentRequestTab /> );
	} );
	await act( async () => {} );
	expect( view.container.textContent ).toContain( 'lateflame42' );
	expect(
		view.container.querySelector( '[data-testid="flame"]' )
	).toBeNull();

	flame = {
		name: 'lateflame-root',
		value: 314.15,
		children: [ { name: 'wp_loaded' } ],
	};
	await tick();

	await waitFor( () =>
		expect(
			view.container.querySelector( '[data-testid="flame"]' )
		).not.toBeNull()
	);
	expect(
		view.container.querySelector( '[data-testid="flame"]' ).textContent
	).toBe( 'lateflame-root' );
	expect( view.container.textContent ).toContain( 'Request Trace' );
} );

// A site running no `flame-builder` topology has no flame coming, so waiting on
// one is waiting forever. The ask outlives the record by a few ticks, no more.
test( 'stops asking a few ticks past the record when no flame arrives', async () => {
	setBlob( { rid: 'noflame13', partition: 7, perfUrl: 'admin.php?page=x' } );
	const wire = installFakeCommandWire( () => ( {
		rid: 'noflame13',
		url: '/wp-admin/themes.php',
		duration_ms: 55.5,
		status_code: 418,
		error_status: '-',
		timestamp: 1760000001,
	} ) );

	let view;
	await act( async () => {
		view = render( <CurrentRequestTab /> );
	} );
	await act( async () => {} );
	expect( view.container.textContent ).toContain( 'noflame13' );

	for ( let i = 0; i < 12; i++ ) {
		await tick();
	}
	const asks = wire.batches.length;
	await tick();
	await tick();

	// It kept asking past the record, and then it stopped.
	expect( asks ).toBeGreaterThan( 1 );
	expect( asks ).toBeLessThanOrEqual( 8 );
	expect( wire.batches.length ).toBe( asks );
} );

// No Refresh button: the tab asks again every tick, so the only thing one
// could do is what is already happening.
test( 'shows a still-processing state when the request is not in the log yet', async () => {
	setBlob( { rid: 'pending9', perfUrl: 'admin.php?page=x' } );
	answerWith( 'Request not found: rid=pending9', {
		error: true,
	} );

	let view;
	await act( async () => {
		view = render( <CurrentRequestTab /> );
	} );

	expect( view.container.textContent.toLowerCase() ).toContain(
		'still processing'
	);
	expect( view.container.querySelector( 'button' ) ).toBeNull();
} );

// The worker writes the record moments after the page rendered, so an early ask
// answers with nothing at all. That is not a failure and must not blank the tab.
test( 'keeps waiting when the reply carries no record yet', async () => {
	setBlob( { rid: 'notyet7', perfUrl: 'admin.php?page=x' } );
	answerWith( null );

	let view;
	await act( async () => {
		view = render( <CurrentRequestTab /> );
	} );

	expect( view.container.textContent.toLowerCase() ).toContain(
		'still processing'
	);
	expect( view.container.textContent ).not.toContain( 'Request:' );
} );

test( 'renders an idle hint when no request id is localized', async () => {
	let view;
	await act( async () => {
		view = render( <CurrentRequestTab /> );
	} );
	expect( view.container.textContent.toLowerCase() ).toContain(
		'no request'
	);
} );

// error_status renders the Status card via errorStatus(); ts 0 → placeholder.
test.each( [
	[ 'F', 'Fatal error' ],
	[ 'T', 'Timed out (orphaned request)' ],
	// killed mid-flight: a worker stop, or a stolen lease
	[ 'A', 'Aborted (worker stopped mid-request)' ],
	// a nominal finish over a firehose hole: the trace is partial
	[ 'I', 'Incomplete (gap in the log)' ],
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
			view = render( <CurrentRequestTab /> );
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
		view = render( <CurrentRequestTab /> );
	} );
	// load() fired; the reply is in flight.
	await waitFor( () => expect( global.fetch ).toHaveBeenCalled() );
	// Tear the tab down while the request_detail call is still in flight.
	view.unmount();
	// Unmounting removed the node, so its request already rejected; the late
	// reply has nowhere to land. A setState here would fail the suite's
	// console gate with React's update-after-unmount warning.
	await act( async () => {
		resolveReply();
	} );
	expect( view.container.textContent ).toBe( '' );
	// And it never reached the tab: the found-state heading would show it.
	expect( view.container.textContent ).not.toContain( 'late1' );
} );
