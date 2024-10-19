# Basis-Image mit PHP und Apache
FROM php:apache

# Benötigte PHP-Erweiterungen installieren
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Mod_rewrite aktivieren
RUN a2enmod rewrite

# Apache-Konfiguration anpassen, um .htaccess zu erlauben
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Arbeitsverzeichnis festlegen
WORKDIR /var/www/html

# Abhängigkeiten installieren (falls Composer benötigt wird)
RUN apt-get update && apt-get install -y git unzip
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Quellcode kopieren
COPY . /var/www/html
#RUN cd /var/www/html && mv dbconnect.docker.php dbconnect.php
RUN cd /var/www/html/ext && composer install && composer update

# Berechtigungen setzen
RUN chown -R www-data:www-data /var/www/html

# Port 80 exponieren
EXPOSE 80

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql mbstring zip

# PHP-Fehleranzeige aktivieren
RUN echo "display_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini
RUN echo "display_startup_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini
RUN echo "error_reporting = E_ALL;" >> /usr/local/etc/php/conf.d/docker-php.ini
RUN echo "log_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini


CMD ["apache2-foreground"]
