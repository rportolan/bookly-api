FROM php:8.3-apache

# 1) Force ONLY one MPM (prefork). Do it "hard" by removing enabled files.
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* \
          /etc/apache2/mods-enabled/mpm_worker.* \
          /etc/apache2/mods-enabled/mpm_prefork.* \
 && a2enmod mpm_prefork

# 2) Enable needed Apache modules (no restart needed)
RUN a2enmod rewrite headers

# 3) PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# 4) Vhost
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
