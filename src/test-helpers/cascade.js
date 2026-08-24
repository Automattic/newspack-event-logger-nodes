/**
 * Compiled-CSS cascade toolkit for tests.
 *
 * Two questions a stylesheet test wants answered, and neither is visible from
 * the SCSS source: what a selector compiles to, and which declaration WINS on
 * an element that several stylesheets reach. `resolveCascade` answers the
 * second against a real rendered element, so a tie broken by import order
 * shows up as the defect it is rather than as a green source-text match.
 */

import path from 'path';
import { pathToFileURL } from 'url';
import * as sass from 'sass';
// PostCSS is a test/build dependency used to inspect the compiled cascade.
// eslint-disable-next-line import/no-extraneous-dependencies
import postcss from 'postcss';

const SRC = path.resolve( __dirname, '..' );
const NODES_SHARED = path.resolve( SRC, '../../newspack-nodes/src/shared' );
const SHARED_ALIAS = '@newspack-nodes/shared';

/**
 * Compile one stylesheet, resolving the shared-source build alias.
 *
 * @param {string} file Absolute path to a .scss entry point.
 * @return {import('postcss').Root} The parsed compiled stylesheet.
 */
export const compile = ( file ) =>
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

/**
 * Compile a stylesheet named relative to `src/`.
 *
 * @param {string} relative Path under src/, e.g. 'rules/rule-edit-modal.scss'.
 * @return {import('postcss').Root} The parsed compiled stylesheet.
 */
export const compileLocal = ( relative ) =>
	compile( path.join( SRC, relative ) );

/**
 * Compile a stylesheet named relative to the substrate's shared source.
 *
 * @param {string} relative Path under newspack-nodes/src/shared.
 * @return {import('postcss').Root} The parsed compiled stylesheet.
 */
export const compileShared = ( relative ) =>
	compile( path.join( NODES_SHARED, relative ) );

/**
 * Selector specificity as [ids, classes, elements], counting `:where()` as 0.
 *
 * @param {string} selector A compiled CSS selector.
 * @return {number[]} The three specificity components.
 */
export const specificity = ( selector ) => {
	const withoutWhere = selector.replace( /:where\([^)]*\)/g, '' );
	const all = ( pattern ) =>
		/** @type {string[]} */ ( withoutWhere.match( pattern ) || [] );
	const functionalPseudos = new Set( [ 'has', 'is', 'not' ] );
	const pseudoClasses = all( /:(?!:)[\w-]+/g ).filter(
		( pseudo ) => ! functionalPseudos.has( pseudo.slice( 1 ) )
	).length;

	return [
		all( /#[\w-]+/g ).length,
		all( /\.[\w-]+/g ).length + all( /\[[^\]]+\]/g ).length + pseudoClasses,
		0,
	];
};

/**
 * Compare two specificity triples.
 *
 * @param {number[]} left  First specificity.
 * @param {number[]} right Second specificity.
 * @return {number} Negative, zero or positive, sort-comparator style.
 */
export const compareSpecificity = ( left, right ) => {
	for ( let index = 0; index < left.length; index++ ) {
		if ( left[ index ] !== right[ index ] ) {
			return left[ index ] - right[ index ];
		}
	}
	return 0;
};

const declarationsOf = ( rule ) =>
	Object.fromEntries(
		rule.nodes
			.filter( ( node ) => 'decl' === node.type )
			.map( ( declaration ) => [ declaration.prop, declaration.value ] )
	);

/**
 * Resolve what an element actually gets from stylesheets emitted in order.
 *
 * Media-query blocks are skipped: jsdom reports no viewport, so honouring them
 * would invent a win no browser agrees with.
 *
 * @param {Element}                  element     The rendered element.
 * @param {import('postcss').Root[]} stylesheets Compiled sheets, in bundle emission order.
 * @return {Object<string, string>} The winning declarations, property to value.
 */
export const resolveCascade = ( element, stylesheets ) => {
	const matches = [];
	stylesheets.forEach( ( stylesheet, sheetIndex ) => {
		let ruleIndex = 0;
		stylesheet.walkRules( ( rule ) => {
			ruleIndex++;
			if ( 'root' !== rule.parent.type ) {
				return;
			}
			const winner = ( rule.selectors || [ rule.selector ] )
				.filter( ( selector ) => element.matches( selector ) )
				.sort( ( left, right ) =>
					compareSpecificity(
						specificity( left ),
						specificity( right )
					)
				)
				.pop();
			if ( winner ) {
				matches.push( {
					order: [ sheetIndex, ruleIndex ],
					weight: specificity( winner ),
					declarations: declarationsOf( rule ),
				} );
			}
		} );
	} );

	return matches
		.sort(
			( left, right ) =>
				compareSpecificity( left.weight, right.weight ) ||
				compareSpecificity( left.order, right.order )
		)
		.reduce(
			( resolved, match ) =>
				Object.assign( resolved, match.declarations ),
			{}
		);
};
