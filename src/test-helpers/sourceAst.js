/**
 * The plugin's shipped source, walked and parsed once per test process.
 *
 * Every contract suite that reads the source text or the JSX tree goes
 * through here, so they agree on which files ship — everything under `src`
 * but `__tests__` and `test-helpers` — and each file is read and parsed once
 * however many suites inspect it.
 */

import fs from 'fs';
import path from 'path';
// Jest's Babel toolchain supplies the parser used to inspect real JSX nodes.
// eslint-disable-next-line import/no-extraneous-dependencies
import { parse } from '@babel/parser';

/** The plugin's `src` directory. */
export const SRC = path.resolve( __dirname, '..' );

const SKIPPED_DIRS = new Set( [ '__tests__', 'test-helpers' ] );

const walk = ( root ) =>
	fs.readdirSync( root, { withFileTypes: true } ).flatMap( ( entry ) => {
		const absolute = path.join( root, entry.name );
		if ( entry.isDirectory() ) {
			return SKIPPED_DIRS.has( entry.name ) ? [] : walk( absolute );
		}
		return /\.(?:js|scss)$/.test( entry.name ) ? [ absolute ] : [];
	} );

let files = null;
const texts = new Map();
const trees = new Map();

/**
 * Every shipped `.js` and `.scss` file, or those ending in `extension`.
 *
 * @param {string} [extension] `.js` or `.scss`; omitted, both.
 * @return {Array<string>} Absolute paths, in directory order.
 */
export const sourceFiles = ( extension = '' ) => {
	files ??= walk( SRC );
	return files.filter( ( file ) => file.endsWith( extension ) );
};

/**
 * A file's path below `src`, the form every offender list reports.
 *
 * @param {string} file Absolute path.
 * @return {string} The path relative to `src`.
 */
export const relativeSource = ( file ) => path.relative( SRC, file );

/**
 * A file's text, read once.
 *
 * @param {string} file Absolute path, or a path relative to `src`.
 * @return {string} The file's contents.
 */
export const readSource = ( file ) => {
	const absolute = path.resolve( SRC, file );
	if ( ! texts.has( absolute ) ) {
		texts.set( absolute, fs.readFileSync( absolute, 'utf8' ) );
	}
	return texts.get( absolute );
};

/**
 * A module's Babel AST, with the JSX the dashboards are written in.
 *
 * @param {string} text Module source.
 * @return {Object} The `File` node.
 */
export const parseCode = ( text ) =>
	parse( text, { sourceType: 'module', plugins: [ 'jsx' ] } );

/**
 * A JavaScript file's Babel AST, parsed once.
 *
 * @param {string} file Absolute path, or a path relative to `src`.
 * @return {Object} The `File` node.
 */
export const parseSource = ( file ) => {
	const absolute = path.resolve( SRC, file );
	if ( ! trees.has( absolute ) ) {
		trees.set( absolute, parseCode( readSource( absolute ) ) );
	}
	return trees.get( absolute );
};

/**
 * Call `callback` on every node below `node`, depth first.
 *
 * @param {Object}                 node     A Babel node.
 * @param {(node: Object) => void} callback Called once per node.
 */
export const visit = ( node, callback ) => {
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
