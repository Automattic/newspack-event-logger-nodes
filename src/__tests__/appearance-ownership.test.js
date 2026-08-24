/* @jest-environment node */

import fs from 'fs';
import path from 'path';
import { pathToFileURL } from 'url';
import * as sass from 'sass';
// postcss-scss declares PostCSS as a required peer; both are test/build deps.
// eslint-disable-next-line import/no-extraneous-dependencies
import postcss from 'postcss';
// Jest's Babel toolchain supplies the parser used to inspect real JSX nodes.
// eslint-disable-next-line import/no-extraneous-dependencies
import { parse as parseJs } from '@babel/parser';

const SRC = path.resolve( __dirname, '..' );
const NODES_SHARED = path.resolve( SRC, '../../newspack-nodes/src/shared' );
const SHARED_ALIAS = '@newspack-nodes/shared';
const LEGACY_SHARED_CLASS =
	/event-logger-(?:performance-loading|skeleton(?:--(?:text|card|circle))?|empty-state|no-selection|stats-grid|stat(?:-(?:value|label))?(?![a-z-])|card-link(?:-(?:name|count))?|error-banner)/g;
const APPEARANCE_PROPERTY =
	/^(?:appearance|background(?:-.+)?|color|border(?:-.+)?|border-radius|box-shadow|cursor|filter|opacity|text-shadow|outline(?:-.+)?|font-family|font-weight|text-decoration|text-transform|letter-spacing)$/;
const FOCUS_SELECTOR = /:focus(?:-visible|-within)?/;
const FOCUS_PROPERTY = /^(?:outline(?:-.+)?|box-shadow|border-color)$/;
const DEAD_SELECTOR_NAMES = [
	'custom-event-checkbox',
	'hook-selector-category-discovered',
	'event-logger-field-row',
	'event-logger-field-content',
	'event-logger-reset-btn',
	'event-logger-reset-field',
	'event-logger-reset-number',
	'event-logger-reset-text',
	'event-logger-auto-disable-row',
	'event-logger-auto-disable-label',
	'event-logger-storage-display',
	'event-logger-field-desc',
	'event-logger-field-examples',
	'event-logger-submit-row',
	'event-logger-inflight-error',
	'event-logger-request-stream-error',
	'entry-timing',
];
const CANONICAL_CLASS_CONTRACTS = {
	'components/LoadingFallback.js': [ 'newspack-nodes-performance-loading' ],
	'components/RequestSummary.js': [ 'newspack-nodes-status', 'is-error' ],
	'current-request/CurrentRequestTab.js': [
		'newspack-nodes-empty-state',
		'newspack-nodes-performance-loading',
		'newspack-nodes-status',
	],
	'overview/PerformanceDashboard.js': [
		'newspack-nodes-performance-loading',
		'newspack-nodes-modal',
		'newspack-nodes-status',
		'is-error',
	],
	'overview/components/OverviewSection.js': [
		'newspack-nodes-stats-grid',
		'newspack-nodes-stat',
		'newspack-nodes-stat-value',
		'newspack-nodes-stat-label',
	],
	'overview/UrlTable.js': [
		'newspack-nodes-table',
		'newspack-nodes-table__header',
		'newspack-nodes-table__row',
		'newspack-nodes-table__cell',
		'newspack-nodes-sortable-header-button',
		'entry-status',
	],
	// The two status modifiers belong to errorStatus.js: the views that paint
	// an error_status read their tone from it rather than spelling it.
	'components/errorStatus.js': [ 'is-warning', 'is-error' ],
	'overview/components/UrlDetailView.js': [
		'newspack-nodes-table',
		'newspack-nodes-table__header',
		'newspack-nodes-table__row',
		'newspack-nodes-table__cell',
		'newspack-nodes-sortable-header-button',
		'newspack-nodes-empty-state',
		'entry-status',
		'newspack-nodes-status',
	],
	'overview/components/RequestDetailView.js': [
		'newspack-nodes-badge',
		'newspack-nodes-no-selection',
		'newspack-nodes-status',
	],
	'overview/RequestProfile.js': [
		'newspack-nodes-table',
		'newspack-nodes-table--undivided',
		'newspack-nodes-table__terminal-data',
		'newspack-nodes-table__summary',
		'newspack-nodes-table__details',
		'button-link',
	],
	'overview/components/LogEntriesTable.js': [
		'newspack-nodes-table',
		'newspack-nodes-table--undivided',
		'newspack-nodes-table__terminal-data',
		'newspack-nodes-status is-info',
		'newspack-nodes-status is-warning',
		'newspack-nodes-status is-muted',
		'button-link',
		'button button-small log-entries-search__nav',
	],
	'gyroscope/Inflight.js': [
		'newspack-nodes-table',
		'newspack-nodes-table__row',
		'newspack-nodes-empty-state',
		'newspack-nodes-badge',
		'newspack-nodes-status',
		'is-warning',
	],
	'requests/RequestStream.js': [ 'newspack-nodes-table__row' ],
	// The cell class belongs to the one table primitive the three log
	// dashboards draw through; each still owns its own modifiers below.
	'log-table/logTable.js': [
		'newspack-nodes-table__cell',
		'entry-status',
		'entry-duration',
		'entry-time',
		'entry-url',
		'entry-ip',
		'entry-ua',
	],
	'error-log/ErrorLog.js': [ 'newspack-nodes-table__row', 'entry-keyword' ],
	'overview/FlameGraph.js': [ 'newspack-nodes-empty-state' ],
	'rules/RulesAdmin.js': [
		'newspack-nodes-table',
		'newspack-nodes-table--undivided',
		'newspack-nodes-badge',
		'newspack-nodes-modal',
	],
	// The frame class rides with the shared chrome; each picker keeps the
	// canonical roles of what IT renders.
	'settings/settings/SelectorModal.js': [ 'newspack-nodes-modal' ],
	'settings/settings/CustomEventSelectorModal.js': [
		'newspack-nodes-status',
	],
	'settings/settings/HookSelectorModal.js': [
		'newspack-nodes-badge',
		'newspack-nodes-status',
	],
	'settings/settings/TagInputField.js': [ 'newspack-nodes-badge' ],
	'rules/RuleEditModal.js': [
		'newspack-nodes-error-banner',
		'newspack-nodes-modal',
		'newspack-nodes-status',
	],
};
const INLINE_APPEARANCE_PROPERTY =
	/\b(?:appearance|background(?:[A-Z]\w*)?|border(?:[A-Z]\w*)?|boxShadow|color|cursor|filter|fontFamily|fontWeight|opacity|outline|textDecoration|textTransform|letterSpacing)\s*:/;
const INLINE_SEMANTIC_ALLOWLIST = new Map( [
	[ 'overview/UrlTable.js', [ /linear-gradient\(to right/ ] ],
	[ 'overview/components/UrlDetailView.js', [ /linear-gradient\(to right/ ] ],
	// A legend item's `color` is data handed to `drawLegend`, not a style prop.
	[ 'overview/ResponseTimeChart.js', [ /color: STATUS_COLORS/ ] ],
	[
		'overview/RequestProfile.js',
		[
			/background:\s*getStateColor/,
			/cursor:\s*'pointer'/,
			/cursor:\s*hasEntries/,
		],
	],
	[
		'overview/components/LogEntriesTable.js',
		[
			/background:\s*hexToRgba/,
			/cursor:\s*isFoldable/,
			/cursor:\s*hasPair\( entry \)/,
		],
	],
	[
		'gyroscope/Inflight.js',
		[ /backgroundColor:\s*background/, /color:\s*getTextColor/ ],
	],
] );

const walkSource = ( root ) =>
	fs.readdirSync( root, { withFileTypes: true } ).flatMap( ( entry ) => {
		const absolute = path.join( root, entry.name );
		if ( entry.isDirectory() ) {
			return '__tests__' === entry.name ? [] : walkSource( absolute );
		}
		return /\.(?:js|scss)$/.test( entry.name ) ? [ absolute ] : [];
	} );

const styleFiles = () =>
	walkSource( SRC ).filter( ( file ) => file.endsWith( '.scss' ) );

const compile = ( file ) =>
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
					return pathToFileURL( path.join( NODES_SHARED, relative ) );
				},
			},
		],
	} ).css;

const STYLE_APPEARANCE_ALLOWLIST = [
	{
		file: 'error-log/styles/error-log.scss',
		selector: /^\.entry-keyword--(?:error|warning|alert|stderr|info)$/,
		properties: [ 'background', 'color', 'border-radius', 'font-weight' ],
	},
	{
		file: 'gyroscope/styles/inflight.scss',
		selector: /^\.entry-duration--[\w-]+$/,
		properties: [ 'color' ],
	},
	{
		file: 'requests/styles/request-stream.scss',
		selector: /^\.entry-duration--[\w-]+$/,
		properties: [ 'color' ],
	},
	{
		file: 'overview/styles/request-profile.scss',
		selector: /^\.event-logger-profile-bar$/,
		properties: [ 'background', 'border-radius' ],
	},
	{
		file: 'overview/styles/request-profile.scss',
		selector: /^\.event-logger-profile-swatch$/,
		properties: [ 'border-radius' ],
	},
	{
		file: 'overview/styles/charts.scss',
		selector: /^\.event-logger-chart-tooltip$/,
		properties: [ 'background', 'color', 'border-radius', 'font-family' ],
	},
	{
		file: 'overview/styles/flame-graph.scss',
		selector: /^\.d3-flame-graph-tip$/,
		properties: [ 'font-family' ],
	},
	{
		file: 'overview/styles/tables.scss',
		selector: /^\.event-logger-table__row$/,
		properties: [ 'cursor' ],
	},
	{
		file: 'settings/styles/custom-event-selector.scss',
		selector: /^\.custom-event-item$/,
		properties: [ 'cursor' ],
	},
	{
		file: 'settings/styles/custom-event-selector.scss',
		selector: /^\.custom-event-swatch$/,
		properties: [ 'background', 'border-radius', 'box-shadow' ],
	},
	{
		file: 'settings/styles/hook-selector.scss',
		selector: /\.hook-selector-(?:recommended|recommended-star)$/,
		properties: [ 'color' ],
	},
	{
		file: 'settings/styles/hook-selector.scss',
		selector: /\.hook-selector-(?:category-header|hook)$/,
		properties: [ 'cursor' ],
	},
	{
		file: 'settings/styles/rules-editor.scss',
		selector: /\.rules-admin__badge--(?:log|skip)$/,
		properties: [ 'background', 'color' ],
	},
	{
		file: 'settings/styles/rules-editor.scss',
		selector: /^\.rules-admin__confirm-backdrop$/,
		properties: [ 'background' ],
	},
];
const IMPORTANT_ALLOWLIST = {
	file: 'overview/styles/flame-graph.scss',
	selector: /^\.d3-flame-graph-tip$/,
	properties: [ 'z-index', 'white-space', 'word-wrap', 'overflow-wrap' ],
};

const isAllowedSemanticAppearance = ( file, selector, property ) =>
	STYLE_APPEARANCE_ALLOWLIST.some(
		( allowed ) =>
			file.endsWith( allowed.file ) &&
			allowed.selector.test( selector ) &&
			allowed.properties.includes( property )
	);

const visit = ( node, callback ) => {
	if ( ! node || 'object' !== typeof node ) {
		return;
	}
	callback( node );
	for ( const value of Object.values( node ) ) {
		if ( Array.isArray( value ) ) {
			value.forEach( ( child ) => visit( child, callback ) );
		} else if ( value && 'object' === typeof value && value.type ) {
			visit( value, callback );
		}
	}
};

const jsxName = ( node ) =>
	'JSXIdentifier' === node.name.type ? node.name.name : '';

const staticLocalClasses = ( node, source ) => {
	const classAttribute = node.attributes.find(
		( attribute ) =>
			'JSXAttribute' === attribute.type &&
			'className' === attribute.name.name
	);
	if ( ! classAttribute ) {
		return [];
	}
	const attributeSource = source.slice(
		classAttribute.start,
		classAttribute.end
	);
	return [
		...attributeSource.matchAll(
			/\b(?:event-logger|eln|log-entries|hook-selector|custom-event|rules-admin)[\w-]*/g
		),
	].map( ( match ) => match[ 0 ] );
};

const hasInteractiveRole = ( node, source ) => {
	const name = jsxName( node );
	if (
		[ 'button', 'input', 'select', 'textarea', 'table' ].includes( name ) ||
		/(?:Button|Control|Modal|Card)$/.test( name )
	) {
		return true;
	}
	const roleAttribute = node.attributes.find(
		( attribute ) =>
			'JSXAttribute' === attribute.type && 'role' === attribute.name.name
	);
	return (
		!! roleAttribute &&
		/\b(?:button|dialog|table)\b/.test(
			source.slice( roleAttribute.start, roleAttribute.end )
		)
	);
};

const selectorHasClass = ( selector, className ) =>
	new RegExp(
		`\\.${ className.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }(?![\\w-])`
	).test( selector );

describe( 'canonical appearance ownership', () => {
	it.each( [
		'requests/styles/request-stream.scss',
		'gyroscope/styles/inflight.scss',
	] )( 'leaves HTTP status paint to shared CSS in %s', ( relative ) => {
		const source = fs.readFileSync( path.join( SRC, relative ), 'utf8' );
		expect( source ).not.toMatch( /\.entry-status\s*\{/ );
		expect( source ).not.toMatch( /\bentry-status--/ );
	} );

	it( 'uses the shared data-attribute status taxonomy', () => {
		const file = path.join( NODES_SHARED, 'styles/_components.scss' );
		const stylesheet = postcss.parse( compile( file ), { from: file } );
		const actual = {};
		stylesheet.walkRules( ( rule ) => {
			const match = /\.entry-status\[data-status\^="([2345])"\]/.exec(
				rule.selector
			);
			if ( ! match ) {
				return;
			}
			const color = rule.nodes.find(
				( declaration ) =>
					'decl' === declaration.type && 'color' === declaration.prop
			);
			actual[ match[ 1 ] ] = color?.value;
		} );

		expect( actual ).toEqual( {
			2: 'var(--sage-text, var(--np-text))',
			3: 'var(--brass-text, var(--np-text))',
			4: 'var(--oxide-text, var(--np-text))',
			5: 'var(--oxide-text, var(--np-text))',
		} );
	} );

	it( 'contains no Event Logger copy of a shared primitive class', () => {
		const offenders = [];

		for ( const file of walkSource( SRC ) ) {
			const source = fs.readFileSync( file, 'utf8' );
			for ( const match of source.matchAll( LEGACY_SHARED_CLASS ) ) {
				const line = source
					.slice( 0, match.index )
					.split( '\n' ).length;
				offenders.push(
					`${ path.relative( SRC, file ) }:${ line }:${ match[ 0 ] }`
				);
			}
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'uses the shared token API and keeps the dashboard base non-emitting', () => {
		expect( fs.existsSync( path.join( SRC, 'styles/_tokens.scss' ) ) ).toBe(
			false
		);
		expect(
			fs.existsSync( path.join( SRC, 'settings/styles/_tokens.scss' ) )
		).toBe( false );
		const emitted = compile( path.join( SRC, 'styles/base.scss' ) )
			.replace( /^@charset\s+"UTF-8";\s*/u, '' )
			.replace( /\/\*\*[\s\S]*?\*\//gu, '' )
			.trim();
		expect( emitted ).toBe( '' );
	} );

	it( 'puts every shared DOM role on its canonical class', () => {
		const missing = [];

		for ( const [ file, classes ] of Object.entries(
			CANONICAL_CLASS_CONTRACTS
		) ) {
			const source = fs.readFileSync( path.join( SRC, file ), 'utf8' );
			for ( const className of classes ) {
				if ( ! source.includes( className ) ) {
					missing.push( `${ file }:${ className }` );
				}
			}
		}

		expect( missing ).toEqual( [] );
	} );

	it( 'uses WordPress visual labels for every manually labelled rule field', () => {
		const source = fs.readFileSync(
			path.join( SRC, 'rules/RuleEditModal.js' ),
			'utf8'
		);

		expect(
			source.match( /<BaseControl\.VisualLabel(?:\s|>)/g ) || []
		).toHaveLength( 3 );
		expect( source ).not.toMatch( /\bcomponents-base-control__label\b/ );
	} );

	it( 'leaves rule-field label typography to WordPress', () => {
		const file = path.join( SRC, 'rules/rule-edit-modal.scss' );
		const stylesheet = postcss.parse( compile( file ), { from: file } );
		const offenders = [];
		const geometry = {};

		stylesheet.walkRules( ( rule ) => {
			if (
				! selectorHasClass( rule.selector, 'rule-edit-field-label' )
			) {
				return;
			}
			geometry[ rule.selector ] = Object.fromEntries(
				rule.nodes
					.filter( ( node ) => 'decl' === node.type )
					.map( ( node ) => [ node.prop, node.value ] )
			);
			for ( const node of rule.nodes ) {
				if (
					'decl' === node.type &&
					/^(?:font(?:-.+)?|letter-spacing|text-transform)$/.test(
						node.prop
					)
				) {
					offenders.push( `${ rule.selector }:${ node.prop }` );
				}
			}
		} );

		expect( offenders ).toEqual( [] );
		expect( geometry ).toMatchObject( {
			'.event-logger-rule-edit-modal .rule-edit-field-label': {
				'margin-bottom': '0',
				'min-width': '130px',
			},
			'.event-logger-rule-edit-modal .rule-edit-tag-field .rule-edit-field-label':
				{
					display: 'block',
					'margin-bottom': '8px',
				},
		} );
	} );

	it( 'uses the shared interactive-row role on both hook rows and custom events', () => {
		const hookSource = fs.readFileSync(
			path.join( SRC, 'settings/settings/HookSelectorModal.js' ),
			'utf8'
		);
		const customEventSource = fs.readFileSync(
			path.join( SRC, 'settings/settings/CustomEventSelectorModal.js' ),
			'utf8'
		);

		expect(
			hookSource.match( /\bnewspack-nodes-interactive-row\b/g ) || []
		).toHaveLength( 2 );
		expect(
			customEventSource.match( /\bnewspack-nodes-interactive-row\b/g ) ||
				[]
		).toHaveLength( 1 );
	} );

	it( 'gives every native button the canonical button role', () => {
		const offenders = [];

		for ( const file of walkSource( SRC ).filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const source = fs.readFileSync( file, 'utf8' );
			const ast = parseJs( source, {
				sourceType: 'module',
				plugins: [ 'jsx' ],
			} );
			visit( ast, ( node ) => {
				if (
					'JSXOpeningElement' !== node.type ||
					'JSXIdentifier' !== node.name.type ||
					'button' !== node.name.name
				) {
					return;
				}
				const classAttribute = node.attributes.find(
					( attribute ) =>
						'JSXAttribute' === attribute.type &&
						'className' === attribute.name.name
				);
				const classSource = classAttribute
					? source.slice( classAttribute.start, classAttribute.end )
					: '';
				if (
					/(?:^|[\s"'`])button(?:-link)?(?:[\s"'`{$]|$)/.test(
						classSource
					) ||
					classSource.includes(
						'newspack-nodes-sortable-header-button'
					)
				) {
					return;
				}
				offenders.push(
					`${ path.relative( SRC, file ) }:${ node.loc.start.line }`
				);
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'keeps request-detail quiet-link paint shared and Show-more layout local', () => {
		const profileFile = path.join(
			SRC,
			'overview/styles/request-profile.scss'
		);
		const profileStylesheet = postcss.parse( compile( profileFile ), {
			from: profileFile,
		} );
		const profileDeclarations = {};
		profileStylesheet.walkRules( ( rule ) => {
			if (
				selectorHasClass(
					rule.selector,
					'event-logger-profile-expansion'
				)
			) {
				Object.assign(
					profileDeclarations,
					Object.fromEntries(
						rule.nodes
							.filter( ( node ) => 'decl' === node.type )
							.map( ( node ) => [ node.prop, node.value ] )
					)
				);
			}
		} );
		expect( profileDeclarations ).toEqual( { padding: '4px 8px' } );

		const tablesFile = path.join( SRC, 'overview/styles/tables.scss' );
		const tablesStylesheet = postcss.parse( compile( tablesFile ), {
			from: tablesFile,
		} );
		const foldSelectors = [];
		tablesStylesheet.walkRules( ( rule ) => {
			if (
				selectorHasClass( rule.selector, 'log-entries-fold-toggle' )
			) {
				foldSelectors.push( rule.selector );
			}
		} );
		expect( foldSelectors ).toEqual( [] );
	} );

	it( 'keeps component-specific interactive selectors geometry-only', () => {
		const interactiveClasses = new Set();

		for ( const file of walkSource( SRC ).filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const source = fs.readFileSync( file, 'utf8' );
			const ast = parseJs( source, {
				sourceType: 'module',
				plugins: [ 'jsx' ],
			} );
			visit( ast, ( node ) => {
				if (
					'JSXOpeningElement' === node.type &&
					hasInteractiveRole( node, source )
				) {
					staticLocalClasses( node, source ).forEach( ( className ) =>
						interactiveClasses.add( className )
					);
				}
			} );
		}

		const offenders = [];
		for ( const file of styleFiles() ) {
			const stylesheet = postcss.parse( compile( file ), { from: file } );
			stylesheet.walkRules( ( rule ) => {
				if (
					! [ ...interactiveClasses ].some( ( className ) =>
						selectorHasClass( rule.selector, className )
					)
				) {
					return;
				}
				for ( const node of rule.nodes ) {
					if (
						'decl' !== node.type ||
						! APPEARANCE_PROPERTY.test( node.prop ) ||
						isAllowedSemanticAppearance(
							file,
							rule.selector,
							node.prop
						)
					) {
						continue;
					}
					offenders.push(
						`${ path.relative( SRC, file ) }:${
							node.source.start.line
						}:${ rule.selector }:${ node.prop }`
					);
				}
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'keeps every local appearance declaration semantic and explicit', () => {
		const offenders = [];

		for ( const file of styleFiles() ) {
			const stylesheet = postcss.parse( compile( file ), { from: file } );
			stylesheet.walkRules( ( rule ) => {
				for ( const node of rule.nodes ) {
					if (
						'decl' !== node.type ||
						! APPEARANCE_PROPERTY.test( node.prop ) ||
						isAllowedSemanticAppearance(
							file,
							rule.selector,
							node.prop
						)
					) {
						continue;
					}
					offenders.push(
						`${ path.relative( SRC, file ) }:${
							node.source.start.line
						}:${ rule.selector }:${ node.prop }`
					);
				}
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'has no Event Logger focus painter', () => {
		const offenders = [];

		for ( const file of styleFiles() ) {
			const stylesheet = postcss.parse( compile( file ), { from: file } );
			stylesheet.walkRules( ( rule ) => {
				if ( ! FOCUS_SELECTOR.test( rule.selector ) ) {
					return;
				}
				for ( const node of rule.nodes ) {
					if (
						'decl' === node.type &&
						FOCUS_PROPERTY.test( node.prop )
					) {
						offenders.push(
							`${ path.relative( SRC, file ) }:${
								node.source.start.line
							}:${ rule.selector }:${ node.prop }`
						);
					}
				}
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'uses important only for the upstream flame-tooltip override', () => {
		const offenders = [];

		for ( const file of styleFiles() ) {
			const stylesheet = postcss.parse( compile( file ), { from: file } );
			stylesheet.walkDecls( ( declaration ) => {
				if ( ! declaration.important ) {
					return;
				}
				const allowed =
					file.endsWith( IMPORTANT_ALLOWLIST.file ) &&
					IMPORTANT_ALLOWLIST.selector.test(
						declaration.parent.selector
					) &&
					IMPORTANT_ALLOWLIST.properties.includes( declaration.prop );
				if ( ! allowed ) {
					offenders.push(
						`${ path.relative( SRC, file ) }:${
							declaration.source.start.line
						}:${ declaration.parent.selector }:${
							declaration.prop
						}`
					);
				}
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'contains no hardcoded inline shared appearance', () => {
		const offenders = [];

		for ( const file of walkSource( SRC ).filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const relative = path.relative( SRC, file );
			const allowlist = INLINE_SEMANTIC_ALLOWLIST.get( relative ) || [];
			const lines = fs.readFileSync( file, 'utf8' ).split( '\n' );
			lines.forEach( ( line, index ) => {
				const declaration = lines.slice( index, index + 3 ).join( ' ' );
				if (
					! INLINE_APPEARANCE_PROPERTY.test( line ) ||
					allowlist.some( ( allowed ) => allowed.test( declaration ) )
				) {
					return;
				}
				offenders.push( `${ relative }:${ index + 1 }` );
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'contains no dead appearance-only selector', () => {
		const source = styleFiles()
			.map( ( file ) => fs.readFileSync( file, 'utf8' ) )
			.join( '\n' );
		const offenders = DEAD_SELECTOR_NAMES.filter( ( selector ) =>
			source.includes( `.${ selector }` )
		);

		expect( offenders ).toEqual( [] );
	} );
} );
