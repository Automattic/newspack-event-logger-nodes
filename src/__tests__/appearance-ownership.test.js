/* @jest-environment node */

/* global NEWSPACK_NODES_SRC */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';
import postcss from 'postcss';
import {
	SRC,
	parseSource,
	readSource,
	relativeSource,
	sourceFiles,
	visit,
} from '../test-helpers/sourceAst';

// The aliases resolved against the base the jest config hands every test.
const { esbuildAlias, sassImporter } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/alias-map.cjs' )
);
const IMPORTER = sassImporter( NEWSPACK_NODES_SRC );
const NODES_SHARED =
	esbuildAlias( NEWSPACK_NODES_SRC )[ '@newspack-nodes/shared' ];
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
	'components/TagToken.js': [ 'newspack-nodes-badge' ],
	'components/RequestSummary.js': [ 'newspack-nodes-status', 'is-error' ],
	'current-request/CurrentRequestTab.js': [
		'newspack-nodes-empty-state',
		'newspack-nodes-performance-loading',
		'newspack-nodes-status',
	],
	'overview/PerformancePage.js': [
		'newspack-nodes-stats-grid',
		'newspack-nodes-stat',
		'newspack-nodes-stat-label',
		'newspack-nodes-modal',
		'newspack-nodes-status',
		'is-error',
	],
	'overview/blocks/url-table.js': [
		'newspack-nodes-card',
		'newspack-nodes-section-heading',
	],
	'overview/UrlTable.js': [
		'newspack-nodes-table',
		'newspack-nodes-table__header',
		'newspack-nodes-table__row',
	],
	// The cell classes both Performance tables build through `cellClass()`.
	'overview/constants.js': [ 'newspack-nodes-table__cell', 'entry-status' ],
	'overview/components/SortHeaderButton.js': [
		'newspack-nodes-table__cell',
		'newspack-nodes-sortable-header-button',
	],
	// The two status modifiers belong to errorStatus.js: the views that paint
	// an error_status read their tone from it rather than spelling it.
	'components/errorStatus.js': [ 'is-warning', 'is-error' ],
	'overview/components/UrlDetailView.js': [
		'newspack-nodes-section-heading event-logger-heading-row',
		'newspack-nodes-table',
		'newspack-nodes-table__header',
		'newspack-nodes-table__row',
		'newspack-nodes-empty-state',
		'newspack-nodes-status',
	],
	'overview/components/RequestDetailView.js': [
		'newspack-nodes-badge',
		'newspack-nodes-no-selection',
		'newspack-nodes-status',
	],
	'overview/RequestProfile.js': [
		'newspack-nodes-section-heading',
		'newspack-nodes-table',
		'newspack-nodes-table--undivided',
		'newspack-nodes-table__terminal-data',
		'newspack-nodes-table__summary',
		'newspack-nodes-table__details',
		'button-link',
	],
	'overview/components/LogEntriesTable.js': [
		'newspack-nodes-section-heading event-logger-heading-row',
		'newspack-nodes-table',
		'newspack-nodes-table--undivided',
		'newspack-nodes-table__terminal-data',
		'newspack-nodes-status is-info',
		'newspack-nodes-status is-warning',
		'newspack-nodes-status is-muted',
		'button-link',
		'button button-small log-entries-search__nav',
	],
	'log-pages/sources.js': [
		'newspack-nodes-badge',
		'newspack-nodes-status',
		'is-warning',
		'entry-keyword',
		'newspack-nodes-status-badge is-error',
	],
	'log-pages/GyroscopeControls.js': [ 'newspack-nodes-badge' ],
	// Every section heading beside a ruled one takes the same shared rule.
	'overview/OwnerTable.js': [ 'newspack-nodes-section-heading' ],
	'overview/ResponseTimeChart.js': [ 'newspack-nodes-section-heading' ],
	'overview/components/RequestTrace.js': [ 'newspack-nodes-section-heading' ],
	// The cell class belongs to the one table primitive the three log
	// streams draw through; each still owns its own modifiers above.
	'log-table/logTable.js': [
		'newspack-nodes-table__cell',
		'entry-status',
		'entry-duration',
		'entry-time',
		'entry-url',
		'entry-ip',
		'entry-ua',
	],
	'overview/FlameGraph.js': [ 'newspack-nodes-empty-state' ],
	'rules/RulesAdmin.js': [
		'newspack-nodes-table',
		'newspack-nodes-table--undivided',
		'newspack-nodes-status-badge is-pill',
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
	'rules/RuleEditModal.js': [
		'newspack-nodes-error-banner',
		'newspack-nodes-modal',
		'newspack-nodes-status',
	],
};
const INLINE_APPEARANCE_PROPERTY =
	/\b(?:appearance|background(?:[A-Z]\w*)?|border(?:[A-Z]\w*)?|boxShadow|color|cursor|filter|fontFamily|fontWeight|opacity|outline|textDecoration|textTransform|letterSpacing)\s*:/;
const INLINE_SEMANTIC_ALLOWLIST = new Map( [
	// @longform The `filter` tokens a URL modal's chips ask `ask` and the
	// facts block under, as the `url:` brief names them: not a style.
	[ 'overview/PerformancePage.js', [ /filter: detailChart\.chips,/ ] ],
	[ 'overview/pageFacts.js', [ /filter: detailChips,/ ] ],
	// A legend item's `color` is series data handed to `ChartLegend`, the
	// swatch the shared role paints from — not a style prop.
	[
		'overview/ResponseTimeChart.js',
		[ /color: phosphorColor\(\s*STATUS_PLACE\[ key \],\s*STATUS_COLORS/ ],
	],
	[
		'overview/RequestProfile.js',
		[
			/background:\s*fill\b/,
			/background:\s*colourOf\.get\( state \)/,
			/cursor:\s*'pointer'/,
			/cursor:\s*hasEntries/,
		],
	],
	// An owner's swatch is its series colour, the data the bar paints too.
	[ 'overview/OwnerTable.js', [ /background:\s*colourOf\.get\( owner \)/ ] ],
	[
		'overview/components/LogEntriesTable.js',
		[
			/background:\s*hexToRgba/,
			/cursor:\s*isFoldable/,
			/cursor:\s*hasPair\( entry \)/,
		],
	],
	[
		'log-pages/sources.js',
		[ /backgroundColor:\s*background/, /color:\s*getTextColor/ ],
	],
] );

const styleFiles = () => sourceFiles( '.scss' );

const compile = ( file ) =>
	sass.compile( file, {
		importers: [ IMPORTER ],
	} ).css;

const STYLE_APPEARANCE_ALLOWLIST = [
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
		file: 'overview/styles/flame-graph.scss',
		selector: /^\.d3-flame-graph-tip$/,
		properties: [ 'font-family' ],
	},
	{
		file: 'overview/styles/tables.scss',
		selector: /^\.event-logger-table__row$/,
		properties: [ 'cursor' ],
	},
	// A row's share of the longest one: data drawn in the accent, not chrome.
	{
		file: 'overview/styles/tables.scss',
		selector: /^\.event-logger-table__cell--bar$/,
		properties: [ 'background' ],
	},
	// The row a reveal lands on, ringed for two seconds.
	{
		file: 'overview/styles/tables.scss',
		selector:
			/^\.event-logger-log-entries tr\.is-flash > td(?::(?:first|last)-child)?$/,
		properties: [ 'box-shadow' ],
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
		selector: /\.hook-selector-(?:recommended|recommended-star|noisy)$/,
		properties: [ 'color' ],
	},
	{
		file: 'settings/styles/hook-selector.scss',
		selector: /\.hook-selector-(?:category-header|hook)$/,
		properties: [ 'cursor' ],
	},
];
const IMPORTANT_ALLOWLIST = {
	file: 'overview/styles/flame-graph.scss',
	selector: /^\.d3-flame-graph-tip$/,
	properties: [ 'z-index', 'white-space', 'word-wrap', 'overflow-wrap' ],
};

// Declarations whose literal paint is justified; each must still match.
const LITERAL_ALLOWLIST = [];

const isAllowedLiteral = ( file, selector, property ) =>
	LITERAL_ALLOWLIST.some(
		( allowed ) =>
			file.endsWith( allowed.file ) &&
			allowed.selector.test( selector ) &&
			allowed.properties.includes( property )
	);

// Every named CSS colour, matched as a whole word in a paint value.
const NAMED_COLOURS = new RegExp(
	`(?<![\\w-])(?:${ [
		'aliceblue antiquewhite aqua aquamarine azure beige bisque black',
		'blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse',
		'chocolate coral cornflowerblue cornsilk crimson cyan darkblue',
		'darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki',
		'darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon',
		'darkseagreen darkslateblue darkslategray darkslategrey darkturquoise',
		'darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick',
		'floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod',
		'gray green greenyellow grey honeydew hotpink indianred indigo ivory',
		'khaki lavender lavenderblush lawngreen lemonchiffon lightblue',
		'lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen',
		'lightgrey lightpink lightsalmon lightseagreen lightskyblue',
		'lightslategray lightslategrey lightsteelblue lightyellow lime',
		'limegreen linen magenta maroon mediumaquamarine mediumblue',
		'mediumorchid mediumpurple mediumseagreen mediumslateblue',
		'mediumspringgreen mediumturquoise mediumvioletred midnightblue',
		'mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab',
		'orange orangered orchid palegoldenrod palegreen paleturquoise',
		'palevioletred papayawhip peachpuff peru pink plum powderblue purple',
		'rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown',
		'seagreen seashell sienna silver skyblue slateblue slategray slategrey',
		'snow springgreen steelblue tan teal thistle tomato turquoise violet',
		'wheat white whitesmoke yellow yellowgreen',
	]
		.join( ' ' )
		.split( ' ' )
		.join( '|' ) })(?![\\w-])`,
	'i'
);
const COLOUR_LITERAL =
	/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/i;
const PAINT_PROPERTY =
	/^(?:color|background(?:-color|-image)?|border(?:-(?:top|right|bottom|left|block|inline)(?:-start|-end)?)?(?:-color)?|outline(?:-color)?|box-shadow|text-shadow|fill|stroke|caret-color|accent-color|column-rule(?:-color)?|text-decoration(?:-color)?|-webkit-text-fill-color)$/;

/**
 * Whether a compiled declaration paints a literal: any `*radius` not opening
 * on `var(`, or a paint property whose value names a colour outright.
 *
 * @param {string} prop  Property.
 * @param {string} value Compiled value.
 * @return {boolean} True when the value is a literal, not a token.
 */
const literalPaint = ( prop, value ) => {
	if ( /radius$/.test( prop ) ) {
		return ! value.trim().startsWith( 'var(' );
	}
	if ( ! PAINT_PROPERTY.test( prop ) ) {
		return false;
	}
	const bare = value.replace( /var\([^()]*(?:\([^()]*\)[^()]*)*\)/g, '' );
	return COLOUR_LITERAL.test( bare ) || NAMED_COLOURS.test( bare );
};

const isAllowedSemanticAppearance = ( file, selector, property ) =>
	STYLE_APPEARANCE_ALLOWLIST.some(
		( allowed ) =>
			file.endsWith( allowed.file ) &&
			allowed.selector.test( selector ) &&
			allowed.properties.includes( property )
	);

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
	it.each( [ 'log-pages/styles/base.scss' ] )(
		'leaves HTTP status paint to shared CSS in %s',
		( relative ) => {
			const source = readSource( relative );
			expect( source ).not.toMatch( /\.entry-status\s*\{/ );
			expect( source ).not.toMatch( /\bentry-status--/ );
		}
	);

	it.each( [ 'log-pages/styles/base.scss' ] )(
		'leaves duration and keyword inks to shared CSS in %s',
		( relative ) => {
			const source = readSource( relative );
			expect( source ).not.toMatch( /\.entry-duration\s*\{/ );
			expect( source ).not.toMatch( /\.entry-keyword\s*\{/ );
		}
	);

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
			4: 'var(--oxide-text, var(--np-error-text))',
			5: 'var(--oxide-text, var(--np-error-text))',
		} );
	} );

	it( 'contains no Event Logger copy of a shared primitive class', () => {
		const offenders = [];

		for ( const file of sourceFiles() ) {
			const source = readSource( file );
			for ( const match of source.matchAll( LEGACY_SHARED_CLASS ) ) {
				const line = source
					.slice( 0, match.index )
					.split( '\n' ).length;
				offenders.push(
					`${ relativeSource( file ) }:${ line }:${ match[ 0 ] }`
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
			const source = readSource( file );
			for ( const className of classes ) {
				if ( ! source.includes( className ) ) {
					missing.push( `${ file }:${ className }` );
				}
			}
		}

		expect( missing ).toEqual( [] );
	} );

	it( 'draws the share bar and the flash ring from the stylesheet, in the accent', () => {
		const paint = {};
		const file = path.join( SRC, 'overview/styles/tables.scss' );
		postcss
			.parse( compile( file ), { from: file } )
			.walkDecls( ( decl ) => {
				if ( /cell--bar|is-flash/.test( decl.parent.selector ) ) {
					paint[ `${ decl.parent.selector } ${ decl.prop }` ] =
						decl.value;
				}
			} );
		expect( paint ).toEqual( {
			'.event-logger-table__cell--bar background':
				'linear-gradient(to right, color-mix(in srgb, var(--cyan, var(--np-primary)) 15%, transparent) var(--bar-pct), transparent var(--bar-pct))',
			'.event-logger-log-entries tr.is-flash > td box-shadow':
				'inset 0 2px 0 0 var(--cyan, var(--np-primary)), inset 0 -2px 0 0 var(--cyan, var(--np-primary))',
			'.event-logger-log-entries tr.is-flash > td:first-child box-shadow':
				'inset 0 2px 0 0 var(--cyan, var(--np-primary)), inset 0 -2px 0 0 var(--cyan, var(--np-primary)), inset 2px 0 0 0 var(--cyan, var(--np-primary))',
			'.event-logger-log-entries tr.is-flash > td:last-child box-shadow':
				'inset 0 2px 0 0 var(--cyan, var(--np-primary)), inset 0 -2px 0 0 var(--cyan, var(--np-primary)), inset -2px 0 0 0 var(--cyan, var(--np-primary))',
		} );
	} );

	it( 'draws the profile bar track on the lightest rung, above page and modal', () => {
		const file = path.join( SRC, 'overview/styles/request-profile.scss' );
		const backgrounds = [];
		postcss
			.parse( compile( file ), { from: file } )
			.walkRules( /^\.event-logger-profile-bar$/, ( rule ) =>
				rule.walkDecls( 'background', ( decl ) =>
					backgrounds.push( decl.value )
				)
			);
		expect( backgrounds ).toEqual( [ 'var(--paper, var(--np-surface))' ] );
	} );

	it( 'spells every compiled radius and colour as a token, never a literal', () => {
		const offenders = [];

		for ( const file of styleFiles() ) {
			const relative = relativeSource( file );
			postcss
				.parse( compile( file ), { from: file } )
				.walkDecls( ( decl ) => {
					const parent = decl.parent;
					const selector = parent.selector ?? parent.params ?? '';
					if (
						! literalPaint( decl.prop, decl.value ) ||
						isAllowedLiteral( relative, selector, decl.prop )
					) {
						return;
					}
					offenders.push(
						`${ relative }:${ selector }:${ decl.prop }: ${ decl.value }`
					);
				} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it.each( [
		[ 'border-top-left-radius', '4px' ],
		[ 'border-radius', '0 0 3px 3px' ],
		[ 'color', 'white' ],
		[ 'background', 'hsl(200 50% 50%)' ],
		[ 'box-shadow', 'inset 0 0 0 1px rgba(0, 0, 0, 0.1)' ],
		[ 'border', '1px solid #ccc' ],
		[ 'fill', 'rebeccapurple' ],
	] )( 'reads %s: %s as a literal', ( prop, value ) => {
		expect( literalPaint( prop, value ) ).toBe( true );
	} );

	it.each( [
		[ 'border-radius', 'var(--np-radius-sm)' ],
		[ 'color', 'var(--ink, var(--np-text))' ],
		[ 'background', 'transparent' ],
		[ 'box-shadow', 'inset 0 0 0 1px var(--paper-shadow)' ],
		[ 'border', '1px solid currentcolor' ],
		[ 'grid-area', 'feed-list' ],
		[ 'content', '"see #123"' ],
	] )( 'reads %s: %s as token or geometry', ( prop, value ) => {
		expect( literalPaint( prop, value ) ).toBe( false );
	} );

	it( 'lets every allowlist entry excuse at least one compiled declaration', () => {
		const used = new Set();
		const lists = [
			...STYLE_APPEARANCE_ALLOWLIST,
			IMPORTANT_ALLOWLIST,
			...LITERAL_ALLOWLIST,
		];

		for ( const file of styleFiles() ) {
			postcss
				.parse( compile( file ), { from: file } )
				.walkDecls( ( decl ) => {
					const selector = decl.parent.selector ?? '';
					lists.forEach( ( entry ) => {
						if (
							file.endsWith( entry.file ) &&
							entry.selector.test( selector ) &&
							entry.properties.includes( decl.prop )
						) {
							used.add( entry );
						}
					} );
				} );
		}

		const unused = lists
			.filter( ( entry ) => ! used.has( entry ) )
			.map( ( entry ) => `${ entry.file }:${ entry.selector }` );
		expect( unused ).toEqual( [] );
	} );

	it( 'spells no colour literal in markup outside the data colours', () => {
		// Data colours: a state swatch, the PHP event default, the flame label inks.
		const allowed = {
			'log-pages/GyroscopeControls.js': [ '#9e9e9e' ],
			'settings/settings/CustomEventSelectorModal.js': [ '#ffa726' ],
			'overview/flameColors.js': [ '#0b140d', '#eafff1' ],
		};
		const offenders = [];

		for ( const file of sourceFiles().filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const relative = relativeSource( file );
			const ast = parseSource( file );
			visit( ast, ( node ) => {
				const literals = {
					StringLiteral: () => node.value,
					TemplateElement: () => node.value.cooked,
				};
				if ( ! literals[ node.type ] ) {
					return;
				}
				const text = literals[ node.type ]();
				// A literal that a token falls back to belongs to that token.
				const bare = text.replace(
					/var\(--[\w-]+,\s*#[0-9a-fA-F]+\)/g,
					''
				);
				for ( const match of bare.matchAll(
					/#[0-9a-fA-F]{3,8}\b|\brgba?\(/g
				) ) {
					if ( ! allowed[ relative ]?.includes( match[ 0 ] ) ) {
						offenders.push(
							`${ relative }:${ node.loc.start.line }:${ match[ 0 ] }`
						);
					}
				}
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'draws every card on the shared card role, never a WordPress Card', () => {
		const offenders = [];

		for ( const file of sourceFiles().filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const ast = parseSource( file );
			for ( const statement of ast.program.body ) {
				if (
					'ImportDeclaration' !== statement.type ||
					'@wordpress/components' !== statement.source.value
				) {
					continue;
				}
				for ( const spec of statement.specifiers ) {
					if ( /^Card/.test( spec.imported?.name ?? '' ) ) {
						offenders.push(
							`${ relativeSource( file ) }:${
								spec.imported.name
							}`
						);
					}
				}
			}
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'uses WordPress labels for every rule field it labels itself', () => {
		const source = readSource( 'rules/RuleEditModal.js' );

		// The three selector rows take a visual label; the tag box, a BaseControl label.
		expect(
			source.match( /<BaseControl\.VisualLabel(?:\s|>)/g ) || []
		).toHaveLength( 3 );
		expect( source.match( /<BaseControl(?:\s|>)/g ) || [] ).toHaveLength(
			1
		);
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
		const hookSource = readSource(
			'settings/settings/HookSelectorModal.js'
		);
		const customEventSource = readSource(
			'settings/settings/CustomEventSelectorModal.js'
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

		for ( const file of sourceFiles().filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const source = readSource( file );
			const ast = parseSource( file );
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
					`${ relativeSource( file ) }:${ node.loc.start.line }`
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

		for ( const file of sourceFiles().filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const source = readSource( file );
			const ast = parseSource( file );
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
						`${ relativeSource( file ) }:${
							node.source.start.line
						}:${ rule.selector }:${ node.prop }`
					);
				}
			} );
		}

		expect( offenders ).toEqual( [] );
	} );

	it( 'leaves tick and axis-line ink to the shared chart role', () => {
		const offenders = [];

		for ( const file of styleFiles() ) {
			const stylesheet = postcss.parse( compile( file ), { from: file } );
			stylesheet.walkRules( ( rule ) => {
				if (
					! rule.selectors.some( ( selector ) =>
						/\.(?:tick|domain)(?![\w-])/.test( selector )
					)
				) {
					return;
				}
				rule.walkDecls( /^(?:fill|stroke)$/, ( decl ) => {
					offenders.push(
						`${ relativeSource( file ) }:${ rule.selector }:${
							decl.prop
						}`
					);
				} );
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
						`${ relativeSource( file ) }:${
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
							`${ relativeSource( file ) }:${
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
						`${ relativeSource( file ) }:${
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

	it( "draws CRT's flame frames opaque, the fill their label inks are checked on", () => {
		const sheet = postcss.parse(
			compile( path.join( SRC, 'overview/styles/flame-graph.scss' ) )
		);
		const opacities = [];
		sheet.walkDecls( 'fill-opacity', ( declaration ) => {
			opacities.push(
				`${ declaration.parent.selector } ${ declaration.value }`
			);
		} );
		expect( opacities ).toContain( '.theme-crt .d3-flame-graph rect 1' );
	} );

	it( 'lets every inline allowlist pattern excuse a line it still names', () => {
		const unused = [];
		for ( const [ file, patterns ] of INLINE_SEMANTIC_ALLOWLIST ) {
			const lines = readSource( file ).split( '\n' );
			for ( const pattern of patterns ) {
				const used = lines.some( ( _line, index ) =>
					pattern.test( lines.slice( index, index + 3 ).join( ' ' ) )
				);
				if ( ! used ) {
					unused.push( `${ file }:${ pattern }` );
				}
			}
		}
		expect( unused ).toEqual( [] );
	} );

	it( 'contains no hardcoded inline shared appearance', () => {
		const offenders = [];

		for ( const file of sourceFiles().filter( ( candidate ) =>
			candidate.endsWith( '.js' )
		) ) {
			const relative = relativeSource( file );
			const allowlist = INLINE_SEMANTIC_ALLOWLIST.get( relative ) || [];
			const lines = readSource( file ).split( '\n' );
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
			.map( ( file ) => readSource( file ) )
			.join( '\n' );
		const offenders = DEAD_SELECTOR_NAMES.filter( ( selector ) =>
			source.includes( `.${ selector }` )
		);

		expect( offenders ).toEqual( [] );
	} );
} );

describe( 'the type scale floor', () => {
	const { stylesheetOffenders, scriptOffenders } = require(
		path.join( NEWSPACK_NODES_SRC, 'build-kit/type-scale-floor.cjs' )
	);

	it( 'sets no UI type below --np-text-2xs in a stylesheet', () => {
		const offenders = styleFiles()
			.filter( ( file ) => ! path.basename( file ).startsWith( '_' ) )
			.flatMap( ( file ) =>
				stylesheetOffenders(
					postcss.parse( compile( file ), { from: file } ),
					path.relative( SRC, file )
				)
			);
		expect( offenders ).toEqual( [] );
	} );

	it( 'sets no UI type below --np-text-2xs from a script', () => {
		const offenders = sourceFiles( '.js' ).flatMap( ( file ) =>
			scriptOffenders(
				fs.readFileSync( file, 'utf8' ),
				path.relative( SRC, file )
			)
		);
		expect( offenders ).toEqual( [] );
	} );
} );

// A numeric cell right-aligns on tabular digits, both from the shared mixin.
describe( 'numeric cells', () => {
	it( 'sets right-aligned tabular digits on the table cell', () => {
		const declared = {};
		postcss
			.parse( compile( path.join( SRC, 'overview/styles/tables.scss' ) ) )
			.walkRules( /event-logger-table__cell--numeric$/, ( rule ) => {
				rule.walkDecls( ( decl ) => {
					declared[ decl.prop ] = decl.value;
				} );
			} );
		expect( declared ).toMatchObject( {
			'font-variant-numeric': 'tabular-nums',
			'text-align': 'right',
		} );
	} );
} );
