#!/bin/bash
set -e

CONTAINER_NAME="nextcloud-dev"
MYSQL_CONTAINER="nextcloud-db"
NETWORK_NAME="nextcloud-net"
VOLUME_NAME="nextcloud-data"
MYSQL_VOLUME="nextcloud-mysql"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PORT=8080

echo "=== MediaDC Dev Environment Setup ==="

# Stop and remove existing containers/volumes/network
echo "Cleaning up old environment..."
docker rm -f "$CONTAINER_NAME" "$MYSQL_CONTAINER" 2>/dev/null || true
docker volume rm "$VOLUME_NAME" "$MYSQL_VOLUME" 2>/dev/null || true
docker network rm "$NETWORK_NAME" 2>/dev/null || true

# Clear Python bytecode cache
find "$APP_DIR" -type d -name "__pycache__" -exec rm -rf {} + 2>/dev/null || true

# Create network
docker network create "$NETWORK_NAME"

# Start MariaDB
echo "Starting MariaDB..."
docker run -d \
  --name "$MYSQL_CONTAINER" \
  --network "$NETWORK_NAME" \
  -v "$MYSQL_VOLUME:/var/lib/mysql" \
  -e MYSQL_ROOT_PASSWORD=nextcloud \
  -e MYSQL_DATABASE=nextcloud \
  -e MYSQL_USER=nextcloud \
  -e MYSQL_PASSWORD=nextcloud \
  mariadb:11

# Start Nextcloud
echo "Starting Nextcloud 33..."
docker run -d \
  --name "$CONTAINER_NAME" \
  --network "$NETWORK_NAME" \
  -p "$PORT:80" \
  -v "$VOLUME_NAME:/var/www/html" \
  -v "$APP_DIR:/var/www/html/custom_apps/mediadc" \
  nextcloud:33

# Wait for MariaDB to be ready
echo "Waiting for MariaDB..."
for i in $(seq 1 30); do
  if docker exec "$MYSQL_CONTAINER" mariadb -u nextcloud -pnextcloud -e "SELECT 1" &>/dev/null; then
    break
  fi
  sleep 1
done

# Wait for Nextcloud container
sleep 3

# Install Nextcloud with MySQL
echo "Installing Nextcloud..."
docker exec -u www-data "$CONTAINER_NAME" php occ maintenance:install \
  --admin-user admin --admin-pass admin \
  --database mysql \
  --database-host "$MYSQL_CONTAINER" \
  --database-name nextcloud \
  --database-user nextcloud \
  --database-pass nextcloud

# Enable the app
echo "Enabling MediaDC..."
docker exec -u www-data "$CONTAINER_NAME" php occ app:enable mediadc

# Install Python dependencies
echo "Installing Python dependencies (this takes a minute)..."
docker exec "$CONTAINER_NAME" bash -c "apt-get update -qq && apt-get install -y -qq python3-pip python3-dev build-essential ffmpeg > /dev/null 2>&1"
docker exec "$CONTAINER_NAME" pip3 install --break-system-packages -q -r /var/www/html/custom_apps/mediadc/requirements.txt

# Force file scan so NC knows about any existing files
docker exec -u www-data "$CONTAINER_NAME" php occ files:scan --all -q

echo ""
echo "=== Done ==="
echo "Nextcloud: http://localhost:$PORT"
echo "Login:     admin / admin"
echo ""
echo "Useful commands:"
echo "  Restart Apache (after PHP changes):  docker exec $CONTAINER_NAME apache2ctl graceful"
echo "  View Python log:                     docker exec $CONTAINER_NAME cat \$(find /var/www/html/data -path '*/mediadc/logs/output.log' 2>/dev/null)"
echo "  View NC log:                         docker exec -u www-data $CONTAINER_NAME php occ log:tail"
echo "  Rebuild frontend:                    npm run build"
echo "  Re-enable app (after PHP changes):   docker exec -u www-data $CONTAINER_NAME php occ app:disable mediadc && docker exec -u www-data $CONTAINER_NAME php occ app:enable mediadc"
