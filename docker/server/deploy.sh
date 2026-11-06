#!/bin/sh
# Deploys the latest main on the server: pull, rebuild, restart. Uploads stay in their volume.
set -e
cd /opt/myapp-backend
git pull --ff-only
cd docker/server
docker compose up -d --build
docker image prune -f
