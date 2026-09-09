#!/bin/sh
# Verify the vendored Jaxon client runtime.
#
# async/js/vendor/jaxon holds the files jaxon-core would otherwise pull from
# cdn.jsdelivr.net. Serving them from the tree is what makes the async CSRF
# header reviewable, but a copied dependency has two failure modes a CDN does
# not, and this checks both:
#
#   integrity - a file edited in place, by accident or otherwise. SHA256SUMS is
#               the record; a mismatch means the served bytes are not the ones
#               that were reviewed.
#   freshness - upstream moved on and nobody noticed. Dependabot cannot see
#               this: the files are not a declared dependency, so nothing
#               proposes the update.
#
# Integrity is checked offline and always. Freshness needs the network and is
# skipped without it, so the job still runs on an isolated builder.
set -eu

repository_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
asset_dir="$repository_root/async/js/vendor/jaxon"
# The checksum and version records live under tests/ rather than beside the
# files: async/js is served to browsers, and these two say nothing a visitor
# needs. tests/ is denied wholesale by the web server.
sums_file="$repository_root/tests/Async/jaxon-assets.sha256"
pinned_version=$(sed -n 's/^JAXON_JS_VERSION=//p' "$repository_root/tests/Async/jaxon-assets.version")

if [ -z "$pinned_version" ]; then
    echo "tests/Async/jaxon-assets.version does not name a version" >&2
    exit 1
fi

echo "Jaxon client runtime, pinned at $pinned_version"

# --- integrity -------------------------------------------------------------
cd "$asset_dir"
if ! sha256sum --quiet --check "$sums_file"; then
    echo >&2
    echo "A vendored Jaxon file does not match tests/Async/jaxon-assets.sha256." >&2
    echo "Either restore it, or re-run the update procedure in docs/Docker.md" >&2
    echo "and commit the regenerated checksums together with the new files." >&2
    exit 1
fi
echo "   integrity: all files match the recorded checksums"

# The version the PHP side defaults to has to be the one that is vendored, or
# the comment in async/common/jaxon.php is describing a different release.
core_default=$(sed -n "s#.*jaxon-js@\([0-9.]*\)/dist.*#\1#p" \
    "$repository_root/vendor/jaxon-php/jaxon-core/src/Plugin/Code/AssetManager.php" | head -n 1)
if [ -n "$core_default" ] && [ "$core_default" != "$pinned_version" ]; then
    echo "   MISMATCH: jaxon-core expects $core_default, the tree carries $pinned_version" >&2
    exit 1
fi
echo "   matches the version jaxon-core defaults to"

# --- freshness -------------------------------------------------------------
latest=$(curl -fsS --max-time 20 \
    "https://api.github.com/repos/jaxon-php/jaxon-js/releases/latest" 2>/dev/null \
    | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"v\{0,1\}\([^"]*\)".*/\1/p' | head -n 1) || latest=""

if [ -z "$latest" ]; then
    echo "   freshness: skipped, upstream not reachable"
    exit 0
fi

if [ "$latest" = "$pinned_version" ]; then
    echo "   freshness: $pinned_version is the current release"
    exit 0
fi

echo "   freshness: upstream is at $latest, the tree carries $pinned_version" >&2
echo >&2
echo "Nothing proposes this update automatically. Review the upstream changes," >&2
echo "then follow the update procedure in docs/Docker.md." >&2
exit 1
