# syntax=docker/dockerfile:1

# Install dependency archives before copying the application so ordinary source
# changes retain the expensive Composer download layer.
FROM composer:2 AS composer
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

# This multiarch image ships the required extensions as pre-built modules.
# Keep the manifest-list digest synchronized with docs/Docker.md.
FROM thecodingmachine/php:8.3-v4-apache@sha256:7bc852ed28adb908d245ef4a71b2c2d19fd9626c1975af61ba5a8f958a035ec7

USER root

# The fat runtime enables all required modules except GD by default. Its
# entrypoint materializes the corresponding ini file before Apache starts.
ENV PHP_EXTENSION_GD=1 \
    APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data

# The vhost contains the production security rules, so per-request .htaccess
# discovery is unnecessary.
# Compress text responses and let Apache emit explicit freshness metadata for
# static assets. Dynamic PHP pages remain uncached because game output is tied
# to the authenticated session.
RUN a2enmod deflate expires headers rewrite \
    && a2dissite 000-default
COPY docker/apache/lotgd.conf /etc/apache2/sites-available/lotgd.conf
RUN a2ensite lotgd
COPY docker/health/ready.php /var/www/lotgd-health/ready.php
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-lotgd.ini
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
#     > /usr/local/etc/php/conf.d/zzz-lotgd-debug.ini

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
ENTRYPOINT ["lotgd-entrypoint"]
CMD ["apache2-foreground"]
