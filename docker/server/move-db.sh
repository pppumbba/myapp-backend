#!/bin/sh
# One-time move onto the MySQL in this stack (from Aiven), run as root from docker/server:
#   ./move-db.sh /root/server-db.sql [/root/storage-app.tar.gz]
# 1. makes the database passwords in .env (once), 2. starts MySQL on an empty volume and loads the
# dump (local-dump.php: every schema), 3. with a second file, swaps the uploads for those in it
# (the ones there now are kept in /root/storage-app-before-move.tar.gz), 4. deploys.
# It stops without changing anything if the database already has the main schema.
set -e
cd "$(dirname "$0")"
DUMP="$1"
FILES="$2"
[ -f "$DUMP" ] || { echo "Usage: ./move-db.sh <server-db.sql> [storage-app.tar.gz]"; exit 1; }
[ -z "$FILES" ] || [ -f "$FILES" ] || { echo "No such file: $FILES"; exit 1; }
MAIN=$(sed -n 's/^-- Local database for the server, .*UTC: \([^,]*\),.*/\1/p' "$DUMP" | head -n 1)
[ -n "$MAIN" ] || { echo "$DUMP doesn't look like local-dump.php's file."; exit 1; }

touch .env
grep -q '^MYSQL_ROOT_PASSWORD=' .env || echo "MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)" >> .env
grep -q '^DB_PASSWORD=' .env || echo "DB_PASSWORD=$(openssl rand -hex 24)" >> .env
chmod 600 .env

git -C /opt/myapp-backend pull --ff-only
docker compose stop app
docker compose up -d mysql
echo "Waiting for MySQL..."
until [ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q mysql)")" = healthy ]; do sleep 2; done

sql() { docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"' sh "$@"; }
if [ "$(sql -N -e "select count(*) from information_schema.schemata where schema_name = '$MAIN'")" != 0 ]; then
    echo "The database already has $MAIN: nothing was loaded. (To start over: docker compose down, docker volume rm the mysql-data volume.)"
    exit 1
fi
echo "Loading $DUMP..."
sql < "$DUMP"
sql -N -e "select concat(table_schema, ': ', count(*), ' tables') from information_schema.tables where table_schema not in ('mysql', 'sys', 'information_schema', 'performance_schema') group by table_schema"

if [ -n "$FILES" ]; then
    echo "Swapping the uploads..."
    DIR=$(cd "$(dirname "$FILES")" && pwd)
    NAME=$(basename "$FILES")
    docker compose run --rm --no-deps -v "$DIR":/move --entrypoint sh app -c "
        set -e
        cd /var/www/html/storage/app
        tar czf /move/storage-app-before-move.tar.gz .
        find . -mindepth 1 -delete
        tar xzf /move/$NAME
        chown -R www-data:www-data ."
fi

./deploy.sh
echo "Done. The app now uses the MySQL on this server. Nightly backups: see backup.sh."
