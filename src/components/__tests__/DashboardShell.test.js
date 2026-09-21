/**
 * Tests for DashboardShell — the fixed full-viewport chrome the Gyroscope,
 * Request Log and Error Log dashboards share.
 *
 * The debug overlay is mocked so the storage key each page hands the shell is
 * observable in the rendered text; a shared key would silently merge two
 * dashboards' persisted panel layouts.
 */

jest.mock( '@newspack-nodes/debug-overlay', () => ( {
	__esModule: true,
	default: ( { storageKey } ) => `OVERLAY[${ storageKey }]`,
} ) );

import * as React from 'react';
import DashboardShell from '../DashboardShell';
import { renderComponent } from '../../test-helpers/renderHook';

// jsdom has no ResizeObserver and lays nothing out, so the observed element is
// given a box by hand and the observation is fired from here.
let resizeObserverCb = null;
let disconnects = 0;
global.ResizeObserver = class {
	constructor( cb ) {
		resizeObserverCb = cb;
	}
	observe() {}
	disconnect() {
		disconnects++;
	}
};

const withScrollbar = ( el, outer, inner ) => {
	Object.defineProperty( el, 'offsetWidth', {
		value: outer,
		configurable: true,
	} );
	Object.defineProperty( el, 'clientWidth', {
		value: inner,
		configurable: true,
	} );
};

describe( 'DashboardShell', () => {
	it( 'wraps its children in one skinned provider over a fixed viewport box', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				children: () => 'CHILD_MARKER',
			} )
		);

		const provider = container.firstElementChild;
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expect( provider.style.display ).toBe( 'contents' );

		const box = provider.firstElementChild;
		expect( box.style.position ).toBe( 'fixed' );
		expect( box.style.top ).toBe( '32px' );
		expect( box.style.overflowX ).toBe( 'hidden' );
		expect( container.textContent ).toContain( 'CHILD_MARKER' );
		unmount();
	} );

	it( 'paints an opaque backdrop, so nothing behind the box shows through', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				children: () => null,
			} )
		);

		// The box is positioned, not flowed, so an admin notice WordPress
		// relocates above it keeps its own place in the document and shows
		// through a transparent backdrop. The substrate's shared page-surface
		// class is what paints it; appearance stays out of the inline style.
		expect( container.firstElementChild.firstElementChild.className ).toBe(
			'newspack-nodes-page-surface'
		);
		unmount();
	} );

	it( 'heads every dashboard with one wordmark naming its own surface', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				subtitle: 'Request Log',
				children: () => null,
			} )
		);

		// One header, the substrate wordmark, and a subtitle naming THIS
		// dashboard rather than the station the shared component came from.
		expect( container.querySelectorAll( '.topology-header' ) ).toHaveLength(
			1
		);
		expect(
			container.querySelector( '.topology-brand' ).textContent
		).toContain( 'NEWSPACK' );
		const subtitle = container.querySelector( '.topology-subtitle' );
		expect( subtitle.textContent ).toContain( 'Request Log' );
		unmount();
	} );

	it( 'lays the box out as a column, so the header takes no page height', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				subtitle: 'Request Log',
				children: () => null,
			} )
		);

		// A block box would leave the dashboard root's `height: 100%` resolving
		// against the WHOLE box while the header pushed it down 64px — clipped
		// under overflow hidden, a permanent scrollbar under auto. The station
		// box this copies is a flex column for the same reason.
		const box = container.firstElementChild.firstElementChild;
		expect( box.style.display ).toBe( 'flex' );
		expect( box.style.flexDirection ).toBe( 'column' );
		unmount();
	} );

	it( 'scrolls on the axis the page asks for', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'scroll',
				children: () => null,
			} )
		);

		expect(
			container.firstElementChild.firstElementChild.style.overflowY
		).toBe( 'scroll' );
		unmount();
	} );

	// @longform The page's own ask target is this box, not the tall scroller
	// inside it: the surface is fixed at the dashboard's visible rectangle, so
	// its outline traces exactly what the brief answers for. On the scroller,
	// the outline is drawn round a content-height box whose edges are off
	// screen, which reads as no highlight at all.
	it( 'carries the page ask target on the surface, when a page has one', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'k',
				askDescriptor: 'overview:site',
				children: () => null,
			} )
		);

		const surface = container.querySelector(
			'.newspack-nodes-page-surface'
		);
		expect( surface.getAttribute( 'data-ask' ) ).toBe( 'overview:site' );
		expect( surface.hasAttribute( 'data-ask-page' ) ).toBe( true );
		expect( surface.style.position ).toBe( 'fixed' );
		// @longform The ring is an overlay, because an outline on this box is
		// painted over by its own children — measured in the browser: a fixed
		// overlay inside it draws, an outline on it does not. An overlay must
		// be told where the box is, and this is where that geometry lives.
		expect( surface.style.getPropertyValue( '--nodes-page-top' ) ).toBe(
			surface.style.top
		);
		expect( surface.style.getPropertyValue( '--nodes-page-left' ) ).toBe(
			surface.style.left
		);
		unmount();
	} );

	// The surface scrolls, so its own scrollbar sits inside its right edge: a
	// ring drawn at that edge lands beyond the scrollbar and reads as a
	// browser artifact rather than the page's own boundary. The gutter it
	// publishes is what pulls the ring back inside what the reader sees.
	it( 'publishes its scrollbar gutter for the ring to sit inside', () => {
		resizeObserverCb = null;
		disconnects = 0;
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'k',
				askDescriptor: 'overview:site',
				children: () => null,
			} )
		);

		const surface = container.querySelector(
			'.newspack-nodes-page-surface'
		);
		// 1200 outside, 1183 inside: a 17px scrollbar, not the 0 the state
		// starts at, so a shell that never measures fails here.
		withScrollbar( surface, 1200, 1183 );
		React.act( () => resizeObserverCb( [] ) );

		expect( surface.style.getPropertyValue( '--nodes-page-gutter' ) ).toBe(
			'17px'
		);
		unmount();
		expect( disconnects ).toBe( 1 );
	} );

	// Three dashboards pass no descriptor and draw no ring; observing the box
	// for a measurement none of them reads is work for nobody.
	it( 'measures nothing for a page that draws no ring', () => {
		resizeObserverCb = null;
		const { unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'k',
				children: () => null,
			} )
		);

		expect( resizeObserverCb ).toBeNull();
		unmount();
	} );

	it( 'leaves a page with no brief unaskable', () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'k',
				children: () => null,
			} )
		);

		const surface = container.querySelector(
			'.newspack-nodes-page-surface'
		);
		expect( surface.hasAttribute( 'data-ask' ) ).toBe( false );
		expect( surface.hasAttribute( 'data-ask-page' ) ).toBe( false );
		unmount();
	} );

	it( "hands the debug overlay the page's own storage key", () => {
		const { container, unmount } = renderComponent(
			React.createElement( DashboardShell, {
				storageKey: 'newspack-nodes:debug:probe-shell',
				overflowY: 'auto',
				children: () => null,
			} )
		);

		expect( container.textContent ).toContain(
			'OVERLAY[newspack-nodes:debug:probe-shell]'
		);
		unmount();
	} );
} );
