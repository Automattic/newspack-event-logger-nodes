/**
 * Babel config for jest alone. `babel-jest` is the transform the shared
 * build-kit factory sets in `jest.config.js`; esbuild compiles the dashboard
 * bundles in `build/` and reads none of this.
 *
 * `targets: { node: 'current' }` compiles for the Node running the suite, and
 * the explicit target overrides `package.json`'s `browserslist` key, which
 * preset-env would otherwise read — down-compiling tests to the browser matrix
 * for nothing.
 *
 * `runtime: 'automatic'` matches esbuild's `jsx: 'automatic'`, so JSX resolves
 * through `react/jsx-runtime`, the copy `jest.config.js` pins to this plugin's
 * node_modules. Source modules import `@wordpress/element` and never `React`,
 * so the classic runtime would fail them on an undefined `React`.
 */

module.exports = {
	presets: [
		[ '@babel/preset-env', { targets: { node: 'current' } } ],
		[ '@babel/preset-react', { runtime: 'automatic' } ],
	],
};
