// ESLint config — standalone (no @wordpress/scripts dependency).
//
// `import/core-modules` tells eslint-plugin-import that the exact-match
// `@newspack-nodes/*` aliases resolve at runtime (build alias + jest
// moduleNameMapper handle resolution). The subpath alias
// `@newspack-nodes/shared/*` (sibling-checkout shared hooks/utils/components)
// is whitelisted via the no-unresolved `ignore` pattern below.
module.exports = {
	root: true,
	extends: [
		'plugin:@wordpress/eslint-plugin/recommended',
		'plugin:@wordpress/eslint-plugin/i18n',
	],
	rules: {
		// knip suppression tag: an export that exists for its unit test, not
		// for callers. jsdoc/check-tag-names rejects unknown tags otherwise.
		'jsdoc/check-tag-names': [ 'error', { definedTags: [ 'testonly' ] } ],
		'@wordpress/i18n-text-domain': [
			'error',
			{ allowedTextDomain: [ 'newspack-event-logger-nodes' ] },
		],
		// The 7-field Message TYPE is a bitmask (Tachikoma convention:
		// TM_BYTESTREAM, TM_EOF, …); `&`/`|` on it are idiomatic, not a smell.
		'no-bitwise': 'off',
		// warn/error are legitimate logging (the runtime's stderr sink is the
		// browser console); still flag stray console.log/debug/info.
		'no-console': [ 'error', { allow: [ 'warn', 'error' ] } ],
		// `_`-prefixed args are intentionally unused (signature/override parity).
		'no-unused-vars': [
			'error',
			{ ignoreRestSiblings: true, argsIgnorePattern: '^_' },
		],
		'react/forbid-component-props': [
			'error',
			{
				forbid: [
					{
						propName: 'isSmall',
						message: 'Deprecated in WP 6.2 — use size="small".',
					},
				],
			},
		],
		// The `@newspack-nodes/shared/*` subpath alias resolves at runtime
		// (esbuild alias + jest moduleNameMapper) to the sibling newspack-nodes
		// checkout; eslint can't follow it without the build context.
		'import/no-unresolved': [
			'error',
			{ ignore: [ '^@newspack-nodes/shared/' ] },
		],
	},
	overrides: [
		{
			files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
			extends: [ 'plugin:@wordpress/eslint-plugin/test-unit' ],
			// jest.setup.js defines this console-assertion helper globally.
			globals: { expectConsoleWarn: 'readonly' },
		},
		{
			// Build/CLI scripts run under Node and legitimately log to the console.
			files: [ 'scripts/**/*.@(js|mjs)' ],
			env: { node: true },
			rules: {
				'no-console': 'off',
				'jsdoc/require-param': 'off',
			},
		},
	],
	settings: {
		'import/core-modules': [
			'@newspack-nodes/runtime',
			'@newspack-nodes/debug-overlay',
		],
	},
};
