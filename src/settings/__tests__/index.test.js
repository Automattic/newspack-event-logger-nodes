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

import { act } from '../../test-helpers/renderHook';
import { mountRulesEditor } from '../index';

describe( 'settings/index — rules editor mount', () => {
	afterEach( () => {
		document.body.innerHTML = '';
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

	test( 'does nothing when the container is absent', () => {
		expect( () => {
			act( () => {
				mountRulesEditor();
			} );
		} ).not.toThrow();
	} );
} );
