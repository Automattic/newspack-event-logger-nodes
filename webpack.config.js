/**
 * Webpack config — extends the wp-scripts default and adds a single resolve
 * alias so dashboard JS can import the substrate runtime by name:
 *
 *   import { CommandClient, useNodeState, SseConnector } from '@newspack-nodes/runtime';
 *
 * The substrate sits as a sibling plugin checkout. Webpack inlines every
 * import into the consuming dashboard's bundle (wp-scripts has no split-chunks
 * configured), so once `npm run build` runs the runtime is baked into each
 * `build/<tree>/index.js` and no separate runtime artifact needs to ship with
 * the released plugin zip.
 *
 * If you ever switch wp-scripts to a split-chunks mode, you'll need to rsync
 * the substrate runtime into the release artifact too — see build-release.sh.
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const SUBSTRATE_RUNTIME = path.resolve(
	__dirname,
	'../newspack-nodes/src/runtime'
);

module.exports = {
	...defaultConfig,
	resolve: {
		...( defaultConfig.resolve || {} ),
		alias: {
			...( ( defaultConfig.resolve && defaultConfig.resolve.alias ) ||
				{} ),
			'@newspack-nodes/runtime': SUBSTRATE_RUNTIME,
		},
	},
};
