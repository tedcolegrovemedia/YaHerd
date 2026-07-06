FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql && a2enmod rewrite

COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf

COPY server /var/www/html/server
COPY deploy/config.production.php /var/www/html/server/src/config.php

RUN mkdir -p /var/www/html/server/storage/screenshots \
    && chown -R www-data:www-data /var/www/html/server/storage
