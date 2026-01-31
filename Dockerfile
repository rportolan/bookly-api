FROM php:8.3-apache

RUN rm -f /etc/apache2/mods-enabled/mpm_event.* \
          /etc/apache2/mods-enabled/mpm_worker.* \
          /etc/apache2/mods-enabled/mpm_prefork.* \
 && a2enmod mpm_prefork

RUN a2enmod rewrite headers
RUN docker-php-ext-install pdo pdo_mysql

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html

COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
