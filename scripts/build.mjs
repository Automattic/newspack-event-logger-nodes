#!/usr/bin/env node
/**
 * Dashboard build — a thin shell over the substrate's shared build-kit.
 * esbuild/sass/rtlcss come from THIS plugin's node_modules and are injected;
 * the kit takes no bare dependency on them so it works against a sibling
 * newspack-nodes checkout that has no node_modules of its own.
 *
 * The kit and the `@newspack-nodes/*` aliases both derive from one directory,
 * the substrate's `src`: a sibling checkout by default, `NEWSPACK_NODES_SRC`
 * wherever the substrate sits elsewhere. Bare imports resolve to THIS plugin's
 * node_modules, pinned below, whichever tree the importing source came from.
 *
 * `npm run build` empties `build/` before this runs, so the script only
 * compiles; `--watch` keeps it compiling as sources change.
 */

import esbuild from 'esbuild';
import * as sass from 'sass';
import rtlcss from 'rtlcss';
import path from 'node:path';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

/** This file's directory; ESM defines no `__dirname`. */
const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

/** The plugin root, which every path in this file resolves from. */
const ROOT = path.resolve( __dirname, '..' );

/**
 * The substrate `src` directory — a sibling newspack-nodes checkout, or
 * `NEWSPACK_NODES_SRC` wherever the substrate sits elsewhere, as in CI.
 *
 * One override serves the build kit and every alias, because all of them name
 * paths inside this directory. A knob per alias lets a build set some and omit
 * another, and the omitted one resolves to a path that does not exist. The
 * existence check names the missing directory here, instead of surfacing as an
 * unresolved import deep inside esbuild.
 */
const substrateSrc =
	process.env.NEWSPACK_NODES_SRC ||
	path.resolve( ROOT, '../newspack-nodes/src' );
if ( ! existsSync( substrateSrc ) ) {
	throw new Error(
		`substrate src not found at ${ substrateSrc } — set NEWSPACK_NODES_SRC when building outside a sibling newspack-nodes checkout`
	);
}

/**
 * The build kit's entry point. It and the alias map both load through
 * `import()` rather than a static import, because a static specifier cannot be
 * a path computed at run time; the alias map arrives under `.default` because
 * it is CommonJS.
 */
const buildKit = path.join( substrateSrc, 'build-kit/index.mjs' );
const { buildDashboards } = await import( pathToFileURL( buildKit ).href );
const { esbuildAlias, assertNoRetiredOverrides } = (
	await import(
		pathToFileURL( path.join( substrateSrc, 'build-kit/alias-map.cjs' ) )
			.href
	)
).default;

// Refuse the retired per-alias overrides; never silently ignore one.
assertNoRetiredOverrides( process.env );

/** The `@newspack-nodes/*` aliases; the loop below adds this plugin's deps. */
const alias = esbuildAlias( substrateSrc );

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

/**
 * One bundle per screen: five admin pages that `enqueue_react_page()` mounts by
 * directory name, plus the front-end `current-request` overlay. Into each
 * `outDir` the kit emits `index.js` and the `index.asset.php` manifest PHP
 * reads dependencies and version from, plus `index.css` and its RTL companion
 * whenever the entry imports styles.
 */
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
