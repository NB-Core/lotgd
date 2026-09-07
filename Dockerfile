# syntax=docker/dockerfile:1

# Install dependency archives before copying the application so ordinary source
# changes retain the expensive Composer download layer.
# Keep the readable release line while pinning the reviewed multi-architecture
# index; Dependabot proposes digest refreshes without silently changing builds.
FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-autoloader \
    --no-scripts
COPY . ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# This multiarch image ships the required extensions as pre-built modules, so
# no extension is ever compiled here. Keep the manifest-list digest
# synchronized with docs/Docker.md.
#
# The v5 line is built on Ubuntu with the ondrej PHP packages, so PHP's
# configuration lives under /etc/php/${PHP_VERSION} rather than the
# /usr/local/etc/php of the official php images. Read
# docs/Docker.md#php-runtime-image before changing anything below.
FROM thecodingmachine/php:8.4-v5-apache@sha256:d04b2b76c615c9af90cdc66b54cf4e4d09eba64ee74f9bbbd79b78b06d902a66

USER root

# The fat runtime ships every required module pre-built and enables all but GD
# by default; its entrypoint materializes the matching ini file before Apache
# starts.
#
# TEMPLATE_PHP_INI selects which stock php.ini the runtime links in at startup.
# It defaults to "development", which would turn displayed errors on in an
# image built for production.
#
# DOCKER_USER short-circuits the runtime's ownership heuristic. Without it the
# entrypoint probes the working directory by creating and deleting a scratch
# directory in the document root on every start, which the read-only document
# root of this image is deliberately not meant to allow.
ENV PHP_EXTENSION_GD=1 \
    TEMPLATE_PHP_INI=production \
    DOCKER_USER=docker \
    APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data

# The runtime re-runs a2enmod/a2dismod on every container start from its own
# default list, which would silently switch mod_headers back off after the
# build enabled it. Without it the response headers below are quietly dropped:
# no-store on game pages, nosniff on static files. deflate, expires, rewrite
# and alias are already part of that default list.
ENV APACHE_EXTENSION_HEADERS=1

# The vhost contains the production security rules, so per-request .htaccess
# discovery is unnecessary.
# Compress text responses and let Apache emit explicit freshness metadata for
# static assets. Dynamic PHP pages remain uncached because game output is tied
# to the authenticated session.
RUN a2enmod deflate expires headers rewrite \
    && a2dissite 000-default
COPY docker/apache/lotgd.conf /etc/apache2/sites-available/lotgd.conf
RUN a2ensite lotgd
# Read after the base image's security.conf, so its ServerTokens/TraceEnable
# defaults are replaced rather than merged.
COPY docker/apache/hardening.conf /etc/apache2/conf-enabled/zz-lotgd-hardening.conf
COPY docker/health/ready.php /var/www/lotgd-health/ready.php

# Resolve the scan directory from the runtime's own PHP_VERSION instead of
# hardcoding it, and fail the build rather than silently dropping the
# production settings into a directory PHP does not read.
COPY docker/php/production.ini /tmp/lotgd-production.ini
RUN set -eu; \
    conf_dir="/etc/php/${PHP_VERSION}/apache2/conf.d"; \
    test -d "$conf_dir"; \
    install -o root -g root -m 0644 /tmp/lotgd-production.ini "$conf_dir/zz-lotgd.ini"; \
    rm /tmp/lotgd-production.ini

COPY docker/entrypoint.sh /usr/local/bin/lotgd-entrypoint
RUN chmod +x /usr/local/bin/lotgd-entrypoint

# Debug-only alternative for users who cannot use docker-compose.dev.yml.
# Keep this disabled in production because PHP errors may disclose secrets or
# implementation details. The development override is the preferred approach.
# RUN printf '%s\n' \
#         'display_errors = On' \
#         'display_startup_errors = On' \
#         'error_reporting = E_ALL' \
#         'log_errors = On' \
#         'error_log = /dev/stderr' \
#     > "/etc/php/${PHP_VERSION}/apache2/conf.d/zzz-lotgd-debug.ini"

WORKDIR /var/www/html
COPY --from=composer /app /var/www/html

# Twig and Doctrine use separate children of this persistent runtime cache.
RUN install -d -o www-data -g www-data -m 0775 \
        /var/cache/lotgd \
        /var/cache/lotgd/twig \
        /var/cache/lotgd/doctrine \
        /var/lib/lotgd \
        /var/lib/lotgd/logs \
    && chown -R root:root /var/www/html \
    && chmod -R go-w /var/www/html

ENV APP_ENV=production \
    LOTGD_STATE_PATH=/var/lib/lotgd \
    LOTGD_DATA_DIR=/var/lib/lotgd/logs \
    MYSQL_USEDATACACHE=1 \
    MYSQL_DATACACHEPATH=/var/cache/lotgd

EXPOSE 80

# Compose overrides this with its own definition; keeping it in the image means
# a plain `docker run` also reports readiness instead of only liveness. The
# probe is container-local by Apache configuration and performs a
# side-effect-free SELECT 1 without starting a game session.
#
# curl rather than php: on this runtime /usr/bin/php is a wrapper script that
# sudo-chowns a cache file and may regenerate the PHP configuration before
# handing over to the real binary. That is unwanted work every 15 seconds, and
# a failure inside the wrapper would be reported as an unhealthy application.
# --fail turns any 4xx/5xx into a non-zero exit; the probe answers 204 or 503.
HEALTHCHECK --interval=15s --timeout=3s --start-period=60s --retries=4 \
    CMD ["curl", "--fail", "--silent", "--show-error", "--output", "/dev/null", "http://127.0.0.1/_health/ready"]

ENTRYPOINT ["lotgd-entrypoint"]
CMD ["apache2-foreground"]
