#!/bin/sh
set -eu

# Exercise the rendered Compose model rather than relying on textual checks.
command -v docker >/dev/null 2>&1 || {
    echo "docker is required to run this test" >&2
    exit 1
}

project_dir=$(mktemp -d)
trap 'rm -rf "$project_dir"' EXIT
cp docker-compose.yml "$project_dir/docker-compose.yml"

for missing_secret in MYSQL_PASSWORD MYSQL_ROOT_PASSWORD; do
    cat > "$project_dir/.env" <<'EOF'
MYSQL_PASSWORD=compose-test-application-secret
MYSQL_ROOT_PASSWORD=compose-test-root-secret
EOF
    sed -i "s/^${missing_secret}=.*$/${missing_secret}=/" "$project_dir/.env"
    if docker compose --project-directory "$project_dir" config >/dev/null 2>&1; then
        echo "Compose accepted missing secret '$missing_secret'" >&2
        exit 1
    fi
done

cat > "$project_dir/.env" <<'EOF'
MYSQL_PASSWORD=compose-test-application-secret
MYSQL_ROOT_PASSWORD=compose-test-root-secret
EOF
rendered=$(docker compose --project-directory "$project_dir" config)
printf '%s\n' "$rendered" | grep -F 'host_ip: 127.0.0.1' >/dev/null || {
    echo "Default web port is not bound to loopback:8080" >&2
    exit 1
}
printf '%s\n' "$rendered" | grep -E "published: ['\"]?8080['\"]?$" >/dev/null || {
    echo "Default host port is not 8080" >&2
    exit 1
}

for example_secret in lotgdpass rootpass changeme password example; do
    if MYSQL_PASSWORD="$example_secret" MYSQL_ROOT_PASSWORD=valid-test-secret \
        LOTGD_STATE_PATH="$project_dir/state" docker/entrypoint.sh true 2>/dev/null; then
        echo "Entrypoint accepted documented example secret '$example_secret'" >&2
        exit 1
    fi
done

echo "Docker Compose security configuration passed"
