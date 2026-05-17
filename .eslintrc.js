/**
 * Eslint config — extends wp-scripts defaults and tells eslint-plugin-import
 * to treat `@newspack-nodes/runtime` as a known module so it doesn't flag the
 * webpack alias as unresolvable. Resolution at runtime is handled by
 * webpack.config.js (build time) and jest.config.js (test time).
 */
module.exports = {
	extends: [ require.resolve( '@wordpress/scripts/config/.eslintrc.js' ) ],
	settings: {
		'import/core-modules': [ '@newspack-nodes/runtime' ],
	},
};
