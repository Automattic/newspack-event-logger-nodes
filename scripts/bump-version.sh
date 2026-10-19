#!/bin/sh
#
# bump-version.sh — update the version across this plugin.
#
# Usage:
#   ./scripts/bump-version.sh <new-version>
#
# The shared flow lives in scripts/lib/bump-version.sh; this file is only the
# per-plugin knobs.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd || exit 1)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
# shellcheck disable=SC2034  # consumed by the sourced lib
SUBSTRATE_DIR="$PLUGIN_DIR/../newspack-nodes"
# shellcheck disable=SC2034  # consumed by the sourced lib
PLUGIN_FILE="newspack-event-logger-nodes.php"
# shellcheck disable=SC2034
VERSION_CONST="NEWSPACK_EVENT_LOGGER_NODES_VERSION"

# Bundles are built in CI against this pin; a stale one ships old shared code.
# shellcheck disable=SC2034
SUBSTRATE_PIN=".github/workflows/release.yml"

# The drop-in ships as its own release asset and is copied by hand, so its
# header has to move with the plugin or nobody can tell which one a site has.
# shellcheck disable=SC2034
DROP_IN="mu-plugins/00-newspack-profiler.php"

# shellcheck source=/dev/null
. "$SCRIPT_DIR/lib/bump-version.sh"
