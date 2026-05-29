/**
 * performance:command tests — the slice-tagging command-builder Node.
 *
 * Post-migration to substrate-canonical wiring, this Node does NOT own the
 * network. Each fetch* method:
 *  - emits a `{action:'loading', slice}` (or `error` for validation failures)
 *    TM_STRUCT control through `sink` stamped `TO = target` (→ `performance:view`),
 *  - registers a pending entry `{slice, initial?}` on the view's `pending` Map
 *    keyed by `message[ID]`,
 *  - builds a TM_COMMAND (FROM=`performance:view`, TO=`_http/performance`, ID,
 *    VALUE={name,arguments,payload}) and fills it into `sink` (the CI).
 *
 * `resolveRequest` and `fetchUrlBreakdown` register a `resolveOnly` pending
 * entry that the view's reply path resolves with the payload (transformed for
 * breakdown). On a TM_ERROR reply, the view rejects the pending Promise.
 *
 * Validation failures (invalid hash / partition / request id) emit an error
 * control and skip sending — no message, no pending entry.
 */

import {
	Core,
	TYPE,
	TO,
	FROM,
	ID,
	VALUE,
	TM_COMMAND,
	TM_STRUCT,
} from '@newspack-nodes/runtime';
import { createPerformanceCommand } from '../performanceCommand';
import { createPerformanceView } from '../performanceView';

beforeEach( () => Core.reset() );

// Mount a command+view pair sharing a recording sink so we can inspect every
// outbound message in order.
function mount( opts = {} ) {
	const outbox = [];
	const sink = { fill: ( m ) => outbox.push( m ) };
	const view = createPerformanceView( 'performance:view' );
	view.sink = sink;
	const command = createPerformanceCommand( 'performance:command', opts );
	command.sink = sink;
	command.target = 'performance:view';
	command.viewName = 'performance:view';
	return { outbox, view, command };
}

describe( 'performance:command — control emissions (loading)', () => {
	test( 'fetchOverview emits a loading control TO=target', () => {
		const { outbox, command } = mount();
		command.fetchOverview();
		const control = outbox.find(
			( m ) => m[ TYPE ] === TM_STRUCT && m[ VALUE ].action === 'loading'
		);
		expect( control ).toBeTruthy();
		expect( control[ VALUE ] ).toEqual( {
			action: 'loading',
			slice: 'overview',
		} );
		expect( control[ TO ] ).toBe( 'performance:view' );
	} );

	test( 'fetchUrls emits a urls loading control', () => {
		const { outbox, command } = mount();
		command.fetchUrls();
		const control = outbox.find(
			( m ) => m[ TYPE ] === TM_STRUCT && m[ VALUE ].action === 'loading'
		);
		expect( control[ VALUE ] ).toEqual( {
			action: 'loading',
			slice: 'urls',
		} );
	} );

	test( 'fetchUrlDetail with initial:true emits loading; without initial it is SILENT', () => {
		const { outbox, command } = mount();
		command.fetchUrlDetail( 'abc123', { initial: true } );
		const initialLoadings = outbox.filter(
			( m ) =>
				m[ TYPE ] === TM_STRUCT &&
				m[ VALUE ] &&
				m[ VALUE ].action === 'loading'
		);
		expect( initialLoadings ).toHaveLength( 1 );
		outbox.length = 0;
		command.fetchUrlDetail( 'abc123' );
		const silentLoadings = outbox.filter(
			( m ) =>
				m[ TYPE ] === TM_STRUCT &&
				m[ VALUE ] &&
				m[ VALUE ].action === 'loading'
		);
		expect( silentLoadings ).toHaveLength( 0 );
	} );

	test( 'fetchRequestDetail emits a requestDetail loading control', () => {
		const { outbox, command } = mount();
		command.fetchRequestDetail( 'ok-rid', 0 );
		const control = outbox.find(
			( m ) => m[ TYPE ] === TM_STRUCT && m[ VALUE ].action === 'loading'
		);
		expect( control[ VALUE ] ).toEqual( {
			action: 'loading',
			slice: 'requestDetail',
		} );
	} );
} );

describe( 'performance:command — TM_COMMAND build', () => {
	test( 'fetchOverview emits a TM_COMMAND TO=_http/performance FROM=performance:view with overview verb', () => {
		const { outbox, command } = mount();
		command.fetchOverview( 'web1', [ 'server', 'status' ] );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmd ).toBeTruthy();
		expect( cmd[ TO ] ).toBe( '_http/performance' );
		expect( cmd[ FROM ] ).toBe( 'performance:view' );
		expect( cmd[ VALUE ].name ).toBe( 'overview' );
		expect( cmd[ VALUE ].payload ).toEqual( {
			categories: true,
			server: 'web1',
			breakdown: 'server,status',
		} );
		expect( typeof cmd[ ID ] ).toBe( 'string' );
		expect( cmd[ ID ].length ).toBeGreaterThan( 0 );
	} );

	test( 'fetchOverview without server or dims omits both keys but keeps categories:true', () => {
		const { outbox, command } = mount();
		command.fetchOverview();
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmd[ VALUE ].payload ).toEqual( { categories: true } );
	} );

	test( 'fetchUrls forwards only present params plus limit:100', () => {
		const { outbox, command } = mount();
		command.fetchUrls( { search: 'x', offset: 20 } );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmd[ VALUE ].name ).toBe( 'urls' );
		expect( cmd[ VALUE ].payload ).toEqual( {
			limit: 100,
			search: 'x',
			offset: 20,
		} );
	} );

	test( 'fetchUrlDetail builds a url_detail TM_COMMAND with {hash, categories?, breakdown?}', () => {
		const { outbox, command } = mount();
		command.fetchUrlDetail( 'abc123', { categories: true, initial: true } );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmd[ VALUE ].name ).toBe( 'url_detail' );
		expect( cmd[ VALUE ].payload ).toEqual( {
			hash: 'abc123',
			categories: true,
		} );
	} );

	test( 'fetchRequestDetail builds a request_detail TM_COMMAND with {rid, partition}', () => {
		const { outbox, command } = mount();
		command.fetchRequestDetail( 'ok-rid', 0 );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmd[ VALUE ].name ).toBe( 'request_detail' );
		expect( cmd[ VALUE ].payload ).toEqual( {
			rid: 'ok-rid',
			partition: 0,
		} );
	} );
} );

describe( 'performance:command — pending registration on the view', () => {
	test( 'fetchOverview registers a pending entry on the view keyed by the command ID', () => {
		const { outbox, view, command } = mount();
		command.fetchOverview();
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( view.pending.has( cmd[ ID ] ) ).toBe( true );
		expect( view.pending.get( cmd[ ID ] ) ).toMatchObject( {
			slice: 'overview',
		} );
	} );

	test( 'fetchUrlDetail registers slice + initial in the pending entry', () => {
		const { outbox, view, command } = mount();
		command.fetchUrlDetail( 'abc123', { initial: true } );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( view.pending.get( cmd[ ID ] ) ).toMatchObject( {
			slice: 'urlDetail',
			initial: true,
		} );
	} );

	test( 'fetchUrlDetail non-initial registers initial:false', () => {
		const { outbox, view, command } = mount();
		command.fetchUrlDetail( 'abc123' );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( view.pending.get( cmd[ ID ] ) ).toMatchObject( {
			slice: 'urlDetail',
			initial: false,
		} );
	} );
} );

describe( 'performance:command — validation errors emit + skip sending', () => {
	test( 'fetchUrlDetail with invalid hash emits error and does NOT build a TM_COMMAND', () => {
		const errs = [];
		const { outbox, view, command } = mount( {
			onError: ( e ) => errs.push( e ),
		} );
		command.fetchUrlDetail( 'NOT-HEX!', { initial: true } );
		const cmds = outbox.filter( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmds ).toHaveLength( 0 );
		const err = outbox.find(
			( m ) => m[ TYPE ] === TM_STRUCT && m[ VALUE ].action === 'error'
		);
		expect( err[ VALUE ] ).toEqual( {
			action: 'error',
			slice: 'urlDetail',
			error: 'Invalid URL hash format',
		} );
		expect( view.pending.size ).toBe( 0 );
		expect( errs[ 0 ].message ).toBe( 'Invalid URL hash format' );
	} );

	test( 'fetchRequestDetail with invalid rid emits error and does NOT send', () => {
		const errs = [];
		const { outbox, command } = mount( {
			onError: ( e ) => errs.push( e ),
		} );
		command.fetchRequestDetail( 'bad rid!', 0 );
		const cmds = outbox.filter( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmds ).toHaveLength( 0 );
		expect( errs[ 0 ].message ).toBe( 'Invalid request ID format' );
	} );

	test( 'fetchRequestDetail with invalid partition emits error and does NOT send', () => {
		const errs = [];
		const { outbox, command } = mount( {
			onError: ( e ) => errs.push( e ),
		} );
		command.fetchRequestDetail( 'ok-rid', -1 );
		const cmds = outbox.filter( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmds ).toHaveLength( 0 );
		expect( errs[ 0 ].message ).toBe( 'Invalid partition number' );
	} );
} );

describe( 'performance:command — resolveRequest & fetchUrlBreakdown via pending', () => {
	test( 'resolveRequest builds a request_search TM_COMMAND and registers a resolveOnly pending', () => {
		const { outbox, view, command } = mount();
		command.resolveRequest( 'rid-9' );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmd[ VALUE ].name ).toBe( 'request_search' );
		expect( cmd[ VALUE ].payload ).toEqual( { rid: 'rid-9' } );
		const entry = view.pending.get( cmd[ ID ] );
		expect( entry ).toMatchObject( { resolveOnly: true } );
		expect( typeof entry.resolve ).toBe( 'function' );
		expect( typeof entry.reject ).toBe( 'function' );
	} );

	test( 'resolveRequest does NOT emit any TM_STRUCT loading control', () => {
		const { outbox, command } = mount();
		command.resolveRequest( 'rid-9' );
		const controls = outbox.filter( ( m ) => m[ TYPE ] === TM_STRUCT );
		expect( controls ).toHaveLength( 0 );
	} );

	test( 'fetchUrlBreakdown returns null on invalid hash without sending', async () => {
		const errs = [];
		const { outbox, command } = mount( {
			onError: ( e ) => errs.push( e ),
		} );
		const result = await command.fetchUrlBreakdown( 'NO', 'method' );
		expect( result ).toBeNull();
		expect( outbox ).toHaveLength( 0 );
		expect( errs ).toHaveLength( 0 );
	} );

	test( 'fetchUrlBreakdown with valid hash sends url_detail TM_COMMAND with a transform pending', () => {
		const { outbox, view, command } = mount();
		command.fetchUrlBreakdown( 'abc123', 'method' );
		const cmd = outbox.find( ( m ) => m[ TYPE ] === TM_COMMAND );
		expect( cmd[ VALUE ].name ).toBe( 'url_detail' );
		expect( cmd[ VALUE ].payload ).toEqual( {
			hash: 'abc123',
			breakdown: 'method',
		} );
		const entry = view.pending.get( cmd[ ID ] );
		expect( entry ).toMatchObject( { resolveOnly: true } );
		expect( typeof entry.transform ).toBe( 'function' );
		// Transform extracts breakdown_time_series.
		expect(
			entry.transform( { breakdown_time_series: { a: 1 } } )
		).toEqual( { a: 1 } );
		expect( entry.transform( {} ) ).toBeNull();
	} );
} );

describe( 'performance:command — close() guard', () => {
	test( 'a fetch after close() emits nothing and does not register pending', () => {
		const { outbox, view, command } = mount();
		command.close();
		command.fetchOverview();
		expect( outbox ).toHaveLength( 0 );
		expect( view.pending.size ).toBe( 0 );
	} );
} );

describe( 'performance:command — node identity', () => {
	test( 'createPerformanceCommand names the node', () => {
		Core.reset();
		const n = createPerformanceCommand( 'performance:command', {} );
		expect( n.name ).toBe( 'performance:command' );
	} );

	test( 'no longer requires a commandClient (no client.send path)', () => {
		// The factory accepts no opts.commandClient — passing one MUST not throw,
		// but the command no longer consults it.
		expect( () =>
			createPerformanceCommand( 'performance:command', {} )
		).not.toThrow();
	} );
} );
