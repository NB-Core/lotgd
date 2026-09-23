#!/bin/sh
set -eu

# Verifies the access rules of the root .htaccess against a real Apache 2.4.
#
# Shared hosting reads .htaccess and nothing else, so these rules are the only
# boundary between the web and everything the legacy layout keeps inside the
# document root. The Docker smoke test cannot cover them: that vhost sets
# AllowOverride None and carries its own copy of the rules.
#
# The document root is synthetic: the repository's .htaccess plus an empty
# fixture for every path requested. The rules match on paths alone, so the
# contents do not matter, and the test does not depend on what an installation
# happens to have created. Every path is requested twice, once with the game
# at the document root and once installed in a subdirectory, because
# RewriteRule patterns in .htaccess are relative to the directory and a rule
# that only works at the root is the usual way a subdirectory install leaks.
#
# Apache runs unprivileged on a loopback port with its own configuration; no
# system service is touched. Override the binary and module directory with
# APACHE_BIN and APACHE_MODULES on distributions other than Debian/Ubuntu.

APACHE_BIN="${APACHE_BIN:-/usr/sbin/apache2}"
APACHE_MODULES="${APACHE_MODULES:-/usr/lib/apache2/modules}"
PORT="${LOTGD_HTACCESS_PORT:-18090}"

repo=$(cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
pidfile="$work/httpd.pid"

cleanup() {
    if [ -f "$pidfile" ]; then
        kill "$(cat "$pidfile")" 2>/dev/null || true
    fi
    rm -rf "$work"
}
trap cleanup EXIT INT TERM

# Paths that must answer 403.
denied_paths='
data/cache/datacache-gamesettings
data/cache/twig/aa/compiled.php
data/cache/doctrine/prefix/cache-entry
cache/datacache-gamesettings
datacache-gamesettings
composer.json
composer.lock
cron.php
.env
dbconnect.php.bak
config/async.settings.php.dist
logs/bootstrap.log
vendor/autoload.php
src/Lotgd/Settings.php
docs/AdminGuide.md
README.md
migrations/Version20250724000000.php
pages/about/about_default.php
async/common/jaxon.php
'

# Paths that must stay reachable. Any status but 403 or 404 passes.
public_paths='
index.php
src/Lotgd/e_dom.js
templates_twig/aurora/assets/style.css
images/mydatacache-banner.png
'

make_fixtures() {
    root=$1
    mkdir -p "$root"
    cp "$repo/.htaccess" "$root/.htaccess"
    for path in $denied_paths $public_paths; do
        mkdir -p "$root/$(dirname "$path")"
        : > "$root/$path"
    done
}

docroot="$work/htdocs"
make_fixtures "$docroot"
make_fixtures "$docroot/lotgd"
# Apache drops to an unprivileged account when started as root; mktemp
# directories are private to their owner.
chmod -R a+rX "$work"

conf="$work/httpd.conf"
cat > "$conf" <<EOF
ServerRoot "$work"
ServerName 127.0.0.1
Listen 127.0.0.1:$PORT
PidFile "$pidfile"
ErrorLog "$work/error.log"
LogLevel warn
<IfModule !mpm_event_module>
    LoadModule mpm_event_module "$APACHE_MODULES/mod_mpm_event.so"
</IfModule>
<IfModule !authz_core_module>
    LoadModule authz_core_module "$APACHE_MODULES/mod_authz_core.so"
</IfModule>
<IfModule !dir_module>
    LoadModule dir_module "$APACHE_MODULES/mod_dir.so"
</IfModule>
<IfModule !mime_module>
    LoadModule mime_module "$APACHE_MODULES/mod_mime.so"
</IfModule>
<IfModule !rewrite_module>
    LoadModule rewrite_module "$APACHE_MODULES/mod_rewrite.so"
</IfModule>
<IfModule !unixd_module>
    LoadModule unixd_module "$APACHE_MODULES/mod_unixd.so"
</IfModule>
TypesConfig /dev/null
DocumentRoot "$docroot"
<Directory "$docroot">
    AllowOverride All
    Require all granted
</Directory>
EOF

"$APACHE_BIN" -t -f "$conf"
"$APACHE_BIN" -f "$conf" -k start

attempt=0
until curl --silent --output /dev/null "http://127.0.0.1:$PORT/"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Apache did not start" >&2
        cat "$work/error.log" >&2 || true
        exit 1
    fi
    sleep 1
done

failures=0

status_of() {
    curl --silent --output /dev/null --write-out '%{http_code}' \
        "http://127.0.0.1:$PORT/$1"
}

for prefix in '' 'lotgd/'; do
    for path in $denied_paths; do
        status=$(status_of "$prefix$path")
        if [ "$status" != 403 ]; then
            echo "FAIL /$prefix$path: expected 403, got $status" >&2
            failures=$((failures + 1))
        fi
    done
    for path in $public_paths; do
        status=$(status_of "$prefix$path")
        case "$status" in
            403|404)
                echo "FAIL /$prefix$path: expected reachable, got $status" >&2
                failures=$((failures + 1))
                ;;
        esac
    done
done

if [ "$failures" -ne 0 ]; then
    echo "--- Apache error log ---" >&2
    cat "$work/error.log" >&2 || true
    exit 1
fi

echo "All .htaccess access checks passed (document root and subdirectory)."
