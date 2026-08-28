#!/bin/sh
set -eu

state_path="${LOTGD_STATE_PATH:-/var/lib/lotgd}"
dbconnect_path="/var/www/html/dbconnect.php"

# Fail before modifying persistent state when database credentials are absent or
# match values previously published as usable examples.
validate_secret() {
    variable_name="$1"
    secret_value=$(printenv "$variable_name" 2>/dev/null || true)
    case "$secret_value" in
        ""|lotgdpass|rootpass|changeme|password|example)
            echo "$variable_name must be set to a non-example secret; generate one with 'openssl rand -base64 32'" >&2
            exit 1
            ;;
    esac
}

validate_secret MYSQL_PASSWORD
validate_secret MYSQL_ROOT_PASSWORD

mkdir -p "$state_path"
if [ ! -w "$state_path" ]; then
    echo "LOTGD_STATE_PATH '$state_path' is not writable" >&2
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

exec "$@"
