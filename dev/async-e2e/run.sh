#!/usr/bin/env bash
#
# Live WordPress -> API async-indexing flow (no worker, no TEI).
#
# Runs the real plugin code against the real API: capability negotiation, a
# durable bulk run (snapshot -> create -> upload -> seal) and a receipt replay.
# The worker execution leg is NOT exercised here (it needs the private worker
# authority network); the API repo's own harness covers execution.
#
# Requirements: Docker, and the pinned images below (or network access).
# TEI is not started: the API only creates its client lazily and this flow never
# embeds. Qdrant is required for the empty-source proof.
#
#   ./dev/async-e2e/run.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
API_DIR="$(cd "$PLUGIN_DIR/../api" && pwd)"
NET="cl-e2e-net"
ADMIN_KEY="e2e-admin-key-not-a-production-secret"

DB=cl-e2e-db; WP=cl-e2e-wp; PG=cl-e2e-pg; QD=cl-e2e-qdrant; API=cl-e2e-api
VOL_WP=cl-e2e-wpdata; VOL_PG=cl-e2e-pgdata; VOL_QD=cl-e2e-qddata; VOL_API=cl-e2e-apidata

cleanup() {
    if [ "${KEEP:-0}" = "1" ]; then
        echo "KEEP=1: leaving containers up for inspection" >&2
        return 0
    fi
    docker rm -f "$API" "$WP" "$DB" "$PG" "$QD" >/dev/null 2>&1 || true
    docker volume rm "$VOL_WP" "$VOL_PG" "$VOL_QD" "$VOL_API" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

docker network create "$NET" >/dev/null
docker volume create "$VOL_WP" >/dev/null
docker volume create "$VOL_PG" >/dev/null
docker volume create "$VOL_QD" >/dev/null
docker volume create "$VOL_API" >/dev/null

wait_for() {  # wait_for <description> <command...>
    local desc="$1"; shift
    for _ in $(seq 1 120); do
        if "$@" >/dev/null 2>&1; then return 0; fi
        sleep 1
    done
    echo "✗ timed out waiting for $desc" >&2
    return 1
}

echo "→ PostgreSQL + Qdrant"
docker run -d --name "$PG" --network "$NET" \
    -e POSTGRES_USER=cherrylink -e POSTGRES_PASSWORD=cherrylink -e POSTGRES_DB=cherrylink \
    -v "$VOL_PG":/var/lib/postgresql/data postgres:16-alpine >/dev/null
docker run -d --name "$QD" --network "$NET" -v "$VOL_QD":/qdrant/storage \
    qdrant/qdrant:latest >/dev/null
wait_for "postgres" docker exec "$PG" pg_isready -U cherrylink -d cherrylink
for _ in $(seq 1 60); do
    docker run --rm --network "$NET" curlimages/curl:latest -sf "http://$QD:6333/readyz" >/dev/null 2>&1 && break
    sleep 1
done

echo "→ API (async enabled)"
docker build -q -t cl-e2e-api:local "$API_DIR/api" >/dev/null
docker run -d --name "$API" --network "$NET" -p 18092:8000 \
    -e TEI_URL=http://tei.invalid:80 \
    -e QDRANT_URL="http://$QD:6333" \
    -e DATABASE_URL="postgresql://cherrylink:cherrylink@$PG:5432/cherrylink" \
    -e ADMIN_KEY="$ADMIN_KEY" \
    -e LICENSING_URL=https://licensing.invalid \
    -e ASYNC_INDEXING_ENABLED=1 \
    -e ASYNC_INDEXING_MODE=async \
    cl-e2e-api:local >/dev/null
wait_for "api health" docker run --rm --network "$NET" curlimages/curl:latest -sf "http://$API:8000/health"

echo "→ MySQL + WordPress"
docker run -d --name "$DB" --network "$NET" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp mysql:8.0 >/dev/null
ready=0
for _ in $(seq 1 120); do
    if docker exec "$DB" mysql -uroot -proot --ssl-mode=DISABLED -N -e 'SELECT 1' -D wp >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" -eq 1 ] || { echo "✗ MySQL not ready" >&2; exit 1; }

docker run -d --name "$WP" --network "$NET" \
    -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root \
    -e WORDPRESS_DB_NAME=wp -e WORDPRESS_TABLE_PREFIX=clsmk_ \
    -v "$VOL_WP":/var/www/html \
    -v "$PLUGIN_DIR":/var/www/html/wp-content/plugins/cherrylink-pro-ai:ro \
    wordpress:php8.2-apache >/dev/null
wait_for "wp-config" docker exec "$WP" test -f /var/www/html/wp-config.php

wp_cli() {
    docker run --rm --network "$NET" --volumes-from "$WP" \
        -v "$PLUGIN_DIR/dev/async-e2e":/e2e:ro \
        -e E2E_API_URL="http://$API:8000" -e E2E_API_KEY="$ADMIN_KEY" \
        -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root \
        -e WORDPRESS_DB_NAME=wp -e WORDPRESS_TABLE_PREFIX=clsmk_ \
        wordpress:cli-php8.2 "$@"
}

echo "→ install WordPress + activate plugin"
install_ok=0
for _ in $(seq 1 20); do
    if wp_cli wp core install --url=http://localhost:8080 --title=E2E --admin_user=admin \
        --admin_password=adminpw --admin_email=e2e@example.invalid --skip-email >/dev/null 2>&1; then
        install_ok=1; break
    fi
    sleep 3
done
[ "$install_ok" -eq 1 ] || { echo "✗ WordPress install failed" >&2; exit 1; }
wp_cli wp plugin activate cherrylink-pro-ai >/dev/null
wp_cli wp eval-file /e2e/configure.php >/dev/null

echo "→ publish 3 posts"
for i in 1 2 3; do
    wp_cli wp post create --post_status=publish --post_title="E2E post $i" \
        --post_content="<p>Body $i with <a href=\"/e2e-post-1/\">link</a></p>" --porcelain >/dev/null
done

echo "→ establish the plugin source epoch"
EPOCH="$(wp_cli wp eval-file /e2e/epoch.php | tail -1 | tr -d '\r')"
[ -n "$EPOCH" ] || { echo "✗ no source epoch" >&2; exit 1; }
echo "   epoch=$EPOCH"

echo "→ simulate the operator mode switch (bind epoch, async, snapshot required)"
docker exec "$PG" psql -U cherrylink -d cherrylink -v ON_ERROR_STOP=1 -q -c "
INSERT INTO index_tenant_state (client_id, source_epoch, mode, source_bound, source_ready, snapshot_required)
VALUES ('localhost', '$EPOCH'::uuid, 'async', TRUE, TRUE, TRUE)
ON CONFLICT (client_id) DO UPDATE
  SET source_epoch = EXCLUDED.source_epoch,
      mode = 'async', source_bound = TRUE, source_ready = TRUE, snapshot_required = TRUE,
      active_run_id = NULL, updated_at = NOW();
"

echo "→ plugin <-> API flow"
wp_cli wp eval-file /e2e/flow.php
