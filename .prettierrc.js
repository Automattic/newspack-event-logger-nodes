// Prettier config — the WordPress preset.
//
// Loads `@wordpress/prettier-config`: tabs, single quotes, and the spaces
// inside parens that the pinned `wp-prettier` fork adds. Its `.scss` override
// drops the last two, and `npm run format` rewrites SCSS too.
//
// Only the CLI needs this file. `@wordpress/eslint-plugin` seeds its
// `prettier/prettier` rule with the same preset, so eslint expects this output
// either way; without a config, `prettier --write` falls back to 2-space,
// double-quoted defaults and leaves eslint erroring on every file it touched.
module.exports = require( '@wordpress/prettier-config' );
