#!/bin/sh
set -eu

# End-to-end verification of the production image and its HTTP security
# boundary. Credentials exist only for this disposable Compose project.
export COMPOSE_PROJECT_NAME="lotgd-ci-${GITHUB_RUN_ID:-local}-$$"
export LOTGD_HTTP_PORT="${LOTGD_HTTP_PORT:-18080}"
export MYSQL_PASSWORD="ci-application-secret-$(date +%s)-$$"
export MYSQL_ROOT_PASSWORD="ci-root-secret-$(date +%s)-$$"
export MYSQL_DATABASE=lotgd
export MYSQL_USER=lotgduser

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
MYSQL_USER=$MYSQL_USER
MYSQL_PASSWORD=$MYSQL_PASSWORD
MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
LOTGD_HTTP_PORT=$LOTGD_HTTP_PORT
EOF
    created_env=true
fi

docker compose config >/dev/null
docker compose build web
docker compose up -d

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

docker compose exec -T web php -r '
    $configuration = [
        "DB_HOST" => getenv("MYSQL_HOST"),
        "DB_USER" => getenv("MYSQL_USER"),
        "DB_PASS" => getenv("MYSQL_PASSWORD"),
        "DB_NAME" => getenv("MYSQL_DATABASE"),
    ];
    if (file_put_contents("/var/lib/lotgd/dbconnect.php", "<?php\nreturn " . var_export($configuration, true) . ";\n") === false) {
        exit(1);
    }
'

attempt=0
until [ "$(docker inspect --format '{{.State.Health.Status}}' "${COMPOSE_PROJECT_NAME}-web-1")" = healthy ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 40 ]; then
        docker compose logs web
        exit 1
    fi
    sleep 2
done

# Verify the exact runtime prerequisites rather than assuming a successful
# package installation means PHP loaded every module.
docker compose exec -T web php -r '
    foreach (["gd", "mbstring", "mysqli", "pdo", "pdo_mysql", "zip"] as $extension) {
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
    test -w /var/lib/lotgd
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
        echo "Unexpected HTTP status for protected path (expected $expected, got $actual)" >&2
        exit 1
    fi
}

# These host requests exercise the production vhost, including the leading
# slash semantics of RewriteRule in VirtualHost context.
assert_status /installer.php 403
assert_status /install/ 403
assert_status /_health/ready 403
assert_status /.env 403
assert_status /lib/dbwrapper.php 403
assert_status /modules/cities.php 403

echo "Docker production smoke test passed"
