/**
 * RulesAdmin tests — the thin React view over the ruleset editor node graph.
 *
 * useRulesGraph is owned + tested separately; here it's mocked to hand back a
 * model + spy CRUD callbacks. RuleEditModal is mocked to a trivial "emit a fixed
 * draft on Save" stub so this suite tests RulesAdmin's table + add/edit/delete
 * wiring, not the modal (which has its own suite).
 */

jest.mock( '../useRulesGraph', () => ( {
	__esModule: true,
	useRulesGraph: jest.fn(),
} ) );

jest.mock( '../RuleEditModal', () => ( {
	__esModule: true,
	default: ( { rule, onSave, onCancel } ) => {
		const el = require( 'react' ).createElement;
		return el( 'div', { role: 'dialog', 'data-testid': 'rule-edit' }, [
			el( 'span', { key: 'p' }, `editing:${ rule?.pattern ?? '' }` ),
			el(
				'button',
				{
					key: 's',
					type: 'button',
					'data-testid': 'modal-save',
					onClick: () =>
						onSave( {
							...rule,
							pattern: rule?.pattern || '/new',
							action: 'log',
						} ),
				},
				'save'
			),
			el(
				'button',
				{
					key: 'c',
					type: 'button',
					'data-testid': 'modal-cancel',
					onClick: onCancel,
				},
				'cancel'
			),
		] );
	},
} ) );

import { renderComponent, act } from '../../test-helpers/renderHook';
import RulesAdmin from '../RulesAdmin';

const { useRulesGraph } = require( '../useRulesGraph' );

const SAMPLE_RULES = [
	{
		id: 'r1',
		pattern: '/blog',
		action: 'log',
		auto_disable_threshold: 5,
		auto_protect_time_threshold: 250,
		significant_events: [],
		custom_events: [],
		hooks: [ 'init', 'wp_loaded' ],
		hooks_in: 'inline',
	},
	{
		id: 'r2',
		pattern: '/admin',
		action: 'skip',
		auto_disable_threshold: 0,
		auto_protect_time_threshold: 0,
		significant_events: [],
		custom_events: [],
		hooks: [],
		hooks_in: 'inline',
	},
];

function click( el ) {
	act( () => {
		el.dispatchEvent( new Event( 'click', { bubbles: true } ) );
	} );
}

function dialogButton( testid ) {
	return document.querySelector( `[data-testid="${ testid }"]` );
}

describe( 'RulesAdmin', () => {
	let upsert;
	let remove;
	let saveAll;
	let list;
	const mounted = [];

	function setGraph( overrides = {} ) {
		upsert = jest.fn().mockResolvedValue( { rule: SAMPLE_RULES[ 0 ] } );
		remove = jest.fn().mockResolvedValue( { deleted: true } );
		saveAll = jest.fn().mockResolvedValue( { saved: 2 } );
		list = jest.fn().mockResolvedValue( { rules: SAMPLE_RULES } );
		useRulesGraph.mockReturnValue( {
			rules: SAMPLE_RULES,
			loading: false,
			error: null,
			list,
			saveAll,
			upsert,
			remove,
			...overrides,
		} );
	}

	beforeEach( () => {
		useRulesGraph.mockClear();
		setGraph();
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	function mount() {
		const r = renderComponent( <RulesAdmin /> );
		mounted.push( r );
		return r;
	}

	test( 'mounts the graph (calls useRulesGraph)', () => {
		mount();
		expect( useRulesGraph ).toHaveBeenCalled();
	} );

	test( 'renders a wp-list-table with a row per rule', () => {
		const { container } = mount();
		expect( container.querySelector( 'table.wp-list-table' ) ).toBeTruthy();
		expect(
			container.querySelector( 'tr[data-rule-id="r1"]' )
		).toBeTruthy();
		expect(
			container.querySelector( 'tr[data-rule-id="r2"]' )
		).toBeTruthy();
		expect( container.textContent ).toContain( '/blog' );
		expect( container.textContent ).toContain( '/admin' );
	} );

	test( 'shows the action badge and the hooks count per row', () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		expect( row.textContent.toLowerCase() ).toContain( 'log' );
		// r1 has 2 hooks.
		expect( row.textContent ).toContain( '2' );
	} );

	test( 'shows the empty state when there are no rules', () => {
		setGraph( { rules: [] } );
		const { container } = mount();
		expect( container.textContent ).toContain( 'No rules' );
	} );

	test( 'shows a loading state', () => {
		setGraph( { rules: [], loading: true } );
		const { container } = mount();
		expect( container.textContent.toLowerCase() ).toContain( 'loading' );
	} );

	test( 'shows the error banner from the model', () => {
		setGraph( { error: 'ruleset unavailable' } );
		const { container } = mount();
		expect( container.textContent ).toContain( 'ruleset unavailable' );
	} );

	test( '+ Add Rule opens the edit modal with a blank draft', () => {
		const { container } = mount();
		expect( dialogButton( 'rule-edit' ) ).toBeNull();
		const add = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Add Rule' )
		);
		expect( add ).toBeTruthy();
		click( add );
		expect( dialogButton( 'rule-edit' ) ).toBeTruthy();
	} );

	test( 'saving from the add modal upserts and closes', async () => {
		const { container } = mount();
		const add = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Add Rule' )
		);
		click( add );
		await act( async () => {
			dialogButton( 'modal-save' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		expect( upsert ).toHaveBeenCalledTimes( 1 );
		expect( dialogButton( 'rule-edit' ) ).toBeNull();
	} );

	test( 'Edit opens the modal prefilled with that rule', () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const edit = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Edit'
		);
		click( edit );
		expect( dialogButton( 'rule-edit' ).textContent ).toContain(
			'editing:/blog'
		);
	} );

	test( 'saving from the edit modal upserts the edited rule', async () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const edit = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Edit'
		);
		click( edit );
		await act( async () => {
			dialogButton( 'modal-save' ).dispatchEvent(
				new Event( 'click', { bubbles: true } )
			);
		} );
		expect( upsert ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 'r1', pattern: '/blog' } )
		);
	} );

	test( 'Delete opens a confirm dialog (does not remove immediately)', () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const del = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Delete'
		);
		click( del );
		expect( document.body.textContent ).toContain( 'Are you sure' );
		expect( remove ).not.toHaveBeenCalled();
	} );

	test( 'confirming delete calls remove(id)', async () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const del = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Delete'
		);
		click( del );
		const confirm = Array.from(
			document
				.querySelector( '.rules-admin__confirm' )
				.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.trim() === 'Delete' );
		await act( async () => {
			confirm.dispatchEvent( new Event( 'click', { bubbles: true } ) );
		} );
		expect( remove ).toHaveBeenCalledWith( 'r1' );
	} );

	test( 'cancelling the confirm does not remove', () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const del = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Delete'
		);
		click( del );
		const cancel = Array.from(
			document
				.querySelector( '.rules-admin__confirm' )
				.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.trim() === 'Cancel' );
		click( cancel );
		expect( remove ).not.toHaveBeenCalled();
		expect( document.querySelector( '.rules-admin__confirm' ) ).toBeNull();
	} );

	test( 'the Add Rule button is a stock primary button that keeps its layout class', () => {
		const { container } = mount();
		const add = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Add Rule' )
		);
		expect( add.classList.contains( 'button' ) ).toBe( true );
		expect( add.classList.contains( 'button-primary' ) ).toBe( true );
		expect( add.classList.contains( 'rules-admin__add' ) ).toBe( true );
	} );

	test( 'the row Edit button is a stock compact button', () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const edit = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Edit'
		);
		expect( edit.classList.contains( 'button' ) ).toBe( true );
		expect( edit.classList.contains( 'button-small' ) ).toBe( true );
	} );

	test( 'the row Delete button uses the canonical destructive class', () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const del = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Delete'
		);
		expect( del.classList.contains( 'button' ) ).toBe( true );
		expect( del.classList.contains( 'button-small' ) ).toBe( true );
		expect( del.classList.contains( 'button-link-delete' ) ).toBe( true );
	} );

	test( 'the confirm dialog Cancel and Delete use stock classes', () => {
		const { container } = mount();
		const row = container.querySelector( 'tr[data-rule-id="r1"]' );
		const del = Array.from( row.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Delete'
		);
		click( del );
		const confirm = document.querySelector( '.rules-admin__confirm' );
		const cancel = Array.from( confirm.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.trim() === 'Cancel'
		);
		const confirmDel = Array.from(
			confirm.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.trim() === 'Delete' );
		expect( cancel.classList.contains( 'button' ) ).toBe( true );
		expect( confirmDel.classList.contains( 'button' ) ).toBe( true );
		expect( confirmDel.classList.contains( 'button-link-delete' ) ).toBe(
			true
		);
	} );
} );
