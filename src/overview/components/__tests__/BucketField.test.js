/* global KeyboardEvent */
/**
 * Tests for BucketField — the Time field holding a selection of five-minute
 * buckets: one token per run of adjacent buckets, and a typed or picked time
 * or range to add more. It holds no selection of its own: every edit reports
 * the selection's canonical spelling through `onChange`.
 */

import * as React from 'react';
import BucketField from '../BucketField';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import { slotsEndingAt } from '../../../test-helpers/chartWire';
import {
	compileLocal,
	compileShared,
	resolveCascade,
	sharedToken,
} from '../../../test-helpers/cascade';

/**
 * The charted slots at 18:40 UTC on 5 October, 11:40 AM in Los Angeles, where
 * the suite runs, newest first: the window opens at 11:45 AM the day before,
 * so every local time names one bucket.
 */
const SLOTS = slotsEndingAt( '2026-10-05-18-40' );

/**
 * Set a controlled input's value through the native setter, so React sees it.
 *
 * @param {HTMLInputElement} input     The input.
 * @param {string}           value     Its new value.
 * @param {string}           inputType How it arrived.
 */
function fill( input, value, inputType ) {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, value );
		input.dispatchEvent(
			new window.InputEvent( 'input', { bubbles: true, inputType } )
		);
	} );
}

/**
 * Type into the box from the keyboard.
 *
 * @param {HTMLInputElement} input The input.
 * @param {string}           value Its new value.
 */
const type = ( input, value ) => fill( input, value, 'insertText' );

/**
 * Pick a suggestion, as a browser fills the box from its list.
 *
 * @param {HTMLInputElement} input The input.
 * @param {string}           value The option's value.
 */
const pick = ( input, value ) => fill( input, value, 'insertReplacementText' );

/**
 * Press a key on an element.
 *
 * @param {Element} target The element.
 * @param {string}  key    KeyboardEvent key name.
 * @return {KeyboardEvent} The dispatched event.
 */
function press( target, key ) {
	const event = new KeyboardEvent( 'keydown', {
		key,
		bubbles: true,
		cancelable: true,
	} );
	act( () => {
		target.dispatchEvent( event );
	} );
	return event;
}

/**
 * Mount the field under an owner holding its selection, as the dashboard
 * does, recording every spelling it reports and every key reaching the owner,
 * as the modal around the field would.
 *
 * @param {string}   value The selection's first spelling.
 * @param {string[]} slots The charted slots, newest first.
 * @return {{container: Element, seen: string[], ownerKeys: string[], input: () => HTMLInputElement, tokens: () => string[], unmount: Function}} The mount.
 */
function mountField( value, slots = SLOTS ) {
	const seen = [];
	const ownerKeys = [];
	function Owner() {
		const [ held, setHeld ] = React.useState( value );
		return React.createElement(
			'div',
			{ onKeyDown: ( event ) => ownerKeys.push( event.key ) },
			React.createElement( BucketField, {
				value: held,
				slots,
				onChange: ( next ) => {
					seen.push( next );
					setHeld( next );
				},
			} )
		);
	}
	const mount = renderComponent( React.createElement( Owner ) );
	return {
		...mount,
		seen,
		ownerKeys,
		input: () => mount.container.querySelector( 'input' ),
		tokens: () =>
			Array.from(
				mount.container.querySelectorAll( '.event-logger-tag-text' )
			).map( ( token ) => token.textContent ),
	};
}

describe( 'BucketField', () => {
	it( 'shows adjacent buckets as one token, a lone bucket as its own', () => {
		const field = mountField(
			'2026-10-05-16-55..2026-10-05-17-10,2026-10-05-18-30'
		);
		expect( field.tokens() ).toEqual( [
			'9:55–10:15 AM',
			'11:30–11:35 AM',
		] );
		field.unmount();
	} );

	it( "sets tokens and box in the tag input's one horizontal row", () => {
		const field = mountField( '2026-10-05-16-55,2026-10-05-18-30' );
		const row = field.container.querySelector(
			'.event-logger-tag-container.horizontal'
		);

		expect(
			Array.from( row.children, ( child ) => child.tagName )
		).toEqual( [ 'SPAN', 'SPAN', 'INPUT', 'DATALIST' ] );
		expect( field.input().className ).toBe( '' );
		field.unmount();
	} );

	it( 'centres its row on the toolbar line, with no margin beneath it', () => {
		const field = mountField( '2026-10-05-18-30' );
		const row = field.container.querySelector(
			'.event-logger-tag-container'
		);
		const won = resolveCascade( row, [
			compileLocal( 'settings/styles/tag-input.scss' ),
		] );

		expect( won[ 'align-items' ] ).toBe( 'center' );
		expect( won[ 'margin-bottom' ] ).toBeUndefined();
		field.unmount();
	} );

	it( "sizes each token to the toolbar's shared control height", () => {
		const field = mountField( '2026-10-05-18-30' );
		const token = field.container.querySelector(
			'.event-logger-tag-token'
		);
		const won = resolveCascade( token, [
			compileShared( 'styles/_components.scss' ),
			compileLocal( 'components/TagToken.scss' ),
			compileLocal( 'settings/styles/tag-input.scss' ),
		] );

		expect( won[ 'min-height' ] ).toBe( sharedToken( 'control-height' ) );
		expect( won[ 'box-sizing' ] ).toBe( 'border-box' );
		field.unmount();
	} );

	it( 'draws no Clear button, leaving each token its own ×', () => {
		const field = mountField(
			'2026-10-05-16-55..2026-10-05-17-10,2026-10-05-18-30'
		);

		expect(
			Array.from(
				field.container.querySelectorAll( 'button' ),
				( button ) => button.getAttribute( 'aria-label' )
			)
		).toEqual( [ 'Remove 9:55–10:15 AM', 'Remove 11:30–11:35 AM' ] );
		field.unmount();
	} );

	it( 'shows a placeholder short enough to fit, the grammar in its title', () => {
		const field = mountField( '' );

		expect( field.input().getAttribute( 'placeholder' ) ).toBe(
			'Time, e.g. 16:55'
		);
		expect( field.input().getAttribute( 'title' ) ).toBe(
			'16:55, 4:55 PM or 16:55-17:30, in local time'
		);
		field.unmount();
	} );

	it( 'adds the latest charted bucket at a typed local time', () => {
		const field = mountField( '2026-10-05-18-30' );
		type( field.input(), '11:40' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [ '2026-10-05-18-30,2026-10-05-18-40' ] );
		expect( field.input().value ).toBe( '' );
		field.unmount();
	} );

	it( 'adds a bucket for each of two picks, clearing the box between', () => {
		const field = mountField( '' );
		pick( field.input(), '10:20 AM' );
		expect( field.input().value ).toBe( '' );
		pick( field.input(), '2:05 AM' );

		expect( field.seen ).toEqual( [
			'2026-10-05-17-20',
			'2026-10-05-09-05,2026-10-05-17-20',
		] );
		expect( field.tokens() ).toEqual( [
			'2:05–2:10 AM',
			'10:20–10:25 AM',
		] );
		field.unmount();
	} );

	it( 'adds a typed range through the bucket opening at its end', () => {
		const field = mountField( '' );
		type( field.input(), '9:55-10:30' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [
			'2026-10-05-16-55..2026-10-05-17-30',
		] );
		expect( field.tokens() ).toEqual( [ '9:55–10:35 AM' ] );
		field.unmount();
	} );

	it( 'adds a forward range longer than 12 hours', () => {
		const field = mountField( '' );
		type( field.input(), '11:45-23:50' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [
			'2026-10-04-18-45..2026-10-05-06-50',
		] );
		expect( field.container.querySelector( '[role="alert"]' ) ).toBeNull();
		field.unmount();
	} );

	it( 'reads a range across local midnight as the latest run it names', () => {
		const field = mountField( '' );
		type( field.input(), '23:50-00:05' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [
			'2026-10-05-06-50..2026-10-05-07-05',
		] );
		expect( field.tokens() ).toEqual( [
			'Oct 4, 11:50 PM–Oct 5, 12:10 AM',
		] );
		field.unmount();
	} );

	it( 'reads a range across midnight at 00:30, its start the day before', () => {
		const field = mountField( '', slotsEndingAt( '2026-10-05-07-30' ) );
		type( field.input(), '23:50-00:05' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [
			'2026-10-05-06-50..2026-10-05-07-05',
		] );
		field.unmount();
	} );

	it.each( [
		[ '17:00, its start charted the day before', '2026-10-06-00-00' ],
		[ '19:00, its start off the chart', '2026-10-06-02-00' ],
	] )(
		'refuses a range running backwards at %s, offering it the right way round',
		( _, last ) => {
			const field = mountField( '', slotsEndingAt( last ) );
			type( field.input(), '17:30-16:55' );
			press( field.input(), 'Enter' );

			expect( field.seen ).toEqual( [] );
			expect( field.input().value ).toBe( '17:30-16:55' );
			expect(
				field.container.querySelector( '[role="alert"]' ).textContent
			).toBe( '17:30-16:55 runs backwards; did you mean 16:55-17:30?' );
			field.unmount();
		}
	);

	it( 'refuses an uncharted time inline, keeping what was typed', () => {
		const field = mountField( '2026-10-05-18-30' );
		type( field.input(), '16:57' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [] );
		expect( field.input().value ).toBe( '16:57' );
		expect( field.input().getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect(
			field.container.querySelector( '[role="alert"]' ).textContent
		).toBe( 'No charted bucket opens at 16:57.' );
		field.unmount();
	} );

	it( 'refuses a range whose start falls before the charted window', () => {
		const field = mountField( '' );
		type( field.input(), '11:30-11:50' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [] );
		expect(
			field.container.querySelector( '[role="alert"]' ).textContent
		).toBe( 'No charted bucket opens at 11:30.' );
		field.unmount();
	} );

	it( 'refuses an unparseable time inline, and typing clears the refusal', () => {
		const field = mountField( '' );
		type( field.input(), 'teatime' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [] );
		expect(
			field.container.querySelector( '[role="alert"]' ).textContent
		).toBe( '"teatime" is not a time: type 16:55, or 16:55-17:30.' );

		type( field.input(), '16:5' );
		expect( field.container.querySelector( '[role="alert"]' ) ).toBeNull();
		field.unmount();
	} );

	it( 'removes the last token on Backspace in an empty field', () => {
		const field = mountField(
			'2026-10-05-16-55..2026-10-05-17-10,2026-10-05-18-30'
		);
		const event = press( field.input(), 'Backspace' );

		expect( field.seen ).toEqual( [
			'2026-10-05-16-55..2026-10-05-17-10',
		] );
		expect( event.defaultPrevented ).toBe( true );
		field.unmount();
	} );

	it( 'leaves the tokens alone on Backspace while text is typed', () => {
		const field = mountField( '2026-10-05-18-30' );
		type( field.input(), '16:5' );
		press( field.input(), 'Backspace' );

		expect( field.seen ).toEqual( [] );
		field.unmount();
	} );

	it( 'drops the typed text and its refusal on Escape, and holds the Escape', () => {
		const field = mountField( '' );
		type( field.input(), 'teatime' );
		press( field.input(), 'Enter' );
		const event = press( field.input(), 'Escape' );

		expect( field.input().value ).toBe( '' );
		expect( field.container.querySelector( '[role="alert"]' ) ).toBeNull();
		expect( event.defaultPrevented ).toBe( true );
		expect( field.ownerKeys ).not.toContain( 'Escape' );
		field.unmount();
	} );

	it( 'lets an Escape in an empty field reach the modal around it', () => {
		const field = mountField( '2026-10-05-18-30' );
		const event = press( field.input(), 'Escape' );

		expect( event.defaultPrevented ).toBe( false );
		expect( field.ownerKeys ).toEqual( [ 'Escape' ] );
		field.unmount();
	} );

	it( 'keeps the Enter of a refused time from the modal around it', () => {
		const field = mountField( '' );
		type( field.input(), 'teatime' );
		const event = press( field.input(), 'Enter' );

		expect( event.defaultPrevented ).toBe( true );
		expect( field.ownerKeys ).toEqual( [] );
		field.unmount();
	} );

	it( 'waits for Enter to refuse a time, rather than refusing as it is typed', () => {
		const field = mountField( '' );
		type( field.input(), '16:5' );

		expect( field.container.querySelector( '[role="alert"]' ) ).toBeNull();
		expect( field.input().getAttribute( 'aria-invalid' ) ).toBeNull();
		field.unmount();
	} );

	it( 'keeps a typed time through a blur, adding nothing', () => {
		const field = mountField( '' );
		type( field.input(), '16:55' );
		act( () => {
			field
				.input()
				.dispatchEvent( new Event( 'focusout', { bubbles: true } ) );
		} );

		expect( field.seen ).toEqual( [] );
		expect( field.input().value ).toBe( '16:55' );
		field.unmount();
	} );

	it( "removes one token through its ×, labelled with the token's span", () => {
		const field = mountField(
			'2026-10-05-16-55..2026-10-05-17-10,2026-10-05-18-30'
		);
		const remove = field.container.querySelector(
			'button[aria-label="Remove 9:55–10:15 AM"]'
		);
		act( () => remove.click() );

		expect( field.seen ).toEqual( [ '2026-10-05-18-30' ] );
		expect( field.tokens() ).toEqual( [ '11:30–11:35 AM' ] );
		field.unmount();
	} );

	it( 'shows a spelling the server would refuse as it arrived, and removes it', () => {
		const field = mountField( '2026-10-05-16-57' );
		expect( field.tokens() ).toEqual( [ '2026-10-05-16-57' ] );
		act( () =>
			field.container
				.querySelector( '.event-logger-tag-token button' )
				.click()
		);

		expect( field.seen ).toEqual( [ '' ] );
		field.unmount();
	} );

	it( 'suggests every charted bucket, newest first, by local time', () => {
		const field = mountField( '' );
		const list = document.getElementById(
			field.input().getAttribute( 'list' )
		);
		const options = Array.from( list.querySelectorAll( 'option' ) );

		expect( options ).toHaveLength( 288 );
		expect( options[ 0 ].value ).toBe( '11:40 AM' );
		expect( options[ 0 ].label ).toBe( '11:40–11:45 AM' );
		expect( options.at( -1 ).value ).toBe( '11:45 AM' );
		field.unmount();
	} );

	it.each( [
		[ '4:55pm', '2026-10-04-23-55' ],
		[ '12:05 AM', '2026-10-05-07-05' ],
		[ '4:55 PM-5:30 pm', '2026-10-04-23-55..2026-10-05-00-30' ],
	] )( 'reads the 12-hour %j under a 12-hour locale', ( typed, spelling ) => {
		const field = mountField( '' );
		type( field.input(), typed );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [ spelling ] );
		field.unmount();
	} );

	it.each( [ '4:55-5:30 PM', '13:00 PM', '0:30 am', '4:55 p.m.', '24:00' ] )(
		'refuses %j as no time it reads',
		( typed ) => {
			const field = mountField( '' );
			type( field.input(), typed );
			press( field.input(), 'Enter' );

			expect( field.seen ).toEqual( [] );
			expect(
				field.container.querySelector( '[role="alert"]' ).textContent
			).toBe( `"${ typed }" is not a time: type 16:55, or 16:55-17:30.` );
			field.unmount();
		}
	);

	describe( 'under a 24-hour locale', () => {
		beforeEach( () => {
			const real = Intl.DateTimeFormat.prototype.resolvedOptions;
			jest.spyOn(
				Intl.DateTimeFormat.prototype,
				'resolvedOptions'
			).mockImplementation( function () {
				return { ...real.call( this ), hour12: false };
			} );
		} );

		afterEach( () => {
			jest.restoreAllMocks();
		} );

		it( 'refuses a 12-hour time, and suggests 24-hour ones', () => {
			const field = mountField( '' );
			type( field.input(), '4:55 PM' );
			press( field.input(), 'Enter' );

			expect( field.seen ).toEqual( [] );
			expect(
				document
					.getElementById( field.input().getAttribute( 'list' ) )
					.querySelector( 'option' ).value
			).toBe( '11:40' );
			expect( field.input().getAttribute( 'title' ) ).toBe(
				'16:55 or 16:55-17:30, in local time'
			);
			field.unmount();
		} );
	} );

	it( 'takes the later of a local time the clocks fall back through', () => {
		const field = mountField( '', slotsEndingAt( '2026-11-01-12-00' ) );
		const values = Array.from(
			document
				.getElementById( field.input().getAttribute( 'list' ) )
				.querySelectorAll( 'option' ),
			( option ) => option.value
		);
		type( field.input(), '1:30' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [ '2026-11-01-09-30' ] );
		expect( new Set( values ).size ).toBe( values.length );
		field.unmount();
	} );

	it( 'refuses a local time the clocks spring forward past', () => {
		const field = mountField( '', slotsEndingAt( '2026-03-08-18-00' ) );
		type( field.input(), '2:30' );
		press( field.input(), 'Enter' );

		expect( field.seen ).toEqual( [] );
		expect(
			field.container.querySelector( '[role="alert"]' ).textContent
		).toBe( 'No charted bucket opens at 2:30.' );
		field.unmount();
	} );
} );
