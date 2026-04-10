#!/bin/bash
set -e

CONTAINER_NAME="nextcloud-dev"
MYSQL_CONTAINER="nextcloud-db"
NETWORK_NAME="nextcloud-net"
VOLUME_NAME="nextcloud-data"
MYSQL_VOLUME="nextcloud-mysql"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PORT=8080

usage() {
    echo "Usage: $0 {setup|start|stop|restart|destroy|logs|status}"
    echo ""
    echo "  setup    Create a fresh dev environment (destroys existing)"
    echo "  start    Start existing containers"
    echo "  stop     Stop containers without removing them"
    echo "  restart  Restart containers + reload Apache"
    echo "  destroy  Remove containers, volumes, and network"
    echo "  logs     Tail Nextcloud and Python logs"
    echo "  status   Show container status and useful info"
    exit 1
}

do_destroy() {
    echo "Destroying dev environment..."
    docker rm -f "$CONTAINER_NAME" "$MYSQL_CONTAINER" 2>/dev/null || true
    docker volume rm "$VOLUME_NAME" "$MYSQL_VOLUME" 2>/dev/null || true
    docker network rm "$NETWORK_NAME" 2>/dev/null || true
    echo "Done."
}

do_setup() {
    do_destroy

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

    # Wait for MariaDB
    echo "Waiting for MariaDB..."
    for i in $(seq 1 30); do
      if docker exec "$MYSQL_CONTAINER" mariadb -u nextcloud -pnextcloud -e "SELECT 1" &>/dev/null; then
        break
      fi
      sleep 1
    done

    sleep 3

    # Install Nextcloud
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

    # Force file scan
    docker exec -u www-data "$CONTAINER_NAME" php occ files:scan --all -q

    echo ""
    do_status
}

do_start() {
    docker start "$MYSQL_CONTAINER" "$CONTAINER_NAME" 2>/dev/null || {
        echo "Containers don't exist. Run '$0 setup' first."
        exit 1
    }
    echo "Started."
    do_status
}

do_stop() {
    docker stop "$CONTAINER_NAME" "$MYSQL_CONTAINER" 2>/dev/null || true
    echo "Stopped."
}

do_restart() {
    docker restart "$MYSQL_CONTAINER" "$CONTAINER_NAME" 2>/dev/null || {
        echo "Containers don't exist. Run '$0 setup' first."
        exit 1
    }
    # Wait for Apache to be ready then reload
    sleep 3
    docker exec "$CONTAINER_NAME" apache2ctl graceful 2>/dev/null
    # Clear Python cache
    find "$APP_DIR" -type d -name "__pycache__" -exec rm -rf {} + 2>/dev/null || true
    echo "Restarted."
    do_status
}

do_logs() {
    echo "=== Nextcloud log (last 20) ==="
    docker exec -u www-data "$CONTAINER_NAME" php occ log:tail -n 20 2>/dev/null || echo "(container not running)"
    echo ""
    echo "=== Python task log ==="
    docker exec "$CONTAINER_NAME" cat "$(docker exec "$CONTAINER_NAME" find /var/www/html/data -path '*/mediadc/logs/output.log' 2>/dev/null)" 2>/dev/null | tail -30 || echo "(no log yet)"
}

do_status() {
    NC_STATUS=$(docker inspect -f '{{.State.Status}}' "$CONTAINER_NAME" 2>/dev/null || echo "not created")
    DB_STATUS=$(docker inspect -f '{{.State.Status}}' "$MYSQL_CONTAINER" 2>/dev/null || echo "not created")

    echo "=== MediaDC Dev Environment ==="
    echo "  Nextcloud:  $NC_STATUS"
    echo "  MariaDB:    $DB_STATUS"
    if [ "$NC_STATUS" = "running" ]; then
        echo ""
        echo "  URL:        http://localhost:$PORT"
        echo "  Login:      admin / admin"
        echo ""
        echo "Useful commands:"
        echo "  $0 stop       Stop containers"
        echo "  $0 restart    Restart + reload Apache/Python cache"
        echo "  $0 logs       View logs"
        echo "  $0 destroy    Remove everything"
        echo "  npm run build                    Rebuild frontend"
    fi
}

case "${1:-}" in
    setup)   do_setup ;;
    start)   do_start ;;
    stop)    do_stop ;;
    restart) do_restart ;;
    destroy) do_destroy ;;
    logs)    do_logs ;;
    status)  do_status ;;
    *)       usage ;;
esac
