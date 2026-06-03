#!/usr/bin/env bash
# Unschedule the cache warmer and remove the state it created: the recurring
# cron event, the secret option, the encrypted auth credential, and the
# single-flight lock transient.
#
# Tolerant of already-absent state, but does NOT mask real wp failures: a
# reachability check runs first, so a bad --path / missing wp / unreachable DB
# aborts loud instead of being reported as a clean "cleaned up". Extra args
# pass through to `wp`, e.g.:
#   ./unschedule-cache-warmer.sh --allow-root --path=/var/www/html

set -euo pipefail

readonly HOOK="eln_cache_warmer_tick"
readonly SECRET_OPTION="eln_cache_warmer_secret"
readonly AUTH_OPTION="eln_cache_warmer_auth"
readonly LOCK_TRANSIENT="eln_cache_warmer_lock"
WP="${WP:-wp}"

# Prove wp + the WP install are reachable up front. With this established, a
# non-zero from a delete below genuinely means "already absent" (the case we
# tolerate) rather than a wp/connectivity failure we'd be hiding.
"$WP" option get siteurl "$@" > /dev/null

# `cron event delete` removes ALL scheduled instances of the hook.
"$WP" cron event delete "$HOOK" "$@" || echo "  (no scheduled $HOOK)"
"$WP" option delete "$SECRET_OPTION" "$@" || echo "  ($SECRET_OPTION not set)"
"$WP" option delete "$AUTH_OPTION" "$@" || echo "  ($AUTH_OPTION not set)"
"$WP" transient delete "$LOCK_TRANSIENT" "$@" || echo "  ($LOCK_TRANSIENT not set)"

echo "cache warmer unscheduled and cleaned up"
