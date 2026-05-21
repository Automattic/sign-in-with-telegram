#!/usr/bin/env bash
# Sync readme.txt's `Stable tag:` from sign-in-with-telegram.php's `Version:`
# header. The PHP header is the source of truth — release-please bumps it via
# the block annotation (`* x-release-please-start-version`); readme.txt is
# regenerated to match.
#
# Why we don't annotate readme.txt directly: wp.org's readme parser treats
# HTML comments inside the header block as terminators — `Stable tag:` and
# `License:` after the marker silently go missing, breaking Plugin Check.
# The PHP file's parser (`get_file_data` in WP core) ignores unknown lines,
# so the annotation only lives there.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_FILE="$ROOT/sign-in-with-telegram.php"
README="$ROOT/readme.txt"

[[ -f "$PHP_FILE" ]] || { echo "Missing $PHP_FILE" >&2; exit 1; }
[[ -f "$README"   ]] || { echo "Missing $README"   >&2; exit 1; }

VERSION=$(sed -nE \
	's|^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]/]+).*$|\1|p' \
	"$PHP_FILE" | head -1)

if [[ -z "$VERSION" ]]; then
	echo "Could not extract Version: from $PHP_FILE" >&2
	exit 1
fi

# Portable sed (works on BSD + GNU) via a temp file.
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

sed -E "s|^Stable tag:[[:space:]]*.*$|Stable tag: $VERSION|" "$README" > "$tmp"

if ! cmp -s "$tmp" "$README"; then
	mv "$tmp" "$README"
	trap - EXIT
	echo "readme.txt Stable tag set to $VERSION."
else
	echo "readme.txt Stable tag already in sync."
fi
