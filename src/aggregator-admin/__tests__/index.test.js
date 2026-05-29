/**
 * ServersAdmin UI-surface tests — the thin React view over the Configured-Servers
 * node graph (the full-React replacement for the old jQuery aggregator-admin).
 *
 * The graph is owned by useAggregatorAdminGraph (tested separately); here we mock
 * it to hand back spy CRUD callbacks, and we register a fixture `servers:view` node
 * in Core so the view can read its model via useNodeState. The rendered DOM reuses
 * the exact class names + ids the old PHP table used (`wp-list-table`,
 * `#new-server-id`, `.event-aggregator-test`, …) so the styled result matches.
 * Mirrors AggregatorStatus.test.js.
 */

jest.mock( '../hooks/useAggregatorAdminGraph', () => {
	const actual = jest.requireActual( '../hooks/useAggregatorAdminGraph' );
	return {
		__esModule: true,
		...actual,
		useAggregatorAdminGraph: jest.fn(),
	};
} );

import * as React from 'react';
import { Core } from '@newspack-nodes/runtime';
import ServersAdmin from '../ServersAdmin';
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

const {
	useAggregatorAdminGraph,
} = require( '../hooks/useAggregatorAdminGraph' );

const SAMPLE_SERVERS = [
	{
		id: 'spoke-01',
		url: 'https://a.example.test',
		enabled: true,
		logs: [ 'firehose.log' ],
		has_credentials: true,
		is_config: false,
	},
	{
		id: 'spoke-02',
		url: 'https://b.example.test',
		enabled: false,
		logs: [],
		has_credentials: false,
		is_config: true,
	},
];

// A minimal stand-in for the servers:view node: the model lives in
// setStateCache.view (what useNodeState subscribes to). setState here notifies
// subscribers exactly like the real Node.setState. Mirrors AggregatorStatus's
// registerViewFixture.
function registerViewFixture( overrides = {} ) {
	const model = {
		servers: null,
		loading: true,
		error: null,
		...overrides,
	};
	const node = {
		registrations: { view: {} },
		setStateCache: {},
		register( event, listener, cb ) {
			this.registrations[ event ][ listener ] = cb;
			if ( event in this.setStateCache ) {
				cb( this.setStateCache[ event ] );
			}
		},
		unregister( event, listener ) {
			delete this.registrations[ event ]?.[ listener ];
		},
		setState( event, payload ) {
			this.setStateCache[ event ] = payload;
			Object.values( this.registrations[ event ] || {} ).forEach(
				( cb ) => cb( payload )
			);
		},
	};
	node.setState( 'view', model );
	Core.nodes.set( 'servers:view', node );
	return node;
}

// Set an input's value the React-controlled way and dispatch the input event.
function setInput( input, value ) {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

// The confirm Modal renders into a portal on document.body. Scope to the dialog
// so a label like "Remove" matches the modal action, not the row's Remove button.
function dialogButton( label ) {
	const dialog = document.querySelector( '[role="dialog"]' ) || document;
	return Array.from( dialog.querySelectorAll( 'button' ) ).find(
		( btn ) => btn.textContent.trim() === label
	);
}

describe( 'ServersAdmin', () => {
	let addServer;
	let updateServer;
	let removeServer;
	let testServer;
	const mounted = [];

	beforeEach( () => {
		Core.reset();
		addServer = jest.fn().mockResolvedValue( { id: 'spoke-01' } );
		updateServer = jest.fn().mockResolvedValue( { id: 'spoke-01' } );
		removeServer = jest.fn().mockResolvedValue( { id: 'spoke-01' } );
		testServer = jest
			.fn()
			.mockResolvedValue( { id: 'spoke-01', status: 'connected' } );
		useAggregatorAdminGraph.mockClear();
		useAggregatorAdminGraph.mockReturnValue( {
			addServer,
			updateServer,
			removeServer,
			testServer,
		} );
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	function mount() {
		const r = renderComponent( React.createElement( ServersAdmin ) );
		mounted.push( r );
		return r;
	}

	it( 'mounts the graph (calls useAggregatorAdminGraph)', () => {
		registerViewFixture();
		mount();
		expect( useAggregatorAdminGraph ).toHaveBeenCalled();
	} );

	it( 'renders the server table with the wp-list-table class', () => {
		registerViewFixture( { servers: SAMPLE_SERVERS, loading: false } );
		const { container } = mount();
		expect( container.querySelector( 'table.wp-list-table' ) ).toBeTruthy();
	} );

	it( 'renders a row per server from the view model', () => {
		registerViewFixture( { servers: SAMPLE_SERVERS, loading: false } );
		const { container } = mount();
		expect(
			container.querySelector( 'tr[data-server-id="spoke-01"]' )
		).toBeTruthy();
		expect(
			container.querySelector( 'tr[data-server-id="spoke-02"]' )
		).toBeTruthy();
		expect( container.textContent ).toContain( 'spoke-01' );
		expect( container.textContent ).toContain( 'https://a.example.test' );
	} );

	it( 'shows the no-servers empty row when servers is an empty array', () => {
		registerViewFixture( { servers: [], loading: false } );
		const { container } = mount();
		expect( container.textContent ).toContain( 'No servers configured' );
	} );

	it( 'shows the enabled dashicon for an enabled server', () => {
		registerViewFixture( {
			servers: [ SAMPLE_SERVERS[ 0 ] ],
			loading: false,
		} );
		const { container } = mount();
		expect( container.querySelector( '.dashicons-yes-alt' ) ).toBeTruthy();
	} );

	it( 'shows the disabled dashicon for a disabled server', () => {
		registerViewFixture( {
			servers: [ SAMPLE_SERVERS[ 1 ] ],
			loading: false,
		} );
		const { container } = mount();
		expect( container.querySelector( '.dashicons-no' ) ).toBeTruthy();
	} );

	it( 'renders the add-server form fields with the legacy ids', () => {
		registerViewFixture( { servers: [], loading: false } );
		const { container } = mount();
		expect( container.querySelector( '#new-server-id' ) ).toBeTruthy();
		expect( container.querySelector( '#new-server-url' ) ).toBeTruthy();
		expect(
			container.querySelector( '#new-server-username' )
		).toBeTruthy();
		expect(
			container.querySelector( '#new-server-password' )
		).toBeTruthy();
		expect(
			container.querySelector( '#event-aggregator-add-server' )
		).toBeTruthy();
	} );

	it( 'submits the add form via the graph callback with the field values', async () => {
		registerViewFixture( { servers: [], loading: false } );
		const { container } = mount();
		setInput( container.querySelector( '#new-server-id' ), 'spoke-09' );
		setInput(
			container.querySelector( '#new-server-url' ),
			'https://spoke.example'
		);
		setInput( container.querySelector( '#new-server-username' ), 'admin' );
		setInput( container.querySelector( '#new-server-password' ), 'secret' );
		await act( async () => {
			container
				.querySelector( '#event-aggregator-add-server' )
				.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		} );
		expect( addServer ).toHaveBeenCalledWith( {
			id: 'spoke-09',
			url: 'https://spoke.example',
			auth_username: 'admin',
			auth_password: 'secret',
		} );
	} );

	it( 'blocks add submission and shows a message when the id is empty', async () => {
		registerViewFixture( { servers: [], loading: false } );
		const { container } = mount();
		setInput(
			container.querySelector( '#new-server-url' ),
			'https://spoke.example'
		);
		await act( async () => {
			container
				.querySelector( '#event-aggregator-add-server' )
				.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		} );
		expect( addServer ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain( 'Server ID is required' );
	} );

	it( 'blocks add submission when the URL is not https', async () => {
		registerViewFixture( { servers: [], loading: false } );
		const { container } = mount();
		setInput( container.querySelector( '#new-server-id' ), 'spoke-09' );
		setInput(
			container.querySelector( '#new-server-url' ),
			'http://insecure'
		);
		await act( async () => {
			container
				.querySelector( '#event-aggregator-add-server' )
				.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		} );
		expect( addServer ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain( 'https://' );
	} );

	it( 'opens the confirm dialog (not removeServer) when remove is clicked', async () => {
		registerViewFixture( { servers: SAMPLE_SERVERS, loading: false } );
		const { container } = mount();
		const row = container.querySelector( 'tr[data-server-id="spoke-01"]' );
		await act( async () => {
			row.querySelector( '.event-aggregator-remove' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		// The dialog shows the confirmation message; removeServer is deferred.
		expect( document.body.textContent ).toContain(
			'Are you sure you want to remove this server?'
		);
		expect( removeServer ).not.toHaveBeenCalled();
	} );

	it( 'calls removeServer when the dialog confirm is clicked', async () => {
		registerViewFixture( { servers: SAMPLE_SERVERS, loading: false } );
		const { container } = mount();
		const row = container.querySelector( 'tr[data-server-id="spoke-01"]' );
		await act( async () => {
			row.querySelector( '.event-aggregator-remove' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		await act( async () => {
			dialogButton( 'Remove' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		expect( removeServer ).toHaveBeenCalledWith( 'spoke-01' );
	} );

	it( 'does NOT call removeServer when the dialog is cancelled', async () => {
		registerViewFixture( { servers: SAMPLE_SERVERS, loading: false } );
		const { container } = mount();
		const row = container.querySelector( 'tr[data-server-id="spoke-01"]' );
		await act( async () => {
			row.querySelector( '.event-aggregator-remove' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		await act( async () => {
			dialogButton( 'Cancel' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		expect( removeServer ).not.toHaveBeenCalled();
		// Closing the dialog removes it from the document.
		expect( document.body.textContent ).not.toContain(
			'Are you sure you want to remove this server?'
		);
	} );

	it( 'calls updateServer toggling enabled when the toggle is clicked', async () => {
		registerViewFixture( {
			servers: [ SAMPLE_SERVERS[ 0 ] ],
			loading: false,
		} );
		const { container } = mount();
		const row = container.querySelector( 'tr[data-server-id="spoke-01"]' );
		await act( async () => {
			row.querySelector( '.event-aggregator-toggle' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		// spoke-01 is enabled, so the toggle disables it.
		expect( updateServer ).toHaveBeenCalledWith( 'spoke-01', {
			enabled: false,
		} );
	} );

	it( 'calls testServer and shows the per-row test status on success', async () => {
		testServer.mockResolvedValue( { id: 'spoke-01', status: 'connected' } );
		registerViewFixture( {
			servers: [ SAMPLE_SERVERS[ 0 ] ],
			loading: false,
		} );
		const { container } = mount();
		const row = container.querySelector( 'tr[data-server-id="spoke-01"]' );
		await act( async () => {
			row.querySelector( '.event-aggregator-test' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		expect( testServer ).toHaveBeenCalledWith( 'spoke-01' );
		expect( row.querySelector( '.test-status' ).textContent ).toContain(
			'Connected'
		);
	} );

	it( 'shows the per-row test failure message when testServer rejects', async () => {
		testServer.mockRejectedValue( new Error( 'connection refused' ) );
		registerViewFixture( {
			servers: [ SAMPLE_SERVERS[ 0 ] ],
			loading: false,
		} );
		const { container } = mount();
		const row = container.querySelector( 'tr[data-server-id="spoke-01"]' );
		await act( async () => {
			row.querySelector( '.event-aggregator-test' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		expect( row.querySelector( '.test-status' ).textContent ).toContain(
			'connection refused'
		);
	} );

	it( 'shows the error banner from the view model', () => {
		registerViewFixture( {
			servers: SAMPLE_SERVERS,
			loading: false,
			error: 'registry down',
		} );
		const { container } = mount();
		expect( container.textContent ).toContain( 'registry down' );
	} );

	it( 'falls back to a loading model when the view node is absent', () => {
		// No fixture registered — useNodeState yields undefined; the view must
		// still render (the table chrome + add form) without throwing.
		const { container } = mount();
		expect( container.querySelector( 'table.wp-list-table' ) ).toBeTruthy();
		expect(
			container.querySelector( '#event-aggregator-add-server' )
		).toBeTruthy();
	} );
} );
