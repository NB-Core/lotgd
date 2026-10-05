#!/bin/sh
# Check that common.php declares the version a release tag is for.
#
# Usage: scripts/check-release-version.sh 2.0.8   (the tag without its "v")
#
# Used by .github/workflows/release.yml before it packages a tag: an archive
# whose common.php names another version would install as that version.
set -eu

expected="${1:?usage: $0 VERSION}"
root=$(dirname "$0")/..
declared=$(sed -n 's/^\$logd_version = "\([^" ]*\).*";$/\1/p' "$root/common.php")

if [ "$declared" != "$expected" ]; then
    echo "::error::common.php declares version '$declared', but the tag is for '$expected'." >&2
    exit 1
fi

echo "common.php declares $declared, matching the tag."
