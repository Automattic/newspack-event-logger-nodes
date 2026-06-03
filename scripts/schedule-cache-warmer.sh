#!/usr/bin/env bash
# Schedule the standalone cache warmer's recurring tick.
#
# Idempotent: re-running won't create a duplicate event. Requires the
# 01-newspack-cache-warmer.php drop-in to be installed (it registers the
# `eln_cache_warmer_minute` recurrence this schedules against).
#
# Any extra args pass through to `wp` so this works in the dev container
# (`--allow-root --path=/var/www/html`) and on a remote host alike, e.g.:
#   ./schedule-cache-warmer.sh --allow-root --path=/var/www/html

set -euo pipefail

readonly HOOK="eln_cache_warmer_tick"
readonly RECURRENCE="eln_cache_warmer_minute"
WP="${WP:-wp}"

# Capture the event list in its own step so a wp failure (bad --path, wp
# missing, DB down) aborts loud here under `set -e` instead of being read as
# "hook not scheduled" and silently creating a duplicate.
existing="$( "$WP" cron event list --field=hook "$@" )"

if grep -Fxq "$HOOK" <<< "$existing"; then
	echo "already scheduled: $HOOK"
	exit 0
fi

"$WP" cron event schedule "$HOOK" now "$RECURRENCE" "$@"
echo "scheduled $HOOK every minute ($RECURRENCE)"
