# Docker deployment

The Docker configuration shares one production image between deployment modes:
the default Compose file is production-oriented, while
`docker-compose.dev.yml` adds live source mounts and development PHP settings.
The image uses PHP 8.3 with Apache, OPcache, optimized production Composer
dependencies, and MySQL 8.4.

## Pinned multi-architecture images

All external images retain a readable tag and are pinned to a reviewed,
immutable multi-architecture manifest digest:

| Purpose | Pinned image | Authoritative locations |
| --- | --- | --- |
| Composer build stage | `composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040` | `Dockerfile`, `.github/workflows/ci.yml` |
| PHP/Apache runtime | `thecodingmachine/php:8.3-v4-apache@sha256:7bc852ed28adb908d245ef4a71b2c2d19fd9626c1975af61ba5a8f958a035ec7` | `Dockerfile`, `.github/workflows/ci.yml` |
| MySQL database | `mysql:8.4@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb` | `docker-compose.yml`, `.github/workflows/ci.yml` |

Dependabot's Docker ecosystem entry proposes monthly digest updates while
preserving these tags. Review the upstream release and security information,
confirm that the proposed digest is a manifest index, and merge only after the
Docker CI job verifies native `linux/amd64` and `linux/arm64` entries. If an
update is made manually, change every location in the relevant table row and
the displayed digest in this section in the same maintenance PR.

## PHP runtime image

The application stage uses the maintained, multiarch
`thecodingmachine/php:8.3-v4-apache` fat image. It is pinned to the immutable
manifest-list digest
`sha256:7bc852ed28adb908d245ef4a71b2c2d19fd9626c1975af61ba5a8f958a035ec7`,
not merely to its moving tag. The same manifest contains native `linux/amd64`
and `linux/arm64` variants. The image retains the official PHP/Apache-compatible
interfaces used here: `a2enmod`, `a2ensite`, `apache2-foreground`, the
`/usr/local/etc/php/conf.d` scan directory, and the Apache configuration below
`/etc/apache2`. The application explicitly runs Apache as `www-data` and chains
the runtime's entrypoint so its binary-module configuration still runs.

The production runtime contract is derived from `Dockerfile`,
`docker/health/ready.php`, Composer's platform requirements, and the production
smoke test:

| Requirement | Reason |
| --- | --- |
| `gd` | Game image processing; enabled from the runtime's pre-built module. |
| `mbstring` | Multibyte-safe game and dependency string handling. |
| `mysqli` | Legacy database access and the readiness query. |
| `opcache` | Production bytecode caching and readiness cache invalidation. |
| `pdo`, `pdo_mysql` | Doctrine and modern MySQL data access. |
| `zip` | Composer dependencies and archive handling. |

Composer also requires the standard/core modules `ctype`, `dom`, `fileinfo`,
`filter`, `hash`, `iconv`, `json`, `libxml`, `pcre`, `phar`, `simplexml`,
`tokenizer`, and `xmlwriter`; the selected fat runtime provides them. The smoke
test verifies the seven explicit runtime extensions and the runtime build runs
Composer against the resulting platform.

To update the runtime, inspect the tag with `docker buildx imagetools inspect`,
verify that its manifest still contains both required architectures, and run the commands in
[Health and performance verification](#health-and-performance-verification).
Runtime updates are scheduled
maintenance or definition/security changes; ordinary application PRs must not
refresh the base. The static CI guard rejects extension compilers and native
build toolchains in the application Dockerfile.

The Docker CI job has a target wall-clock budget of **at most five minutes**.
It therefore builds and loads the AMD64 application image once, passes that
exact image to the production smoke test, and checks ARM64 availability from
the already-published runtime manifest without QEMU or emulated compilation.

## Initial configuration

`.env.example` is a template, not a deployable configuration. Copy it and
generate two independent database secrets locally (do not commit `.env`):

```bash
cp .env.example .env
sed -i "s|^MYSQL_PASSWORD=$|MYSQL_PASSWORD=$(openssl rand -base64 32)|" .env
sed -i "s|^MYSQL_ROOT_PASSWORD=$|MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)|" .env
```

Compose refuses to render the deployment when either secret is missing or
empty. As a second, early runtime boundary, the web container rejects the
documented legacy/example password values (including case variants) before it
modifies persistent state. The two generated values must be independent.

### Rotating legacy Docker example passwords

Deployments that already initialized `db_data` with the formerly documented
`lotgdpass` and `rootpass` values must rotate the persisted MySQL accounts
**before** starting the hardened web container. Merely editing `.env` does not
change accounts stored in an existing MySQL volume. Back up both the database
and `lotgd_state`, stop the web service, and then run the following from the
checkout while `.env` still contains the old values:

```bash
docker compose stop web
docker compose up -d db

new_app_password=$(openssl rand -base64 32)
new_root_password=$(openssl rand -base64 32)

# Update the application configuration in lotgd_state while the web service is stopped.
docker compose run --rm --no-deps \
    --entrypoint php \
    -e NEW_APP_PASSWORD="$new_app_password" \
    web -r '
$path = "/var/lib/lotgd/dbconnect.php";
$config = require $path;
$config["DB_PASS"] = getenv("NEW_APP_PASSWORD");
if (file_put_contents($path, "<?php\n\nreturn " . var_export($config, true) . ";\n") === false) {
    fwrite(STDERR, "Unable to update dbconnect.php\n");
    exit(1);
}'

# Rotate both persisted MySQL accounts in one atomic ALTER USER statement.
docker compose exec -T -e MYSQL_PWD=rootpass db mysql --user=root <<SQL
ALTER USER
    'lotgduser'@'%' IDENTIFIED BY '$new_app_password',
    'root'@'localhost' IDENTIFIED BY '$new_root_password';
SQL

sed -i "s|^MYSQL_PASSWORD=lotgdpass$|MYSQL_PASSWORD=$new_app_password|" .env
sed -i "s|^MYSQL_ROOT_PASSWORD=rootpass$|MYSQL_ROOT_PASSWORD=$new_root_password|" .env
unset new_app_password new_root_password

docker compose up -d --force-recreate db web
```

If the deployment used a different `MYSQL_USER` or MySQL account host, adjust
the account in `ALTER USER` accordingly. Do not destroy `db_data` as a shortcut:
that deletes the game database. After the recreated services are healthy,
verify application login and retain the pre-rotation backups until the upgrade
has been validated.

### Deliberately enabling initial installation

The installer is denied by default. Set `LOTGD_INSTALL_ENABLED=1` in `.env`
only for the installation window, recreate the web container, and start the
stack. The published port remains restricted to the Docker host's loopback
interface (`127.0.0.1:8080` by default):

```bash
docker compose up -d --build
```

On a remote server, reach it through an SSH tunnel instead of publishing the
installer publicly. Run this on the administrator's workstation, replacing
`admin@example.com` with the SSH destination, then browse to
`http://127.0.0.1:8080/installer.php`:

```bash
ssh -N -L 8080:127.0.0.1:8080 admin@example.com
```

Installer stage 11 lets `www-data` write only the persistent
`installation-complete` marker. Apache denies the installer immediately when
that marker appears; because application files are root-owned, the root-run
entrypoint removes `installer.php` on the next container start. The marker takes
precedence over
`LOTGD_INSTALL_ENABLED`, so restoring or leaving the flag at `1` cannot restore
installer access after successful completion. Set `LOTGD_INSTALL_ENABLED=0`
again and recreate the web container as defense in depth:

```bash
docker compose up -d --force-recreate web
```

`MYSQL_USEDATACACHE=1` and `MYSQL_DATACACHEPATH=/var/cache/lotgd` enable the
application data cache. The installer persists these values in `dbconnect.php`.
Consequently, changing them in `.env` after installation may also require
updating `dbconnect.php` or regenerating it by running the installer again.

## Production

Build and launch the immutable application image:

```bash
docker compose up -d --build
```

The default web service has no source-code bind mount. Application code is
root-owned and read-only to `www-data`; only the cache and persistent state
volumes are writable by the web process. Composer installs without
development dependencies and generates an authoritative classmap. PHP hides
errors from responses, logs them to container stderr, and enables OPcache
without timestamp validation. Rebuild the image to deploy code changes.

Only container port 80 is exposed, and its host mapping is loopback-only. Set
`LOTGD_HTTP_PORT` to choose another loopback host port. To serve an installed
game remotely, put a TLS reverse proxy on a public interface and proxy to
`127.0.0.1:${LOTGD_HTTP_PORT}`; do not make the installer port public.

### Container privilege and network boundary

Both services set `no-new-privileges`, drop Docker's entire default capability
set, and add back only the startup capabilities exercised by their official
entrypoints. The web entrypoint needs `CHOWN`, `FOWNER`, and `DAC_OVERRIDE` to
traverse and repair restrictive, `www-data`-owned persistent volume metadata,
`NET_BIND_SERVICE` for container port 80, and
`SETUID`/`SETGID` to start Apache workers as `www-data`.
MySQL additionally needs `DAC_OVERRIDE` to traverse an existing mysql-owned
data volume while initializing it. Neither service retains networking,
mounting, tracing, raw-socket, or module-loading capabilities.

The `web-proxy` network is the frontend boundary and contains only `web` by
default. The separate `database` network is marked `internal: true`; `web` joins
it for SQL traffic, while `db` joins only that network. A reverse proxy should
join `web-proxy`, never `database`.

The web service uses `tmpfs` for `/tmp`, Apache PID state, and Apache locks with
`nosuid`, `nodev`, and `noexec`; cache and state remain in their named volumes.
A fully read-only root filesystem was evaluated but is not enabled for this
runtime image: its inherited `/usr/local/bin/docker-entrypoint.sh` materializes
PHP extension configuration under `/usr/local/etc/php/conf.d` at container
startup (including the requested GD module). That write happens before Apache
starts and cannot be redirected to the Apache/PHP transient paths without
masking the image's production INI files. The document root therefore remains
root-owned with group/other writes removed, `www-data` cannot modify code, and
the reduced capability/no-new-privileges boundary limits the remaining root
startup process. Re-evaluate `read_only: true` when adopting a runtime whose
extension configuration is completely fixed at image-build time.

### SSL/TLS is not included

This stack intentionally does **not** configure TLS or advertise port 443.
Certificates are domain- and deployment-specific, must be stored securely, and
must be renewed regularly, so a useful certificate cannot be safely bundled in
the image. Terminate HTTPS in a reverse proxy such as Caddy, Nginx, Traefik, or
a managed load balancer and proxy plain HTTP to this service. That proxy can
obtain and renew a trusted certificate through Let's Encrypt or another
certificate authority.

## Development

Start the base stack with the development override:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

The override sets `APP_ENV=development`, enables displayed errors and PHP/Twig
timestamp checks, and mounts the checkout at `/var/www/html`. Named volumes mask
`vendor/` and `/var/cache/lotgd`, so dependencies and generated files remain
container-local rather than being written into the host checkout.

Rebuild after changing Composer dependencies or the image configuration:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build --force-recreate
```

## Persistent volumes and permissions

`db_data` holds MySQL data, `lotgd_cache` holds the shared runtime cache, and
`lotgd_state` preserves `dbconnect.php`, installer logs under `logs/install.log`,
and the installer's completion marker.
The state volume prevents an image replacement from losing database settings or
restoring an installer that an administrator already removed. Back up
`lotgd_state` together with the database; do not delete it during a routine
deployment.

The image and entrypoint create `/var/cache/lotgd/{twig,doctrine}` and
`/var/lib/lotgd/logs` as `www-data` with restrictive permissions; Docker copies
the initial directories into newly created named volumes, and startup repairs
volume metadata without making the document root writable. Removing only the
cache volume discards generated cache data but not game, configuration, or
database data.

Inspect ownership and repair an existing volume created with incorrect
permissions as root:

```bash
docker compose exec web stat -c '%U:%G %a %n' /var/cache/lotgd /var/cache/lotgd/{twig,doctrine} /var/lib/lotgd /var/lib/lotgd/logs /var/www/html
docker compose exec --user root web chown -R www-data:www-data /var/cache/lotgd
docker compose exec --user root web chmod -R u+rwX,g+rwX /var/cache/lotgd
```

## Health and performance verification

Compose waits for MySQL's health check before starting the web service. The web
health check calls the container-local `/_health/ready` endpoint, which validates
the runtime extensions, configuration file, and a side-effect-free `SELECT 1`
without creating a game session.

Validate and inspect the running deployment:

```bash
docker compose config
docker compose ps
docker run --rm <image> php -m
docker compose exec web php -i | grep -E 'opcache.enable =>|opcache.validate_timestamps =>'
docker compose exec --user www-data web sh -c 'test -w /var/cache/lotgd/twig && test -w /var/cache/lotgd/doctrine'
docker compose exec web find /var/cache/lotgd -mindepth 1 -maxdepth 2 -type f -print
```

The web service becomes healthy only after PHP can read `dbconnect.php`, all
required PHP extensions are loaded, and a side-effect-free `SELECT 1` reaches
the configured database. The readiness URL is restricted to requests from
inside the web container; use `docker compose ps` or Docker's health status
instead of publishing the probe through a reverse proxy. Installation can
therefore proceed while the container reports `starting` or `unhealthy`, and a
completed installation transitions it to `healthy` without a restart.

The repository also includes a focused production smoke test and configuration
regression test (both require the Docker Compose plugin). Build an image once
and pass its tag to the smoke test; the script deliberately refuses to build it
again:

```bash
docker build -t lotgd-smoke:local .
tests/Docker/smoke.sh lotgd-smoke:local
tests/Docker/compose-security.sh
```

After completing installation and requesting a Twig-backed page, repeat the
request to compare cold and warm timings (replace `/` with a known lightweight
page for the installation):

```bash
curl -sS -o /dev/null -w 'cold: %{time_total}s\n' "http://127.0.0.1:${LOTGD_HTTP_PORT:-8080}/"
curl -sS -o /dev/null -w 'warm: %{time_total}s\n' "http://127.0.0.1:${LOTGD_HTTP_PORT:-8080}/"
```

The first request may populate Twig and Doctrine caches. Subsequent timings are
most meaningful after several warm-up requests and without concurrent traffic.

Apache also compresses text responses and sends bounded browser-cache metadata
for CSS, JavaScript, images, and WOFF2 fonts. Personalized PHP responses are
explicitly marked `no-store, private`; full-page HTTP caching must not be added
without a session-aware cache design. Inspect both behaviors with:

```bash
curl --compressed -sSI "http://127.0.0.1:${LOTGD_HTTP_PORT:-8080}/templates_twig/aurora/assets/style.css"
curl -sSI "http://127.0.0.1:${LOTGD_HTTP_PORT:-8080}/index.php"
```

## Operations and troubleshooting

```bash
docker compose logs -f web db
docker compose restart
docker compose down
docker compose down --volumes  # destructive: removes database, configuration, and cache data
```

If the database connection fails, compare `.env` with the values persisted in
`dbconnect.php`, then inspect `docker compose ps` and the database logs. If code
changes do not appear in production, rebuild the immutable image. In
development, confirm both Compose files were supplied and verify that
`APP_ENV=development` is present with `docker compose exec web env`.

Installer failures are logged outside the document root at
`/var/lib/lotgd/logs/install.log`. Apache denies `/install/errors/` regardless
of whether installation is enabled. Production PHP
errors are available through `docker compose logs web` and are never displayed
to clients.
