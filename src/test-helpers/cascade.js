/**
 * Compiled-CSS cascade toolkit for tests.
 *
 * Two questions a stylesheet test wants answered, and neither is visible from
 * the SCSS source: what a selector compiles to, and which declaration WINS on
 * an element that several stylesheets reach. The substrate's one cascade
 * reader answers the second against a real rendered element: `winning()` for
 * one longhand, every shorthand that sets it competing, `computed()` for that
 * value with the custom properties it reads resolved, and `resolved()` for
 * every property, so a tie broken by import order shows up as the defect it is
 * rather than as a green source-text match.
 */

/* global NEWSPACK_NODES_SRC */
import path from 'path';
import * as sass from 'sass';
import postcss from 'postcss';

const SRC = path.resolve( __dirname, '..' );

// The aliases resolved against the base the jest config hands every test.
const { esbuildAlias, sassImporter } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/alias-map.cjs' )
);
const IMPORTER = sassImporter( NEWSPACK_NODES_SRC );
const NODES_SHARED =
	esbuildAlias( NEWSPACK_NODES_SRC )[ '@newspack-nodes/shared' ];

/**
 * Compile one stylesheet, resolving the `@newspack-nodes/*` build aliases.
 *
 * @param {string} file Absolute path to a .scss entry point.
 * @return {import('postcss').Root} The parsed compiled stylesheet.
 */
export const compile = ( file ) =>
	postcss.parse(
		sass.compile( file, {
			importers: [ IMPORTER ],
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
 * The substrate's theme sheet, which sets the product tokens on its root.
 *
 * @return {import('postcss').Root} The parsed compiled stylesheet.
 */
export const compileTheme = () =>
	compile( path.join( NEWSPACK_NODES_SRC, 'theme/newspack-theme.scss' ) );

/**
 * A shared design token's compiled value, read from `_tokens.scss` itself.
 *
 * @param {string} name The token without its `$`, e.g. 'control-height'.
 * @return {string} The value the token compiles to.
 */
export const sharedToken = ( name ) => {
	let value;
	postcss
		.parse(
			sass.compileString( `@use "tokens"; a { b: tokens.$${ name }; }`, {
				loadPaths: [ path.join( NODES_SHARED, 'styles' ) ],
			} ).css
		)
		.walkDecls( ( declaration ) => {
			value = declaration.value;
		} );
	return value;
};

// The substrate's cascade reader, which every plugin's style tests share.
export const {
	specificity,
	compareSpecificity,
	winning,
	computed,
	resolved,
} = require( path.join( NEWSPACK_NODES_SRC, 'build-kit/css-reader.cjs' ) );
