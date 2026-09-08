#!/bin/sh
set -eu

# Report on the freshness of the pinned base images.
#
# Two different things can be wrong with a digest pin, and only one of them is
# something Dependabot can tell us about:
#
#   Drift      The tag now resolves to a different digest than the one pinned.
#              An update exists. Dependabot opens a PR for this, so it is
#              reported here and does not fail the run.
#
#   Staleness  The tag itself has not been rebuilt for a long time. That
#              usually means the upstream line has been abandoned, and it is
#              invisible to a bot: when a line stops moving, the digest a bot
#              would propose is the digest already pinned, so no PR ever
#              appears. This fails the run, because nothing else will notice.
#
# The pins are read out of Dockerfile and docker-compose.yml rather than being
# repeated here, so this cannot drift away from what is actually built.

STALE_AFTER_DAYS="${STALE_AFTER_DAYS:-120}"

# Validate before anything else. A non-numeric threshold makes the [ -gt ]
# below fail with "Illegal number", and because that comparison is an if
# condition, set -e does not stop the script: every staleness test would be
# skipped and the run would end with a reassuring success message while an
# abandoned pin sat in the output. A check that silently stops checking is
# worse than no check.
case "$STALE_AFTER_DAYS" in
    ''|*[!0-9]*)
        echo "STALE_AFTER_DAYS must be a non-negative integer, got '$STALE_AFTER_DAYS'" >&2
        exit 1
        ;;
esac

for tool in curl jq; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "$tool is required to run this check" >&2
        exit 1
    }
done

repository_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)

# Emit "<image reference>" for every pinned external image.
collect_pins() {
    sed -n 's/^FROM \([^ ]*@sha256:[0-9a-f]\{64\}\).*$/\1/p' "$repository_root/Dockerfile"
    sed -n 's/^[[:space:]]*image:[[:space:]]*\([^ ]*@sha256:[0-9a-f]\{64\}\).*$/\1/p' \
        "$repository_root/docker-compose.yml"
}

now_epoch=$(date -u +%s)
failures=0
checked=0

for pin in $(collect_pins); do
    reference=${pin%@*}
    pinned_digest=${pin#*@}
    repository=${reference%:*}
    tag=${reference##*:}

    # Docker Hub namespaces official images under "library".
    case "$repository" in
        */*) hub_repository="$repository" ;;
        *)   hub_repository="library/$repository" ;;
    esac

    checked=$((checked + 1))
    echo "== $reference"

    response=$(curl --silent --show-error --fail --max-time 30 \
        "https://hub.docker.com/v2/repositories/${hub_repository}/tags/${tag}" 2>/dev/null) || {
        echo "   could not query Docker Hub for ${hub_repository}:${tag}" >&2
        failures=$((failures + 1))
        continue
    }

    current_digest=$(printf '%s' "$response" | jq -r '.digest // empty')
    last_updated=$(printf '%s' "$response" | jq -r '.last_updated // empty')

    if [ -z "$current_digest" ] || [ -z "$last_updated" ]; then
        echo "   Docker Hub returned no digest or timestamp" >&2
        failures=$((failures + 1))
        continue
    fi

    # BSD date needs a different flag; only GNU date is expected in CI.
    last_epoch=$(date -u -d "$last_updated" +%s 2>/dev/null || echo '')
    if [ -z "$last_epoch" ]; then
        echo "   could not parse the rebuild timestamp '$last_updated'" >&2
        failures=$((failures + 1))
        continue
    fi
    age_days=$(( (now_epoch - last_epoch) / 86400 ))

    echo "   last rebuilt: $last_updated (${age_days} days ago)"

    if [ "$current_digest" = "$pinned_digest" ]; then
        echo "   pin is current"
    else
        echo "   NOTE: the tag now resolves to ${current_digest}"
        echo "         An update exists; Dependabot should be proposing it."
    fi

    if [ "$age_days" -gt "$STALE_AFTER_DAYS" ]; then
        echo "   STALE: not rebuilt for ${age_days} days (threshold ${STALE_AFTER_DAYS})." >&2
        echo "          An upstream line that stops being rebuilt stops receiving" >&2
        echo "          security updates, and no bot will report it. Check whether" >&2
        echo "          this line is still maintained; see" >&2
        echo "          docs/Docker.md#migrating-to-another-runtime-line." >&2
        failures=$((failures + 1))
    fi
done

if [ "$checked" -eq 0 ]; then
    echo "No pinned images found — has the pin format changed?" >&2
    exit 1
fi

if [ "$failures" -ne 0 ]; then
    echo "Image pin check failed for ${failures} of ${checked} images." >&2
    exit 1
fi

echo "All ${checked} pinned images were rebuilt within ${STALE_AFTER_DAYS} days."
