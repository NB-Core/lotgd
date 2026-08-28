#!/bin/sh
set -eu

# Exercise Compose's normalized JSON model so aliases and short YAML syntax
# cannot accidentally make a textual security check pass.
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
docker compose --project-directory "$project_dir" config --format json > "$project_dir/rendered.json"

# Validate both the immutable supply-chain inputs and the runtime boundaries in
# the fully interpolated model. The application image is intentionally supplied
# by CI/deployment, while every external image is digest pinned here.
python3 - "$project_dir/rendered.json" <<'PY'
import json
import pathlib
import re
import sys

model = json.loads(pathlib.Path(sys.argv[1]).read_text())
compose = pathlib.Path("docker-compose.yml").read_text()
dockerfile = pathlib.Path("Dockerfile").read_text()

pins = {
    "composer": r"FROM composer:2@sha256:[0-9a-f]{64} AS composer",
    "runtime": r"FROM thecodingmachine/php:8\.3-v4-apache@sha256:[0-9a-f]{64}",
    "database": r"image: mysql:8\.4@sha256:[0-9a-f]{64}",
}
for name, pattern in pins.items():
    source = compose if name == "database" else dockerfile
    if not re.search(pattern, source):
        raise SystemExit(f"{name} image is not pinned by readable tag and digest")

services = model["services"]
expected_caps = {
    "web": {"CHOWN", "DAC_OVERRIDE", "FOWNER", "NET_BIND_SERVICE", "SETGID", "SETUID"},
    "db": {"CHOWN", "DAC_OVERRIDE", "FOWNER", "SETGID", "SETUID"},
}
for name, capabilities in expected_caps.items():
    service = services[name]
    if "no-new-privileges:true" not in service.get("security_opt", []):
        raise SystemExit(f"{name} does not enable no-new-privileges")
    if set(service.get("cap_drop", [])) != {"ALL"}:
        raise SystemExit(f"{name} must drop every default capability")
    if set(service.get("cap_add", [])) != capabilities:
        raise SystemExit(f"{name} capability allowlist changed without review")

web_networks = set(services["web"]["networks"])
db_networks = set(services["db"]["networks"])
if web_networks != {"web-proxy", "database"} or db_networks != {"database"}:
    raise SystemExit("web/proxy and database network boundaries are not isolated")
if not model["networks"]["database"].get("internal", False):
    raise SystemExit("database network is not internal")

published = services["web"]["ports"][0]
if published.get("host_ip") != "127.0.0.1" or int(published.get("published")) != 8080:
    raise SystemExit("default web port is not bound to loopback:8080")

# A fully read-only root is currently blocked by the runtime entrypoint (see
# docs/Docker.md), but known transient paths must still use restrictive tmpfs.
tmpfs = services["web"].get("tmpfs", [])
for path in ("/tmp", "/run/apache2", "/var/lock/apache2"):
    entry = next((item for item in tmpfs if item.split(":", 1)[0] == path), None)
    if entry is None or "nosuid" not in entry or "nodev" not in entry or "noexec" not in entry:
        raise SystemExit(f"{path} is not a restrictive tmpfs mount")
PY

for example_secret in lotgdpass rootpass changeme password example; do
    if MYSQL_PASSWORD="$example_secret" MYSQL_ROOT_PASSWORD=valid-test-secret \
        LOTGD_STATE_PATH="$project_dir/state" docker/entrypoint.sh true 2>/dev/null; then
        echo "Entrypoint accepted documented example secret '$example_secret'" >&2
        exit 1
    fi
done

echo "Docker Compose security configuration passed"
