#!/usr/bin/env node
/**
 * Dashboard build — a thin shell over the substrate's shared build-kit.
 * esbuild/sass/rtlcss come from THIS plugin's node_modules and are injected;
 * the kit takes no bare dependency on them so it works against a sibling
 * newspack-nodes checkout that has no node_modules of its own.
 *
 * The kit, the `@newspack-nodes/*` aliases, and bare-import resolution all
 * point at the sibling newspack-nodes checkout; CI overrides each via the
 * matching NEWSPACK_NODES_* env var.
 */

import esbuild from 'esbuild';
import * as sass from 'sass';
import rtlcss from 'rtlcss';
import path from 'node:path';
import { readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const ROOT = path.resolve( __dirname, '..' );

// Sibling newspack-nodes checkout; CI overrides via NEWSPACK_NODES_BUILD_KIT.
const buildKit =
	process.env.NEWSPACK_NODES_BUILD_KIT ||
	path.resolve( ROOT, '../newspack-nodes/src/build-kit/index.mjs' );
const { buildDashboards } = await import( pathToFileURL( buildKit ).href );

const alias = {
	// Substrate runtime: CI sets NEWSPACK_NODES_RUNTIME; else sibling checkout.
	'@newspack-nodes/runtime':
		process.env.NEWSPACK_NODES_RUNTIME ||
		path.resolve( ROOT, '../newspack-nodes/src/runtime/index.js' ),
	// Overlay: CI sets NEWSPACK_NODES_DEBUG_OVERLAY; else sibling checkout.
	'@newspack-nodes/debug-overlay':
		process.env.NEWSPACK_NODES_DEBUG_OVERLAY ||
		path.resolve(
			ROOT,
			'../newspack-nodes/src/debug-overlay/DebugOverlay.js'
		),
	// Shared React: CI sets NEWSPACK_NODES_SHARED; else sibling src/shared.
	'@newspack-nodes/shared':
		process.env.NEWSPACK_NODES_SHARED ||
		path.resolve( ROOT, '../newspack-nodes/src/shared' ),
};

/**
 * Pin every dependency we own to OUR copy, so a dev build and a CI build emit
 * the same bytes.
 *
 * Shared substrate source importing a bare dep (`d3`, `@noble/hashes`) resolves
 * it from ITS own tree first. In CI that tree is a dependency-free checkout, so
 * resolution falls through to `nodePaths` below and finds ours. In a dev
 * checkout the sibling HAS node_modules, so esbuild bundles a second copy under
 * a different absolute path — 88KB of duplicate d3 in the overview bundle.
 */
for ( const dep of Object.keys(
	JSON.parse( readFileSync( path.join( ROOT, 'package.json' ), 'utf8' ) )
		.dependencies || {}
) ) {
	// `@wordpress/*` is externalised by a plugin; a path alias would defeat it.
	if ( ! dep.startsWith( '@wordpress/' ) ) {
		alias[ dep ] = path.resolve( ROOT, 'node_modules', dep );
	}
}

const ENTRIES = [
	{
		entry: 'src/overview/index.js',
		outDir: path.resolve( ROOT, 'build/overview' ),
	},
	{
		entry: 'src/error-log/index.js',
		outDir: path.resolve( ROOT, 'build/error-log' ),
	},
	{
		entry: 'src/settings/index.js',
		outDir: path.resolve( ROOT, 'build/settings' ),
	},
	{
		entry: 'src/gyroscope/index.js',
		outDir: path.resolve( ROOT, 'build/gyroscope' ),
	},
	{
		entry: 'src/requests/index.js',
		outDir: path.resolve( ROOT, 'build/requests' ),
	},
	{
		entry: 'src/current-request/index.js',
		outDir: path.resolve( ROOT, 'build/current-request' ),
	},
];

buildDashboards( {
	esbuild,
	sass,
	rtlcss,
	root: ROOT,
	entries: ENTRIES,
	alias,
	nodePaths: [ path.resolve( ROOT, 'node_modules' ) ],
	watch: process.argv.includes( '--watch' ),
} ).catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
