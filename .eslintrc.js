// ESLint config — standalone (no @wordpress/scripts dependency).
//
// `import/core-modules` tells eslint-plugin-import that `@newspack-nodes/runtime`
// resolves at runtime (build alias + jest moduleNameMapper handle resolution).
module.exports = {
	root: true,
	extends: [
		'plugin:@wordpress/eslint-plugin/recommended',
		'plugin:@wordpress/eslint-plugin/i18n',
	],
	rules: {
		'@wordpress/i18n-text-domain': [
			'error',
			{ allowedTextDomain: [ 'newspack-event-logger-nodes' ] },
		],
	},
	overrides: [
		{
			files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
			extends: [ 'plugin:@wordpress/eslint-plugin/test-unit' ],
		},
	],
	settings: {
		'import/core-modules': [ '@newspack-nodes/runtime' ],
	},
};
