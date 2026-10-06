/**
 * Tests for TagToken — the removable token the rule editor's tag input and
 * the Time field both draw.
 */

import fs from 'fs';
import path from 'path';
import postcss from 'postcss';
import * as React from 'react';
import TagToken from '../TagToken';
import { renderComponent, act } from '../../test-helpers/renderHook';
import {
	compileLocal,
	compileShared,
	resolveCascade,
} from '../../test-helpers/cascade';

/**
 * WordPress's own component stylesheet, which paints every
 * `.components-button` in its admin foreground.
 */
const WORDPRESS = postcss.parse(
	fs.readFileSync(
		path.resolve(
			__dirname,
			'../../../node_modules/@wordpress/components/build-style/style.css'
		),
		'utf8'
	)
);

const mountToken = ( props ) =>
	renderComponent(
		React.createElement( TagToken, {
			label: '/kakapo-4471',
			removeLabel: 'Drop kakapo',
			onRemove: jest.fn(),
			...props,
		} )
	);

describe( 'TagToken', () => {
	it( 'draws the label on the shared badge', () => {
		const { container, unmount } = mountToken();
		const token = container.firstElementChild;
		expect( [ ...token.classList ] ).toEqual( [
			'event-logger-tag-token',
			'newspack-nodes-badge',
		] );
		expect(
			token.querySelector( '.event-logger-tag-text' ).textContent
		).toBe( '/kakapo-4471' );
		unmount();
	} );

	it( 'removes through a close-icon button carrying the remove label', () => {
		const onRemove = jest.fn();
		const { container, unmount } = mountToken( { onRemove } );
		const remove = container.querySelector( 'button' );
		expect( [ ...remove.classList ] ).toEqual(
			expect.arrayContaining( [
				'components-button',
				'event-logger-tag-remove',
			] )
		);
		expect( remove.querySelector( 'svg' ) ).not.toBeNull();
		expect( remove.getAttribute( 'aria-label' ) ).toBe( 'Drop kakapo' );
		act( () => remove.click() );
		expect( onRemove ).toHaveBeenCalledTimes( 1 );
		unmount();
	} );

	it( "paints its × in the skin's ink, through WordPress's foreground token", () => {
		const { container, unmount } = renderComponent(
			React.createElement(
				'div',
				{ className: 'newspack-nodes-ui' },
				React.createElement( TagToken, {
					label: '/kakapo-4471',
					removeLabel: 'Drop kakapo',
					onRemove: jest.fn(),
				} )
			)
		);
		const skin = container.firstElementChild;
		const remove = skin.querySelector( 'button' );
		const sheets = [
			WORDPRESS,
			compileShared( 'styles/_controls.scss' ),
			compileLocal( 'components/TagToken.scss' ),
		];

		expect( resolveCascade( remove, sheets ).color ).toBe(
			'var(--wp-components-color-foreground, #1e1e1e)'
		);
		expect(
			resolveCascade( skin, sheets )[ '--wp-components-color-foreground' ]
		).toBe( 'var(--ink, var(--np-text))' );
		expect(
			resolveCascade( remove.querySelector( 'svg' ), sheets ).fill
		).toBe( 'currentColor' );
		unmount();
	} );
} );
