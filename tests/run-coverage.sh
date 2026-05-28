#!/bin/bash
#
# Run PHPUnit tests with code coverage
#
# Usage:
#   ./run-coverage.sh              # Run all tests with coverage
#   ./run-coverage.sh --filter X   # Run specific test
#
# Coverage report is written to /volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage/
#

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Log_Manager refuses to run as root (permission problems for www-data
# workers). Tests instantiate Log_Manager, so the suite fails with
# "enabled is false" when invoked via `docker exec` (root by default).
# Bail loudly here instead of silently running 37 false failures.
if [ "$(id -u)" -eq 0 ]; then
	echo "ERROR: tests/run-coverage.sh must run as a non-root user." >&2
	echo "       Log_Manager refuses to run as root; invoke via:" >&2
	echo "         docker exec -u bend <container> bash tests/run-coverage.sh" >&2
	exit 1
fi

# Also clean up the directory the tests actually use. The earlier line at
# the bottom of this script cleaned /tmp/newspack-event-logger-nodes-test,
# but LogManagerTest writes to /tmp/event-logger-nodes-test (different).
rm -rf /tmp/event-logger-nodes-test 2>/dev/null

# Pin phpunit to the project's vendor binary rather than whatever
# /usr/bin/phpunit happens to be. The container's system phpunit is
# 11.x; the project pins 10.5.x in composer.json. Mixing them causes
# "Call to undefined method PHPUnit\Event\DispatchingEmitter::exportsObjects"
# because the system loader pulls 11.x classes while the vendor tree
# is wired for 10.x.
PHPUNIT="$SCRIPT_DIR/../vendor/bin/phpunit"

# Ensure xdebug coverage mode is enabled
export XDEBUG_MODE=coverage

# Clean up any previous test artifacts
rm -rf /tmp/newspack-event-logger-nodes-test 2>/dev/null

# Run PHPUnit with coverage
"$PHPUNIT" --configuration phpunit.xml \
    --coverage-clover /volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage/clover.xml \
    --coverage-html /volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage \
	--enforce-time-limit \
    "$@"

echo ""
echo "Coverage report: /volumes/pyrobase/tmp/newspack-event-logger-nodes-coverage/index.html"
