# Legend of the Green Dragon on Raspberry Pi 4 (Docker-first)

This guide targets **Raspberry Pi 4 + Raspberry Pi OS 64-bit** for a smooth Docker experience.

## Why Docker?
Docker is the easiest path on Raspberry Pi because it bundles PHP, Apache, and database dependencies in a reproducible container setup. This avoids manual package hunting, keeps the host clean, and makes upgrades/rollbacks predictable.

## Recommended base image
Use **Raspberry Pi OS Lite (64-bit)** as the host OS. It is the most stable, officially supported image for Raspberry Pi 4 and keeps the host lightweight while Docker contains the app stack.

Official Raspberry Pi OS downloads:
- https://www.raspberrypi.com/software/operating-systems/

## Recommended Docker tutorials (Raspberry Pi)
These are authoritative, maintained sources that match the Pi 4 + Debian-based OS stack:

- Docker Engine (Debian/Raspberry Pi OS):
  - https://docs.docker.com/engine/install/debian/
- Docker Compose plugin install:
  - https://docs.docker.com/compose/install/
- Optional: Post-install steps (docker group, permissions):
  - https://docs.docker.com/engine/install/linux-postinstall/

## Install LOTGD via Docker (easiest path)

### 1) Install Raspberry Pi OS Lite (64-bit)
Flash the OS to your SD card (Raspberry Pi Imager), boot the Pi, and complete the standard first-time setup.

### 2) Install Docker Engine + Compose on the Pi
Follow the official Docker docs above. The Debian instructions explicitly cover **Raspberry Pi OS (64-bit)** under supported platforms/architectures, so they are the correct reference for Pi 4 ARM64. When done, confirm:

```
docker --version
docker compose version
```

### 3) Clone the LOTGD repository
```
git clone https://github.com/lotgd/lotgd.git
cd lotgd
```

### 4) Start the Docker environment (already provided in this repo)
This repository already includes a `Dockerfile` and `docker-compose.yml` suitable for local development/testing. Use the built-in docs:

- [docs/Docker.md](docs/Docker.md) (repo guide)

Then run:

```
docker compose up -d --build
```

### 5) Finish in the browser
Open your Pi’s IP address in a browser (e.g., `http://raspberrypi.local/` or `http://<pi-ip>/`) and complete the in-app setup.

## Notes and troubleshooting
- If you make changes to the Dockerfile or PHP settings, rebuild with:
  ```
  docker compose up -d --build
  ```
- For logs:
  ```
  docker compose logs -f
  ```
- For an interactive shell:
  ```
  docker compose exec web bash
  ```

## Alternative (non-Docker) path
Docker is strongly recommended. If you must install directly on the host, follow the dependencies in `composer.json` and use Apache/PHP + MariaDB on Raspberry Pi OS. This is more fragile, harder to upgrade, and not the easiest path.
