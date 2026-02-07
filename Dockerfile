FROM php:8.3-apache

# --- Apache modules ---
RUN a2enmod rewrite headers

# --- System deps for Composer + PHP zip extension ---
# Needed for: composer prefer-dist (zip/unzip) and fallback source (git)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
  && docker-php-ext-install zip \
  && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ---
RUN docker-php-ext-install pdo pdo_mysql

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

# Sanity: vendor autoload must exist
RUN test -f /var/www/html/vendor/autoload.php

# Copy app
COPY . .

# Sanity: public index must exist
RUN test -f /var/www/html/public/index.php

# Start script
COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
