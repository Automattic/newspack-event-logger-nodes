/**
 * RequestStream UI-surface tests — the thin wrapper over the shared
 * `LogStreamViewer` chrome. The virtualized list (LogRowList) and the browse
 * sidebar's LogBrowser are exercised by their own suites; here they are mocked
 * to markers that capture the props RequestStream wires into them, so these
 * tests cover the toolbar wiring, the column picker, the row/header renderers,
 * and the browse rail. Mirrors the substrate's PartitionViewer.test.js.
 */

jest.mock( '../hooks/useRequestLogGraph', () => ( {
	useRequestLogGraph: jest.fn(),
} ) );

// Capture the props RequestStream hands the shared list + sidebar each render.
let logRowListProps;
jest.mock( '@newspack-nodes/shared/components/LogRowList', () => ( {
	__esModule: true,
	default: ( props ) => {
		logRowListProps = props;
		return <div data-testid="log-row-list" />;
	},
} ) );

import * as React from 'react';
import { Core } from '@newspack-nodes/runtime';
import RequestStream from '../RequestStream';
import { renderComponent, act } from '../../test-helpers/renderHook';

const { useRequestLogGraph } = require( '../hooks/useRequestLogGraph' );

// requestlog:view stand-in: model in setStateCache.view, ring on the node.
function registerViewFixture( {
	paused = false,
	connectionError = false,
	lines = [],
} = {} ) {
	const node = {
		registrations: { view: {} },
		setStateCache: {},
		lines,
		get linesCount() {
			return this.lines.length;
		},
		lineAt( i ) {
			return this.lines[ i ];
		},
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
	node.setState( 'view', { paused, connectionError } );
	Core.nodes.set( 'requestlog:view', node );
	return node;
}

function row( overrides = {} ) {
	return {
		id: 1,
		isEven: false,
		rid: 'r1',
		url: '/foo',
		urlHash: 'abc123',
		method: 'GET',
		duration_ms: 50,
		status_code: 200,
		timestamp: 1748960000,
		remote_addr: '10.0.0.1',
		user_agent: 'curl/7',
		...overrides,
	};
}

// A browse model the mocked graph hook hands back for the browse-UI tests.
function browseMock( overrides = {} ) {
	return {
		pickerOptions: null,
		selectedPartition: '',
		selectPartition: jest.fn(),
		jump: jest.fn(),
		sidebar: <div data-testid="log-browser" />,
		...overrides,
	};
}

describe( 'RequestStream', () => {
	let setPaused;
	let clearGraph;
	const mounted = [];

	beforeEach( () => {
		Core.reset();
		window.localStorage.clear();
		logRowListProps = undefined;
		setPaused = jest.fn();
		useRequestLogGraph.mockClear();
		clearGraph = jest.fn();
		useRequestLogGraph.mockReturnValue( {
			setFilter: ( term ) => {
				const view = Core.nodes.get( 'requestlog:view' );
				if ( view ) {
					view.filter = String( term ).toLowerCase();
				}
			},
			setPaused,
			clear: clearGraph,
			browse: browseMock(),
		} );
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	function mount( props = {} ) {
		const r = renderComponent(
			React.createElement( RequestStream, { maxEntries: 100, ...props } )
		);
		mounted.push( r );
		return r;
	}

	it( 'renders the Request Log heading (no source picker)', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent ).toContain( 'Request Log' );
		// pickerOptions is null: the toolbar has no source dropdown.
		expect(
			container.querySelector(
				'.newspack-nodes-toolbar select.newspack-nodes-select'
			)
		).toBeNull();
	} );

	it( 'wires LogRowList at the live requestlog:view node with the fixed row height', () => {
		const node = registerViewFixture();
		mount();
		expect( logRowListProps.getNode() ).toBe( node );
		expect( logRowListProps.rowHeight ).toBe( 33 );
	} );

	it( 'pause button reflects the view model and calls setPaused on click', () => {
		registerViewFixture( { paused: false } );
		const { container } = mount();
		const pauseBtn = container.querySelector( 'button.button' );
		expect( pauseBtn.textContent ).toContain( '⏸' );
		act( () => pauseBtn.click() );
		expect( setPaused ).toHaveBeenCalledWith( true );
	} );

	it( 'pause button shows ▶ when the view model is paused', () => {
		registerViewFixture( { paused: true } );
		const { container } = mount();
		const pauseBtn = container.querySelector( 'button.button' );
		expect( pauseBtn.textContent ).toContain( '▶' );
		act( () => pauseBtn.click() );
		expect( setPaused ).toHaveBeenCalledWith( false );
	} );

	it( 'shows the reconnect banner when the view model reports connectionError', () => {
		registerViewFixture( { connectionError: true } );
		const { container } = mount();
		expect(
			container.querySelector( '.newspack-nodes-connection-banner' )
		).toBeTruthy();
	} );

	it( 'does not show the reconnect banner when connected', () => {
		registerViewFixture( { connectionError: false } );
		const { container } = mount();
		expect(
			container.querySelector( '.newspack-nodes-connection-banner' )
		).toBeNull();
	} );

	it( 'sends the URL filter to the view node, and honours the placeholder', () => {
		const node = registerViewFixture();
		const { container } = mount();
		const input = container.querySelector( '.newspack-nodes-search-input' );
		expect( input.placeholder ).toBe( 'Filter by URL…' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;

		act( () => {
			setter.call( input, 'needle-317' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		// Gating at ingest is what keeps a rare match from ageing out of the
		// ring; the list is never handed a filter to re-apply.
		expect( node.filter ).toBe( 'needle-317' );
		expect( logRowListProps.filter ).toBeUndefined();
	} );

	it( 'reflects the counts LogRowList reports up as requests + req/s', () => {
		registerViewFixture();
		const { container } = mount();
		act( () =>
			logRowListProps.onStats( { total: 40, visible: 12, lps: 3.5 } )
		);
		// The split now means the DEBUG cap, not a filter.
		expect( container.textContent ).toContain( '12 / 40 requests' );
		expect( container.textContent ).toContain( '3.5 req/s' );
	} );

	it( 'shows the plain count when nothing is filtered out', () => {
		registerViewFixture();
		const { container } = mount();
		act( () =>
			logRowListProps.onStats( { total: 7, visible: 7, lps: 0 } )
		);
		expect( container.textContent ).toContain( '7 requests' );
	} );

	it( 'clear empties the ring and rebases the list via resetSignal', () => {
		const node = registerViewFixture( {
			lines: [ row( { id: 1, rid: 'r-clear' } ) ],
		} );
		const { container } = mount();
		const before = logRowListProps.resetSignal;
		const kept = node.lines;
		const buttons = container.querySelectorAll( '.button' );
		const clearBtn = Array.from( buttons ).find(
			( b ) => b.textContent === 'Clear'
		);
		act( () => clearBtn.click() );
		// Clear travels as the view's control; the viewer never blanks `lines`.
		expect( clearGraph ).toHaveBeenCalledTimes( 1 );
		expect( node.lines ).toBe( kept );
		expect( logRowListProps.resetSignal ).toBe( before + 1 );
	} );

	it( 'renderRow draws the visible-column cells on a grid log row keyed by id', () => {
		registerViewFixture();
		mount();
		const { container } = renderComponent(
			logRowListProps.renderRow(
				row( {
					id: 8,
					isEven: true,
					rid: 'r-cells',
					url: '/cells',
					status_code: 418,
					duration_ms: 1500,
				} )
			)
		);
		const el = container.querySelector( '.newspack-nodes-log-row' );
		expect( el.classList.contains( 'row-even' ) ).toBe( true );
		expect( el.style.gridTemplateColumns ).toContain( '240px' );
		expect( el.querySelector( '.entry-url-link' ).textContent ).toBe(
			'/cells'
		);
		const status = el.querySelector( '.entry-status' );
		expect( status.textContent ).toBe( '418' );
		expect( status.dataset.status ).toBe( '418' );
		expect( status.className ).not.toContain( 'entry-status--' );
		expect( status.style.color ).toBe( '' );
		expect( el.querySelector( '.entry-duration--slow' ) ).toBeTruthy();
		expect( el.querySelector( '.entry-rid' ).textContent ).toBe(
			'r-cells'
		);
		expect( el.querySelector( '.entry-ip' ).textContent ).toBe(
			'10.0.0.1'
		);
	} );

	it( 'striping comes from the stamped isEven, not position', () => {
		registerViewFixture();
		mount();
		const { container } = renderComponent(
			logRowListProps.renderRow( row( { id: 3, isEven: false } ) )
		);
		const el = container.querySelector( '.newspack-nodes-log-row' );
		expect( el.classList.contains( 'row-odd' ) ).toBe( true );
	} );

	it( 'renders the default column headers via the shared LogListHeader', () => {
		registerViewFixture();
		const { container } = mount();
		const ths = [
			...container.querySelectorAll( '.newspack-nodes-log-header__th' ),
		].map( ( el ) => el.textContent );
		expect( ths ).toEqual( [
			'Time',
			'Request ID',
			'URL',
			'Status',
			'IP',
			'Duration',
		] );
	} );

	it( 'the header wrapper carries the same grid template as the rows', () => {
		registerViewFixture();
		const { container } = mount();
		const wrapper = container.querySelector(
			'.event-logger-request-stream-columns'
		);
		expect( wrapper ).toBeTruthy();
		const template = wrapper.style.getPropertyValue(
			'--stream-grid-template'
		);
		expect( template ).toBe( '100px 240px auto 50px 100px 70px' );

		const { container: rowc } = renderComponent(
			logRowListProps.renderRow( row( { id: 5 } ) )
		);
		expect(
			rowc.querySelector( '.newspack-nodes-log-row' ).style
				.gridTemplateColumns
		).toBe( template );
	} );

	it( 'toggleColumn removes a checked column when its checkbox is clicked', () => {
		registerViewFixture();
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		act( () => colsBtn.click() );
		expect(
			container.querySelector( '.newspack-nodes-column-picker' )
		).toBeTruthy();
		const checkbox = container.querySelector( '#col-rid' );
		expect( checkbox.checked ).toBe( true );
		act( () => checkbox.click() );
		const ths = [
			...container.querySelectorAll( '.newspack-nodes-log-header__th' ),
		].map( ( el ) => el.textContent );
		expect( ths ).not.toContain( 'Request ID' );
		expect(
			JSON.parse(
				window.localStorage.getItem( 'event-logger-stream-columns' )
			)
		).not.toContain( 'rid' );
	} );

	it( 'toggleColumn adds an unchecked column in canonical order', () => {
		registerViewFixture();
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		act( () => colsBtn.click() );
		const uaCheckbox = container.querySelector( '#col-user_agent' );
		expect( uaCheckbox.checked ).toBe( false );
		act( () => uaCheckbox.click() );
		const ths = [
			...container.querySelectorAll( '.newspack-nodes-log-header__th' ),
		].map( ( el ) => el.textContent );
		const ipIdx = ths.indexOf( 'IP' );
		const uaIdx = ths.indexOf( 'UA' );
		const durIdx = ths.indexOf( 'Duration' );
		expect( ipIdx ).toBeLessThan( uaIdx );
		expect( uaIdx ).toBeLessThan( durIdx );
	} );

	it( 'restores a saved column selection from localStorage', () => {
		window.localStorage.setItem(
			'event-logger-stream-columns',
			JSON.stringify( [ 'time', 'rid', 'user_agent' ] )
		);
		registerViewFixture();
		const { container } = mount();
		const ths = [
			...container.querySelectorAll( '.newspack-nodes-log-header__th' ),
		].map( ( el ) => el.textContent );
		expect( ths ).toEqual( [ 'Time', 'Request ID', 'UA' ] );
	} );

	describe( 'glob browse UI', () => {
		it( 'renders neither the partition selector nor the sidebar by default', () => {
			registerViewFixture();
			const { container } = mount();
			expect(
				container.querySelector( 'select.newspack-nodes-select' )
			).toBeNull();
			expect(
				container.querySelector( '[data-testid="log-browser"]' )
			).toBeNull();
		} );

		// An empty catalog is the state every cold load starts in — the reply
		// rides the router tick — so a "no partitions" claim would be false
		// for the first second on a machine that has them.
		it( 'says nothing about partitions before the catalog answers', () => {
			registerViewFixture();
			const { container } = mount();
			expect(
				container.querySelector( '.newspack-nodes-toolbar-status' )
			).toBeNull();
		} );

		it( 'renders a partition selector (All + each dir) once partitions are cataloged', () => {
			registerViewFixture();
			useRequestLogGraph.mockReturnValue( {
				setFilter: ( term ) => {
					const view = Core.nodes.get( 'requestlog:view' );
					if ( view ) {
						view.filter = String( term ).toLowerCase();
					}
				},
				setPaused,
				clear: jest.fn(),
				browse: browseMock( {
					pickerOptions: [
						{ key: '', label: 'All partitions (live)' },
						{ key: 'completed.p0', label: 'completed.p0' },
						{ key: 'completed.p3', label: 'completed.p3' },
					],
				} ),
			} );
			const { container } = mount();
			const select = container.querySelector(
				'select.newspack-nodes-select'
			);
			expect( select ).toBeTruthy();
			expect(
				Array.from( select.options ).map( ( o ) => o.value )
			).toEqual( [ '', 'completed.p0', 'completed.p3' ] );
		} );

		it( 'selecting a partition drives browse.selectPartition', () => {
			registerViewFixture();
			const selectPartition = jest.fn();
			useRequestLogGraph.mockReturnValue( {
				setFilter: ( term ) => {
					const view = Core.nodes.get( 'requestlog:view' );
					if ( view ) {
						view.filter = String( term ).toLowerCase();
					}
				},
				setPaused,
				clear: jest.fn(),
				browse: browseMock( {
					pickerOptions: [
						{ key: '', label: 'All partitions (live)' },
						{ key: 'completed.p3', label: 'completed.p3' },
						{ key: 'completed.p4', label: 'completed.p4' },
					],
					selectPartition,
				} ),
			} );
			const { container } = mount();
			const select = container.querySelector(
				'select.newspack-nodes-select'
			);
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLSelectElement.prototype,
				'value'
			).set;
			act( () => {
				setter.call( select, 'completed.p3' );
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			} );
			expect( selectPartition ).toHaveBeenCalledWith( 'completed.p3' );
		} );

		it( 'renders the segment rail when a partition is selected', () => {
			registerViewFixture();
			const browse = browseMock( {
				pickerOptions: [
					{ key: 'completed.p3', label: 'completed.p3' },
				],
				selectedPartition: 'completed.p3',
			} );
			useRequestLogGraph.mockReturnValue( {
				setFilter: ( term ) => {
					const view = Core.nodes.get( 'requestlog:view' );
					if ( view ) {
						view.filter = String( term ).toLowerCase();
					}
				},
				setPaused,
				clear: jest.fn(),
				browse,
			} );
			const { container } = mount();
			expect(
				container.querySelector( '[data-testid="log-browser"]' )
			).toBeTruthy();
		} );
	} );

	it( 'falls back to an empty model when the view node is absent', () => {
		// No fixture → useNodeState undefined; the chrome still renders.
		const { container } = mount();
		expect( container.textContent ).toContain( 'Request Log' );
	} );
} );
