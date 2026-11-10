#!/usr/bin/env bash
#
# Real-WordPress smoke for the async outbox (schema + repository lifecycle).
#
# The unit suite mocks $wpdb, so it cannot prove that dbDelta creates the tables,
# that a custom table prefix is honoured, or that the SQL transactions behave.
# This script boots a throwaway MySQL + WordPress pair (nothing is reused or
# published), activates the plugin, and exercises the repository against real
# MySQL. It always cleans up its containers, network and volume.
#
# Requirements: Docker. Network access is not required (images must exist
# locally: mysql:8.0, wordpress:php8.2-apache, wordpress:cli-php8.2).
#
#   ./dev/outbox-smoke/run.sh
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
NET="cl-outbox-smoke-net"
DB="cl-outbox-smoke-db"
WP="cl-outbox-smoke-wp"
VOL="cl-outbox-smoke-data"
PREFIX="clsmk_"

cleanup() {
    docker rm -f "$WP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$VOL" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup
docker network create "$NET" >/dev/null
docker volume create "$VOL" >/dev/null

echo "→ starting MySQL"
docker run -d --name "$DB" --network "$NET" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp mysql:8.0 >/dev/null
# `mysqladmin ping` answers during first-boot initialisation too, so wait for a
# real query against the target database before touching WordPress.
ready=0
for _ in $(seq 1 120); do
    if docker exec "$DB" mysql -uroot -proot --ssl-mode=DISABLED -N -e 'SELECT 1' -D wp >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
if [ "$ready" -ne 1 ]; then
    echo "✗ MySQL did not become ready" >&2
    exit 1
fi

echo "→ starting WordPress (plugin mounted read-only, custom prefix $PREFIX)"
docker run -d --name "$WP" --network "$NET" \
    -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root \
    -e WORDPRESS_DB_NAME=wp -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
    -v "$VOL":/var/www/html \
    -v "$REPO":/var/www/html/wp-content/plugins/cherrylink-pro-ai:ro \
    wordpress:php8.2-apache >/dev/null
for _ in $(seq 1 90); do
    docker exec "$WP" test -f /var/www/html/wp-config.php >/dev/null 2>&1 && break
    sleep 1
done

wp_cli() {
    docker run --rm --network "$NET" --volumes-from "$WP" \
        -v "$REPO/dev/outbox-smoke":/smoke:ro \
        -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root \
        -e WORDPRESS_DB_NAME=wp -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
        wordpress:cli-php8.2 "$@"
}

echo "→ installing WordPress"
installed=0
for _ in $(seq 1 20); do
    if wp_cli wp core install --url=http://localhost:8080 --title=Smoke \
        --admin_user=admin --admin_password=adminpw \
        --admin_email=smoke@example.invalid --skip-email >/dev/null 2>&1; then
        installed=1
        break
    fi
    sleep 3
done
if [ "$installed" -ne 1 ]; then
    echo "✗ WordPress install failed; retrying verbosely" >&2
    wp_cli wp core install --url=http://localhost:8080 --title=Smoke \
        --admin_user=admin --admin_password=adminpw \
        --admin_email=smoke@example.invalid --skip-email
    exit 1
fi

echo "→ activating plugin (runs Lifecycle::activate → Schema::install)"
wp_cli wp plugin activate cherrylink-pro-ai >/dev/null

echo "→ repository + lifecycle assertions"
wp_cli wp eval-file /smoke/repository-smoke.php
wp_cli wp eval-file /smoke/lifecycle-smoke.php

echo "✓ outbox smoke passed"
