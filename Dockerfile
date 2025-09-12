# ---------------------------------------------
# Composer stage - install PHP dependencies
# ---------------------------------------------
FROM composer:2 AS composer

# Working directory for Composer
WORKDIR /app

# Copy Composer files separately to leverage Docker layer caching
COPY composer.json composer.lock ./

# Install dependencies without development packages
RUN composer install --no-dev --no-interaction --prefer-dist

# ---------------------------------------------
# Application stage - PHP with Apache
# ---------------------------------------------
FROM php:8.4-apache

# Install system packages and PHP extensions required by the game
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite for clean URLs
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Application code lives here
WORKDIR /var/www/html

# Copy application source
COPY . /var/www/html

# Bring in Composer dependencies from the composer stage
COPY --from=composer /app/vendor /var/www/html/vendor

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Expose the Apache port
EXPOSE 80

# Enable verbose PHP error reporting (development only)
RUN echo "display_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "display_startup_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "error_reporting = E_ALL;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "log_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "error_log = /dev/stderr;" >> /usr/local/etc/php/conf.d/docker-php.ini

# Launch Apache
CMD ["apache2-foreground"]
