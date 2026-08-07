/* @jest-environment node */

import fs from 'fs';
import path from 'path';
import { pathToFileURL } from 'url';
import * as sass from 'sass';
// PostCSS is a test/build dependency used to inspect the compiled cascade.
// eslint-disable-next-line import/no-extraneous-dependencies
import postcss from 'postcss';

const SRC = path.resolve( __dirname, '..' );
const NODES = path.resolve( SRC, '../../newspack-nodes/src' );
const NODES_SHARED = path.join( NODES, 'shared' );
const SHARED_ALIAS = '@newspack-nodes/shared';
const WORDPRESS_BUTTON_GEOMETRY = [
	{
		name: 'desktop button-small',
		selector: '.wp-core-ui .button.button-small',
		declarations: {
			'min-height': '24px',
			padding: '0 8px',
			'font-size': '11px',
			'line-height': '2',
			'white-space': 'nowrap',
			'margin-bottom': '0',
			'vertical-align': 'top',
		},
	},
	{
		name: 'mobile button-small at max-width 782px',
		selector: '.wp-core-ui .button.button-small',
		declarations: {
			'min-height': '40px',
			padding: '0 14px',
			'font-size': '14px',
			'line-height': '2.71428571',
			'white-space': 'nowrap',
			'margin-bottom': '4px',
			'vertical-align': 'middle',
		},
	},
];

const compile = ( file ) =>
	postcss.parse(
		sass.compile( file, {
			importers: [
				{
					findFileUrl( url ) {
						if (
							url !== SHARED_ALIAS &&
							! url.startsWith( `${ SHARED_ALIAS }/` )
						) {
							return null;
						}
						const relative = url
							.slice( SHARED_ALIAS.length )
							.replace( /^\/+/, '' );
						return pathToFileURL(
							path.join( NODES_SHARED, relative )
						);
					},
				},
			],
		} ).css
	);

const normalize = ( value ) => value.replace( /\s+/g, ' ' ).trim();

const findRule = ( stylesheet, selector ) => {
	let match;
	stylesheet.walkRules( ( rule ) => {
		const selectors = rule.selectors || [ rule.selector ];
		if (
			selectors.some(
				( candidate ) =>
					normalize( candidate ) === normalize( selector )
			)
		) {
			match = rule;
		}
	} );
	if ( ! match ) {
		throw new Error( `Missing compiled selector: ${ selector }` );
	}
	return match;
};

const declarations = ( rule ) =>
	Object.fromEntries(
		rule.nodes
			.filter( ( node ) => 'decl' === node.type )
			.map( ( declaration ) => [ declaration.prop, declaration.value ] )
	);

const cascadeDeclarations = ( stylesheet, selector ) => {
	const result = {};
	stylesheet.walkRules( ( rule ) => {
		const selectors = rule.selectors || [ rule.selector ];
		if (
			! selectors.some(
				( candidate ) =>
					normalize( candidate ) === normalize( selector )
			)
		) {
			return;
		}
		Object.assign( result, declarations( rule ) );
	} );
	return result;
};

const specificity = ( selector ) => {
	const withoutWhere = selector.replace( /:where\([^)]*\)/g, '' );
	const functionalPseudos = new Set( [ 'has', 'is', 'not' ] );
	const pseudoClasses = (
		withoutWhere.match( /:(?!:)[\w-]+/g ) || []
	).filter(
		( pseudo ) => ! functionalPseudos.has( pseudo.slice( 1 ) )
	).length;

	return [
		( withoutWhere.match( /#[\w-]+/g ) || [] ).length,
		( withoutWhere.match( /\.[\w-]+/g ) || [] ).length +
			( withoutWhere.match( /\[[^\]]+\]/g ) || [] ).length +
			pseudoClasses,
		0,
	];
};

const compareSpecificity = ( left, right ) => {
	for ( let index = 0; index < left.length; index++ ) {
		if ( left[ index ] !== right[ index ] ) {
			return left[ index ] - right[ index ];
		}
	}
	return 0;
};

const local = ( relative ) => compile( path.join( SRC, relative ) );
const sharedComponents = compile(
	path.join( NODES_SHARED, 'styles/_components.scss' )
);
const sharedButtons = compile(
	path.join( NODES_SHARED, 'styles/_buttons.scss' )
);
const sharedToolbar = compile(
	path.join( NODES_SHARED, 'styles/_toolbar.scss' )
);

const expectLocalCascadeWin = ( {
	localStylesheet,
	localSelector,
	sharedStylesheet,
	sharedSelector,
	expected,
} ) => {
	const sharedRule = findRule( sharedStylesheet, sharedSelector );
	const localRule = findRule( localStylesheet, localSelector );
	expect( declarations( sharedRule ) ).not.toEqual( {} );
	expect( declarations( localRule ) ).toMatchObject( expected );
	expect(
		compareSpecificity(
			specificity( localSelector ),
			specificity( sharedSelector )
		)
	).toBeGreaterThanOrEqual( 0 );
};

describe( 'Event Logger layout cascade', () => {
	it( 'keeps overview stats as the original five-item flex row', () => {
		expect.hasAssertions();
		expectLocalCascadeWin( {
			localStylesheet: local( 'overview/styles/base.scss' ),
			localSelector: '.event-logger-overview-stats',
			sharedStylesheet: sharedComponents,
			sharedSelector:
				':where(.newspack-nodes-ui) .newspack-nodes-stats-grid',
			expected: {
				display: 'flex',
				'justify-content': 'space-between',
				'align-items': 'baseline',
			},
		} );
	} );

	it.each( [
		[
			'overview/styles/charts.scss',
			'.event-logger-detail-loading',
			{ display: 'block', 'min-height': '0' },
		],
		[
			'current-request/current-request.scss',
			'.eln-current-request__chart-loading',
			{ display: 'block', 'min-height': '0' },
		],
	] )(
		'keeps compact loading geometry in %s',
		( file, localSelector, expected ) => {
			expect.hasAssertions();
			expectLocalCascadeWin( {
				localStylesheet: local( file ),
				localSelector,
				sharedStylesheet: sharedComponents,
				sharedSelector:
					':where(.newspack-nodes-ui) .newspack-nodes-performance-loading',
				expected,
			} );
		}
	);

	it.each( [
		[
			'overview/styles/charts.scss',
			'.event-logger-request-detail-empty',
			{ padding: '0', 'text-align': 'left' },
		],
		[
			'overview/styles/tables.scss',
			'.event-logger-table__empty',
			{ display: 'flex', height: '100px', padding: '0' },
		],
		[
			'overview/styles/flame-graph.scss',
			'.event-logger-flame-empty',
			{ padding: '24px' },
		],
		[
			// No padding here: the row carries `is-quiet`, which is the shared
			// variant for an empty state that should read as a quiet note
			// rather than a boxed panel, so it takes the shared padding.
			'gyroscope/styles/request-stream.scss',
			'.event-logger-request-stream-empty',
			{ display: 'flex', height: '100%' },
		],
	] )(
		'keeps compact empty-state geometry in %s',
		( file, localSelector, expected ) => {
			expect.hasAssertions();
			const stylesheet = local( file );
			expectLocalCascadeWin( {
				localStylesheet: stylesheet,
				localSelector,
				sharedStylesheet: sharedComponents,
				sharedSelector:
					':where(.newspack-nodes-ui) .newspack-nodes-empty-state',
				expected,
			} );
			expect(
				declarations(
					findRule( stylesheet, `${ localSelector }::before` )
				).display
			).toBe( 'none' );
		}
	);

	it( 'keeps the current-request panel and title compact', () => {
		const stylesheet = local( 'current-request/current-request.scss' );
		expect(
			declarations( findRule( stylesheet, '.eln-current-request' ) )
		).toMatchObject( {
			display: 'flex',
			gap: '16px',
			padding: '16px',
			'font-size': '13px',
			'line-height': '1.5',
		} );
		expect(
			declarations(
				findRule( stylesheet, '.eln-current-request__title' )
			)
		).toMatchObject( {
			margin: '0',
			'font-size': '20px',
			'word-break': 'break-all',
		} );
	} );

	it( 'leaves sortable-header alignment to consumer layout modifiers', () => {
		const sharedSelector =
			':where(.newspack-nodes-ui) .newspack-nodes-table__header .newspack-nodes-sortable-header-button';
		const sharedDeclarations = declarations(
			findRule( sharedComponents, sharedSelector )
		);
		const stylesheet = local( 'overview/styles/tables.scss' );

		expect( sharedDeclarations[ 'text-align' ] ).toBeUndefined();
		expect( sharedDeclarations ).toMatchObject( {
			'letter-spacing': 'normal',
			'text-transform': 'none',
		} );
		expect(
			declarations(
				findRule( stylesheet, '.event-logger-table__header-btn' )
			)[ 'text-align' ]
		).toBe( 'left' );
		expect(
			declarations(
				findRule(
					stylesheet,
					'.event-logger-table__header-btn--numeric'
				)
			)[ 'text-align' ]
		).toBe( 'right' );
		expect(
			declarations(
				findRule(
					stylesheet,
					'.event-logger-table__header-btn--center'
				)
			)[ 'text-align' ]
		).toBe( 'center' );
	} );

	it( 'ships RequestProfile geometry with every bundle that renders it', () => {
		const source = fs.readFileSync(
			path.join( SRC, 'overview/RequestProfile.js' ),
			'utf8'
		);
		expect( source ).toContain( "import './styles/request-profile.scss';" );
		const stylesheet = local( 'overview/styles/request-profile.scss' );
		expect(
			declarations(
				findRule( stylesheet, '.event-logger-request-profile h3' )
			)
		).toMatchObject( {
			margin: '0 0 12px',
			'font-size': '16px',
		} );
		expect(
			declarations( findRule( stylesheet, '.event-logger-profile-bar' ) )
		).toMatchObject( {
			display: 'flex',
			height: '24px',
			overflow: 'hidden',
			'margin-bottom': '16px',
		} );
	} );

	it( 'keeps Show-more padding above the shared quiet-link reset', () => {
		expect.hasAssertions();
		expectLocalCascadeWin( {
			localStylesheet: local( 'overview/styles/request-profile.scss' ),
			localSelector:
				'.newspack-nodes-ui .event-logger-request-profile .button-link.event-logger-profile-expansion',
			sharedStylesheet: sharedButtons,
			sharedSelector: '.newspack-nodes-ui.newspack-nodes-ui .button-link',
			expected: {
				padding: '4px 8px',
			},
		} );
	} );

	it.each( [
		[
			'gyroscope/styles/inflight.scss',
			'.event-logger-inflight .newspack-nodes-toolbar-stats',
			{ 'flex-direction': 'row', gap: '8px 16px' },
		],
		[
			'requests/styles/request-stream.scss',
			'.event-logger-request-stream .newspack-nodes-toolbar-stats',
			{
				'flex-direction': 'row',
				gap: '8px 16px',
				'min-width': '100px',
			},
		],
		[
			'error-log/styles/error-log.scss',
			'.event-logger-error-log .newspack-nodes-toolbar-stats',
			{ 'min-width': '80px' },
		],
	] )(
		'scopes toolbar-stat overrides to their dashboard in %s',
		( file, localSelector, expected ) => {
			expect.hasAssertions();
			expectLocalCascadeWin( {
				localStylesheet: local( file ),
				localSelector,
				sharedStylesheet: sharedToolbar,
				sharedSelector:
					':where(.newspack-nodes-ui) .newspack-nodes-toolbar-stats',
				expected,
			} );
		}
	);

	it( 'targets ThemedRoot actual direct child for full-page height', () => {
		const rule = findRule(
			local( 'components/ThemedRoot.scss' ),
			'.newspack-nodes-skin-root > .event-logger-admin-wrap'
		);
		expect( declarations( rule ) ).toMatchObject( {
			'min-height': 'calc(100vh - 96px)',
			'box-sizing': 'border-box',
		} );
	} );

	it( 'preserves standalone and embedded modal frame geometry', () => {
		const stylesheet = local( 'rules/rule-edit-modal.scss' );
		expect(
			declarations(
				findRule(
					stylesheet,
					'.event-logger-rule-edit-modal.components-modal__frame'
				)
			)
		).toMatchObject( {
			'min-width': '560px',
			width: '640px',
			'max-width': 'calc(100% - 32px)',
		} );
		expect(
			cascadeDeclarations(
				stylesheet,
				'.newspack-nodes-skin-root.event-logger-rule-edit-modal.components-modal__frame'
			)
		).toMatchObject( {
			display: 'flex',
			'flex-direction': 'column',
			'grid-template': 'none',
			gap: '0',
			width: '600px',
			height: 'auto',
			'min-height': '0',
			'max-height': 'calc(100vh - 64px)',
		} );
		expect(
			declarations(
				findRule(
					stylesheet,
					'.newspack-nodes-skin-root.event-logger-rule-edit-modal.components-modal__frame .components-modal__content'
				)
			)
		).toMatchObject( {
			width: '100%',
			'box-sizing': 'border-box',
			'overflow-y': 'auto',
		} );
		expect(
			declarations(
				findRule(
					stylesheet,
					'.newspack-nodes-skin-root.event-logger-rule-edit-modal.components-modal__frame .components-modal__header'
				)
			)
		).toMatchObject( {
			padding: '0 18px',
			height: '64px',
		} );
		for ( const picker of [ 'hook-selector', 'custom-event' ] ) {
			expect(
				cascadeDeclarations(
					stylesheet,
					`.newspack-nodes-skin-root.event-logger-${ picker }-modal.components-modal__frame`
				)
			).toMatchObject( {
				display: 'flex',
				'flex-direction': 'column',
				'grid-template': 'none',
				gap: '0',
			} );
		}
	} );

	it.each( [
		[
			'overview/styles/modal.scss',
			'.event-logger-performance-modal.event-logger-performance-modal.components-modal__frame .event-logger-modal-back-button',
			{
				width: '36px',
				height: '36px',
				'min-height': '36px',
				padding: '0',
				'font-size': '18px',
				'line-height': 'inherit',
				'white-space': 'normal',
				'margin-bottom': '0',
				'vertical-align': 'baseline',
			},
		],
		[
			'overview/styles/tables.scss',
			'.newspack-nodes-ui .button.button-small.log-entries-search__nav',
			{
				'min-height': '0',
				padding: '2px 5px',
				'font-size': '10px',
				'line-height': '1',
				'white-space': 'nowrap',
				'margin-bottom': '0',
				'vertical-align': 'baseline',
			},
		],
		[
			'overview/styles/tables.scss',
			'.newspack-nodes-ui .button.button-small.event-logger-search-result',
			{
				'min-height': '0',
				padding: '4px 8px',
				'font-size': 'inherit',
				'line-height': 'inherit',
				'white-space': 'normal',
				'margin-bottom': '0',
				'vertical-align': 'baseline',
			},
		],
	] )(
		'beats the actual WordPress desktop and mobile button geometry in %s',
		( file, localSelector, expected ) => {
			const rule = findRule( local( file ), localSelector );
			const localDeclarations = declarations( rule );
			expect( localDeclarations ).toMatchObject( expected );
			for ( const wordpress of WORDPRESS_BUTTON_GEOMETRY ) {
				expect(
					compareSpecificity(
						specificity( localSelector ),
						specificity( wordpress.selector )
					)
				).toBeGreaterThan( 0 );
				for ( const property of Object.keys(
					wordpress.declarations
				) ) {
					expect( localDeclarations ).toHaveProperty( property );
				}
			}
			expect( expected[ 'margin-bottom' ] ).not.toBe( '4px' );
			expect( expected[ 'vertical-align' ] ).not.toBe( 'middle' );
		}
	);
} );
