#!/usr/bin/env sh
set -eu

: "${PORT:=80}"
echo ">>> PORT=$PORT"

# Force Apache to listen on Railway port (no fragile sed)
printf "Listen %s\n" "$PORT" > /etc/apache2/ports.conf

# Force vhost to match the same port
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf || true

# Avoid ServerName warning (optional)
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true

# Show what Apache will listen on
echo ">>> ports.conf:"
cat /etc/apache2/ports.conf

exec apache2-foreground
