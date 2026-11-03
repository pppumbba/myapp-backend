#!/bin/sh
# Deploys the latest main on the droplet: pull, rebuild, restart. Uploads stay in their volume.
set -e
cd /opt/myapp-backend
git pull --ff-only
cd docker/droplet
docker compose up -d --build
docker image prune -f
