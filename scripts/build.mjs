#!/usr/bin/env node
/**
 * Build script — replaces `wp-scripts build` with a direct esbuild invocation.
 *
 * For each entry, emits to <outDir>:
 *   - index.js         minified bundle
 *   - index.css        extracted CSS (if any styles imported)
 *   - index.asset.php  WordPress enqueue manifest: { dependencies, version }
 *
 * Imports of `@wordpress/*` packages and JSX runtime are rewritten to read
 * from the corresponding window global (the way WordPress's enqueue system
 * exposes them) and recorded in `index.asset.php` so wp_enqueue_script picks
 * up the right handles.
 */

import esbuild from 'esbuild';
import * as sass from 'sass';
import rtlcss from 'rtlcss';
import { createHash } from 'node:crypto';
import { mkdir, writeFile, readFile, rm, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const ROOT = path.resolve( __dirname, '..' );

// Map import path → { global, handle }.
//   global: runtime JS expression (read from `window`)
//   handle: WordPress enqueue handle for *.asset.php
const WP_EXTERNALS = {
	'@wordpress/element': {
		global: 'window.wp.element',
		handle: 'wp-element',
	},
	'@wordpress/api-fetch': {
		global: 'window.wp.apiFetch',
		handle: 'wp-api-fetch',
	},
	'@wordpress/components': {
		global: 'window.wp.components',
		handle: 'wp-components',
	},
	'@wordpress/i18n': {
		global: 'window.wp.i18n',
		handle: 'wp-i18n',
	},
	'@wordpress/icons': {
		global: 'window.wp.icons',
		handle: 'wp-icons',
	},
	'@wordpress/data': {
		global: 'window.wp.data',
		handle: 'wp-data',
	},
	react: {
		global: 'window.React',
		handle: 'react',
	},
	'react-dom': {
		global: 'window.ReactDOM',
		handle: 'react-dom',
	},
	'react/jsx-runtime': {
		global: 'window.ReactJSXRuntime',
		handle: 'react-jsx-runtime',
	},
};

/**
 * esbuild plugin: rewrites WP/React imports to read from window globals
 * and records which handles were actually used (for *.asset.php).
 */
function wpExternalsPlugin( usedHandles ) {
	return {
		name: 'wp-externals',
		setup( build ) {
			const filter = new RegExp(
				'^(' +
					Object.keys( WP_EXTERNALS )
						.map( ( k ) =>
							k.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' )
						)
						.join( '|' ) +
					')$'
			);
			build.onResolve( { filter }, ( args ) => ( {
				path: args.path,
				namespace: 'wp-external',
			} ) );
			build.onLoad(
				{ filter: /.*/, namespace: 'wp-external' },
				( args ) => {
					const info = WP_EXTERNALS[ args.path ];
					usedHandles.add( info.handle );
					return {
						contents: `module.exports = ${ info.global };`,
						loader: 'js',
					};
				}
			);
		},
	};
}

/**
 * esbuild plugin: compile .scss via the Sass package, hand the CSS to esbuild.
 */
function scssPlugin() {
	return {
		name: 'scss',
		setup( build ) {
			build.onLoad( { filter: /\.scss$/ }, async ( args ) => {
				const result = await sass.compileAsync( args.path, {
					loadPaths: [ path.dirname( args.path ) ],
				} );
				return {
					contents: result.css,
					loader: 'css',
				};
			} );
		},
	};
}

function emitAssetPhp( handles, version ) {
	const deps = [ ...handles ]
		.sort()
		.map( ( h ) => `'${ h }'` )
		.join( ', ' );
	return `<?php return array('dependencies' => array(${ deps }), 'version' => '${ version }');\n`;
}

/**
 * Plugin: after each esbuild run, emit index.asset.php (deps from
 * usedHandles + content-hash version) and index-rtl.css (rtlcss-processed
 * companion for is_rtl() loads). Runs on every rebuild in watch mode.
 */
function postBuildPlugin( entry, outDir, base, usedHandles ) {
	return {
		name: 'post-build',
		setup( build ) {
			build.onEnd( async ( result ) => {
				if ( result.errors.length ) {
					return;
				}
				const jsBytes = await readFile(
					path.join( outDir, `${ base }.js` )
				);
				const version = createHash( 'sha256' )
					.update( jsBytes )
					.digest( 'hex' )
					.slice( 0, 20 );
				await writeFile(
					path.join( outDir, `${ base }.asset.php` ),
					emitAssetPhp( usedHandles, version )
				);

				const cssPath = path.join( outDir, `${ base }.css` );
				try {
					await access( cssPath );
					const css = await readFile( cssPath, 'utf8' );
					await writeFile(
						path.join( outDir, `${ base }-rtl.css` ),
						rtlcss.process( css )
					);
				} catch ( err ) {
					if ( err.code !== 'ENOENT' ) {
						throw err;
					}
				}

				console.log(
					`✓ ${ entry } → ${ path.relative(
						ROOT,
						outDir
					) }/${ base }.* [deps: ${
						[ ...usedHandles ].join( ', ' ) || '(none)'
					}] [v${ version }]`
				);
			} );
		},
	};
}

async function makeContext( entry, outDir ) {
	const usedHandles = new Set();
	// Output basename mirrors the entry filename (settings.js → settings.js
	// in outDir, index.js → index.js). Several WP-side enqueue paths look up
	// `build/<dir>/<entry-basename>.css` directly.
	const base = path.basename( entry, '.js' );
	return esbuild.context( {
		entryPoints: [ path.resolve( ROOT, entry ) ],
		bundle: true,
		minify: true,
		// dump_metadata reads node.constructor.name to label classes on the
		// canvas — without keepNames, minify mangles them to two-letter ids.
		// Same fix the substrate bundle uses; the overlay imports node classes
		// from the substrate so we need it here too.
		keepNames: true,
		format: 'iife',
		target: [ 'es2020' ],
		jsx: 'automatic',
		outfile: path.join( outDir, `${ base }.js` ),
		loader: {
			'.js': 'jsx',
			'.svg': 'dataurl',
			'.png': 'dataurl',
		},
		alias: {
			// Substrate runtime: CI sets NEWSPACK_NODES_RUNTIME; local dev falls back to the sibling checkout.
			'@newspack-nodes/runtime':
				process.env.NEWSPACK_NODES_RUNTIME ||
				path.resolve( ROOT, '../newspack-nodes/src/runtime/index.js' ),
			// Universal debugger overlay: CI sets NEWSPACK_NODES_DEBUG_OVERLAY;
			// local dev falls back to the sibling checkout's DebugOverlay.
			'@newspack-nodes/debug-overlay':
				process.env.NEWSPACK_NODES_DEBUG_OVERLAY ||
				path.resolve(
					ROOT,
					'../newspack-nodes/src/debug-overlay/DebugOverlay.js'
				),
		},
		plugins: [
			wpExternalsPlugin( usedHandles ),
			scssPlugin(),
			postBuildPlugin( entry, outDir, base, usedHandles ),
		],
		logLevel: 'warning',
	} );
}

const ENTRIES = [
	{
		entry: 'src/performance-dashboards/index.js',
		outDir: path.resolve( ROOT, 'build/performance-dashboards' ),
	},
	{
		entry: 'src/performance-logger/index.js',
		outDir: path.resolve( ROOT, 'build/performance-logger' ),
	},
	{
		entry: 'src/performance-gyroscope/index.js',
		outDir: path.resolve( ROOT, 'build/performance-gyroscope' ),
	},
	{
		entry: 'src/performance-request-log/index.js',
		outDir: path.resolve( ROOT, 'build/performance-request-log' ),
	},
	{
		entry: 'src/event-aggregator/index.js',
		outDir: path.resolve( ROOT, 'build/event-aggregator' ),
	},
	{
		entry: 'src/event-aggregator/settings.js',
		outDir: path.resolve( ROOT, 'build/event-aggregator-settings' ),
	},
	{
		entry: 'src/aggregator-admin/index.js',
		outDir: path.resolve( ROOT, 'build/aggregator-admin' ),
	},
];

async function main() {
	const watch = process.argv.includes( '--watch' );
	await rm( path.resolve( ROOT, 'build' ), { recursive: true, force: true } );
	for ( const e of ENTRIES ) {
		await mkdir( e.outDir, { recursive: true } );
	}
	const contexts = await Promise.all(
		ENTRIES.map( ( e ) => makeContext( e.entry, e.outDir ) )
	);
	if ( watch ) {
		await Promise.all( contexts.map( ( c ) => c.watch() ) );
		console.log( '👀 watching for changes…' );
		// Keep node alive; esbuild's watcher runs in a worker thread.
	} else {
		await Promise.all( contexts.map( ( c ) => c.rebuild() ) );
		await Promise.all( contexts.map( ( c ) => c.dispose() ) );
	}
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
