/* @jest-environment node */

/* global NEWSPACK_NODES_SRC */
/**
 * Guard: every dialog this plugin renders portals to document.body, escaping
 * the themed dashboard root, so each must arrive there wearing the theme
 * classes or its token references resolve to nothing.
 *
 * The walk finds every `<Modal>` in the source by its import, so a dialog
 * added later is held to the contract without being named here. A
 * `@wordpress/components` Modal carries the canonical modal, theme and UI
 * classes on its own `className`; the substrate's shared Modal portals
 * through `ModalPortal`, whose host wears the skin, theme and UI roots.
 * Neither context borrows `.topology-app`.
 */

import fs from 'fs';
import path from 'path';
import {
	parseCode,
	parseSource,
	readSource,
	relativeSource,
	sourceFiles,
	visit,
} from '../test-helpers/sourceAst';

const WP_MODAL = '@wordpress/components';
const SHARED_MODAL = '@newspack-nodes/shared/components/Modal';
const WP_MODAL_CLASSES = [
	'newspack-nodes-modal',
	'newspack-nodes-theme',
	'newspack-nodes-ui',
];

/**
 * The static text of a `className` value: literals, template quasis, and any
 * module-level string constant a template interpolates.
 *
 * @param {Object} expression Babel node.
 * @param {Object} constants  Module-level string constants by name.
 * @return {string} The text the class list is certain to hold.
 */
const staticText = ( expression, constants ) => {
	if ( ! expression ) {
		return '';
	}
	if ( 'StringLiteral' === expression.type ) {
		return expression.value;
	}
	if ( 'JSXExpressionContainer' === expression.type ) {
		return staticText( expression.expression, constants );
	}
	if ( 'Identifier' === expression.type ) {
		return constants[ expression.name ] ?? '';
	}
	if ( 'CallExpression' === expression.type ) {
		return staticText( expression.callee.object, constants );
	}
	if ( 'TemplateLiteral' === expression.type ) {
		return expression.quasis
			.map(
				( quasi, i ) =>
					quasi.value.cooked +
					' ' +
					staticText( expression.expressions[ i ], constants )
			)
			.join( ' ' );
	}
	return '';
};

/**
 * Whether an import binding names a dialog. A named binding is one by the
 * name it exports, `Modal` from anywhere or `Confirm` from the shared module;
 * a default binding is one by the module it comes from, whatever it is
 * locally called, so `import Dialog from './Modal'` is still a dialog.
 *
 * @param {Object} spec   Babel import specifier.
 * @param {string} source The module it imports from.
 * @return {boolean} True for a dialog binding.
 */
const isDialogBinding = ( spec, source ) => {
	if ( 'ImportDefaultSpecifier' === spec.type ) {
		return /(?:^|\/)Modal$/.test( source );
	}
	if ( 'ImportSpecifier' !== spec.type ) {
		return false;
	}
	return (
		'Modal' === spec.imported.name ||
		( SHARED_MODAL === source && 'Confirm' === spec.imported.name )
	);
};

/**
 * Every dialog one module renders, with the module it came from and the
 * static class text on its frame.
 *
 * @param {Object} ast  The module's Babel AST.
 * @param {string} file The module's path below `src`, for reporting.
 * @return {Array<{file: string, source: string, classes: string}>} Dialogs.
 */
const dialogsIn = ( ast, file ) => {
	const imported = {};
	const constants = {};
	for ( const statement of ast.program.body ) {
		if ( 'ImportDeclaration' === statement.type ) {
			for ( const spec of statement.specifiers ) {
				if ( isDialogBinding( spec, statement.source.value ) ) {
					imported[ spec.local.name ] = statement.source.value;
				}
			}
		}
		if ( 'VariableDeclaration' === statement.type ) {
			for ( const declarator of statement.declarations ) {
				if ( 'StringLiteral' === declarator.init?.type ) {
					constants[ declarator.id.name ] = declarator.init.value;
				}
			}
		}
	}
	const found = [];
	visit( ast, ( node ) => {
		if (
			'JSXOpeningElement' !== node.type ||
			'JSXIdentifier' !== node.name.type ||
			! imported[ node.name.name ]
		) {
			return;
		}
		const classAttribute = node.attributes.find(
			( attribute ) =>
				'JSXAttribute' === attribute.type &&
				'className' === attribute.name.name
		);
		found.push( {
			file: `${ file }:${ node.loc.start.line }`,
			source: imported[ node.name.name ],
			classes: staticText( classAttribute?.value, constants ),
		} );
	} );
	return found;
};

/**
 * Every dialog the plugin renders.
 *
 * @return {Array<{file: string, source: string, classes: string}>} Dialogs.
 */
const modals = () =>
	sourceFiles( '.js' ).flatMap( ( file ) =>
		dialogsIn( parseSource( file ), relativeSource( file ) )
	);

describe( 'dashboard Modal theme class', () => {
	it( 'finds every dialog the plugin renders', () => {
		const files = modals().map( ( modal ) => modal.file.split( ':' )[ 0 ] );
		expect( files ).toEqual(
			expect.arrayContaining( [
				'overview/PerformanceDashboard.js',
				'overview/components/AskPanel.js',
				'rules/RuleEditModal.js',
				'rules/RulesAdmin.js',
				'settings/settings/SelectorModal.js',
			] )
		);
	} );

	it( 'finds a dialog by the module it imports, not its local name', () => {
		const found = dialogsIn(
			parseCode(
				[
					"import Dialog from '@newspack-nodes/shared/components/Modal';",
					"import Box from './elsewhere/Modal';",
					"import { Modal as Frame } from '@wordpress/components';",
					"import { Confirm as Ask } from '@newspack-nodes/shared/components/Modal';",
					"import Panel from './Panel';",
					'export default () => <><Dialog /><Box /><Frame /><Ask /><Panel /></>;',
				].join( '\n' )
			),
			'fixture.js'
		);
		expect( found.map( ( dialog ) => dialog.source ) ).toEqual( [
			SHARED_MODAL,
			'./elsewhere/Modal',
			WP_MODAL,
			SHARED_MODAL,
		] );
	} );

	it( 'renders every Modal from one of the two themed shells', () => {
		const strays = modals()
			.filter(
				( modal ) =>
					! [ WP_MODAL, SHARED_MODAL ].includes( modal.source )
			)
			.map( ( modal ) => `${ modal.file } from ${ modal.source }` );
		expect( strays ).toEqual( [] );
	} );

	it( 'puts the modal, theme and UI classes on every @wordpress Modal frame', () => {
		const missing = modals()
			.filter( ( modal ) => WP_MODAL === modal.source )
			.flatMap( ( modal ) =>
				WP_MODAL_CLASSES.filter(
					( name ) => ! modal.classes.split( /\s+/ ).includes( name )
				).map( ( name ) => `${ modal.file }:${ name }` )
			);
		expect( missing ).toEqual( [] );
	} );

	it( 'borrows no topology layout root on any Modal frame', () => {
		const borrowed = modals()
			.filter( ( modal ) => /\btopology-app\b/.test( modal.classes ) )
			.map( ( modal ) => modal.file );
		expect( borrowed ).toEqual( [] );
	} );

	it( 'relies on a shared Modal portal that wears every root class', () => {
		const shared = fs.readFileSync(
			path.join( NEWSPACK_NODES_SRC, 'shared/components/Modal.js' ),
			'utf8'
		);
		const portal = shared.slice(
			shared.indexOf( 'export function ModalPortal' ),
			shared.indexOf( 'export const ModalTitleId' )
		);
		expect( portal ).toContain(
			'className="newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui"'
		);
		expect( portal ).toContain( 'document.body' );
	} );

	it( 'reads no stored skin to dress a modal frame', () => {
		const files = new Set(
			modals().map( ( modal ) => modal.file.split( ':' )[ 0 ] )
		);
		const readers = [ ...files ].filter( ( file ) =>
			readSource( file ).includes( 'getStoredTheme' )
		);
		expect( readers ).toEqual( [] );
	} );

	// Shared modal chrome owns the close-button paint in every skin.
	it.each( [
		'overview/styles/modal.scss',
		'rules/rule-edit-modal.scss',
		'settings/styles/custom-event-selector.scss',
		'settings/styles/hook-selector.scss',
		'settings/styles/selector-modal.scss',
	] )( '%s: does not repaint the modal close button', ( rel ) => {
		expect( readSource( rel ) ).not.toMatch(
			/\.components-modal__header\s*>?\s*button\s*\{[^}]*(?:color|background|border|box-shadow|outline)\s*:/
		);
	} );

	it.each( [
		'settings/settings/HookSelectorModal.js',
		'settings/settings/CustomEventSelectorModal.js',
	] )( '%s: names its own modal frame class', ( rel ) => {
		const src = readSource( rel );
		expect( src ).toMatch(
			/`event-logger-[\w-]+-modal \$\{ className \}`/
		);
		expect( src ).not.toMatch( /className=.*topology-app/ );
	} );
} );
