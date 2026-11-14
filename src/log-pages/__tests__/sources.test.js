/**
 * The cells the three log sources declare: what a reader's eye reads off a
 * row and a role tree does not — the keyword's severity ink and the alert
 * badge, the state badge's colors and its title, the delay tiers, the URL as
 * sent beside its identity's link, and the columns' widths.
 */

import { sourceDeclaration } from '@newspack-nodes/shared/sources';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { renderComponent } from '../../test-helpers/renderHook';
import '../sources';

/**
 * One source's cell for one column, drawn for a row.
 *
 * @param {string} source The source.
 * @param {string} column The column.
 * @param {Object} row    The row.
 * @return {Element} The cell.
 */
const cellOf = ( source, column, row ) =>
	renderComponent( sourceDeclaration( source ).fields[ column ].cell( row ) )
		.container.firstElementChild;

afterEach( () => {
	delete window.eventLoggerHookCategories;
} );

describe( 'the Error Log’s keyword', () => {
	const kind = ( k ) =>
		cellOf( 'eln/errors', 'keyword', { k } ).dataset.keyword;

	it( 'inks each keyword by its severity', () => {
		expect( kind( 'error' ) ).toBe( 'error' );
		expect( kind( 'alert' ) ).toBe( 'alert' );
		expect( kind( 'stderr' ) ).toBe( 'stderr' );
		expect( kind( 'notice 7993' ) ).toBe( 'info' );
		expect( kind( 'warning' ) ).toBe( 'warning' );
		// PSR-3's levels above error accent as errors, not the fleet journal.
		expect( kind( 'qm (critical)' ) ).toBe( 'error' );
		expect( kind( 'qm (alert)' ) ).toBe( 'error' );
		expect( kind( 'qm (emergency)' ) ).toBe( 'error' );
		expect( kind( 'qm (warning)' ) ).toBe( 'warning' );
	} );

	it( 'chips a fleet alert as the shared error badge, and only an alert', () => {
		const chip = ( k ) =>
			cellOf( 'eln/errors', 'keyword', { k } ).querySelector(
				'.newspack-nodes-status-badge'
			);
		expect( chip( 'alert' ).className ).toBe(
			'newspack-nodes-status-badge is-error'
		);
		expect( chip( 'alert' ).textContent ).toBe( 'alert' );
		expect( chip( 'qm (alert)' ) ).toBeNull();
	} );

	it( 'titles the message with its whole text', () => {
		const cell = cellOf( 'eln/errors', 'message', { m: 'kea 7994 slow' } );
		expect( cell.className ).toContain( 'entry-message' );
		expect( cell.title ).toBe( 'kea 7994 slow' );
	} );
} );

describe( 'the Gyroscope’s cells', () => {
	it( 'inks a pale state badge dark so the label stays legible', () => {
		window.eventLoggerHookCategories = {
			_colors: { Settings: '#CDDC39' },
			_patterns: { Settings: [ '^option_' ] },
		};
		const badge = cellOf( 'eln/gyroscope', 'state', {
			state: 'option_home hook',
		} ).querySelector( '.event-logger-state-badge' );
		expect( badge.style.backgroundColor ).toBe( 'rgb(205, 220, 57)' );
		expect( badge.style.color ).toBe( 'rgb(30, 30, 30)' );
	} );

	it( 'titles the clipped state badge with the whole state', () => {
		const badge = ( state ) =>
			cellOf( 'eln/gyroscope', 'state', { state } ).querySelector(
				'.event-logger-state-badge'
			);
		expect( badge( 'include template' ).textContent ).toBe( 'template' );
		expect( badge( 'include template' ).title ).toBe( 'include template' );
		expect( badge( 'Performance_CI query 7995' ).title ).toBe(
			'Performance_CI query 7995'
		);
	} );

	it( 'shows a URL as sent and links it by its identity', () => {
		const link = cellOf( 'eln/gyroscope', 'url', {
			method: 'GET',
			url: 'http://Kea.Test/Moa-7996/?v=?',
		} ).querySelector( 'a' );
		expect( link.textContent ).toBe( 'http://Kea.Test/Moa-7996/?v=?' );
		expect( link.getAttribute( 'href' ) ).toBe(
			`admin.php?page=event-logger-overview&url=${ fnv1a(
				'https://kea.test/moa-7996/?v=?'
			) }`
		);
	} );

	it( 'estimates from the logs a request has not estimated yet', () => {
		expect(
			cellOf( 'eln/gyroscope', 'est', { time_ms: 7997 } ).textContent
		).toBe( '8.00s' );
	} );

	it( 'warns of an age past five seconds and a lag past one', () => {
		const now = Date.now() / 1000;
		const tier = ( column, row ) =>
			cellOf( 'eln/gyroscope', column, row ).classList.contains(
				'is-warning'
			);
		expect( tier( 'age', { last_log_ts: now - 6.41 } ) ).toBe( true );
		expect( tier( 'age', { last_log_ts: now - 0.64 } ) ).toBe( false );
		expect( tier( 'lag', { lag_ms: 1283 } ) ).toBe( true );
		expect( tier( 'lag', { lag_ms: 128 } ) ).toBe( false );
	} );

	it( 'gives State, What and UA room for what they hold', () => {
		const { fields } = sourceDeclaration( 'eln/gyroscope' );
		expect(
			[ 'state', 'what', 'user_agent' ].map(
				( key ) => fields[ key ].width
			)
		).toEqual( [ '160px', '400px', '320px' ] );
	} );
} );
