# Legend of the Green Dragon on Raspberry Pi 4 (Docker-first)

This guide targets **Raspberry Pi 4 with Raspberry Pi OS Lite 64-bit**. The
production images are checked for native ARM64 support in CI, so emulation is
not required.

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
git clone https://github.com/lotgd/lotgd.git
cd lotgd
cp .env.example .env
chmod 600 .env
sed -i "s|^MYSQL_PASSWORD=$|MYSQL_PASSWORD=$(openssl rand -base64 32)|" .env
sed -i "s|^MYSQL_ROOT_PASSWORD=$|MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)|" .env
sed -i 's/^LOTGD_INSTALL_ENABLED=.*/LOTGD_INSTALL_ENABLED=1/' .env
```

Use two independently generated secrets; do not reuse either password or
commit `.env`. Keep the installer flag at `1` only for initial setup.

## 3. Start the private installer

```bash
docker compose up -d --build
```

Compose publishes the selected `${LOTGD_HTTP_PORT}` (8080 by default) on
`127.0.0.1` **of the Pi only**. It is intentionally not reachable directly at
`http://raspberrypi.local/` or the Pi's LAN address. From an administrator
workstation, create an SSH tunnel:

```bash
ssh -N -L 8080:127.0.0.1:8080 pi@raspberrypi.local
```

Then open `http://127.0.0.1:8080/installer.php` on that workstation and finish
installation. If `LOTGD_HTTP_PORT` is changed, use that Pi-side port after the
second colon; the workstation-side port may be any unused local port.

After installation, set `LOTGD_INSTALL_ENABLED=0` in `.env` and recreate web as
defense in depth. The persistent completion marker already keeps the installer
locked even if the flag is accidentally left enabled:

```bash
sed -i 's/^LOTGD_INSTALL_ENABLED=.*/LOTGD_INSTALL_ENABLED=0/' .env
docker compose up -d --force-recreate web
```

## 4. Public operation requires a TLS reverse proxy

For a permanently public game, run a TLS-terminating reverse proxy such as
Caddy, Nginx, or Traefik on the Pi's public/LAN interface and proxy it to
`127.0.0.1:${LOTGD_HTTP_PORT}`. Do not change the Compose port to `0.0.0.0` and
do not expose the temporary installer directly. Certificate issuance, trusted
proxy configuration, Docker network boundaries, backups, and verification are
covered in [Docker deployment](Docker.md).

## Operations and troubleshooting

```bash
docker compose logs -f web db
docker compose up -d --build       # rebuild after image/PHP changes
docker compose exec web sh         # diagnostic shell
```

Back up the `db_data` and `lotgd_state` volumes together. The latter contains
`dbconnect.php`, installer logs, and the completion marker; deleting it can
lose configuration and installer-lock state. For the non-Docker alternative,
install the requirements from `composer.json` with Apache/PHP and a compatible
database, but this path is more fragile and is not recommended.
