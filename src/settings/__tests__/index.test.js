/**
 * settings/index tests — the settings bundle entry mounts the RulesAdmin editor
 * into its `#event-logger-rules-editor` container. RulesAdmin is mocked so this
 * suite tests only the mount wiring (no node graph / network).
 */

jest.mock( '../../rules/RulesAdmin', () => ( {
	__esModule: true,
	default: () => {
		const el = require( 'react' ).createElement;
		return el( 'div', { 'data-testid': 'rules-admin' }, 'rules-admin' );
	},
} ) );

import { resetSkin, THEME_STORAGE_KEY } from '@newspack-nodes/shared/theme';
import { act } from '../../test-helpers/renderHook';
import { mountRulesEditor } from '../index';

describe( 'settings/index — rules editor mount', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		document.body.removeAttribute( 'style' );
		window.localStorage.removeItem( THEME_STORAGE_KEY );
		resetSkin();
	} );

	test( 'applies the stored skin as the bundle loads, with no container on the page', () => {
		window.localStorage.setItem( THEME_STORAGE_KEY, 'solarized' );
		resetSkin();

		jest.isolateModules( () => {
			require( '../index' );
		} );

		expect( document.getElementById( 'event-logger-rules-editor' ) ).toBe(
			null
		);
		expect( document.documentElement.classList ).toContain(
			'theme-solarized'
		);
	} );

	test( 'mounts RulesAdmin into #event-logger-rules-editor when present', () => {
		const container = document.createElement( 'div' );
		container.id = 'event-logger-rules-editor';
		document.body.appendChild( container );

		act( () => {
			mountRulesEditor();
		} );

		expect(
			container.querySelector( '[data-testid="rules-admin"]' )
		).toBeTruthy();
	} );

	test( 'paints the gutters from the printed wrap and adds no second skin root', () => {
		window.localStorage.setItem( THEME_STORAGE_KEY, 'nord' );
		document.body.innerHTML =
			'<div class="wrap event-logger-settings-wrap newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui">' +
			'<div id="event-logger-rules-editor"></div></div>';
		const wrap = document.querySelector( '.event-logger-settings-wrap' );
		const container = document.getElementById(
			'event-logger-rules-editor'
		);
		const realStyle = window.getComputedStyle;
		// Only a probe parented on the wrap resolves the skin's paper.
		const spy = jest
			.spyOn( window, 'getComputedStyle' )
			.mockImplementation( ( el ) =>
				el.parentElement === wrap
					? {
							backgroundColor: 'rgb(46, 52, 64)',
							getPropertyValue: () => '',
					  }
					: realStyle( el )
			);

		try {
			act( () => {
				mountRulesEditor();
			} );
		} finally {
			spy.mockRestore();
		}

		expect( document.body.style.background ).toBe( 'rgb(46, 52, 64)' );
		expect(
			container.firstElementChild.getAttribute( 'data-testid' )
		).toBe( 'rules-admin' );
		expect( container.querySelector( '.newspack-nodes-skin-root' ) ).toBe(
			null
		);
		expect( document.documentElement.classList ).toContain( 'theme-nord' );
	} );

	test( 'does nothing when the container is absent', () => {
		expect( () => {
			act( () => {
				mountRulesEditor();
			} );
		} ).not.toThrow();
	} );
} );
