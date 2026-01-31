FROM php:8.3-apache

RUN a2dismod mpm_event mpm_worker || true \
 && a2enmod mpm_prefork

RUN a2enmod rewrite headers
RUN docker-php-ext-install pdo pdo_mysql

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
