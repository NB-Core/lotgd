#!/bin/sh
set -eu

# End-to-end verification of the production image and its HTTP security
# boundary. Credentials exist only for this disposable Compose project.
export COMPOSE_PROJECT_NAME="lotgd-ci-${GITHUB_RUN_ID:-local}-$$"
export LOTGD_HTTP_PORT="${LOTGD_HTTP_PORT:-18080}"
export MYSQL_PASSWORD="ci-application-secret-$(date +%s)-$$"
export MYSQL_ROOT_PASSWORD="ci-root-secret-$(date +%s)-$$"
export MYSQL_DATABASE=lotgd
export MYSQL_HOST=db
export MYSQL_USER=lotgduser

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <prebuilt-web-image>" >&2
    exit 2
fi
export LOTGD_WEB_IMAGE="$1"

created_env=false

cleanup() {
    docker compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    if [ "$created_env" = true ]; then
        rm -f .env
    fi
}
trap cleanup EXIT INT TERM

# The production Compose model intentionally requires .env. A clean CI
# checkout does not contain one, so create a private disposable file without
# overwriting a developer's existing local configuration.
if [ ! -e .env ]; then
    umask 077
    cat > .env <<EOF
MYSQL_DATABASE=$MYSQL_DATABASE
MYSQL_HOST=$MYSQL_HOST
MYSQL_USER=$MYSQL_USER
MYSQL_PASSWORD=$MYSQL_PASSWORD
MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
LOTGD_HTTP_PORT=$LOTGD_HTTP_PORT
EOF
    created_env=true
fi

docker compose config >/dev/null
docker compose up -d --no-build

# Confirm Docker applied, rather than merely parsed, the privilege boundary.
# Inspect is used deliberately: a Compose YAML assertion alone cannot prove the
# daemon created the containers with the requested HostConfig.
for service in web db; do
    container="${COMPOSE_PROJECT_NAME}-${service}-1"
    docker inspect --format '{{json .HostConfig.SecurityOpt}}' "$container" \
        | grep -F 'no-new-privileges:true' >/dev/null || {
            echo "no-new-privileges is not effective for $service" >&2
            exit 1
        }
    [ "$(docker inspect --format '{{json .HostConfig.CapDrop}}' "$container")" = '["ALL"]' ] || {
        echo "default capabilities were not dropped for $service" >&2
        exit 1
    }
done

# Wait for MySQL before installing the minimal, read-only probe configuration.
attempt=0
until [ "$(docker inspect --format '{{.State.Health.Status}}' "${COMPOSE_PROJECT_NAME}-db-1")" = healthy ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        docker compose logs db
        exit 1
    fi
    sleep 2
done

# A restart loop previously surfaced only as an opaque `docker compose exec`
# daemon error. Require a stable, executable web process first and print its
# startup logs immediately if the least-privilege entrypoint cannot initialize.
attempt=0
until docker compose exec -T web true >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Web container did not reach a runnable state" >&2
        docker compose ps web
        docker compose logs web
        exit 1
    fi
    sleep 1
done

docker compose exec -T web php -r '
    $configuration = [
        "DB_HOST" => getenv("MYSQL_HOST"),
        "DB_USER" => getenv("MYSQL_USER"),
        "DB_PASS" => getenv("MYSQL_PASSWORD"),
        "DB_NAME" => getenv("MYSQL_DATABASE"),
    ];
    // Deliberate output proves the readiness endpoint safely discards content
    // emitted by legacy or hand-edited database configuration files.
    $contents = "<?php\necho \"discarded configuration output\";\nreturn "
        . var_export($configuration, true)
        . ";\n";
    if (file_put_contents("/var/www/html/dbconnect.php", $contents) === false) {
        fwrite(STDERR, "Failed to write dbconnect.php\n");
        exit(1);
    }
    // Verify the file is readable and contains expected content
    if (!is_readable("/var/www/html/dbconnect.php")) {
        fwrite(STDERR, "dbconnect.php is not readable\n");
        exit(1);
    }
    $testConfig = require("/var/www/html/dbconnect.php");
    if (!is_array($testConfig) || empty($testConfig["DB_HOST"])) {
        fwrite(STDERR, "dbconnect.php configuration invalid\n");
        exit(1);
    }
'

# The readiness fixture is intentionally a configured installation. Record its
# exact content so the later fresh-installer window can temporarily hide the
# file without weakening the persistence assertion.
dbconnect_checksum=$(docker compose exec -T web sha256sum /var/lib/lotgd/dbconnect.php | awk '{print $1}')

# Wait for web container to become healthy after configuration is written.
# The health check probe will attempt a MySQL connection, so increase retry
# limit to account for any remaining database initialization time.
attempt=0
until [ "$(docker inspect --format '{{.State.Health.Status}}' "${COMPOSE_PROJECT_NAME}-web-1")" = healthy ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 90 ]; then
        echo "Web container failed to become healthy after $((attempt * 2)) seconds" >&2
        docker compose logs web
        echo "--- Checking dbconnect.php in web container ---" >&2
        docker compose exec -T web sh -c 'test -f /var/www/html/dbconnect.php && echo "File exists" || echo "File missing"' 2>&1 || true
        docker compose exec -T web sh -c 'test -r /var/www/html/dbconnect.php && echo "File readable" || echo "File not readable"' 2>&1 || true
        exit 1
    fi
    sleep 2
done

# Verify the exact runtime prerequisites rather than assuming a successful
# package installation means PHP loaded every module.
docker compose exec -T web php -r '
    // PHP exposes OPcache to extension_loaded() under its registered Zend name.
    foreach (["gd", "mbstring", "mysqli", "Zend OPcache", "pdo", "pdo_mysql", "zip"] as $extension) {
        if (! extension_loaded($extension)) {
            fwrite(STDERR, "Missing PHP extension\n");
            exit(1);
        }
    }
'
docker compose exec -T --user www-data web sh -c '
    test -w /var/cache/lotgd &&
    test -w /var/cache/lotgd/twig &&
    test -w /var/cache/lotgd/doctrine &&
    test -w /var/lib/lotgd &&
    test -w /var/lib/lotgd/logs &&
    test ! -w /var/www/html &&
    php_file=$(find /var/www/html -type f -name "*.php" -print -quit) &&
    test -n "$php_file" &&
    test ! -w "$php_file"
'

# The container-local probe must succeed without returning diagnostic content.
docker compose exec -T web php -r '
    $body = file_get_contents("http://127.0.0.1/_health/ready");
    $status = $http_response_header[0] ?? "";
    exit($body === "" && str_contains($status, "204") ? 0 : 1);
'

# The runtime re-runs a2enmod/a2dismod from its own default list on every
# start, and that list does not contain mod_headers. If the image ever stops
# asking for it, these headers vanish silently: game pages become cacheable by
# shared caches and static files lose their content-type guard.
assert_header() {
    path="$1"
    expected="$2"
    headers=$(curl --silent --show-error --head "http://127.0.0.1:${LOTGD_HTTP_PORT}${path}")
    if ! printf '%s' "$headers" | grep -Fiq "$expected"; then
        echo "Missing header '$expected' on ${path}" >&2
        printf '%s\n' "$headers" >&2
        exit 1
    fi
}

# Match the complete header value, not a substring: the distribution default
# "Server: Apache/2.4.x (Ubuntu)" contains "Server: Apache" and would satisfy a
# substring check while proving the opposite of what this asserts.
assert_header_value() {
    path="$1"
    expected="$2"
    headers=$(curl --silent --show-error --head "http://127.0.0.1:${LOTGD_HTTP_PORT}${path}" | tr -d '\r')
    if ! printf '%s\n' "$headers" | grep -Fxiq "$expected"; then
        echo "Header '$expected' is not present verbatim on ${path}" >&2
        printf '%s\n' "$headers" >&2
        exit 1
    fi
}

assert_header /templates_twig/aurora/assets/style.css 'X-Content-Type-Options: nosniff'
assert_header /index.php 'Cache-Control: no-store, private'
# docker/apache/hardening.conf replaces the distribution's "ServerTokens OS",
# so nothing may follow the product name.
assert_header_value /index.php 'Server: Apache'

# PHP's scan directory is version- and SAPI-specific on this runtime, so a
# wrong path would leave the production settings silently unapplied rather
# than failing anything. The Apache SAPI is the one serving the game.
docker compose exec -T web sh -c '
    set -eu
    conf="/etc/php/${PHP_VERSION}/apache2/conf.d/zz-lotgd.ini"
    test -f "$conf"
    grep -q "display_errors = Off" "$conf"
' || {
    echo "Production PHP settings are not installed in the Apache scan directory" >&2
    exit 1
}

assert_status() {
    path="$1"
    expected="$2"
    actual=$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${LOTGD_HTTP_PORT}${path}")
    if [ "$actual" != "$expected" ]; then
        echo "Unexpected HTTP status for ${path} (expected $expected, got $actual)" >&2
        exit 1
    fi
}

wait_for_status() {
    path="$1"
    expected="$2"
    description="$3"
    attempt=0
    while :; do
        # curl exits successfully for HTTP 403, so readiness is determined only
        # from its explicit status output, never from curl's process status.
        actual=$(curl --silent --output /dev/null --write-out '%{http_code}' \
            "http://127.0.0.1:${LOTGD_HTTP_PORT}${path}" || true)
        if [ "$actual" = "$expected" ]; then
            return
        fi
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "$description (expected $expected, got $actual)" >&2
            echo "--- Unexpected HTTP response ---" >&2
            curl --silent --show-error --include \
                "http://127.0.0.1:${LOTGD_HTTP_PORT}${path}" >&2 || true
            docker compose logs web
            exit 1
        fi
        sleep 1
    done
}

# These host requests exercise the production vhost, including the leading
# slash semantics of RewriteRule in VirtualHost context.
assert_status /installer.php 403
assert_status /install/ 403
assert_status /install/errors/install.log 403
assert_status /_health/ready 403
assert_status /.env 403
assert_status /lib/dbwrapper.php 403
assert_status /modules/cities.php 403
# Include-only trees and CLI entry points must not be reachable over HTTP.
assert_status /pages/about/about_default.php 403
assert_status /async/common/jaxon.php 403
assert_status /cron.php 403
assert_status /vendor/autoload.php 403
assert_status /src/Lotgd/Settings.php 403
assert_status /config/async.settings.php.dist 403
assert_status /logs/bootstrap.log 403
assert_status /migrations/Version20250724000000.php 403
assert_status /composer.json 403
assert_status /composer.lock 403
# The image also carries its own build files inside the document root; the
# second copy of the readiness probe must not become a public database oracle.
assert_status /docker/health/ready.php 403
assert_status /docker/entrypoint.sh 403
# Regular game entry points and static assets stay reachable. A 200 is not
# required here (an uninstalled game may redirect), only a non-denied status.
for public_path in /index.php /templates_twig/aurora/assets/style.css /src/Lotgd/e_dom.js; do
    status=$(curl --silent --output /dev/null --write-out '%{http_code}' \
        "http://127.0.0.1:${LOTGD_HTTP_PORT}${public_path}")
    case "$status" in
        403|404)
            echo "Public entry point ${public_path} was denied (got $status)" >&2
            exit 1
            ;;
    esac
done

# The web container must not carry the MySQL administrative credential.
if docker compose exec -T web printenv MYSQL_ROOT_PASSWORD >/dev/null 2>&1; then
    echo "MYSQL_ROOT_PASSWORD is exposed to the web container" >&2
    exit 1
fi

# Seed a log sentinel before recreation. Together with dbconnect.php this proves
# the state volume retains both installer output and database configuration.
docker compose exec -T web sh -c \
    'printf "%s\n" persistent-installer-log > /var/lib/lotgd/logs/install.log'

# A real fresh installer has no dbconnect.php yet. The earlier readiness probe
# needs one, so retain it under a temporary name in the same state volume while
# testing installer access; leaving it active would make stage 0 treat the empty
# CI database as an upgrade and query tables which have not been installed.
docker compose exec -T web \
    mv /var/lib/lotgd/dbconnect.php /var/lib/lotgd/dbconnect.php.smoke-backup

# Recreate Apache so its environment sees the temporary installer flag. A 200
# response is required: curl returning zero for a 403 would be a false positive.
LOTGD_INSTALL_ENABLED=1 docker compose up -d --force-recreate --no-deps --no-build web
wait_for_status /installer.php 200 "Installer did not become explicitly accessible"
assert_status /install/errors/install.log 403

# Restore the exact readiness configuration before modelling completion. The
# final recreation below must preserve this file alongside logs and the marker.
docker compose exec -T web \
    mv /var/lib/lotgd/dbconnect.php.smoke-backup /var/lib/lotgd/dbconnect.php

# Model installer stage 11 by placing its durable completion marker in the
# state volume. Recreating with the flag deliberately left at 1 proves the
# marker takes precedence and cannot be bypassed by stale configuration.
docker compose exec -T web sh -c \
    'printf "%s\n" completed > /var/lib/lotgd/installation-complete'
LOTGD_INSTALL_ENABLED=1 docker compose up -d --force-recreate --no-deps --no-build web
wait_for_status /installer.php 403 "Completion marker did not lock installer.php"
assert_status /install/ 403

# All durable installer state must survive both forced recreations.
docker compose exec -T web sh -c '
    test -L /var/www/html/dbconnect.php &&
    test -s /var/lib/lotgd/dbconnect.php &&
    grep -F persistent-installer-log /var/lib/lotgd/logs/install.log >/dev/null &&
    grep -F completed /var/lib/lotgd/installation-complete >/dev/null
'
restored_checksum=$(docker compose exec -T web sha256sum /var/lib/lotgd/dbconnect.php | awk '{print $1}')
if [ "$restored_checksum" != "$dbconnect_checksum" ]; then
    echo "dbconnect.php changed while exercising installer persistence" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# A first installation without the browser: `bin/install` in the container,
# as the operator documentation describes it. Start from the state of a fresh
# deployment: no database configuration and no completion marker. The earlier
# steps left the readiness fixture and a modelled marker behind, both written
# by root, which www-data could not replace.
docker compose exec -T web rm -f /var/lib/lotgd/dbconnect.php /var/lib/lotgd/installation-complete

if install_output=$(docker compose exec -T --user www-data web php bin/install --admin=Smoke 2>&1); then
    :
else
    echo "bin/install failed:" >&2
    echo "$install_output" >&2
    exit 1
fi
echo "$install_output" | grep -q '^Password: [A-Za-z0-9]\{24\}$' || {
    echo "bin/install did not print a generated password:" >&2
    echo "$install_output" >&2
    exit 1
}
docker compose exec -T web sh -c '
    test -s /var/lib/lotgd/dbconnect.php &&
    grep -F completed /var/lib/lotgd/installation-complete >/dev/null
' || {
    echo "bin/install did not write the configuration and the completion marker to the state volume" >&2
    exit 1
}
if docker compose exec -T --user www-data web php bin/install --admin=Again >/dev/null 2>&1; then
    echo "bin/install ran a second time over an installed game" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# An update of an installed game. The installer is gone at this point, as it is
# in every completed container, so the game has to bring its schema up to date
# by itself on the first request -- through Doctrine, which reads dbconnect.php
# through the state-volume link.
#
# Model an update that brings one new migration and a new version number: roll
# the newest migration back and record an older version as installed.
newest_migration=$(docker compose exec -T web sh -c 'ls /var/www/html/migrations | sort | tail -n 1 | sed "s/\.php$//"')
docker compose exec -T --user www-data web \
    php bin/doctrine migrations:execute "Lotgd\\Migrations\\${newest_migration}" --down --no-interaction

db_sql() {
    docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql --batch --skip-column-names -u"$MYSQL_USER" "$MYSQL_DATABASE"' <<EOF
$1
EOF
}

if [ "$(db_sql "SELECT COUNT(*) FROM accounts WHERE login = 'Smoke' AND superuser & 1")" != "1" ]; then
    echo "bin/install did not create the administrator Smoke" >&2
    exit 1
fi
if [ "$(db_sql "SELECT COUNT(*) FROM modules WHERE active = 1")" = "0" ]; then
    echo "bin/install did not install and activate the recommended modules" >&2
    exit 1
fi

db_sql "REPLACE INTO settings (setting, value) VALUES ('installer_version', '0.0.0 smoke')"
if [ "$(db_sql "SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = 'Lotgd\\\\Migrations\\\\${newest_migration}'")" != "0" ]; then
    echo "Rolling back ${newest_migration} did not take effect" >&2
    exit 1
fi
# Settings are cached on disk; drop the copy that predates the edit above.
docker compose exec -T --user www-data web sh -c 'find /var/cache/lotgd -maxdepth 1 -name "datacache-*" -delete'

code_version=$(docker compose exec -T web sh -c "sed -n 's/^\\\$logd_version = \"\\(.*\\)\";\$/\\1/p' /var/www/html/common.php")
if [ -z "$code_version" ]; then
    echo "Could not read the game version from common.php" >&2
    exit 1
fi

# home.php, not index.php: index.php only forwards there and never loads the
# game. The status only rules out a crash; the database below proves the
# upgrade.
status=$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${LOTGD_HTTP_PORT}/home.php")
case "$status" in
    200|302) ;;
    *)
        echo "The first request after the update answered $status" >&2
        docker compose logs web
        exit 1
        ;;
esac

installed_version=$(db_sql "SELECT value FROM settings WHERE setting = 'installer_version'")
if [ "$installed_version" != "$code_version" ]; then
    echo "The game did not record its upgrade: installer_version is '$installed_version', expected '$code_version'" >&2
    db_sql "SELECT date, severity, message FROM gamelog WHERE category = 'maintenance' ORDER BY logid DESC LIMIT 5" >&2 || true
    docker compose logs web
    exit 1
fi
if [ "$(db_sql "SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = 'Lotgd\\\\Migrations\\\\${newest_migration}'")" != "1" ]; then
    echo "The game recorded the new version without applying ${newest_migration}" >&2
    exit 1
fi
if [ "$(db_sql "SELECT COUNT(*) FROM gamelog WHERE category = 'maintenance' AND message LIKE 'Upgraded the database from 0.0.0 smoke%'")" != "1" ]; then
    echo "The upgrade was not written to the game log" >&2
    exit 1
fi

echo "Docker production smoke test passed"
