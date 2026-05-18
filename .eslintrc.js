// ESLint config — standalone (no @wordpress/scripts dependency).
//
// `import/core-modules` tells eslint-plugin-import that `@newspack-nodes/runtime`
// resolves at runtime (build alias + jest moduleNameMapper handle resolution).
module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
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
