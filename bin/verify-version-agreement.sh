#!/usr/bin/env bash
# Verify that the Version: header in sign-in-with-telegram.php, the
# Stable tag: in readme.txt, and the release tag all agree before we
# publish a release. A mismatch would ship an artifact whose internal
# version disagrees with the tag it's published under.
#
# Usage: bin/verify-version-agreement.sh <tag>   (e.g. v0.1.1)

set -euo pipefail

GIT_REF="${1:?release tag required, e.g. v0.1.1}"
TAG_VERSION="${GIT_REF#v}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# The release-please block annotation (* x-release-please-start-version)
# sits on its own lines and doesn't match `Version:`, so it's ignored here.
HEADER_VERSION=$(sed -nE \
	's|^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*$|\1|p' \
	"$ROOT/sign-in-with-telegram.php" | head -1)
README_VERSION=$(sed -nE \
	's|^Stable tag:[[:space:]]*([^[:space:]]+).*$|\1|p' \
	"$ROOT/readme.txt" | head -1)

echo "release tag: $TAG_VERSION"
echo "PHP header:  $HEADER_VERSION"
echo "readme.txt:  $README_VERSION"

if [[ -z "$HEADER_VERSION" || -z "$README_VERSION" ]]; then
	echo "::error::Could not extract a version from one of the source files." >&2
	exit 1
fi

if [[ "$TAG_VERSION" != "$HEADER_VERSION" || "$TAG_VERSION" != "$README_VERSION" ]]; then
	echo "::error::Version mismatch — refusing to publish." >&2
	exit 1
fi

echo "All three agree on $TAG_VERSION."
