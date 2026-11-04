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

# The port opens first: Render gives up on a service that takes too long to open one, and
# migrations over the network to Aiven can take minutes. A failed migration is logged and the
# site stays up, so its cause can be read and fixed.
apache2-foreground &
APACHE=$!
trap 'kill -TERM "$APACHE"' TERM INT

# Customers as schemas: the main schema (users, logins) migrates on its own, then every customer's
# schema. Before the database is adopted (php artisan customers:adopt, once, by hand) there are no
# customers yet and the old single schema migrates as before.
if [ -n "${DB_MAIN_DATABASE:-}" ]; then
    as_web php artisan migrate --database=main --path=database/migrations/main --force \
        || echo "MAIN MIGRATIONS FAILED. See the error above." >&2
    if ! as_web php artisan customers:migrate; then
        echo "CUSTOMER MIGRATIONS FAILED for the customers marked FAILED above." >&2
    fi
elif ! as_web php artisan migrate --force; then
    echo "MIGRATIONS FAILED: the site is running on an older database. See the error above." >&2
fi

# Restarted if they stop; --max-time lets the worker pick up a fresh deploy's code regularly.
( while true; do as_web php artisan queue:work --sleep=3 --tries=3 --max-time=3600 || true; sleep 2; done ) &
( while true; do as_web php artisan schedule:work || true; sleep 2; done ) &

wait "$APACHE"
