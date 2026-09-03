/**
 * ESLint rules for the dashboards, the shared modules beneath them and the
 * build scripts, standalone — no @wordpress/scripts dependency.
 *
 * The `@newspack-nodes/*` aliases resolve through the build alias and jest's
 * moduleNameMapper, neither of which eslint can see, so two settings carry
 * them past the resolver. `import/core-modules` takes whole specifiers and
 * matches no prefix, which covers `@newspack-nodes/runtime` and
 * `@newspack-nodes/debug-overlay`; the `@newspack-nodes/shared/*` subpath
 * alias, pointing at the sibling newspack-nodes checkout's hooks, utils and
 * components, needs the `import/no-unresolved` ignore pattern instead.
 */

/**
 * The directories any module may import and that may import no dashboard.
 *
 * One list drives both the restricted zones and their permitted imports below,
 * because three hand-kept copies drift the moment a fourth directory joins
 * them.
 *
 * @type {string[]}
 */
const BOTTOM_LAYERS = [ 'components', 'hooks', 'log-table' ];

/**
 * What a bottom-layer directory may import, relative to `src/`.
 *
 * Each bottom layer, plus `styles` and `test-helpers` — leaves that import no
 * dashboard themselves and so need no zone of their own. The bottom layers'
 * own tests import `test-helpers`, which is why it is permitted here rather
 * than gated.
 *
 * @type {string[]}
 */
const BOTTOM_LAYER_IMPORTS = [
	...BOTTOM_LAYERS.map( ( dir ) => `./${ dir }` ),
	'./styles',
	'./test-helpers',
];

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
		// Every exported function, method and class carries a docblock, and
		// this rule is what keeps it that way. It also catches the orphaned
		// docblock: a member inserted between a docblock and its subject
		// leaves the real function undocumented, and no other gate sees that.
		'jsdoc/require-jsdoc': [
			'error',
			{
				publicOnly: true,
				require: {
					FunctionDeclaration: true,
					MethodDefinition: true,
					ClassDeclaration: true,
				},
			},
		],
		// A stale closure is a bug, not a style note.
		'react-hooks/exhaustive-deps': [
			'error',
			{ additionalHooks: '^(useSelect|useSuspenseSelect)$' },
		],
		// The runtime layer lives in the substrate, so the zone that means
		// something HERE is the shared one: components/, hooks/ and
		// log-table/ are the bottom layer and cannot reach up into a
		// dashboard. Dashboards importing each other is deliberate and stays
		// allowed — settings mounts RulesAdmin, current-request reuses
		// RequestProfile.
		'import/no-restricted-paths': [
			'error',
			{
				zones: BOTTOM_LAYERS.map( ( dir ) => ( {
					target: `./src/${ dir }`,
					from: './src',
					except: BOTTOM_LAYER_IMPORTS,
					message: `src/${ dir } is a bottom layer — it cannot import from a dashboard directory.`,
				} ) ),
			},
		],
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
		// core-modules matches a whole specifier, never a prefix, so the
		// shared subpath alias is exempted here instead.
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
			// Build and CLI scripts run under Node and log to the console by
			// design; their helpers carry a one-line docblock rather than a
			// tag block, so require-param is off.
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
