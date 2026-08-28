#!/usr/bin/env bash
set -euo pipefail

# Supply disposable credentials without ever committing or printing them.
cat >.env <<'EOF'
MYSQL_DATABASE=lotgd_ci
MYSQL_HOST=db
MYSQL_USER=lotgd_ci
MYSQL_PASSWORD=ci-user-password-only
MYSQL_ROOT_PASSWORD=ci-root-password-only
LOTGD_HTTP_PORT=18080
EOF

cleanup() {
    docker compose down --volumes --remove-orphans
    rm -f .env .ci-dbconnect.php
}
trap cleanup EXIT

docker compose config --quiet
docker compose build web
docker compose up -d db

# The normal browser installer writes this state file. The smoke test creates
# the equivalent one-shot configuration so it can test readiness unattended.
cat >.ci-dbconnect.php <<'PHP'
<?php
return [
    'DB_HOST' => 'db',
    'DB_USER' => 'lotgd_ci',
    'DB_PASS' => 'ci-user-password-only',
    'DB_NAME' => 'lotgd_ci',
    'DB_PREFIX' => '',
];
PHP

docker compose up -d web
docker compose cp .ci-dbconnect.php web:/var/lib/lotgd/dbconnect.php

for _ in {1..30}; do
    [ "$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose ps -q web)")" = healthy ] && break
    sleep 2
done
test "$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose ps -q web)")" = healthy

docker compose exec -T web php -r '
    foreach (["gd", "json", "mbstring", "mysqli", "pdo", "pdo_mysql", "zip"] as $extension) {
        if (!extension_loaded($extension)) {
            exit(1);
        }
    }
    exit(0);
'
docker compose exec -T --user www-data web sh -c \
    'test -w /var/cache/lotgd/twig && test -w /var/cache/lotgd/doctrine && test -w /var/lib/lotgd'
docker compose exec -T web apache2ctl -t
docker compose exec -T web lotgd-healthcheck

# Host-side requests are not loopback from Apache's perspective. All sensitive
# resources and the internal readiness endpoint must therefore be forbidden.
for path in /_internal/ready /.env /dbconnect.php /lib/dbwrapper.php; do
    test "$(curl --silent --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:18080${path}")" = 403
done
