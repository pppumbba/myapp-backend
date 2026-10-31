#!/bin/sh
# Starts the API on Render: Apache on $PORT, after caching config and migrating, with the queue
# worker (queued emails) and the scheduler (scheduled forms, prune) running beside it.
set -e
cd /var/www/html

PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Aiven only accepts TLS. Its CA certificate comes as a Render Secret File (default path below)
# or as the text of MYSQL_CA_CERT; it is copied where the web user can read it.
CA_FILE="${MYSQL_CA_FILE:-/etc/secrets/aiven-ca.pem}"
if [ -f "$CA_FILE" ]; then
    cp "$CA_FILE" storage/mysql-ca.pem
elif [ -n "$MYSQL_CA_CERT" ]; then
    printf '%s\n' "$MYSQL_CA_CERT" > storage/mysql-ca.pem
fi
if [ -f storage/mysql-ca.pem ]; then
    chmod 644 storage/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/var/www/html/storage/mysql-ca.pem
fi

as_web() { runuser -u www-data -- "$@"; }

chown -R www-data:www-data storage bootstrap/cache
as_web php artisan storage:link --force >/dev/null 2>&1 || true
as_web php artisan config:cache
as_web php artisan route:cache
as_web php artisan view:cache
as_web php artisan migrate --force

# Restarted if they stop; --max-time lets the worker pick up a fresh deploy's code regularly.
( while true; do as_web php artisan queue:work --sleep=3 --tries=3 --max-time=3600 || true; sleep 2; done ) &
( while true; do as_web php artisan schedule:work || true; sleep 2; done ) &

exec apache2-foreground
