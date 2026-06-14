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
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const ROOT = path.resolve( __dirname, '..' );

// Kit path is ROOT-relative (like the aliases below) so it points at the
// sibling newspack-nodes checkout; CI overrides it via NEWSPACK_NODES_BUILD_KIT.
const buildKit =
	process.env.NEWSPACK_NODES_BUILD_KIT ||
	path.resolve( ROOT, '../newspack-nodes/src/build-kit/index.mjs' );
const { buildDashboards } = await import( pathToFileURL( buildKit ).href );

const alias = {
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
	// Shared React hooks/utils/components: CI sets NEWSPACK_NODES_SHARED;
	// local dev falls back to the sibling checkout's src/shared. We alias
	// the canonical source instead of copying it (the retired sync-shared.sh).
	'@newspack-nodes/shared':
		process.env.NEWSPACK_NODES_SHARED ||
		path.resolve( ROOT, '../newspack-nodes/src/shared' ),
};

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
