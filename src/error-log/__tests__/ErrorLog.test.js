/**
 * ErrorLog UI-surface tests — the thin wrapper over the shared
 * `LogStreamViewer` chrome. The virtualized list (LogRowList) and the browse
 * sidebar's LogBrowser are exercised by their own suites; here they are mocked
 * to markers that capture the props ErrorLog wires into them, so these tests
 * cover the toolbar wiring, the row/header renderers, the multi-field
 * matchRow, and the browse rail. Mirrors RequestStream.test.js.
 */

jest.mock( '../hooks/useErrorLogGraph', () => ( {
	useErrorLogGraph: jest.fn(),
} ) );

// Capture the props ErrorLog hands the shared list + sidebar each render.
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
import ErrorLog from '../ErrorLog';
import { renderComponent, act } from '../../test-helpers/renderHook';

const { useErrorLogGraph } = require( '../hooks/useErrorLogGraph' );

// perferrors:view stand-in: model in setStateCache.view, ring on the node.
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
	Core.nodes.set( 'perferrors:view', node );
	return node;
}

function row( overrides = {} ) {
	return {
		id: 1,
		isEven: false,
		rid: 'r1',
		ts: 1748960000,
		k: 'error',
		m: 'boom',
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

describe( 'ErrorLog', () => {
	let setPaused;
	let clearGraph;
	const mounted = [];

	beforeEach( () => {
		Core.reset();
		logRowListProps = undefined;
		setPaused = jest.fn();
		useErrorLogGraph.mockClear();
		clearGraph = jest.fn();
		useErrorLogGraph.mockReturnValue( {
			setFilter: ( term ) => {
				const view = Core.nodes.get( 'perferrors:view' );
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
		const r = renderComponent( React.createElement( ErrorLog, props ) );
		mounted.push( r );
		return r;
	}

	it( 'renders the Error Log heading (no source picker)', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent ).toContain( 'Error Log' );
		expect(
			container.querySelector(
				'.newspack-nodes-toolbar select.newspack-nodes-select'
			)
		).toBeNull();
	} );

	it( 'wires LogRowList at the live perferrors:view node with the fixed row height', () => {
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

	it( 'sends the filter to the view node, and honours the placeholder', () => {
		const node = registerViewFixture();
		const { container } = mount();
		const input = container.querySelector( '.newspack-nodes-search-input' );
		expect( input.placeholder ).toBe(
			'Filter by URL, keyword, message, or request ID…'
		);
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

	it( 'reflects the counts LogRowList reports up as entries (no rate label)', () => {
		registerViewFixture();
		const { container } = mount();
		act( () =>
			logRowListProps.onStats( { total: 40, visible: 12, lps: 3.5 } )
		);
		// The split now means the DEBUG cap, not a filter.
		expect( container.textContent ).toContain( '12 / 40 entries' );
		// No renderRate: the default lines/s phrasing appears instead.
		expect( container.textContent ).not.toContain( 'req/s' );
	} );

	it( 'clear empties the ring and rebases the list via resetSignal', () => {
		const node = registerViewFixture( {
			lines: [ row( { id: 1, rid: 'r-clear' } ) ],
		} );
		const { container } = mount();
		const before = logRowListProps.resetSignal;
		const kept = node.lines;
		const clearBtn = Array.from(
			container.querySelectorAll( '.button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => clearBtn.click() );
		// Clear travels as the view's control; the viewer never blanks `lines`.
		expect( clearGraph ).toHaveBeenCalledTimes( 1 );
		expect( node.lines ).toBe( kept );
		expect( logRowListProps.resetSignal ).toBe( before + 1 );
	} );

	it( 'renderRow draws the fixed-column cells on a grid log row', () => {
		registerViewFixture();
		mount();
		const { container } = renderComponent(
			logRowListProps.renderRow(
				row( {
					id: 8,
					isEven: true,
					rid: 'r-cells',
					k: 'something (warning)',
					m: 'warn text',
					method: 'PATCH',
					url: '/cells',
					urlHash: 'hash-731',
				} )
			)
		);
		const el = container.querySelector( '.newspack-nodes-log-row' );
		expect( el.classList.contains( 'row-even' ) ).toBe( true );
		expect( el.style.gridTemplateColumns ).toContain( 'minmax(0, 3fr)' );
		expect( el.querySelector( '.entry-rid' ).textContent ).toBe(
			'r-cells'
		);
		expect(
			el.querySelector( '.entry-keyword--warning' ).textContent
		).toBe( 'something (warning)' );
		expect( el.querySelector( '.entry-message' ).textContent ).toBe(
			'warn text'
		);
		const link = el.querySelector( '.entry-url-link' );
		expect( link.textContent ).toBe( '/cells' );
		expect( link.getAttribute( 'href' ) ).toBe(
			'admin.php?page=event-logger-overview&url=hash-731'
		);
	} );

	it( 'classifies error/alert/stderr/info keywords via CSS class', () => {
		registerViewFixture();
		mount();
		const cls = ( k ) => {
			const { container } = renderComponent(
				logRowListProps.renderRow( row( { id: 2, k } ) )
			);
			return container.querySelector( '.entry-keyword' ).className;
		};
		expect( cls( 'error' ) ).toContain( 'entry-keyword--error' );
		expect( cls( 'alert' ) ).toContain( 'entry-keyword--alert' );
		expect( cls( 'stderr' ) ).toContain( 'entry-keyword--stderr' );
		expect( cls( 'notice' ) ).toContain( 'entry-keyword--info' );
		// An alert must NOT fall through to the generic info accent.
		expect( cls( 'alert' ) ).not.toContain( 'entry-keyword--info' );
	} );

	it( 'renders the fixed column headers via the shared LogListHeader', () => {
		registerViewFixture();
		const { container } = mount();
		const ths = [
			...container.querySelectorAll( '.newspack-nodes-log-header__th' ),
		].map( ( el ) => el.textContent );
		expect( ths ).toEqual( [
			'Time',
			'Request ID',
			'URL',
			'Keyword',
			'Message',
		] );
	} );

	it( 'the header wrapper carries the same grid template as the rows', () => {
		registerViewFixture();
		const { container } = mount();
		const wrapper = container.querySelector(
			'.event-logger-error-log-columns'
		);
		expect( wrapper ).toBeTruthy();
		const template = wrapper.style.getPropertyValue(
			'--stream-grid-template'
		);
		expect( template ).toBe(
			'100px 240px minmax(0, 2fr) 240px minmax(0, 3fr)'
		);

		const { container: rowc } = renderComponent(
			logRowListProps.renderRow( row( { id: 5 } ) )
		);
		expect(
			rowc.querySelector( '.newspack-nodes-log-row' ).style
				.gridTemplateColumns
		).toBe( template );
	} );

	it( 'has no column picker (fixed columns)', () => {
		registerViewFixture();
		const { container } = mount();
		expect(
			Array.from( container.querySelectorAll( 'button' ) ).find(
				( b ) => b.textContent === 'Cols'
			)
		).toBeUndefined();
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
			useErrorLogGraph.mockReturnValue( {
				setFilter: ( term ) => {
					const view = Core.nodes.get( 'perferrors:view' );
					if ( view ) {
						view.filter = String( term ).toLowerCase();
					}
				},
				setPaused,
				clear: jest.fn(),
				browse: browseMock( {
					pickerOptions: [
						{ key: '', label: 'All partitions (live)' },
						{ key: 'errors.p0', label: 'errors.p0' },
						{ key: 'errors.p4', label: 'errors.p4' },
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
			).toEqual( [ '', 'errors.p0', 'errors.p4' ] );
		} );

		it( 'selecting a partition drives browse.selectPartition', () => {
			registerViewFixture();
			const selectPartition = jest.fn();
			useErrorLogGraph.mockReturnValue( {
				setFilter: ( term ) => {
					const view = Core.nodes.get( 'perferrors:view' );
					if ( view ) {
						view.filter = String( term ).toLowerCase();
					}
				},
				setPaused,
				clear: jest.fn(),
				browse: browseMock( {
					pickerOptions: [
						{ key: '', label: 'All partitions (live)' },
						{ key: 'errors.p4', label: 'errors.p4' },
						{ key: 'errors.p5', label: 'errors.p5' },
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
				setter.call( select, 'errors.p4' );
				select.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			} );
			expect( selectPartition ).toHaveBeenCalledWith( 'errors.p4' );
		} );

		it( 'renders the segment rail when a partition is selected', () => {
			registerViewFixture();
			const browse = browseMock( {
				pickerOptions: [ { key: 'errors.p4', label: 'errors.p4' } ],
				selectedPartition: 'errors.p4',
			} );
			useErrorLogGraph.mockReturnValue( {
				setFilter: ( term ) => {
					const view = Core.nodes.get( 'perferrors:view' );
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
		expect( container.textContent ).toContain( 'Error Log' );
	} );
} );
