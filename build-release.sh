#!/bin/bash
#
# Build release zip for the newspack-event-logger-nodes plugin.
#
# Produces a single zip containing the plugin directory at the root,
# ready for: wp plugin install --force --activate <url>.zip
#
# Usage: ./build-release.sh
# Output: release/newspack-event-logger-nodes.zip
#

set -euo pipefail

# Stop macOS BSD tooling from emitting AppleDouble (._foo) sidecars when it
# can't preserve xattrs in the destination format. Without this, .zip and .tgz
# artifacts ship with ._<name>.php files that WordPress dutifully includes
# alongside the real plugin code, dumping AppleDouble bytes to stdout.
export COPYFILE_DISABLE=1

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RELEASE_DIR="${SCRIPT_DIR}/release"
STAGING_DIR="${SCRIPT_DIR}/.release-staging"
PLUGIN="newspack-event-logger-nodes"

# Clean previous builds.
rm -rf "${RELEASE_DIR}" "${STAGING_DIR}"
mkdir -p "${RELEASE_DIR}"

# Build production autoloader.
echo "=== Building autoloader ==="
(cd "${SCRIPT_DIR}" && rm -rf vendor 2>/dev/null; composer install --no-dev --optimize-autoloader --quiet)

# Build JS/CSS if node_modules exists.
if [ -d "${SCRIPT_DIR}/node_modules" ]; then
	echo "=== Building JS/CSS ==="
	(cd "${SCRIPT_DIR}" && npm run build)
fi

# Stage plugin into a clean directory.
echo "=== Creating release zip ==="
echo "  ${PLUGIN}.zip"
mkdir -p "${STAGING_DIR}/${PLUGIN}"

# Copy plugin files, excluding dev artifacts.
# '._*' catches any AppleDouble companion files left over from macOS
# tooling — letting them through means WP loads them as PHP at runtime.
rsync -a \
	--exclude='*.log' \
	--exclude='.DS_Store' \
	--exclude='._*' \
	--exclude='.distignore' \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.gitkeep' \
	--exclude='.release-staging' \
	--exclude='00-newspack-profiler.php' \
	--exclude='build-release.sh' \
	--exclude='commitlint.config.js' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='node_modules' \
	--exclude='package-lock.json' \
	--exclude='package.json' \
	--exclude='phpcs.xml.dist' \
	--exclude='release' \
	--exclude='src' \
	--exclude='tests' \
	"${SCRIPT_DIR}/" "${STAGING_DIR}/${PLUGIN}/"

# Belt-and-suspenders: scrub anything the rsync excludes might've missed.
find "${STAGING_DIR}/${PLUGIN}" \( -name '._*' -o -name '.DS_Store' \) -delete

# Create zip with plugin dir at root (required for wp plugin install).
# -X strips extra file attributes; -x patterns block AppleDouble re-entry.
(cd "${STAGING_DIR}" && zip -rqX "${RELEASE_DIR}/${PLUGIN}.zip" "${PLUGIN}" --exclude '*/._*' --exclude '*/.DS_Store')

# Clean up.
rm -rf "${STAGING_DIR}"

# Also publish the mu-plugin file as a standalone asset. The deploy script
# (deploy-event-logger.sh, run on the Atomic side) fetches it directly from
# the release URL because it lives under mu-plugins/ on the target site,
# not under wp-content/plugins/.
cp "${SCRIPT_DIR}/00-newspack-profiler.php" "${RELEASE_DIR}/"

echo ""
echo "=== Release artifacts ==="
ls -lh "${RELEASE_DIR}"/*
