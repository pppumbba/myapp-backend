#!/bin/sh
# Runs once, when the mysql-data volume is first made: the app's own user. ALL on *.* because the
# app makes a schema per customer, with views and triggers (as Aiven's avnadmin could).
set -e
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS 'wand'@'%' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON *.* TO 'wand'@'%';
SQL
