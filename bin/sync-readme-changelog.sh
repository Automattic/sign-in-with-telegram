#!/usr/bin/env bash
# Sync the latest entries from CHANGELOG.md into readme.txt's == Changelog ==
# block. CHANGELOG.md (managed by release-please) is the source of truth;
# readme.txt is regenerated from it.
#
# CHANGELOG.md keeps release-please's developer-facing detail — category
# subheadings and the PR / commit links appended to each line. readme.txt
# is user-facing, so the render below strips that metadata and tidies each
# entry into a plain, sentence-cased, full-stopped bullet. To curate a
# release's wording, edit CHANGELOG.md in the open Release PR; the result
# flows through here on the next sync.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHANGELOG="$ROOT/CHANGELOG.md"
README="$ROOT/readme.txt"

[[ -f "$CHANGELOG" ]] || { echo "Missing $CHANGELOG" >&2; exit 1; }
[[ -f "$README"    ]] || { echo "Missing $README"    >&2; exit 1; }

# A temp file (not awk -v) carries the rendered body — BSD awk on macOS
# rejects newlines in -v variable values.
rendered_file=$(mktemp)
trap 'rm -f "$rendered_file"' EXIT

# Stage 1 — strip markdown links, then the trailing PR / commit references
# release-please appends to each entry (e.g. "([#20](url)) ([acc576b](url))"
# becomes "(#20) (acc576b)" after the link strip, then nothing).
#
# Stage 2 — render the wp.org changelog body with awk:
#   - drop the "# Changelog" title and "### " category subheadings
#   - "## x.y.z (date)" -> "= x.y.z ="
#   - sentence-case each bullet and give it a terminal period
#   - exactly one blank line after each "= x.y.z =", none between bullets
sed -E \
	-e 's/\[([^]]+)\]\([^)]+\)/\1/g' \
	-e 's/ *\(#[0-9]+\)//g' \
	-e 's/ *\([0-9a-f]{7,40}\)//g' \
	"$CHANGELOG" |
	awk '
		/^# Changelog[[:space:]]*$/ { next }
		/^### / { next }

		/^## / {
			heading = $0
			sub( /^## /, "", heading )
			# First bracketed token, else the first whitespace-delimited one.
			if ( match( heading, /\[[^]]+\]/ ) ) {
				version = substr( heading, RSTART + 1, RLENGTH - 2 )
			} else {
				version = heading
				sub( /[[:space:]].*$/, "", version )
			}
			if ( seen ) { print "" }
			print "= " version " ="
			print ""
			seen = 1
			next
		}

		/^[*-] / {
			entry = $0
			sub( /^[*-] [[:space:]]*/, "", entry )
			sub( /[[:space:]]+$/, "", entry )
			if ( entry == "" ) { next }
			# Sentence-case: uppercase a leading lowercase ASCII letter.
			first = substr( entry, 1, 1 )
			if ( first ~ /[a-z]/ ) {
				entry = toupper( first ) substr( entry, 2 )
			}
			# Terminal period unless it already ends in sentence punctuation.
			if ( entry !~ /[.!?]$/ ) { entry = entry "." }
			print "* " entry
			next
		}
	' > "$rendered_file"

# Stage 3 — splice the rendered body into readme.txt's == Changelog ==
# section (which ends at the next "== Heading ==" line or EOF). awk exits 1
# if it didn't find exactly one == Changelog == heading — a renamed or
# deleted section would otherwise let the CI drift guard pass silently
# while the changelog stops being maintained.
new=$(awk -v body_file="$rendered_file" '
	BEGIN { in_section = 0; matched = 0 }
	/^== Changelog ==[[:space:]]*$/ && !matched {
		print "== Changelog =="
		print ""
		while ( ( getline line < body_file ) > 0 ) { print line }
		close( body_file )
		in_section = 1
		matched   = 1
		next
	}
	in_section && /^== / { in_section = 0 }
	!in_section { print }
	END {
		if ( matched != 1 ) {
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
