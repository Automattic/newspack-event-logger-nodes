// Stylelint config — standalone (no newspack-scripts dependency), so SCSS here
// lints the way the canonical Newspack plugins do.
//
// The `rules` block is newspack-scripts/config/stylelint.config.js verbatim.
// Keep it in step with that file: no dependency carries a change across, so a
// local tune is a silent divergence from the plugins this claims to match.
// Two divergences are deliberate. `extends` names the SCSS variant rather than
// the plain CSS root, because every stylesheet here is SCSS; `ignoreFiles`
// names `build/**` rather than `dist/**`, this plugin's output directory.
//
// That SCSS variant is the NON-stylistic `@wordpress/stylelint-config/scss`,
// not `/scss-stylistic`. The stylistic layer's `@stylistic/*` rules
// (indentation, declaration-colon-space-after) fight prettier — prettier wraps
// and indents, those rules flag the result, and `--fix` cannot reconcile the
// two. Prettier owns all formatting; stylelint only checks non-stylistic
// correctness.
module.exports = {
	extends: [ '@wordpress/stylelint-config/scss' ],
	ignoreFiles: [ 'build/**', 'node_modules/**', 'release/**', 'scripts/**' ],
	rules: {
		'rule-empty-line-before': null,
		'at-rule-empty-line-before': null,
		'comment-empty-line-before': null,
		'no-descending-specificity': null,
		'function-url-quotes': null,
		'font-weight-notation': null,
		'color-named': null,
		'selector-class-pattern': null,
		'custom-property-pattern': null,
		'at-rule-no-unknown': null,
		'alpha-value-notation': null,
		'color-function-notation': null,
		'selector-not-notation': null,
		'no-invalid-double-slash-comments': null,
		'function-no-unknown': [ true, { ignoreFunctions: [ '/color/' ] } ],
		'annotation-no-unknown': [ true, { ignoreAnnotations: [ '/default/' ] } ],
		'media-feature-range-notation': null,
	},
};
