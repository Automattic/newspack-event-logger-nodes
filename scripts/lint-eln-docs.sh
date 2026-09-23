#!/usr/bin/env bash
# Stats_Store namespace doc-drift lint: every `public const NS_*` in
# includes/class-stats-store.php must have a matching row in the Memcache
# Schema table of docs/architecture-guide.md, and no row may name a token no
# constant declares. ELN-only, so it can't live in the vendored lint-docs.sh.

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

STORE="includes/class-stats-store.php"
GUIDE="docs/architecture-guide.md"

fail=0
report() { printf '\342\234\227 lint-eln-docs: %s\n' "$1" >&2; fail=1; }

# Constant tokens: the string literal each `public const NS_*` declares.
constants=$( { grep -oE "public const NS_[A-Z_]+ *= *'[^']+'" "$STORE" || true; } \
	| sed -E "s/.*= *'([^']+)'/\1/" | sort -u)
[ -z "$constants" ] && report "no NS_* constants found in $STORE"

# The Memcache Schema table's row region: from the "| Namespace | Use | TTL |"
# header through the last consecutive "| ..." line after it.
table=$(awk '
	/^\| Namespace \| Use \| TTL \|$/ { inhdr = 1; next }
	inhdr == 1 { inhdr = 0; intable = 1; next }
	intable == 1 && /^\|/ { print; next }
	intable == 1 { exit }
' "$GUIDE")
[ -z "$table" ] && report "no Memcache Schema namespace table found in $GUIDE"

# Tokens the table names: every backtick-quoted, NS_*-shaped (letters and
# underscores only) string in each row's first cell. A row may name two, as
# `urlrank_s` / `urlrank_sh` does. A hyphenated token such as `eln-urls-page`
# is a different Table_Node keyspace outside the evlog:p{N} grammar, not an
# NS_* namespace, and is deliberately not matched here.
# shellcheck disable=SC2016 # backticks are grep regex, not a subshell.
row_tokens=$(printf '%s\n' "$table" | awk -F'|' '{print $2}' \
	| grep -oE '`[a-z_]+`' | tr -d '`' | sort -u)

missing=$(comm -23 <(printf '%s\n' "$constants") <(printf '%s\n' "$row_tokens"))
extra=$(comm -13 <(printf '%s\n' "$constants") <(printf '%s\n' "$row_tokens"))

[ -n "$missing" ] && report "NS_* token(s) with no guide row: $(printf '%s' "$missing" | tr '\n' ' ')"
[ -n "$extra" ] && report "guide row token(s) naming no NS_* constant: $(printf '%s' "$extra" | tr '\n' ' ')"

if [ "$fail" -eq 0 ]; then
	num_constants=$(printf '%s\n' "$constants" | grep -c .)
	num_rows=$(printf '%s\n' "$table" | grep -c .)
	printf '\342\234\223 lint-eln-docs: every Stats_Store namespace has a guide row (%d constants, %d rows)\n' \
		"$num_constants" "$num_rows" >&2
fi
exit "$fail"
