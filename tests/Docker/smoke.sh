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

# Seed a log sentinel before recreation. Together with dbconnect.php this proves
# the state volume retains both installer output and database configuration.
docker compose exec -T web sh -c \
    'printf "%s\n" persistent-installer-log > /var/lib/lotgd/logs/install.log'

# Recreate Apache so its environment sees the temporary installer flag. A 200
# response is required: curl returning zero for a 403 would be a false positive.
LOTGD_INSTALL_ENABLED=1 docker compose up -d --force-recreate --no-deps --no-build web
wait_for_status /installer.php 200 "Installer did not become explicitly accessible"
assert_status /install/errors/install.log 403

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

echo "Docker production smoke test passed"
