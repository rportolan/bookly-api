#!/usr/bin/env sh
set -eu

: "${PORT:=80}"
echo ">>> PORT=$PORT"

# --- Force Apache listen port (Railway) ---
printf "Listen %s\n" "$PORT" > /etc/apache2/ports.conf

# --- HARD RESET of MPM modules at runtime ---
# Remove any enabled MPM symlinks (load + conf)
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf || true

# Enable ONLY prefork
a2enmod mpm_prefork >/dev/null 2>&1 || true
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true

echo ">>> Enabled MPM modules:"
apache2ctl -M 2>/dev/null | grep mpm || true
ls -la /etc/apache2/mods-enabled | grep mpm || true

# --- Ensure vhost matches port (optional but clean) ---
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf || true

# Silence ServerName warning (optional)
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true

exec apache2-foreground
