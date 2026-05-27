/**
 * servers:view tests — owns the Configured-Servers admin view model.
 *
 * The command node emits TM_STRUCT controls; this node turns the raw
 * `{ server_id:{} }` map into the render model — `servers` (array via
 * Object.values), `loading`, `error` — and publishes it via
 * `setState('view', model)`. The React `<ServersAdmin>` reads it with
 * `useNodeState('servers:view','view')`. Mirrors aggregator:view.
 */

import {
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { createServersView } from '../serversView';

// setName registers in the per-process Core registry; clear it between tests.
beforeEach( () => Core.reset() );

// A control message: TM_STRUCT carrying { action, ... }.
function controlMsg( payload ) {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = payload;
	return m;
}

const SAMPLE = {
	'spoke-01': {
		id: 'spoke-01',
		url: 'https://a.example.test',
		enabled: true,
		logs: [ 'firehose.log' ],
		has_credentials: true,
		is_config: false,
	},
	'spoke-02': {
		id: 'spoke-02',
		url: 'https://b.example.test',
		enabled: false,
		logs: [],
		has_credentials: false,
		is_config: true,
	},
};

test( 'publishes an initial loading model on construction', () => {
	const v = createServersView( 'servers:view' );
	expect( v.setStateCache.view ).toMatchObject( {
		servers: null,
		loading: true,
		error: null,
	} );
} );

test( 'a servers control converts the server map to an array of servers', () => {
	const v = createServersView( 'servers:view' );
	v.fill( controlMsg( { action: 'servers', servers: SAMPLE } ) );
	const model = v.setStateCache.view;
	expect( Array.isArray( model.servers ) ).toBe( true );
	expect( model.servers ).toHaveLength( 2 );
	expect( model.servers.map( ( s ) => s.id ) ).toEqual( [
		'spoke-01',
		'spoke-02',
	] );
} );

test( 'a servers control clears loading and any prior error', () => {
	const v = createServersView( 'servers:view' );
	v.fill( controlMsg( { action: 'error', error: 'boom' } ) );
	expect( v.setStateCache.view.error ).toBe( 'boom' );
	v.fill( controlMsg( { action: 'servers', servers: SAMPLE } ) );
	expect( v.setStateCache.view.loading ).toBe( false );
	expect( v.setStateCache.view.error ).toBeNull();
} );

test( 'an empty servers map yields an empty servers array (not null)', () => {
	const v = createServersView( 'servers:view' );
	v.fill( controlMsg( { action: 'servers', servers: {} } ) );
	expect( v.setStateCache.view.servers ).toEqual( [] );
	expect( v.setStateCache.view.loading ).toBe( false );
} );

test( 'a null servers payload yields an empty servers array', () => {
	const v = createServersView( 'servers:view' );
	v.fill( controlMsg( { action: 'servers', servers: null } ) );
	expect( v.setStateCache.view.servers ).toEqual( [] );
} );

test( 'an error control sets the error and clears loading (servers untouched)', () => {
	const v = createServersView( 'servers:view' );
	v.fill( controlMsg( { action: 'servers', servers: SAMPLE } ) );
	v.fill( controlMsg( { action: 'error', error: 'registry down' } ) );
	const model = v.setStateCache.view;
	expect( model.error ).toBe( 'registry down' );
	expect( model.loading ).toBe( false );
	// Prior servers are preserved across a transient error.
	expect( model.servers ).toHaveLength( 2 );
} );

test( 'an error control defaults the message when none is supplied', () => {
	const v = createServersView( 'servers:view' );
	v.fill( controlMsg( { action: 'error' } ) );
	expect( typeof v.setStateCache.view.error ).toBe( 'string' );
	expect( v.setStateCache.view.error.length ).toBeGreaterThan( 0 );
} );

test( 'ignores a message with no action', () => {
	const v = createServersView( 'servers:view' );
	const initial = v.setStateCache.view;
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = { servers: SAMPLE };
	v.fill( m );
	// Unchanged: still the initial loading model.
	expect( v.setStateCache.view ).toBe( initial );
} );

test( 'names the node', () => {
	const v = createServersView( 'servers:view' );
	expect( v.name ).toBe( 'servers:view' );
} );
