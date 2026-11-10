#!/bin/sh
# Nightly backup of every schema (users and each customer) to /opt/backups, two weeks kept.
# Cron, once: echo '30 3 * * * root /opt/myapp-backend/docker/server/backup.sh' > /etc/cron.d/myapp-backup
# Restore into an empty database: gunzip -c <file> | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
set -e
cd "$(dirname "$0")"
mkdir -p /opt/backups
OUT=/opt/backups/db-$(date +%Y-%m-%d-%H%M).sql.gz
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --all-databases --single-transaction --routines --triggers --events --hex-blob' | gzip > "$OUT.part"
mv "$OUT.part" "$OUT"
find /opt/backups -name 'db-*.sql.gz' -mtime +14 -delete
echo "Backed up to $OUT"
