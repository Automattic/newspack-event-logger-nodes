/**
 * Tests for aggregator-admin/index.js — the jQuery glue that wires the
 * server-rendered "Configured Servers" UI to the M5 CommandClient.
 *
 * The IIFE registers four delegated handlers on `$(document)`. To test
 * them without a real jQuery, we stub `window.jQuery` with a chainable
 * jest-fn that records every `on()` call. The captured handlers then run
 * in isolation against fake jQuery wrappers — same UX path as production,
 * just with the DOM glue swapped for inspectable mocks.
 */
/* eslint-disable jsdoc/require-param, jsdoc/require-returns -- test helpers. */

jest.mock( '../api', () => ( {
	addServer: jest.fn(),
	updateServer: jest.fn(),
	removeServer: jest.fn(),
	testServer: jest.fn(),
} ) );
jest.mock( '../../shared/utils/commandClient', () => ( {
	getCommandClient: jest.fn( () => ( { id: 'fake-client' } ) ),
} ) );

/**
 * Build a chainable mock jQuery instance — every method returns the same
 * object so chains like `$row.find().text().css()` stay alive. Tagged
 * with __isJQ so the fake `$` factory can recognize wrappers it's already
 * produced and pass them straight through (mirroring real jQuery's
 * `$( $obj ) === $obj` behavior).
 */
function chainable() {
	const obj = {
		__isJQ: true,
		val: jest.fn(),
		text: jest.fn().mockReturnThis(),
		css: jest.fn().mockReturnThis(),
		prop: jest.fn().mockReturnThis(),
		data: jest.fn(),
		closest: jest.fn(),
		find: jest.fn(),
	};
	return obj;
}

/**
 * Set up a fake jQuery factory. Calling `$( selector )` returns the
 * registered wrapper for that selector, or a default chainable if
 * unregistered. `$( document ).on( evt, sel, handler )` stores handlers
 * keyed by selector. `$( wrapper )` returns the wrapper as-is so
 * `$( this )` in delegated handlers works.
 */
function makeJQuery() {
	const handlers = {};
	const selectorMap = {};
	const documentWrapper = {
		on: jest.fn( ( _evt, sel, fn ) => {
			handlers[ sel ] = fn;
		} ),
	};

	function $( target ) {
		if ( target === document ) {
			return documentWrapper;
		}
		if ( target && target.__isJQ ) {
			return target;
		}
		if ( typeof target === 'string' && selectorMap[ target ] ) {
			return selectorMap[ target ];
		}
		// Default empty chainable.
		return chainable();
	}
	$.handlers = handlers;
	$.selectors = selectorMap;
	$.documentWrapper = documentWrapper;
	return $;
}

/**
 * Re-require the api module after `jest.resetModules()`. Each spec gets a
 * fresh apiMock so mock state doesn't leak.
 */
function getApiMock() {
	// eslint-disable-next-line global-require -- intentional: needs to re-resolve after resetModules.
	return require( '../api' );
}

describe( 'aggregator-admin/index.js', () => {
	let $;
	let apiMock;

	beforeEach( () => {
		jest.resetModules();
		apiMock = getApiMock();
		$ = makeJQuery();
		window.jQuery = $;
		window.eventAggregatorAdmin = {
			i18n: {
				testing: 'Testing…',
				success: 'OK!',
				failed: 'NO',
				error: 'Err',
				confirmRemove: 'Remove?',
				adding: 'Adding…',
				added: 'Added!',
			},
		};
		window.alert = jest.fn();
		window.confirm = jest.fn();
		// Silence jsdom's "Not implemented: navigation (reload)" virtual-
		// console error. Production reload runs, but the call cannot be
		// observed (jsdom's Location.reload is non-configurable).
		jest.spyOn( window.console, 'error' ).mockImplementation( () => {} );
		// Load the module — IIFE executes, registers handlers on documentWrapper.
		require( '../index.js' );
	} );

	afterEach( () => {
		delete window.jQuery;
		delete window.eventAggregatorAdmin;
	} );

	it( 'registers a delegated handler for each of the 4 server-CRUD selectors', () => {
		expect( $.handlers[ '.event-aggregator-test' ] ).toEqual(
			expect.any( Function )
		);
		expect( $.handlers[ '.event-aggregator-toggle' ] ).toEqual(
			expect.any( Function )
		);
		expect( $.handlers[ '.event-aggregator-remove' ] ).toEqual(
			expect.any( Function )
		);
		expect( $.handlers[ '#event-aggregator-add-server' ] ).toEqual(
			expect.any( Function )
		);
	} );

	describe( 'test handler', () => {
		function buildContext( serverId = 'spoke-01' ) {
			const $status = chainable();
			const $row = chainable();
			$row.find.mockReturnValue( $status );
			const $button = chainable();
			$button.data.mockImplementation( ( key ) =>
				'server-id' === key ? serverId : null
			);
			$button.closest.mockReturnValue( $row );
			return { $button, $row, $status };
		}

		it( 'reports success via the status span on a clean test', async () => {
			apiMock.testServer.mockResolvedValueOnce( { ok: true } );
			const { $button, $status } = buildContext();
			const handler = $.handlers[ '.event-aggregator-test' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			// We can't directly assert what jQuery returned for `$(this)`,
			// but we CAN verify the api was invoked with the server id
			// $button.data('server-id') returned.
			expect( apiMock.testServer ).toHaveBeenCalledWith(
				{ id: 'fake-client' },
				'spoke-01'
			);
			// $status.text was called with success message after success.
			expect( $status.text ).toHaveBeenCalledWith( 'OK!' );
			expect( $status.css ).toHaveBeenCalledWith( 'color', 'green' );
		} );

		it( 'reports failure via the status span on TM_ERROR', async () => {
			apiMock.testServer.mockRejectedValueOnce(
				new Error( 'connection refused' )
			);
			const { $button, $status } = buildContext();
			const handler = $.handlers[ '.event-aggregator-test' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			// The status text contains "NO: connection refused".
			expect( $status.text ).toHaveBeenCalledWith(
				'NO: connection refused'
			);
			expect( $status.css ).toHaveBeenCalledWith( 'color', 'red' );
		} );

		it( 'falls back to i18n.error when the thrown error has no message', async () => {
			apiMock.testServer.mockRejectedValueOnce( {} );
			const { $button, $status } = buildContext();
			const handler = $.handlers[ '.event-aggregator-test' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			// "NO: " + i18n.error ("Err")
			expect( $status.text ).toHaveBeenCalledWith( 'NO: Err' );
		} );
	} );

	describe( 'toggle handler', () => {
		function buildContext( enabled = 1 ) {
			const $button = chainable();
			$button.data.mockImplementation( ( key ) => {
				if ( 'server-id' === key ) {
					return 'spoke-02';
				}
				if ( 'enabled' === key ) {
					return enabled;
				}
				return null;
			} );
			return { $button };
		}

		it( 'flips enabled→disabled and reloads', async () => {
			apiMock.updateServer.mockResolvedValueOnce( {} );
			const { $button } = buildContext( 1 );
			const handler = $.handlers[ '.event-aggregator-toggle' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( apiMock.updateServer ).toHaveBeenCalledWith(
				{ id: 'fake-client' },
				'spoke-02',
				{ enabled: false }
			);
			// Note: window.location.reload is non-configurable in jsdom —
			// the call hits a stub that logs but is invoked, so the
			// success-path lines still execute.
		} );

		it( 'flips disabled→enabled when data-enabled is not 1', async () => {
			apiMock.updateServer.mockResolvedValueOnce( {} );
			const { $button } = buildContext( 0 );
			const handler = $.handlers[ '.event-aggregator-toggle' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( apiMock.updateServer ).toHaveBeenCalledWith(
				{ id: 'fake-client' },
				'spoke-02',
				{ enabled: true }
			);
		} );

		it( 'alerts and re-enables the button on error', async () => {
			apiMock.updateServer.mockRejectedValueOnce( new Error( 'denied' ) );
			const { $button } = buildContext( 1 );
			const handler = $.handlers[ '.event-aggregator-toggle' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( window.alert ).toHaveBeenCalledWith( 'Err: denied' );
			// $button.prop('disabled', false) called after failure.
			expect( $button.prop ).toHaveBeenCalledWith( 'disabled', false );
		} );
	} );

	describe( 'remove handler', () => {
		function buildContext() {
			const $button = chainable();
			$button.data.mockImplementation( ( key ) =>
				'server-id' === key ? 'spoke-03' : null
			);
			return { $button };
		}

		it( 'aborts when confirm() is canceled — no api call', async () => {
			window.confirm.mockReturnValueOnce( false );
			const { $button } = buildContext();
			const handler = $.handlers[ '.event-aggregator-remove' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( window.confirm ).toHaveBeenCalledWith( 'Remove?' );
			expect( apiMock.removeServer ).not.toHaveBeenCalled();
		} );

		it( 'calls removeServer and reloads on confirmed success', async () => {
			window.confirm.mockReturnValueOnce( true );
			apiMock.removeServer.mockResolvedValueOnce( {} );
			const { $button } = buildContext();
			const handler = $.handlers[ '.event-aggregator-remove' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( apiMock.removeServer ).toHaveBeenCalledWith(
				{ id: 'fake-client' },
				'spoke-03'
			);
			// reload() runs but jsdom's Location.reload is non-configurable,
			// so we can't directly observe it — the surrounding code still
			// executes which is what coverage needs.
		} );

		it( 'alerts and re-enables the button on error', async () => {
			window.confirm.mockReturnValueOnce( true );
			apiMock.removeServer.mockRejectedValueOnce( new Error( 'in-use' ) );
			const { $button } = buildContext();
			const handler = $.handlers[ '.event-aggregator-remove' ];
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( window.alert ).toHaveBeenCalledWith( 'Err: in-use' );
			expect( $button.prop ).toHaveBeenCalledWith( 'disabled', false );
		} );
	} );

	describe( 'add-server handler', () => {
		function setField( selector, value ) {
			const w = chainable();
			// `.val()` with no arg returns the current value as a real
			// string (so `.trim()` works); with one arg it's the setter,
			// returning `this` for chaining (and updates `value`).
			let current = value;
			w.val = jest.fn( ( v ) => {
				if ( undefined !== v ) {
					current = v;
					return w;
				}
				return current;
			} );
			$.selectors[ selector ] = w;
			return w;
		}

		beforeEach( () => {
			$.selectors[ '#add-server-status' ] = chainable();
		} );

		it( 'shows "Server ID is required" when id is empty', async () => {
			setField( '#new-server-id', '' );
			setField( '#new-server-url', 'https://x' );
			setField( '#new-server-username', '' );
			setField( '#new-server-password', '' );
			const status = $.selectors[ '#add-server-status' ];
			const handler = $.handlers[ '#event-aggregator-add-server' ];
			const $button = chainable();
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( status.text ).toHaveBeenCalledWith(
				'Server ID is required'
			);
			expect( apiMock.addServer ).not.toHaveBeenCalled();
		} );

		it( 'shows "Server URL is required" when url is empty', async () => {
			setField( '#new-server-id', 'sp01' );
			setField( '#new-server-url', '' );
			setField( '#new-server-username', '' );
			setField( '#new-server-password', '' );
			const status = $.selectors[ '#add-server-status' ];
			const handler = $.handlers[ '#event-aggregator-add-server' ];
			const $button = chainable();
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( status.text ).toHaveBeenCalledWith(
				'Server URL is required'
			);
			expect( apiMock.addServer ).not.toHaveBeenCalled();
		} );

		it( 'shows https-required when URL does not start with https://', async () => {
			setField( '#new-server-id', 'sp01' );
			setField( '#new-server-url', 'http://insecure' );
			setField( '#new-server-username', '' );
			setField( '#new-server-password', '' );
			const status = $.selectors[ '#add-server-status' ];
			const handler = $.handlers[ '#event-aggregator-add-server' ];
			const $button = chainable();
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( status.text ).toHaveBeenCalledWith(
				'URL must start with https://'
			);
			expect( apiMock.addServer ).not.toHaveBeenCalled();
		} );

		it( 'dispatches addServer with the form values and schedules a reload on success', async () => {
			jest.useFakeTimers();
			setField( '#new-server-id', 'sp01' );
			setField( '#new-server-url', 'https://spoke.example' );
			setField( '#new-server-username', 'admin' );
			setField( '#new-server-password', 'secret' );
			apiMock.addServer.mockResolvedValueOnce( {} );
			const handler = $.handlers[ '#event-aggregator-add-server' ];
			const $button = chainable();
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( apiMock.addServer ).toHaveBeenCalledWith(
				{ id: 'fake-client' },
				{
					id: 'sp01',
					url: 'https://spoke.example',
					auth_username: 'admin',
					auth_password: 'secret',
				}
			);
			// Run the setTimeout that schedules reload — the reload itself
			// runs through jsdom's non-configurable stub, but the timer
			// firing is what we need to cover.
			jest.runAllTimers();
			jest.useRealTimers();
		} );

		it( 'shows the error message on addServer failure', async () => {
			setField( '#new-server-id', 'sp01' );
			setField( '#new-server-url', 'https://spoke.example' );
			setField( '#new-server-username', '' );
			setField( '#new-server-password', '' );
			apiMock.addServer.mockRejectedValueOnce( new Error( 'duplicate' ) );
			const status = $.selectors[ '#add-server-status' ];
			const handler = $.handlers[ '#event-aggregator-add-server' ];
			const $button = chainable();
			await handler.call( $button, { preventDefault: jest.fn() } );

			expect( status.text ).toHaveBeenCalledWith( 'Err: duplicate' );
			expect( $button.prop ).toHaveBeenCalledWith( 'disabled', false );
		} );
	} );
} );
