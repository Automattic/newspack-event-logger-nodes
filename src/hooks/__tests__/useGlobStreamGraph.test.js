/**
 * useGlobStreamGraph tests — the glob dashboard, mounted for real so the
 * RemoteLink, the command wire and the router all run.
 *
 * Segment browsing, the seeks and the pause gate belong to the substrate hooks
 * it declares (`useSegmentBrowse`, `useStreamGraph`) and are asserted there;
 * what is asserted here is what a GLOB adds — which dirs the catalog offers,
 * which one is selected, and what the stream is pointed at as that selection
 * moves — plus the two view controls this hook mints.
 */

import {
	renderHook,
	renderComponent,
	act,
	waitFor,
} from '../../test-helpers/renderHook';
import {
	Core,
	Node,
	FROM,
	VALUE,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { useGlobStreamGraph } from '../useGlobStreamGraph';

class FakeEventSource {
	constructor( url ) {
		this.url = url;
		this.listeners = {};
		this.closed = false;
		FakeEventSource.last = this;
	}
	addEventListener( name, cb ) {
		( this.listeners[ name ] ||= [] ).push( cb );
	}
	close() {
		this.closed = true;
	}
}

// Routes on ORIGIN, like the node it stands in for. Sniffing the payload here
// made every control call site pass with no FROM at all.
class FakeGlobView extends Node {
	constructor() {
		super();
		this.controls = [];
		this.rows = [];
		this.controlFrom = '';
	}
	fill( message ) {
		if ( '' !== this.controlFrom && message[ FROM ] === this.controlFrom ) {
			this.controls.push( message[ VALUE ] );
		} else {
			this.rows.push( message );
		}
	}
	// The last control of a given action (or undefined).
	lastControl( action ) {
		return [ ...this.controls ]
			.reverse()
			.find( ( c ) => action === c.action );
	}
}

const PREFIX = 'globtest';
const LINK = 'globtest:link';
const VIEW = 'globtest:view';
const GLOB = 'errors.*';

// Every verb here rides the router tick; jest's 5s default is not enough.
jest.setTimeout( 20000 );

let wire;

beforeEach( () => {
	Core.reset();
	FakeEventSource.last = null;
	global.EventSource = FakeEventSource;
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

/**
 * Mount the whole glob dashboard against a wire answering by verb.
 *
 * @param {Object}   o                 Options.
 * @param {Object}   [o.payloadByVerb] Reply payload per verb name.
 * @param {string[]} [o.errorVerbs]    Verbs that answer TM_ERROR.
 * @param {string}   [o.glob]          The subscription glob.
 * @return {Object} The renderHook result, plus `view` and `wire`.
 */
async function renderBrowse( {
	payloadByVerb = {},
	errorVerbs = [],
	glob = GLOB,
} = {} ) {
	wire = installFakeCommandWire( ( m ) => {
		const verb = m[ VALUE ]?.name;
		return errorVerbs.includes( verb )
			? new Error( verb )
			: payloadByVerb[ verb ] ?? null;
	} );
	let hook;
	await act( async () => {
		hook = renderHook( () =>
			useGlobStreamGraph( {
				prefix: PREFIX,
				glob,
				viewClass: FakeGlobView,
			} )
		);
	} );
	// The catalog rides the router tick, so it is a wait rather than a flush.
	if (
		payloadByVerb.list_logs?.length &&
		! errorVerbs.includes( 'list_logs' )
	) {
		await waitFor( () => {
			if ( ! hook.result.current.browse.pickerOptions?.length ) {
				throw new Error( 'catalog not in yet' );
			}
		} );
	}
	return { ...hook, view: () => Core.node( VIEW ), wire };
}

/** What the stream is currently subscribed to. */
const subscribedTo = () => Core.node( LINK ).sseIn.subscribe;

/**
 * The segment rail's rows, read off the rail the browse model hands over.
 *
 * @param {Object} browse The `useGlobBrowse` return.
 * @return {Element[]} The rendered segment rows.
 */
function railItems( browse ) {
	const rail = renderComponent( browse.sidebar );
	const items = Array.from(
		rail.container.querySelectorAll( '.newspack-nodes-log-browser__item' )
	);
	rail.unmount();
	return items;
}

/**
 * The mount-time list_logs races /auth: the graph builds synchronously, the
 * session lands a round trip later. Firing before then mints an UNSIGNED
 * command the server refuses, and the browser looks alive only because a later
 * user action happens to run after auth.
 */
test( 'signs the mount-time catalog fetch', async () => {
	forgetSession();
	__setAuthFetch( async () => ( {
		handle: 'dddd4444dddd4444dddd4444dddd4444',
		key: 'key-glob-late-auth',
		expires_in: 3600,
		now: 1771000000,
	} ) );
	await renderBrowse( { payloadByVerb: { list_logs: [] } } );
	await waitFor( () =>
		expect( wire.batches.flat().length ).toBeGreaterThanOrEqual( 1 )
	);
	expect( wire.batches.flat()[ 0 ][ VALUE ].auth ).toBeDefined();
} );

describe( 'the partition catalog', () => {
	const MIXED = [
		{ key: 'errors.p0', label: 'errors.p0' },
		{ key: 'errors.p3', label: 'errors.p3' },
		{ key: 'completed.p0', label: 'completed.p0' },
		{ key: 'jobs.log', label: 'jobs.log' },
	];

	test( 'offers only the dirs whose key matches the glob prefix', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: MIXED },
		} );
		expect(
			result.current.browse.pickerOptions.map( ( p ) => p.key )
		).toEqual( [ '', 'errors.p0', 'errors.p3' ] );
	} );

	// The toolbar picker's rows, ready to render: the empty row widens the
	// subscription back to the whole glob, and a sole dir gets none — "All
	// partitions" would name the same thing twice.
	test( 'several dirs offer an All row above them', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: MIXED },
		} );
		expect( result.current.browse.pickerOptions ).toEqual( [
			{ key: '', label: 'All partitions (live)' },
			{ key: 'errors.p0', label: 'errors.p0' },
			{ key: 'errors.p3', label: 'errors.p3' },
		] );
	} );

	test( 'a sole dir offers itself alone', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p0', label: 'errors.p0' } ],
				log_status: { segments: [ { id: 4, size: 90 } ] },
			},
		} );
		await waitFor( () =>
			expect( result.current.browse.pickerOptions ).toEqual( [
				{ key: 'errors.p0', label: 'errors.p0' },
			] )
		);
	} );

	test( 'a glob with no trailing * filters by the whole glob', async () => {
		const { result } = await renderBrowse( {
			glob: 'errors.p3',
			payloadByVerb: { list_logs: MIXED },
		} );
		expect(
			result.current.browse.pickerOptions.map( ( p ) => p.key )
		).toEqual( [ 'errors.p3' ] );
	} );

	test( 'several dirs start on the whole glob, live', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: MIXED },
		} );
		expect( result.current.browse.selectedPartition ).toBe( '' );
		expect( subscribedTo() ).toEqual( [ GLOB ] );
	} );

	test( 'a sole dir auto-selects: it IS the whole live glob', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p0', label: 'errors.p0' } ],
				log_status: { segments: [ { id: 4, size: 90 } ] },
			},
		} );
		await waitFor( () =>
			expect( result.current.browse.selectedPartition ).toBe(
				'errors.p0'
			)
		);
		expect( subscribedTo() ).toEqual( [ 'errors.p0' ] );
	} );

	test( 'a refused catalog leaves the picker empty', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: MIXED },
			errorVerbs: [ 'list_logs' ],
		} );
		expect( result.current.browse.pickerOptions ).toEqual( [] );
	} );

	// A refusal is an ANSWER, so nothing retries it: the picker recovers only
	// because the catalog is polled.
	test( 'a refused catalog fills on the next tick', async () => {
		let refuse = true;
		wire = installFakeCommandWire( ( m ) => {
			if ( 'list_logs' !== m[ VALUE ]?.name ) {
				return null;
			}
			if ( refuse ) {
				refuse = false;
				return new Error( 'list_logs' );
			}
			return MIXED;
		} );
		let hook;
		await act( async () => {
			hook = renderHook( () =>
				useGlobStreamGraph( {
					prefix: PREFIX,
					glob: GLOB,
					viewClass: FakeGlobView,
				} )
			);
		} );
		expect( hook.result.current.browse.pickerOptions ).toEqual( [] );
		await waitFor(
			() =>
				expect(
					hook.result.current.browse.pickerOptions?.map(
						( p ) => p.key
					)
				).toEqual( [ '', 'errors.p0', 'errors.p3' ] ),
			{ timeout: 15000 }
		);
	} );

	test( 'a catalog that is not a list leaves the picker empty', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: { nope: true } },
		} );
		expect( result.current.browse.pickerOptions ).toEqual( [] );
	} );
} );

describe( 'moving the selection', () => {
	const TWO = [
		{ key: 'errors.p0', label: 'errors.p0' },
		{ key: 'errors.p3', label: 'errors.p3' },
	];

	test( 'picking a dir narrows the stream to it and tells the view', async () => {
		const { result, view } = await renderBrowse( {
			payloadByVerb: {
				list_logs: TWO,
				log_status: { segments: [ { id: 7, size: 12 } ] },
			},
		} );
		await act( async () =>
			result.current.browse.selectPartition( 'errors.p3' )
		);
		expect( subscribedTo() ).toEqual( [ 'errors.p3' ] );
		expect( view().lastControl( 'select' ) ).toEqual( {
			action: 'select',
			dir: 'errors.p3',
		} );
	} );

	test( 'picking All partitions widens back to the glob', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: TWO,
				log_status: { segments: [ { id: 7, size: 12 } ] },
			},
		} );
		await act( async () =>
			result.current.browse.selectPartition( 'errors.p3' )
		);
		await act( async () => result.current.browse.selectPartition( '' ) );
		expect( subscribedTo() ).toEqual( [ GLOB ] );
	} );

	test( 'a refused log_status leaves the rail empty', async () => {
		let refuse = false;
		wire = installFakeCommandWire( ( m ) => {
			const verb = m[ VALUE ]?.name;
			if ( 'log_status' === verb ) {
				return refuse
					? new Error( verb )
					: { segments: [ { id: 12, size: 640 } ] };
			}
			return 'list_logs' === verb ? TWO : null;
		} );
		let hook;
		await act( async () => {
			hook = renderHook( () =>
				useGlobStreamGraph( {
					prefix: PREFIX,
					glob: GLOB,
					viewClass: FakeGlobView,
				} )
			);
		} );
		await act( async () =>
			hook.result.current.browse.selectPartition( 'errors.p3' )
		);
		await waitFor( () =>
			expect( railItems( hook.result.current.browse ) ).toHaveLength( 1 )
		);
		// A refused re-catalog CLEARS the rail rather than leaving a stale one.
		refuse = true;
		await act( async () =>
			hook.result.current.browse.selectPartition( 'errors.p0' )
		);
		await waitFor( () =>
			expect( railItems( hook.result.current.browse ) ).toEqual( [] )
		);
	} );

	test( 'an answered log_status fills the rail', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: TWO,
				log_status: { segments: [ { id: 12, size: 640 } ] },
			},
		} );
		await act( async () =>
			result.current.browse.selectPartition( 'errors.p3' )
		);
		await waitFor( () =>
			expect( railItems( result.current.browse ) ).toHaveLength( 1 )
		);
		expect( railItems( result.current.browse )[ 0 ].textContent ).toContain(
			'Segment 12'
		);
	} );
} );

// Without a dir there is nothing to browse WITHIN, so the rail, the offset
// jump into it and the paused step do not exist. Ungate any one of them and
// the whole-glob view grows an empty rail and two dead controls.
describe( 'the two-level gate', () => {
	const TWO = [
		{ key: 'errors.p0', label: 'errors.p0' },
		{ key: 'errors.p3', label: 'errors.p3' },
	];
	const WITH_RAIL = {
		list_logs: TWO,
		log_status: { segments: [ { id: 9, size: 12 } ] },
	};

	test( 'the whole-glob view has no rail, no jump and no step', async () => {
		const { result } = await renderBrowse( { payloadByVerb: WITH_RAIL } );
		expect( result.current.browse.selectedPartition ).toBe( '' );
		expect( result.current.browse.sidebar ).toBeNull();
		expect( result.current.browse.jump ).toBeUndefined();
		expect( result.current.step ).toBeUndefined();
	} );

	test( 'picking a dir brings all three back', async () => {
		const { result } = await renderBrowse( { payloadByVerb: WITH_RAIL } );
		await act( async () =>
			result.current.browse.selectPartition( 'errors.p3' )
		);
		expect( result.current.browse.sidebar ).not.toBeNull();
		expect( typeof result.current.browse.jump ).toBe( 'function' );
		expect( typeof result.current.step ).toBe( 'function' );
	} );
} );

describe( 'the view controls', () => {
	test( "a filter term becomes the view's ingest gate", async () => {
		const { result, view } = await renderBrowse();
		act( () => result.current.setFilter( 'timeout' ) );
		expect( view().lastControl( 'filter' ) ).toEqual( {
			action: 'filter',
			term: 'timeout',
		} );
	} );

	test( "clear runs the view's ONE reset", async () => {
		const { result, view } = await renderBrowse();
		act( () => result.current.clear() );
		expect( view().lastControl( 'clear' ) ).toEqual( { action: 'clear' } );
	} );
} );
