#!/usr/bin/env bash
# Stats Ledger doc-drift lint: every `public const LEDGER_*` in
# includes/class-stats-store.php must have a matching row in the Stats Schema
# table of docs/architecture-guide.md, and no row may name a Ledger no
# constant declares. ELN-only, so it can't live in the vendored lint-docs.sh.

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

STORE="includes/class-stats-store.php"
GUIDE="docs/architecture-guide.md"

fail=0
report() { printf '\342\234\227 lint-eln-docs: %s\n' "$1" >&2; fail=1; }

# Ledger names: the string literal each `public const LEDGER_*` declares.
constants=$( { grep -oE "public const LEDGER_[A-Z_]+ *= *'[^']+'" "$STORE" || true; } \
	| sed -E "s/.*= *'([^']+)'/\1/" | sort -u)
[ -z "$constants" ] && report "no LEDGER_* constants found in $STORE"

# The Stats Schema table's row region: from the "| Ledger | k | x | Columns |"
# header through the last consecutive "| ..." line after it.
table=$(awk '
	/^\| Ledger \| k \| x \| Columns \|$/ { inhdr = 1; next }
	inhdr == 1 { inhdr = 0; intable = 1; next }
	intable == 1 && /^\|/ { print; next }
	intable == 1 { exit }
' "$GUIDE")
[ -z "$table" ] && report "no Stats Schema Ledger table found in $GUIDE"

# The Ledger each row names: the backtick-quoted `stats:*` in its first cell.
# shellcheck disable=SC2016 # backticks are grep regex, not a subshell.
row_tokens=$(printf '%s\n' "$table" | awk -F'|' '{print $2}' \
	| grep -oE '`stats:[a-z-]+`' | tr -d '`' | sort -u)

missing=$(comm -23 <(printf '%s\n' "$constants") <(printf '%s\n' "$row_tokens"))
extra=$(comm -13 <(printf '%s\n' "$constants") <(printf '%s\n' "$row_tokens"))

[ -n "$missing" ] && report "Ledger(s) with no guide row: $(printf '%s' "$missing" | tr '\n' ' ')"
[ -n "$extra" ] && report "guide row(s) naming no LEDGER_* constant: $(printf '%s' "$extra" | tr '\n' ' ')"

# README's floor row; lint-docs.sh rule 6 reads only version_at_least lines.
floor=$(grep -hoE "version_at_least\( *'[0-9]+\.[0-9]+\.[0-9]+'" newspack-event-logger-nodes.php \
	| grep -oE "[0-9]+\.[0-9]+\.[0-9]+" | head -1 || true)
[ -z "$floor" ] && report "no version_at_least floor found in newspack-event-logger-nodes.php"
# shellcheck disable=SC2016 # backticks are grep regex, not a subshell.
row=$(grep -E '^\| `newspack-nodes` \|' README.md || true)
[ -z "$row" ] && report "no newspack-nodes row in README.md's requirements table"
if [ -n "$floor" ] && [ -n "$row" ] && ! printf '%s' "$row" | grep -qF "| $floor,"; then
	report "README.md's newspack-nodes row disagrees with the loader's floor ($floor): $row"
fi

if [ "$fail" -eq 0 ]; then
	num_constants=$(printf '%s\n' "$constants" | grep -c .)
	num_rows=$(printf '%s\n' "$table" | grep -c .)
	printf '\342\234\223 lint-eln-docs: every stats Ledger has a guide row (%d constants, %d rows)\n' \
		"$num_constants" "$num_rows" >&2
fi
exit "$fail"
