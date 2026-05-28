/**
 * perfErrorsRoute tests — the classifier node that makes the data/control split a
 * first-class, inspectable node (rule #2: no bespoke controlSink). It receives
 * everything the stream emits and stamps TO per class — keyed on the stream-set
 * KEY marker ('connection'), NOT on VALUE content, so an errors-feed envelope that
 * happens to carry a VALUE.action field is never mistaken for a control.
 * data → the transform; connection-status control (KEY='connection') → the view
 * (skipping the transform).
 */

import {
	Node,
	Core,
	newMessage,
	TYPE,
	TO,
	KEY,
	VALUE,
	TM_STRUCT,
} from '@newspack-nodes/runtime';
import { createPerfErrorsRoute } from '../perfErrorsRoute';

beforeEach( () => Core.reset() );

// A sink that records every message it receives (a snapshot of TO).
function captureSink() {
	const node = new Node();
	node.received = [];
	node.fill = ( m ) =>
		node.received.push( { to: m[ TO ], value: m[ VALUE ] } );
	return node;
}

function makeRoute() {
	const route = createPerfErrorsRoute( 'perferrors:route', {
		dataTarget: 'perferrors:transform',
		controlTarget: 'perferrors:view',
	} );
	route.sink = captureSink();
	return route;
}

test( 'a data envelope is stamped TO the transform', () => {
	const route = makeRoute();
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = 'rid-1';
	m[ VALUE ] = { ts: 1, k: 'error', m: 'boom' };

	route.fill( m );

	expect( route.sink.received ).toHaveLength( 1 );
	expect( route.sink.received[ 0 ].to ).toBe( 'perferrors:transform' );
} );

test( 'a connection-status control (KEY=connection) is stamped TO the view', () => {
	const route = makeRoute();
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = 'connection';
	m[ VALUE ] = { action: 'connection', connectionError: true };

	route.fill( m );

	expect( route.sink.received[ 0 ].to ).toBe( 'perferrors:view' );
} );

test( 'a data envelope whose VALUE carries an action field still routes to the transform', () => {
	// The errors feed streams arbitrary envelopes; a row may legitimately carry
	// VALUE.action. Classification is by the stream-set KEY, not VALUE, so this is
	// data — it must reach the transform.
	const route = makeRoute();
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = '5:100';
	m[ VALUE ] = { action: 'select', rid: 'rid-7' };

	route.fill( m );

	expect( route.sink.received[ 0 ].to ).toBe( 'perferrors:transform' );
} );

test( 'the data target is the node target (visible in ls -t)', () => {
	const route = makeRoute();
	expect( route.target ).toBe( 'perferrors:transform' );
} );

test( 'fill increments the counter (pass-through is a real hop)', () => {
	const route = makeRoute();
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = 'rid-2';
	m[ VALUE ] = { ts: 1 };
	route.fill( m );
	expect( route.counter ).toBe( 1 );
} );

test( 'names the node', () => {
	const route = makeRoute();
	expect( route.name ).toBe( 'perferrors:route' );
} );
