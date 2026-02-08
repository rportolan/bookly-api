FROM php:8.3-apache

# --- Apache modules ---
RUN a2enmod rewrite headers

# --- System deps + PHP extensions (zip, curl, pdo_mysql) ---
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    libcurl4-openssl-dev \
  && docker-php-ext-install zip curl pdo pdo_mysql \
  && rm -rf /var/lib/apt/lists/*

# --- Send PHP errors to Railway logs (stderr) ---
RUN { \
  echo "log_errors=On"; \
  echo "error_reporting=E_ALL"; \
  echo "display_errors=Off"; \
  echo "error_log=/proc/self/fd/2"; \
} > /usr/local/etc/php/conf.d/zz-railway-logs.ini

# --- Composer (copy from official image) ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Vhost ---
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copy composer manifests first (build cache)
COPY composer.json composer.lock* ./

# Install deps (generates vendor/autoload.php)
RUN composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader

RUN test -f /var/www/html/vendor/autoload.php

# Copy app
COPY . .

RUN test -f /var/www/html/public/index.php

# Start script
COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
