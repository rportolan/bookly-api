FROM php:8.3-apache

RUN a2enmod rewrite headers
RUN docker-php-ext-install pdo pdo_mysql

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Vhost
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copy composer manifests first (cache)
COPY composer.json composer.lock* ./

# Install deps (even if none, generates vendor/autoload.php)
RUN composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader

# Copy app
COPY . .

# Start script
COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
