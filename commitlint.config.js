/**
 * Commit-message rules for the conventional-commit check `scripts/commit-msg`
 * runs.
 *
 * The shared preset is taken whole, with no local overrides, so the type and
 * scope vocabulary stays identical across the sibling plugins that vendor the
 * same hook.
 */
module.exports = { extends: [ '@commitlint/config-conventional' ] };
