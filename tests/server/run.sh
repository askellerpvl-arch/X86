#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SUFFIX="${RANDOM}-$$"
NETWORK="x86-regression-$SUFFIX"
DATABASE="x86-regression-db-$SUFFIX"
cleanup() { docker rm -f "$DATABASE" >/dev/null 2>&1 || true; docker network rm "$NETWORK" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker build --secret id=proxy_ca,src=/etc/ssl/certs/ca-certificates.crt -t x86-regression-php "$ROOT/tests/server"
docker network create --internal "$NETWORK" >/dev/null
docker run -d --name "$DATABASE" --network "$NETWORK" --network-alias x86-fixture-db \
    -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=x86_test mariadb:11.4 >/dev/null
ready=false
for attempt in $(seq 1 40); do
    if docker exec "$DATABASE" mariadb-admin --host=127.0.0.1 --protocol=tcp ping --silent >/dev/null 2>&1; then ready=true; break; fi
    sleep 1
done
[[ "$ready" == true ]] || { echo 'Fixture database did not start' >&2; exit 1; }
docker run --rm --network "$NETWORK" --mount "type=bind,src=$ROOT/tests/server,dst=/tests,readonly" \
    x86-regression-php php regression.php
