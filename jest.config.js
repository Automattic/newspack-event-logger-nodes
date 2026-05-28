// Jest config — standalone (no @wordpress/scripts dependency).
//
// Transforms JS/JSX via babel-jest (see babel.config.js), uses jsdom for
// tests that touch document/window/renderHook, and mirrors the build's
// `@newspack-nodes/runtime` alias (cross-plugin to the sibling
// newspack-nodes checkout) so `import { CommandClient } from
// '@newspack-nodes/runtime'` resolves identically in tests and bundles.

const path = require( 'path' );

module.exports = {
	testEnvironment: 'jsdom',
	testMatch: [ '**/__tests__/**/*.test.[jt]s?(x)' ],
	moduleNameMapper: {
		'^@newspack-nodes/runtime$': path.resolve(
			__dirname,
			'../newspack-nodes/src/runtime'
		),
		'^@newspack-nodes/debug-overlay$': path.resolve(
			__dirname,
			'../newspack-nodes/src/debug-overlay/DebugOverlay.js'
		),
		// Force ONE copy of React + @wordpress/element across both plugins. The
		// runtime's react hooks (useNodeState/useNodeFill) live in the sibling
		// newspack-nodes checkout, which has its own node_modules; without this,
		// the runtime's `@wordpress/element` resolves to the sibling's React while
		// ELN components use ELN's, and a runtime hook called from an ELN render
		// trips React's "Invalid hook call" (two dispatchers). The production
		// build dedupes these by externalizing them to the single WP global; jest
		// mirrors that by pinning all three to ELN's copy.
		'^@wordpress/element$': path.resolve(
			__dirname,
			'node_modules/@wordpress/element'
		),
		'^react$': path.resolve( __dirname, 'node_modules/react' ),
		'^react-dom$': path.resolve( __dirname, 'node_modules/react-dom' ),
		'^react/jsx-runtime$': path.resolve(
			__dirname,
			'node_modules/react/jsx-runtime'
		),
		'\\.(css|scss)$': '<rootDir>/jest.style-mock.js',
	},
	transform: {
		'\\.[jt]sx?$': 'babel-jest',
	},
	// d3 (and its many d3-* submodules) ships ESM-only — let babel-jest
	// transform them as well. Same goes for internmap (a d3 dep) and
	// delaunator / robust-predicates (d3-delaunay deps).
	transformIgnorePatterns: [
		'node_modules/(?!(d3|d3-.*|internmap|delaunator|robust-predicates)/)',
	],
};
