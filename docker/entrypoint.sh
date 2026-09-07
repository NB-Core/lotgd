#!/bin/sh
set -eu

state_path="${LOTGD_STATE_PATH:-/var/lib/lotgd}"
log_path="${LOTGD_DATA_DIR:-$state_path/logs}"
dbconnect_path="/var/www/html/dbconnect.php"

state_path="$(realpath -m "$state_path")"
log_path="$(realpath -m "$log_path")"

case "$log_path/" in
    "$state_path"/*) ;;
    *)
        echo "LOTGD_DATA_DIR '$log_path' must be inside LOTGD_STATE_PATH '$state_path'" >&2
        exit 1
        ;;
esac

# Fail before modifying persistent state when database credentials are absent or
# match values previously published as usable examples.
#
# Pass "optional" for a credential the web container does not need: an absent
# variable is then accepted, while a present one is still held to the same
# standard. The database service keeps its own required interpolation in
# docker-compose.yml, so a missing root password still fails the deployment.
validate_secret() {
    variable_name="$1"
    optional="${2:-}"
    if ! secret_value=$(printenv "$variable_name"); then
        if [ "$optional" = optional ]; then
            return 0
        fi
        secret_value=""
    fi
    # Normalize only for comparison, so case variants of published placeholders
    # cannot bypass the guard while the original secret reaches the application.
    normalized_secret=$(printf '%s' "$secret_value" | tr '[:upper:]' '[:lower:]')
    case "$normalized_secret" in
        ""|lotgdpass|rootpass|changeme|password|example)
            echo "$variable_name must be set to a non-example secret; generate one with 'openssl rand -base64 32'" >&2
            exit 1
            ;;
    esac
}

validate_secret MYSQL_PASSWORD
# The application never uses the MySQL administrative account. Compose no
# longer injects it here, but a hand-rolled deployment that still does must not
# get away with a published example value.
validate_secret MYSQL_ROOT_PASSWORD optional

# Runtime directories live outside the document root. Re-apply ownership and
# restrictive modes on every start because named volumes retain old metadata.
install -d -o www-data -g www-data -m 0750 "$state_path" "$log_path"
install -d -o www-data -g www-data -m 0770 \
    /var/cache/lotgd /var/cache/lotgd/twig /var/cache/lotgd/doctrine

if ! su -s /bin/sh www-data -c "test -w '$state_path' && test -w '$log_path'"; then
    echo "LOTGD_STATE_PATH '$state_path' or installer log directory '$log_path' is not writable by www-data" >&2
    exit 1
fi

# Production source is immutable, so direct the installer-generated database
# configuration into the persistent state volume. Respect a regular file from
# a development bind mount for compatibility with existing local checkouts.
if [ ! -e "$dbconnect_path" ] && [ ! -L "$dbconnect_path" ]; then
    ln -s "$state_path/dbconnect.php" "$dbconnect_path"
fi

# Installer stage 11 records completion before removing installer.php. Apply
# that persisted decision whenever a replacement image restores the source.
if [ -f "$state_path/installation-complete" ]; then
    rm -f /var/www/html/installer.php
fi

# Preserve the runtime image's extension/Apache initialization after applying
# the application-specific volume and secret checks above.
exec /usr/local/bin/docker-entrypoint.sh "$@"
