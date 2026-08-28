# Docker deployment

The Docker configuration shares one production image between deployment modes:
the default Compose file is production-oriented, while
`docker-compose.dev.yml` adds live source mounts and development PHP settings.
The image uses PHP 8.3 with Apache, OPcache, optimized production Composer
dependencies, and MySQL 8.4.

## Initial configuration

`.env.example` is a template, not a deployable configuration. Copy it and
generate two independent database secrets locally (do not commit `.env`):

```bash
cp .env.example .env
sed -i "s|^MYSQL_PASSWORD=$|MYSQL_PASSWORD=$(openssl rand -base64 32)|" .env
sed -i "s|^MYSQL_ROOT_PASSWORD=$|MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)|" .env
```

Compose refuses to render the deployment when either secret is missing, and
the web container rejects the documented legacy/example password values during
startup.

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

Installer stage 11 writes the persistent `installation-complete` marker and
removes `installer.php`. That marker takes precedence over
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

The default web service has no source-code bind mount. Composer installs without
development dependencies and generates an authoritative classmap. PHP hides
errors from responses, logs them to container stderr, and enables OPcache
without timestamp validation. Rebuild the image to deploy code changes.

Only container port 80 is exposed, and its host mapping is loopback-only. Set
`LOTGD_HTTP_PORT` to choose another loopback host port. To serve an installed
game remotely, put a TLS reverse proxy on a public interface and proxy to
`127.0.0.1:${LOTGD_HTTP_PORT}`; do not make the installer port public.

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
`lotgd_state` preserves `dbconnect.php` plus the installer's completion marker.
The state volume prevents an image replacement from losing database settings or
restoring an installer that an administrator already removed. Back up
`lotgd_state` together with the database; do not delete it during a routine
deployment.

The image creates `/var/cache/lotgd/{twig,doctrine}` as `www-data`; Docker copies
those initial directories into a newly created named volume. Removing only the
cache volume discards generated cache data but not game, configuration, or
database data.

Inspect ownership and repair an existing volume created with incorrect
permissions as root:

```bash
docker compose exec web stat -c '%U:%G %a %n' /var/cache/lotgd /var/cache/lotgd/{twig,doctrine}
docker compose exec --user root web chown -R www-data:www-data /var/cache/lotgd
docker compose exec --user root web chmod -R u+rwX,g+rwX /var/cache/lotgd
```

## Health and performance verification

Compose waits for MySQL's health check before starting the web service. The web
health check requests the static `/errors/403.html` page, avoiding database or
session mutations.

Validate and inspect the running deployment:

```bash
docker compose config
docker compose ps
docker compose exec web php -i | grep -E 'opcache.enable =>|opcache.validate_timestamps =>'
docker compose exec --user www-data web sh -c 'test -w /var/cache/lotgd/twig && test -w /var/cache/lotgd/doctrine'
docker compose exec web find /var/cache/lotgd -mindepth 1 -maxdepth 2 -type f -print
```

The repository also includes a focused configuration regression test (it
requires the Docker Compose plugin):

```bash
tests/Docker/compose-security.sh
```

After completing installation and requesting a Twig-backed page, repeat the
request to compare cold and warm timings (replace `/` with a known lightweight
page for the installation):

```bash
curl -sS -o /dev/null -w 'cold: %{time_total}s\n' http://localhost/
curl -sS -o /dev/null -w 'warm: %{time_total}s\n' http://localhost/
```

The first request may populate Twig and Doctrine caches. Subsequent timings are
most meaningful after several warm-up requests and without concurrent traffic.

Apache also compresses text responses and sends bounded browser-cache metadata
for CSS, JavaScript, images, and WOFF2 fonts. Personalized PHP responses are
explicitly marked `no-store, private`; full-page HTTP caching must not be added
without a session-aware cache design. Inspect both behaviors with:

```bash
curl --compressed -sSI http://localhost/templates_twig/aurora/assets/style.css
curl -sSI http://localhost/index.php
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

Installer failures are logged to `install/errors/install.log`. Production PHP
errors are available through `docker compose logs web` and are never displayed
to clients.
