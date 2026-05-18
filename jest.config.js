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
		'\\.(css|scss)$': '<rootDir>/jest.style-mock.js',
	},
	transform: {
		'\\.[jt]sx?$': 'babel-jest',
	},
};
