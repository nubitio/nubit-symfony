#!/usr/bin/env bash
# Runs the integration suite against a throwaway PostgreSQL server.
#
# The suite needs a real PostgreSQL: the code under test uses Doctrine SQL
# filters, PostgreSQL schemas, search_path switching and pg_dump. Locally this
# script provisions the server and the PHP runtime through Docker. In CI the
# database is a service container, so NUBIT_TEST_DATABASE_URL is already set and
# phpunit runs directly.
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ -n "${NUBIT_TEST_DATABASE_URL:-}" ]]; then
    exec php -d memory_limit=512M vendor/bin/phpunit --testsuite integration "$@"
fi

command -v docker >/dev/null 2>&1 || {
    echo "docker is required to provision the test database, or set NUBIT_TEST_DATABASE_URL yourself." >&2
    exit 1
}

CONTAINER="nubit-integration-postgres"
NETWORK="nubit-integration-net"
PHP_IMAGE="${NUBIT_TEST_PHP_IMAGE:-nubit-integration-php}"
PG_IMAGE="postgres:16-alpine"
PG_PASSWORD="integration"

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup

# PHP with pdo_pgsql. Built once and reused; rebuilding is a no-op when cached.
if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
    echo "Building $PHP_IMAGE (php + pdo_pgsql)..."
    docker build -q -t "$PHP_IMAGE" -f docker/integration/Dockerfile docker/integration >/dev/null
fi

docker network create "$NETWORK" >/dev/null

echo "Starting ${PG_IMAGE}..."
docker run -d --rm \
    --name "$CONTAINER" \
    --network "$NETWORK" \
    -e POSTGRES_PASSWORD="$PG_PASSWORD" \
    -e POSTGRES_USER=integration \
    -e POSTGRES_DB=integration \
    --health-cmd='pg_isready -U integration -d integration' \
    --health-interval=1s \
    --health-timeout=3s \
    --health-retries=30 \
    "$PG_IMAGE" >/dev/null

printf 'Waiting for PostgreSQL'
for _ in $(seq 1 60); do
    if [[ "$(docker inspect -f '{{.State.Health.Status}}' "$CONTAINER" 2>/dev/null)" == "healthy" ]]; then
        echo " ready."
        break
    fi
    printf '.'
    sleep 1
done

DATABASE_URL="postgresql://integration:${PG_PASSWORD}@${CONTAINER}:5432/integration?serverVersion=16&charset=utf8"

docker run --rm \
    --network "$NETWORK" \
    -v "$PWD":/app \
    -w /app \
    -e NUBIT_TEST_DATABASE_URL="$DATABASE_URL" \
    -e NUBIT_TEST_DATABASE_HOST="$CONTAINER" \
    -e NUBIT_TEST_DATABASE_USER=integration \
    -e NUBIT_TEST_DATABASE_PASSWORD="$PG_PASSWORD" \
    "$PHP_IMAGE" \
    php -d memory_limit=512M vendor/bin/phpunit --testsuite integration "$@"
