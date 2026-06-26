/**
 * OverviewViewNode tests — the focused custom slice view for the always-on
 * overview poll slice (D1b de-god). It owns ONLY the overview slice
 * `{ data, loading, error }` and publishes via setState('view', …) for the
 * <OverviewSection> widget (useNodeState).
 *
 * This is the D4 "decoded-object" pattern, NOT SliceViewNode: the server's
 * overview verb returns a live array/object payload (VALUE.payload is the
 * decoded object, not a JSON string), so the view reads value.payload directly.
 *
 * fill() handles three message kinds:
 *   - TM_STRUCT { action:'loading' }  → loading:true, error:null (others kept);
 *   - a command reply (VALUE={name,payload})  → data=payload, loading/error cleared;
 *   - TM_ERROR reply                  → error=<message>, loading cleared, prior data kept.
 */

import {
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	TM_STRUCT,
	newMessage,
	Core,
	CommandInterpreterNode,
} from '@newspack-nodes/runtime';
import { OverviewViewNode } from '../overview-view-node';

beforeEach( () => Core.reset() );

function makeView() {
	const node = new OverviewViewNode();
	node.name = 'overview:view';
	return node;
}

const view = () => Core.node( 'overview:view' ).setStateCache.view;

const reply = ( payload, isError = false ) => {
	const m = newMessage();
	m[ TYPE ] = isError
		? TM_COMMAND | TM_RESPONSE | TM_ERROR
		: TM_COMMAND | TM_RESPONSE;
	m[ VALUE ] = { name: 'overview', payload };
	return m;
};

const loading = () => {
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ VALUE ] = { action: 'loading' };
	return m;
};

describe( 'OverviewViewNode — registration + initial model', () => {
	test( 'is makeNode-able and publishes an empty initial slice', () => {
		CommandInterpreterNode.registerNodeClasses( {
			OverviewView: OverviewViewNode,
		} );
		const interpreter = new CommandInterpreterNode();
		interpreter.name = '_command_interpreter';
		const node = interpreter.makeNode( 'OverviewView', 'overview:view' );
		expect( node ).toBeInstanceOf( OverviewViewNode );
		expect( view() ).toEqual( { data: null, loading: false, error: null } );
	} );
} );

describe( 'OverviewViewNode — fill', () => {
	test( 'loading control sets loading:true and clears error', () => {
		const v = makeView();
		// Seed a prior error, then a loading control must clear it.
		v.fill( reply( 'old-error', true ) );
		expect( view().error ).toBe( 'old-error' );
		v.fill( loading() );
		expect( view().loading ).toBe( true );
		expect( view().error ).toBeNull();
	} );

	test( 'a reply stores the decoded payload as data and clears loading/error', () => {
		const v = makeView();
		v.fill( loading() );
		v.fill( reply( { total_requests: 42 } ) );
		expect( view() ).toEqual( {
			data: { total_requests: 42 },
			loading: false,
			error: null,
		} );
	} );

	test( 'a TM_ERROR reply sets the error string, clears loading, keeps prior data', () => {
		const v = makeView();
		v.fill( reply( { total_requests: 7 } ) );
		v.fill( reply( 'boom', true ) );
		expect( view().error ).toBe( 'boom' );
		expect( view().loading ).toBe( false );
		// Prior data preserved on a transient error.
		expect( view().data ).toEqual( { total_requests: 7 } );
	} );

	test( 'a non-object reply payload is ignored (keeps prior slice)', () => {
		const v = makeView();
		v.fill( reply( { total_requests: 1 } ) );
		// A reply whose VALUE is a bare string (transport garbage) is ignored.
		const garbage = newMessage();
		garbage[ TYPE ] = TM_COMMAND | TM_RESPONSE;
		garbage[ VALUE ] = 'not-an-object';
		v.fill( garbage );
		expect( view().data ).toEqual( { total_requests: 1 } );
	} );
} );
