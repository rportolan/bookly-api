#!/usr/bin/env sh
set -eu

echo ">>> START.SH is running"
echo ">>> PORT=${PORT:-<empty>}"

echo ">>> BEFORE: enabled MPM modules"
apache2ctl -M 2>/dev/null | grep mpm || true
ls -la /etc/apache2/mods-enabled | grep mpm || true

# --- Hard reset MPM (runtime) ---
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf || true

# Enable only prefork, disable others
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

echo ">>> AFTER: enabled MPM modules"
apache2ctl -M 2>/dev/null | grep mpm || true
ls -la /etc/apache2/mods-enabled | grep mpm || true

# --- Railway port ---
: "${PORT:=80}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf || true
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf || true

echo ">>> ports.conf"
grep -n "^Listen" /etc/apache2/ports.conf || true

echo ">>> vhost"
grep -n "<VirtualHost" /etc/apache2/sites-available/000-default.conf || true

exec apache2-foreground
