FROM php:8.3-apache

# 1) Force un seul MPM (prefork) proprement
RUN set -eux; \
    a2dismod mpm_event mpm_worker || true; \
    a2enmod mpm_prefork; \
    apache2ctl -M | grep -E "mpm_(event|worker|prefork)_module" || true; \
    test "$(apache2ctl -M | grep -c -E "mpm_(event|worker|prefork)_module")" -eq 1

# 2) Modules Apache utiles
RUN a2enmod rewrite headers

# 3) Extensions PHP
RUN docker-php-ext-install pdo pdo_mysql

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html

COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
