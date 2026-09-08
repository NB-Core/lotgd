# Docker deployment

The Docker configuration shares one production image between deployment modes:
the default Compose file is production-oriented, while
`docker-compose.dev.yml` adds live source mounts and development PHP settings.
The image uses PHP 8.4 with Apache, OPcache, optimized production Composer
dependencies, and MySQL 8.4.

Both containers drop every default capability, run with `no-new-privileges`,
publish only on loopback, and serve a document root that is read-only to the web
user. Start with [Initial configuration](#initial-configuration) for a new
deployment; if you are auditing an existing one, the two sections worth reading
first are [Status as of 2026-09](#status-as-of-2026-09) (how current the pinned
images are) and [HTTP access boundary](#http-access-boundary) (what the web
server refuses to serve).

## Pinned multi-architecture images

All external images retain a readable tag and are pinned to a reviewed,
immutable multi-architecture manifest digest:

| Purpose | Pinned image | Authoritative locations |
| --- | --- | --- |
| Composer build stage | `composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332` | `Dockerfile`, `.github/workflows/ci.yml` |
| PHP/Apache runtime | `thecodingmachine/php:8.4-v5-apache@sha256:d04b2b76c615c9af90cdc66b54cf4e4d09eba64ee74f9bbbd79b78b06d902a66` | `Dockerfile`, `.github/workflows/ci.yml`, `tests/Docker/compose-security.sh` |
| MySQL database | `mysql:8.4@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb` | `docker-compose.yml`, `.github/workflows/ci.yml` |

Dependabot's Docker ecosystem entry proposes monthly digest updates while
preserving these tags. Review the upstream release and security information,
confirm that the proposed digest is a manifest index, and merge only after the
Docker CI job verifies native `linux/amd64` and `linux/arm64` entries. If an
update is made manually, change every location in the relevant table row and
the displayed digest in this section in the same maintenance PR.

### Checking whether a pin is still current

A digest pin is only as good as the review behind it: it keeps builds
reproducible, but it also freezes the operating-system packages inside the
image. A pin that is never refreshed silently ages out of security support.
Check the state of all three pins without pulling anything:

```bash
# What the tag points at today, and when it was last rebuilt.
docker buildx imagetools inspect composer:2 --format '{{json .Manifest.Digest}}'
docker buildx imagetools inspect thecodingmachine/php:8.4-v5-apache --format '{{json .Manifest.Digest}}'
docker buildx imagetools inspect mysql:8.4 --format '{{json .Manifest.Digest}}'

# Compare against the pins recorded above.
grep -n 'sha256:' Dockerfile docker-compose.yml
```

If a digest differs, look at *why*: a moving tag that has been rebuilt usually
means the upstream base picked up distribution security updates. A tag that has
**not** moved for many months usually means the upstream line is no longer
maintained, which is the more serious of the two cases — no Dependabot PR will
ever appear for it, because the digest it would propose is the digest already
pinned.

That second case is checked automatically. `tests/Docker/check-image-pins.sh`
reads the pins straight out of `Dockerfile` and `docker-compose.yml`, asks
Docker Hub when each tag was last rebuilt, and **fails** when one has been
quiet for more than 120 days (`STALE_AFTER_DAYS` overrides it, and is rejected
unless it is a non-negative integer — a threshold the shell cannot compare
would silently skip every staleness test). Drift is only
reported, since Dependabot already proposes those. The
`Image pin freshness` workflow runs it weekly, on demand, and on pull requests
that touch the pins:

```bash
tests/Docker/check-image-pins.sh
STALE_AFTER_DAYS=30 tests/Docker/check-image-pins.sh   # stricter, for a manual audit
```

Against the runtime this deployment used until 2026-09 the check printed
`pin is current` **and** `STALE: not rebuilt for 455 days` — which is the whole
problem in two lines: the pin was perfectly valid and the line behind it was
dead.

### Status as of 2026-09

| Pin | Upstream state | Action |
| --- | --- | --- |
| `mysql:8.4` | Current; the pinned digest is the one `mysql:8.4` resolves to (rebuilt 2026-07-28). MySQL 8.4 is the LTS series, so staying on it is correct — do not move to a 9.x innovation release. | None. |
| `composer:2` | Current; the pinned digest is the one `composer:2` resolves to (rebuilt 2026-09-03, Composer 2.10.x). Build stage only — nothing from this image reaches the runtime except the resolved `vendor/` tree. | None. |
| `thecodingmachine/php:8.4-v5-apache` | Current, and on the maintained line (rebuilt monthly, last 2026-08-30). | None. |

The runtime previously sat on `8.3-v4-apache`, a line upstream stopped
rebuilding on 2025-06-09. That is the failure mode this section exists to
catch, and it is one Dependabot cannot report: when an upstream line is
abandoned its tag stops moving, so the digest a bot would propose is the digest
already pinned. **A pin that never produces an update PR deserves suspicion,
not comfort.** Re-check the rebuild dates here at least twice a year.

### Migrating to another runtime line

The v4 → v5 migration was not a digest swap, and a future one probably will not
be either. Treat it as scheduled maintenance in its own PR:

1. Pick the tag and resolve its manifest digest:
   ```bash
   docker buildx imagetools inspect thecodingmachine/php:8.4-v5-apache \
       --format '{{json .Manifest}}' | jq '.digest, [.manifests[].platform]'
   ```
2. Confirm the manifest carries native `linux/amd64` **and** `linux/arm64`
   entries (the Raspberry Pi guide depends on the latter).
3. Update the digest in `Dockerfile`, `.github/workflows/ci.yml`, the table at
   the top of this document, and `tests/Docker/compose-security.sh`, whose
   regular expression pins the tag text as well.
4. Read the new line's `Dockerfile` and entrypoint scripts before assuming any
   path still holds — see [PHP runtime image](#php-runtime-image) for the
   assumptions this deployment makes and what broke last time.
5. If the PHP major/minor version changes, run the test suite on it locally
   (`php vendor/bin/phpunit`, `php vendor/bin/phpstan analyse`) and add the
   version to the CI matrix in `.github/workflows/ci.yml`. Leave
   `config.platform.php` in `composer.json` at the **lowest** supported
   version: it is the floor for non-Docker installations, not the runtime
   version.
6. Run the full Docker CI path: `docker build`, `tests/Docker/smoke.sh`,
   `tests/Docker/compose-security.sh`.

Do not combine a runtime bump with application changes; keeping it isolated is
what makes a rollback (restoring the previous digest) a one-line change.

## PHP runtime image

The application stage uses the multiarch `thecodingmachine/php:8.4-v5-apache`
fat image, pinned to the immutable manifest-list digest
`sha256:d04b2b76c615c9af90cdc66b54cf4e4d09eba64ee74f9bbbd79b78b06d902a66`, not
merely to its moving tag. The same manifest contains native `linux/amd64` and
`linux/arm64` variants.

The fat variant is chosen deliberately: it ships every extension this game
needs as a pre-built module, so the application image never compiles anything.
A CI guard (`tests/Docker/no-build-toolchain.sh`) rejects extension compilers
in the Dockerfile, and builds stay in the minutes-not-hours range.

### What this runtime is, and what it expects

The `v5` line is built on **Ubuntu with the ondrej PHP packages** — not on the
official `php:*-apache` Debian images. Anything that assumes the official
layout is wrong here, which is what makes a line change more than a digest
swap. The assumptions this deployment makes, all of them load-bearing:

| Assumption | Why it matters |
| --- | --- |
| PHP's scan directory is `/etc/php/${PHP_VERSION}/apache2/conf.d`, **not** `/usr/local/etc/php/conf.d` | `docker/php/production.ini` is installed there as `zz-lotgd.ini`, sorting after the runtime's own generated config. A wrong path does not fail: PHP simply never reads the file and the production settings silently vanish. The build resolves the path from the image's own `PHP_VERSION` and fails if the directory is absent. |
| The scan directory is per-SAPI | `php` on the command line reads `cli/conf.d` and does **not** see the Apache settings. Verify web-facing PHP configuration through HTTP or by reading the file, never with `docker compose exec web php -i`. |
| `TEMPLATE_PHP_INI` selects the stock `php.ini` linked in at startup | It defaults to `development`, which would enable displayed errors. The image sets it to `production`. |
| The entrypoint runs `a2enmod`/`a2dismod` on **every start** from its own default list | That list does not include `mod_headers`, so a build-time `a2enmod headers` is undone at runtime. `APACHE_EXTENSION_HEADERS=1` keeps it on; without it the `no-store` and `nosniff` headers disappear with no error anywhere. The smoke test asserts both headers over HTTP. |
| `PHP_EXTENSION_*` enables pre-built modules at startup | `PHP_EXTENSION_GD=1` is the only one this game adds; the rest are on by default in the fat image. |
| `DOCKER_USER` skips an ownership heuristic | Unset, the entrypoint probes the working directory by creating and deleting a scratch directory in the document root on every start. |
| `/usr/bin/php` is a wrapper script | It sudo-chowns a cache file and can regenerate PHP configuration before exec'ing the real binary. The health checks therefore use `curl`, not `php`. |
| Apache keeps the Debian layout | `a2enmod`, `a2ensite`, `a2enconf`, `apache2-foreground`, `/etc/apache2/{sites,conf}-available`, and `conf-enabled` all work as usual. |
| `APACHE_RUN_USER`/`APACHE_RUN_GROUP` default to `docker` | The image sets both to `www-data` explicitly; upstream even flags its own default as unsuitable for production. |

The application chains the runtime's entrypoint (`docker/entrypoint.sh` ends in
`exec /usr/local/bin/docker-entrypoint.sh "$@"`) so all of that initialization
still runs after the deployment's own volume and secret checks.

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

To update the runtime, follow
[Migrating to another runtime line](#migrating-to-another-runtime-line) and
finish with the commands in
[Health and performance verification](#health-and-performance-verification).
Runtime updates are scheduled maintenance or definition/security changes;
ordinary application PRs must not refresh the base. The static CI guard rejects
extension compilers and native build toolchains in the application Dockerfile.

### PHP version policy

The image serves **PHP 8.4**. The application's floor is still **8.3**:
`config.platform.php` in `composer.json` pins dependency resolution to 8.3.0,
the README documents 8.3 as the minimum, and CI runs the unit tests and static
analysis on both versions. Non-Docker installations on 8.3 therefore stay
supported, while the container gets an actively maintained branch. Raise the
floor only in a deliberate PR that also updates the README, the Composer
platform config, and the CI matrix.

The Docker CI job has a target wall-clock budget of **at most five minutes**.
It therefore builds and loads the AMD64 application image once, passes that
exact image to the production smoke test, and checks ARM64 availability from
the already-published runtime manifest without QEMU or emulated compilation.

## Initial configuration

`.env.example` is a template, not a deployable configuration. Copy it and
generate two independent database secrets locally (do not commit `.env`):

```bash
cp .env.example .env
chmod 600 .env
sed -i "s|^MYSQL_PASSWORD=$|MYSQL_PASSWORD=$(openssl rand -base64 32)|" .env
sed -i "s|^MYSQL_ROOT_PASSWORD=$|MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)|" .env
```

Compose refuses to render the deployment when either secret is missing or
empty. As a second, early runtime boundary, the web container rejects the
documented legacy/example password values (including case variants) before it
modifies persistent state. The two generated values must be independent.

`.env` holds both database secrets in plain text; `chmod 600` keeps it readable
only by the account that runs Compose. It is already listed in `.gitignore` and
`.dockerignore`, so it never reaches a commit or an image layer.

### Which container sees which secret

`.env` is read by Compose for interpolation only. The web service does **not**
use `env_file`, and every value it needs is listed individually in
`docker-compose.yml`:

| Variable | `web` | `db` | Why |
| --- | --- | --- | --- |
| `MYSQL_HOST`, `MYSQL_USER`, `MYSQL_DATABASE` | yes | yes | The installer pre-fills the connection form from them. |
| `MYSQL_PASSWORD` | yes | yes | The game's own, non-administrative database account. |
| `MYSQL_ROOT_PASSWORD` | **no** | yes | Only MySQL's own entrypoint and health check need the administrative account. The game never authenticates as `root`, so a file-disclosure or code-execution bug in PHP cannot read it out of the environment. |
| `LOTGD_HTTP_PORT` | no | no | Consumed by Compose when publishing the port. |

After installation the game reads its credentials from `dbconnect.php` in the
state volume rather than from the environment; the variables above matter mainly
during first-run setup. Adding custom variables to `.env` no longer forwards
them into the container automatically — add them to the `environment:` block of
the service that needs them.

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
runtime image. Its inherited `/usr/local/bin/docker-entrypoint.sh` configures
the container at every start rather than at build time: it regenerates PHP's
`generated_conf.ini`, enables and disables PHP extensions (including the
requested GD module) and Apache modules through `phpenmod`/`a2enmod`, links the
selected stock `php.ini` into place, and writes `conf-enabled/expose-env.conf`
so environment variables reach Apache. All of that lands in `/etc/php` and
`/etc/apache2` before Apache starts, and none of it can be redirected to a
transient path without masking the image's own configuration.

The document root therefore remains root-owned with group/other writes removed,
`www-data` cannot modify code, and the reduced capability/no-new-privileges
boundary limits the remaining root startup process. Re-evaluate `read_only:
true` only for a runtime whose configuration is fixed at image-build time —
which is the opposite of what this image's environment-variable interface is
built for.

### HTTP access boundary

The legacy layout has no separate `public/` directory: entry points, Composer
dependencies, application classes, page fragments, migrations, and the image's
own build files all live under the document root. The virtual host therefore
denies everything that is not web content, because `AllowOverride None` means
the `.htaccess` files shipped in the checkout are never consulted inside the
container.

| Denied | Reason |
| --- | --- |
| `/bin`, `/config`, `/docker`, `/docs`, `/logs`, `/migrations`, `/scripts`, `/tests`, `/vendor` | No web-reachable entry point. `/logs` would otherwise serve `bootstrap.log`, and `/docker` carries a second copy of the readiness probe plus the entrypoint and PHP ini files. |
| `/src`, except `*.js` | Application classes. The tree also ships two browser scripts that legacy modules load by their current URL — `EDom::includeScript()` emits `<script src='src/Lotgd/e_dom.js'>`, and modules may reference `src/Lotgd/md5.js` — so JavaScript stays reachable there and everything else is denied. |
| `*.php` under `/lib`, `/modules`, `/pages`, `/async/common` | Include-only code that depends on the bootstrap of a root entry point. Non-PHP assets in those trees stay reachable. |
| `cron.php` | A CLI maintenance entry point. Depending on `register_argc_argv`, an HTTP request can supply the execution bitmask through the query string and start a newday or database-cleanup run. |
| Dotfiles and `*.bak` | `.env`, `.git` metadata, editor state, and stray backups. |
| `*.dist`, `*.htm`, `*.ini`, `*.lock`, `*.log`, `*.neon`, `*.sh`, `*.sql`, `*.twig`, `*.yml`, `*.yaml`, `*.md`, `composer.json`, `Dockerfile`, `phpunit.xml`, `phpcs.xml` | Sources and metadata that PHP or the build reads, but that must not be downloadable verbatim. |
| `/install`, `/installer.php` | Denied unless installation is explicitly enabled and not yet completed (see above). |
| `/_health/ready` | `Require local`; reachable only from inside the container. |

`LICENSE.txt` stays readable on purpose — the installer verifies its checksum.
`robots.txt`, template assets, and module assets are unaffected.

Server-wide hardening lives in `docker/apache/hardening.conf`, which is copied
into `conf-enabled/` after the base image's own `security.conf`: `ServerTokens
Prod` and `ServerSignature Off` stop advertising the exact Apache build, and
`TraceEnable Off` disables the TRACE method. Static files and error documents
also receive `X-Content-Type-Options: nosniff`; PHP responses get their security
headers from the application's runtime hardening bootstrap (see
[SECURITY.md](../SECURITY.md)), so the vhost deliberately does not set a second,
possibly conflicting copy.

`tests/Docker/smoke.sh` asserts each of these boundaries against a running
container, so a regression in the vhost fails CI rather than a production audit.

The same rules exist for non-Docker deployments in the repository's root
`.htaccess`, together with an equivalent Nginx snippet in its trailing comment.

### Optional Compose overrides

The shipped Compose file deliberately sets no resource or log limits, because
sensible values depend on the host. Both are worth adding for an
internet-facing deployment, and `docker-compose.limits.yml` provides a starting
point:

```bash
docker compose -f docker-compose.yml -f docker-compose.limits.yml up -d
```

It caps CPU, memory and process count for both services and rotates the
container logs. A limit is a ceiling rather than a reservation, so the two
services may add up to more than the host has. Start generously and tighten after watching `docker stats`;
MySQL in particular fails in confusing ways when its buffer pool does not fit
the limit. Drop the `logging` blocks if the Docker daemon already applies
rotation globally in `daemon.json` — declaring a driver per service overrides
that.

Compose v2 honours `deploy.resources.limits` outside Swarm. Note that the
process cap is declared as `deploy.resources.limits.pids` rather than the
top-level `pids_limit`: Compose maps both onto the same field and refuses a
model that sets them separately.

Two further hardening options are available but are deployment decisions rather
than defaults:

- **File-based secrets.** The MySQL image supports `MYSQL_PASSWORD_FILE` and
  `MYSQL_ROOT_PASSWORD_FILE`, so both credentials can come from Docker secrets
  instead of the environment. The web container currently reads
  `MYSQL_PASSWORD` from the environment during installation only; a `_FILE`
  variant would need a small change in `docker/entrypoint.sh`.
- **`read_only: true` for the web service.** Still blocked by the runtime's own
  entrypoint, which writes PHP extension configuration at container start; see
  the paragraph above.

### SSL/TLS is not included

This stack intentionally does **not** configure TLS or advertise port 443.
Certificates are domain- and deployment-specific, must be stored securely, and
must be renewed regularly, so a useful certificate cannot be safely bundled in
the image. Terminate HTTPS in a reverse proxy such as Caddy, Nginx, Traefik, or
a managed load balancer and proxy plain HTTP to this service. That proxy can
obtain and renew a trusted certificate through Let's Encrypt or another
certificate authority.

#### Minimal reverse-proxy example

Caddy needs the least configuration because it obtains and renews certificates
on its own. Run it on the host and point it at the loopback port:

```caddyfile
# /etc/caddy/Caddyfile
game.example.com {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8080
}
```

To run the proxy as a container instead, attach it to the `web-proxy` network
and address the service by name — never join it to the `database` network:

```yaml
# docker-compose.proxy.yml
services:
  caddy:
    image: caddy:2
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    networks:
      - web-proxy
volumes:
  caddy_data:
  caddy_config:
networks:
  web-proxy:
    external: true
    name: lotgd_web-proxy
```

With a containerised proxy, `reverse_proxy web:80` replaces the loopback
address, and the host port publication in `docker-compose.yml` can be dropped
entirely.

#### Tell the application it is behind a proxy

Apache in this container always speaks plain HTTP, so PHP sees
`HTTPS` as unset and would emit non-`Secure` session cookies and absolute
`http://` URLs. After installation, set the following keys in `dbconnect.php`
(inside the `lotgd_state` volume) so the game trusts the proxy's forwarded
protocol — and only the proxy's:

```php
'SECURITY_TRUST_FORWARDED_PROTO' => true,
// Comma-separated literal addresses and/or CIDR blocks, matched against the
// peer address as the container sees it.
'SECURITY_TRUSTED_PROXIES' => '172.18.0.0/16',
'SECURITY_HSTS_ENABLED' => true,
```

A container's address is assigned from the network's subnet and changes when
the network is recreated, which is why the allowlist accepts blocks. Read the
subnet rather than guessing it:

```bash
docker network inspect lotgd_web-proxy -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
docker compose logs web | tail -n 5   # the peer address is the first log field
```

Leaving `SECURITY_TRUSTED_PROXIES` empty is safe by default but blunt: with no
list, a forwarded protocol is accepted only from loopback and private network
ranges, and ignored from any public address. That covers this Compose
deployment, where the proxy is either on the host's loopback interface or on
the `web-proxy` network. Set an explicit list when the proxy reaches the
container from a public address, or when you want to name exactly one peer;
an explicit list replaces the private-range default rather than extending it.

The full list of keys, their defaults, and the HSTS rollout advice are in
[SECURITY.md](../SECURITY.md#runtime-hardening-defaults). Restart the web
service after editing `dbconnect.php`; OPcache runs with
`validate_timestamps=0` and will otherwise keep serving the cached version:

```bash
docker compose restart web
```

## Development

Start the base stack with the development override:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

The override sets `APP_ENV=development`, enables displayed errors and PHP/Twig
timestamp checks, and mounts the checkout at `/var/www/html`. Named volumes mask
`vendor/` and `/var/cache/lotgd`, so dependencies and generated files remain
container-local rather than being written into the host checkout.

`docker/php/development.ini` is mounted at `/etc/lotgd/php-development.ini`
rather than directly into PHP's scan directory, because that directory's path
contains the runtime's PHP version. The entrypoint links the file into it as
`zzz-lotgd-development.ini`, sorting after the production settings, so the
Compose file never has to be edited when the PHP version changes.

Rebuild after changing Composer dependencies or the image configuration:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build --force-recreate
```

The bind mount puts the *whole* checkout into the document root, including
`.git/`, `.env`, and the test suite, which the production image excludes through
`.dockerignore`. The virtual host denies all of them (see
[HTTP access boundary](#http-access-boundary)), and the development port stays
on loopback — but the override is still meant for a workstation, never for a
host that is reachable from the internet. Displayed errors are enabled there and
will happily print file paths and query fragments to whoever asks.

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
docker compose exec --user www-data web sh -c 'test -w /var/cache/lotgd/twig && test -w /var/cache/lotgd/doctrine'
docker compose exec web find /var/cache/lotgd -mindepth 1 -maxdepth 2 -type f -print
```

PHP's configuration is per-SAPI on this runtime, so `docker compose exec web
php -i` reports the **command-line** settings and not the ones Apache serves
with. Read the web-facing configuration from the file instead:

```bash
docker compose exec web sh -c 'cat /etc/php/${PHP_VERSION}/apache2/conf.d/zz-lotgd.ini'
docker compose exec web sh -c 'ls /etc/php/${PHP_VERSION}/apache2/conf.d/'
```

Confirm that the response headers survived the runtime's module handling — the
image asks for `mod_headers` through `APACHE_EXTENSION_HEADERS`, and without it
these two headers disappear silently:

```bash
curl -sSI "http://127.0.0.1:${LOTGD_HTTP_PORT:-8080}/index.php" | grep -i 'cache-control\|^server'
curl -sSI "http://127.0.0.1:${LOTGD_HTTP_PORT:-8080}/templates_twig/aurora/assets/style.css" | grep -i 'x-content-type-options'
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

Spot-check the access boundary of a running deployment — every path below must
answer `403`, and it is worth repeating through the reverse proxy once one is in
front of the stack:

```bash
for path in /cron.php /vendor/autoload.php /src/Lotgd/Settings.php /config/ \
            /logs/bootstrap.log /docker/health/ready.php /composer.json \
            /.env /_health/ready /pages/about/about_default.php; do
    printf '%s %s\n' \
        "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${LOTGD_HTTP_PORT:-8080}${path}")" \
        "$path"
done
```

## Backups

Two volumes must be captured together to have a restorable deployment: the
database and `lotgd_state`. A database dump without `dbconnect.php` leaves you
guessing the configuration; `lotgd_state` without the database restores an
installation that points at nothing. `lotgd_cache` is disposable and is
regenerated on demand.

Compose prefixes volume names with the project name (the directory name unless
`COMPOSE_PROJECT_NAME` is set), so confirm them first:

```bash
docker volume ls --filter name=lotgd
```

A consistent logical dump plus the state volume:

```bash
set -a; . ./.env; set +a
stamp=$(date +%Y%m%d-%H%M%S)

docker compose exec -T -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" db \
    mysqldump --user=root --single-transaction --routines --events \
    --default-character-set=utf8mb4 "$MYSQL_DATABASE" \
    | gzip > "lotgd-db-$stamp.sql.gz"

docker run --rm \
    -v lotgd_lotgd_state:/state:ro \
    -v "$PWD:/backup" \
    busybox tar czf "/backup/lotgd-state-$stamp.tar.gz" -C /state .
```

`--single-transaction` keeps the InnoDB dump consistent without locking players
out. Store the two files together, keep them off the game host, and treat them
as secrets: the dump contains player e-mail addresses and password hashes, and
the state archive contains the database password.

Restoring into a fresh stack:

```bash
docker compose up -d db
set -a; . ./.env; set +a
gunzip -c lotgd-db-<stamp>.sql.gz \
    | docker compose exec -T -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" db \
      mysql --user=root "$MYSQL_DATABASE"

docker run --rm \
    -v lotgd_lotgd_state:/state \
    -v "$PWD:/backup" \
    busybox sh -c 'tar xzf /backup/lotgd-state-<stamp>.tar.gz -C /state'

docker compose up -d
```

If the restored `dbconnect.php` carries a different password than the restored
database, follow
[Rotating legacy Docker example passwords](#rotating-legacy-docker-example-passwords)
— the same procedure realigns any mismatched credential pair.

Verify a backup occasionally by restoring it into a throwaway project
(`COMPOSE_PROJECT_NAME=lotgd-restore-test docker compose up -d`) rather than
discovering during an outage that it was never readable.

## Updating the deployment

Application updates are image rebuilds; nothing is patched in place, because the
document root is read-only to `www-data` and OPcache never revalidates
timestamps.

```bash
# 1. Back up first (see above) — migrations are not reversible in general.
git pull
# 2. Rebuild and restart. Only the web service changes; the database keeps running.
docker compose up -d --build web
# 3. Apply schema changes.
docker compose exec -T --user www-data web php bin/doctrine migrations:migrate --no-interaction
# 4. Confirm the container reports healthy again.
docker compose ps
```

Check `UPGRADING.md` and `CHANGELOG.md` before every update; some releases add
configuration keys to `dbconnect.php` that the installer would normally write.

Base-image and dependency updates follow the separate, deliberate path in
[Pinned multi-architecture images](#pinned-multi-architecture-images); do not
fold them into an application update.

To roll back, check out the previous tag and rebuild. A schema migration that
has already run is *not* undone by rebuilding an older image, which is why the
database backup in step 1 is not optional.

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

Both services log to the container runtime, so `docker compose logs` is the only
place to look; nothing is written into the image or the document root. Docker's
default `json-file` driver never rotates on its own — see
[Optional Compose overrides](#optional-compose-overrides) if the host has no
global rotation policy in `daemon.json`.

A `403` on a path that used to work is almost always the
[HTTP access boundary](#http-access-boundary). Custom themes or modules that
load assets from a denied tree (`vendor/`, `src/`, or a `.twig` file requested
directly by the browser) must move those assets under `templates_twig/`,
`images/`, or their own module directory; do not widen the vhost rules to serve
code paths. A `403` for the whole site instead points at file ownership — the
document root must stay root-owned and readable.

Modules that ship their own `.htaccess` have no effect in the container:
`AllowOverride None` is set deliberately so that access rules cannot be changed
by anything inside the document root. Port such a rule into
`docker/apache/lotgd.conf` and rebuild.
