FROM php:8.3-apache

# 1) Force a single MPM (prefork) to avoid "More than one MPM loaded"
RUN a2dismod mpm_event mpm_worker || true \
 && a2enmod mpm_prefork

# 2) Enable needed Apache modules
RUN a2enmod rewrite headers

# 3) PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# 4) Use our vhost config
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
