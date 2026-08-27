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

FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libjpeg-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mbstring mysqli opcache pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# The vhost contains the production security rules, so per-request .htaccess
# discovery is unnecessary. Only mod_rewrite is needed by those rules.
RUN a2enmod rewrite \
    && a2dissite 000-default
COPY docker/apache/lotgd.conf /etc/apache2/sites-available/lotgd.conf
RUN a2ensite lotgd
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
    && chown -R www-data:www-data /var/www/html

ENV APP_ENV=production \
    LOTGD_STATE_PATH=/var/lib/lotgd \
    MYSQL_USEDATACACHE=1 \
    MYSQL_DATACACHEPATH=/var/cache/lotgd

EXPOSE 80
ENTRYPOINT ["lotgd-entrypoint"]
CMD ["apache2-foreground"]
