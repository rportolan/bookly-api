FROM php:8.3-apache

RUN a2enmod rewrite headers
RUN docker-php-ext-install pdo pdo_mysql

# Use our vhost config
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
