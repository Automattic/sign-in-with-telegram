#!/usr/bin/env bash
# Sync the latest entries from CHANGELOG.md into readme.txt's == Changelog ==
# block. CHANGELOG.md (managed by release-please) is the source of truth;
# readme.txt is regenerated from it.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHANGELOG="$ROOT/CHANGELOG.md"
README="$ROOT/readme.txt"

[[ -f "$CHANGELOG" ]] || { echo "Missing $CHANGELOG" >&2; exit 1; }
[[ -f "$README"    ]] || { echo "Missing $README"    >&2; exit 1; }

# Render CHANGELOG.md → wp.org changelog format into a temp file:
#   - drop "# Changelog" top header
#   - "## [x.y.z](url) (date)"  →  "= x.y.z ="
#   - drop "### Subheading" lines (release-please's CC type groupings; wp.org
#     readme doesn't use them)
#   - "[text](url)"             →  "text"
#   - squeeze multiple blank lines to one (cat -s)
#
# Use a temp file (not awk -v) because BSD awk on macOS rejects newlines in
# variable values — would fail on multi-entry changelogs.
rendered_file=$(mktemp)
trap 'rm -f "$rendered_file"' EXIT

sed -E \
	-e '/^# Changelog$/d' \
	-e 's/^## \[?([A-Za-z0-9._+-]+)\]?.*$/= \1 =/' \
	-e '/^### /d' \
	-e 's/\[([^]]+)\]\([^)]+\)/\1/g' \
	"$CHANGELOG" |
	cat -s > "$rendered_file"

# Replace the entire == Changelog == ... section of readme.txt with the
# rendered body. Section ends at the next "== Heading ==" line or EOF.
#
# awk exits 1 if it didn't find exactly one == Changelog == heading — that
# would mean the section was renamed or deleted, and a silent no-op here
# would let the CI drift guard pass while the changelog stops being
# maintained. The non-zero exit aborts the script via `set -e`.
new=$(awk -v body_file="$rendered_file" '
	BEGIN { in_section = 0; matched = 0 }
	/^== Changelog ==[[:space:]]*$/ && !matched {
		print "== Changelog =="
		print ""
		started = 0
		while ((getline line < body_file) > 0) {
			# Skip leading blanks so we get exactly one blank after the
			# == Changelog == heading, not two.
			if (!started && line == "") continue
			started = 1
			print line
		}
		close(body_file)
		in_section = 1
		matched   = 1
		next
	}
	in_section && /^== / { in_section = 0 }
	!in_section { print }
	END {
		if (matched != 1) {
			print "ERROR: could not locate a == Changelog == section in readme.txt" > "/dev/stderr"
			exit 1
		}
	}
' "$README")

if [[ "$new" != "$(cat "$README")" ]]; then
	printf '%s\n' "$new" > "$README"
	echo "readme.txt updated."
else
	echo "readme.txt already in sync."
fi
