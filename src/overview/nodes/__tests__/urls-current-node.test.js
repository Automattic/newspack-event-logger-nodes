/**
 * UrlsCurrentNode tests — the gate on the `urls` slice's edge that lets
 * through only the answer to a question the named Fetcher still asks.
 */

import {
	VALUE,
	TO,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	newMessage,
	Core,
	Node,
} from '@newspack-nodes/runtime';
import { UrlsCurrentNode } from '../urls-current-node';

class RecordingSink extends Node {
	constructor() {
		super();
		this.received = [];
	}
	fill( message ) {
		this.received.push( message );
	}
}

beforeEach( () => Core.reset() );

/**
 * A gate reading an asker whose outbox holds `asked`, wired to a sink.
 *
 * @param {string[][]} asked The args of each standing ask.
 * @return {{gate: UrlsCurrentNode, sink: RecordingSink}} The pair.
 */
function makeGate( asked ) {
	const asker = new Node();
	asker.name = 'kahu:fetch';
	asker.outbox = asked.map( ( args ) => ( { args } ) );
	Core.nodes[ asker.name ] = asker;
	const gate = new UrlsCurrentNode();
	gate.arguments = [ 'kahu:fetch' ];
	gate.target = 'kahu:view';
	const sink = new RecordingSink();
	gate.sink = sink;
	return { gate, sink };
}

function reply( args, to = '' ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
	m[ TO ] = to;
	m[ VALUE ] = { name: 'urls', arguments: args, payload: { data: [] } };
	return m;
}

it( 'names the Fetcher it reads from its one argument', () => {
	const { gate } = makeGate( [] );
	expect( gate.asker ).toBe( 'kahu:fetch' );
	expect( gate.arguments ).toEqual( [ 'kahu:fetch' ] );
} );

it( 'forwards the answer to a standing ask, addressed to the view bare', () => {
	const { gate, sink } = makeGate( [ [ '--sort=url' ] ] );

	gate.fill( reply( [ '--sort=url' ], '--sort%3Durl' ) );

	expect( sink.received ).toHaveLength( 1 );
	expect( sink.received[ 0 ][ TO ] ).toBe( 'kahu:view' );
} );

it( 'drops an answer to a question no longer asked', () => {
	const { gate, sink } = makeGate( [ [ '--sort=url' ] ] );

	gate.fill( reply( [ '--sort=count' ] ) );

	expect( sink.received ).toEqual( [] );
	expect( gate.counter ).toBe( 1 );
} );

it( 'names no Fetcher when its arguments name none', () => {
	const { gate } = makeGate( [] );

	gate.arguments = [];

	expect( gate.asker ).toBe( '' );
} );

it( 'drops a reply that echoes no arguments', () => {
	const { gate, sink } = makeGate( [ [ '--search=kahu' ] ] );
	const m = reply( [ '--search=kahu' ] );
	m[ VALUE ] = null;

	gate.fill( m );

	expect( sink.received ).toEqual( [] );
	expect( gate.counter ).toBe( 1 );
} );

it( 'drops everything while the Fetcher it names is not in the graph', () => {
	const gate = new UrlsCurrentNode();
	const sink = new RecordingSink();
	gate.sink = sink;

	gate.fill( reply( [] ) );

	expect( sink.received ).toEqual( [] );
} );
