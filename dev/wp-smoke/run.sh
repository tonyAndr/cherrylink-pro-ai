#!/usr/bin/env bash
#
# WordPress smoke for the editor path (and anything else that needs real options).
#
# Boots a throwaway MySQL + WordPress pair with the plugin bind-mounted, activates
# it, publishes a post, and runs editor-check.php through `wp eval-file`. The API
# is optional: without it the search leg reports 4xx but the fatal/empty-list
# regressions are still covered.
#
#   ./dev/wp-smoke/run.sh
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
NET="cl-wp-smoke-net"; DB="cl-wp-smoke-db"; WP="cl-wp-smoke-wp"
VOL="cl-wp-smoke-data"; PREFIX="clsmk_"

cleanup() {
    if [ "${KEEP:-0}" = "1" ]; then echo "KEEP=1: leaving containers up" >&2; return 0; fi
    docker rm -f "$WP" "$DB" >/dev/null 2>&1 || true
    docker volume rm "$VOL" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

docker network create "$NET" >/dev/null
docker volume create "$VOL" >/dev/null

echo "→ MySQL"
docker run -d --name "$DB" --network "$NET" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp mysql:8.0 >/dev/null
ready=0
for _ in $(seq 1 120); do
    docker exec "$DB" mysql -uroot -proot --ssl-mode=DISABLED -N -e 'SELECT 1' -D wp >/dev/null 2>&1 && { ready=1; break; }
    sleep 1
done
[ "$ready" -eq 1 ] || { echo "✗ MySQL not ready" >&2; exit 1; }

echo "→ WordPress (plugin bind-mounted)"
docker run -d --name "$WP" --network "$NET" \
    -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root \
    -e WORDPRESS_DB_NAME=wp -e WORDPRESS_TABLE_PREFIX="$PREFIX" -e WORDPRESS_DEBUG=1 \
    -v "$VOL":/var/www/html \
    -v "$REPO":/var/www/html/wp-content/plugins/cherrylink-pro-ai:ro \
    wordpress:php8.2-apache >/dev/null
for _ in $(seq 1 90); do docker exec "$WP" test -f /var/www/html/wp-config.php >/dev/null 2>&1 && break; sleep 1; done

wp_cli() {
    docker run --rm --network "$NET" --volumes-from "$WP" \
        -v "$REPO/dev/wp-smoke":/smoke:ro \
        -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root \
        -e WORDPRESS_DB_NAME=wp -e WORDPRESS_TABLE_PREFIX="$PREFIX" \
        wordpress:cli-php8.2 "$@"
}

echo "→ install + activate"
ok=0
for _ in $(seq 1 20); do
    if wp_cli wp core install --url=http://localhost:8080 --title=Smoke --admin_user=admin \
        --admin_password=adminpw --admin_email=smoke@example.invalid --skip-email >/dev/null 2>&1; then ok=1; break; fi
    sleep 3
done
[ "$ok" -eq 1 ] || { echo "✗ WordPress install failed" >&2; exit 1; }
wp_cli wp plugin activate cherrylink-pro-ai >/dev/null
wp_cli wp post create --post_status=publish --post_title="Smoke article" --post_content="<p>Body</p>" --porcelain >/dev/null

echo "→ editor path"
wp_cli wp eval-file /smoke/editor-check.php

echo "✓ editor smoke passed"
