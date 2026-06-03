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

# Optionally store an application password for the warm loopback so an edge /
# page cache forwards it to PHP for a real render instead of serving a cached
# homepage. Read silently; blank leaves any existing value untouched. The
# drop-in stores it encrypted (sodium, keyed off wp_salt); the plaintext rides
# stdin into `wp eval`, never a CLI arg, so it never lands in `ps`.
printf 'App password for the warm loopback (user:app-password), blank to skip: ' >&2
read -r -s cred || true
printf '\n' >&2
if [ -n "$cred" ]; then
	printf '%s' "$cred" | "$WP" eval '
$c = trim( file_get_contents( "php://stdin" ) );
if ( ! class_exists( "Newspack_Cache_Warmer\\Cache_Warmer" ) ) {
	fwrite( STDERR, "cache-warmer drop-in not installed; cannot store auth\n" );
	exit( 1 );
}
Newspack_Cache_Warmer\Cache_Warmer::store_auth( $c );
' "$@"
	echo "stored encrypted loopback credential"
fi

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
