#!/bin/sh
set -eu

state_path="${LOTGD_STATE_PATH:-/var/lib/lotgd}"
dbconnect_path="/var/www/html/dbconnect.php"

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
