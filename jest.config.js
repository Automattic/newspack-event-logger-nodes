// Jest config — built from the substrate's shared build-kit factory (resolved
// from the sibling newspack-nodes checkout, the same place the @newspack-nodes/*
// aliases point). React + @wordpress/element + d3 are pinned to THIS plugin's
// node_modules so a runtime hook called from an ELN render can't trip React's
// "Invalid hook call" (two dispatchers), and d3 (installed only here) resolves
// for the shared useTimeChart. d3 ships ESM-only, so its packages opt out of
// transformIgnorePatterns.

const path = require( 'node:path' );
const {
	createJestConfig,
} = require( '../newspack-nodes/src/build-kit/jest.cjs' );

module.exports = createJestConfig( {
	aliasBase: path.resolve( __dirname, '../newspack-nodes/src' ),
	pinReactFrom: path.resolve( __dirname, 'node_modules' ),
	extraMappers: {
		'^d3$': path.resolve( __dirname, 'node_modules/d3' ),
	},
	// @noble/hashes is the substrate's synchronous command signer; it and d3 ship
	// ESM-only, so both must opt OUT of the node_modules transform skip.
	transformIgnorePatterns: [
		'node_modules/(?!(@noble/.*|d3|d3-.*|internmap|delaunator|robust-predicates)/)',
	],
} );
