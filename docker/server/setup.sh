#!/bin/sh
# One-time setup of a fresh Ubuntu 24.04 server (Hetzner Cloud), run as root: Docker, 2 GB swap (a small server
# needs it to build the image), a firewall, and the code in /opt/myapp-backend.
set -e

if ! command -v docker >/dev/null; then
    curl -fsSL https://get.docker.com | sh
fi

if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

command -v ufw >/dev/null || { apt-get update && apt-get install -y ufw; }
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw --force enable

if [ ! -d /opt/myapp-backend ]; then
    git clone https://github.com/pppumbba/myapp-backend.git /opt/myapp-backend
fi

echo "Done. Next: put .env.production, aiven-ca.pem and .env (DOMAIN=...) in /opt/myapp-backend/docker/server"
