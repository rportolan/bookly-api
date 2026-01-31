#!/usr/bin/env sh
set -eu

# Railway fournit PORT
: "${PORT:=80}"

# Apache doit écouter sur $PORT
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf

# Ton vhost doit matcher le port aussi
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Optionnel mais propre
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true

exec apache2-foreground
