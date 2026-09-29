# Legend of the Green Dragon on Raspberry Pi (Docker-first)

This guide targets **Raspberry Pi 4 or 5 with Raspberry Pi OS Lite 64-bit**. The
production images are checked for native ARM64 support in CI, so emulation is
not required. Any other ARM64 single-board computer running a current Debian or
Ubuntu works the same way.

A Pi 4 with 2 GB of RAM is enough for a small game, but MySQL 8.4 and PHP
together leave little headroom: use a 4 GB board (or a Pi 5) if you expect more
than a handful of concurrent players, put the data on an SSD rather than an SD
card, and enable swap. SD cards wear out quickly under a database write load and
are the most common cause of a Pi deployment dying after a few months.

## 1. Install Docker and Compose

Install Raspberry Pi OS Lite (64-bit), then follow Docker's maintained Debian
instructions (which include 64-bit Raspberry Pi OS):

- [Docker Engine on Debian](https://docs.docker.com/engine/install/debian/)
- [Docker Compose plugin](https://docs.docker.com/compose/install/)
- [Optional Linux post-install steps](https://docs.docker.com/engine/install/linux-postinstall/)

Verify the installation:

```bash
docker --version
docker compose version
```

## 2. Clone and create private configuration

```bash
git clone https://github.com/NB-Core/lotgd.git
cd lotgd
cp .env.example .env
chmod 600 .env
sed -i "s|^MYSQL_PASSWORD=$|MYSQL_PASSWORD=$(openssl rand -base64 32)|" .env
sed -i "s|^MYSQL_ROOT_PASSWORD=$|MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)|" .env
echo 'LOTGD_WEB_IMAGE=ghcr.io/nb-core/lotgd:master' >> .env
```

Use two independently generated secrets; do not reuse either password or
commit `.env`.

The last line selects the [prebuilt image](Docker.md#prebuilt-image), which is
published for ARM64. The Pi then downloads the finished image instead of
building it, which is slow on a Pi and repeats the Composer download on every
update. Leave the line out to build locally instead, and use `--build` where
this guide says `--no-build`.

## 3. Start and install

```bash
docker compose pull web
docker compose up -d --no-build
docker compose exec -T --user www-data web php bin/install --admin=YourName
```

The last command installs the game and creates your administrator account. It
prints the account's password once, so copy it now. The details, including how
to choose the password yourself, are in
[Installing from the command line](Docker.md#installing-from-the-command-line).
No browser, SSH tunnel or installer switch is involved.

Compose publishes the selected `${LOTGD_HTTP_PORT}` (8080 by default) on
`127.0.0.1` **of the Pi only**. It is intentionally not reachable directly at
`http://raspberrypi.local/` or the Pi's LAN address. To look at the game before
the reverse proxy below is in place, tunnel to it from your workstation:

```bash
ssh -N -L 8080:127.0.0.1:8080 pi@raspberrypi.local
```

and open `http://127.0.0.1:8080/`. If `LOTGD_HTTP_PORT` is changed, use that
Pi-side port after the second colon.

## 4. Public operation requires a TLS reverse proxy

For a permanently public game, run a TLS-terminating reverse proxy such as
Caddy, Nginx, or Traefik on the Pi's public/LAN interface and proxy it to
`127.0.0.1:${LOTGD_HTTP_PORT}`. Do not change the Compose port to `0.0.0.0`. Certificate issuance, trusted
proxy configuration, Docker network boundaries, backups, and verification are
covered in [Docker deployment](Docker.md).

## Operations and troubleshooting

```bash
docker compose logs -f web db
docker compose pull web && docker compose up -d --no-build   # update the image
docker compose exec web sh         # diagnostic shell
```

Back up the `db_data` and `lotgd_state` volumes together. The latter contains
`dbconnect.php`, installer logs, and the completion marker; deleting it can
lose configuration and installer-lock state. [Backups](Docker.md#backups) has
copy-paste commands, including the `mysqldump` invocation — take them off the Pi
itself, since the SD card is the component most likely to fail.

Keep the deployment current: the image is rebuilt, never patched in place. See
[Updating the deployment](Docker.md#updating-the-deployment) for application
updates and
[Pinned multi-architecture images](Docker.md#pinned-multi-architecture-images)
for the base-image maintenance path — both matter on a Pi that is exposed to the
internet through a home connection.

For the non-Docker alternative, install the requirements from `composer.json`
with Apache/PHP and a compatible database, but this path is more fragile and is
not recommended.
