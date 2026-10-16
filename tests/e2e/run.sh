#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# End-to-end suite: a real WordPress + WooCommerce (HPOS) + MariaDB, importing from a fake Shopify and a
# fake Etsy that serve the recorded fixtures. For each source it proves the import, that an unchanged
# re-run writes nothing (content tables byte-identical), that changes and deletions land; and that one
# source's runs never touch another's mirror, and Shopify webhook authentication. Everything it creates
# is removed on exit.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PREFIX="iim-e2e-$$"
NETWORK="${PREFIX}-net"
DB="${PREFIX}-db"
FAKE="${PREFIX}-fake"
WP_VOLUME="${PREFIX}-wp"
STATE_DIR="$(mktemp -d)"
DB_IMAGE="docker.io/library/mariadb:11.4"
WP_IMAGE="docker.io/library/wordpress:cli-php8.3"
PHP_IMAGE="docker.io/library/php:8.4-cli"
PLUGIN_SLUG="wp-woo-idempotent-importer"
PLUGIN_PATH="/var/www/html/wp-content/plugins/$PLUGIN_SLUG"
DB_READY_ATTEMPTS=120
DB_READY_INTERVAL=1
WP_CLI_MEMORY=1G

# Optional: keep the image/container store somewhere else (e.g. local disk when $HOME is on NFS).
if [ -n "${E2E_PODMAN_ROOT:-}" ]; then
    mkdir -p "$E2E_PODMAN_ROOT"
    podman() { command podman --root "$E2E_PODMAN_ROOT" "$@"; }
fi

cleanup() {
    local status=$?
    if [ "$status" -ne 0 ]; then
        echo "== Importer log (WooCommerce → Status → Logs)"
        podman run --rm -v "$WP_VOLUME:/var/www/html" "$WP_IMAGE" sh -c 'cat /var/www/html/wp-content/uploads/wc-logs/idempotent-import-*.log 2>/dev/null || true' || true
    fi
    podman rm -f "$DB" "$FAKE" >/dev/null 2>&1 || true
    podman volume rm -f "$WP_VOLUME" >/dev/null 2>&1 || true
    podman network rm -f "$NETWORK" >/dev/null 2>&1 || true
    rm -rf "$STATE_DIR"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# WP-CLI as www-data. The plugin is mounted only once WordPress has created wp-content itself (a bind
# mount would otherwise create its parent directories as root).
PLUGIN_MOUNT=()
wp() {
    podman run --rm -i --network "$NETWORK" --user 33:33 \
        -v "$WP_VOLUME:/var/www/html" \
        "${PLUGIN_MOUNT[@]}" \
        -v "$STATE_DIR:/state" \
        "$WP_IMAGE" php -d memory_limit="$WP_CLI_MEMORY" /usr/local/bin/wp "$@"
}

# MariaDB's image first runs a socket-only server to initialise, so readiness is a TCP ping: it only
# answers once the real server is listening for WordPress.
wait_for_db() {
    for _ in $(seq "$DB_READY_ATTEMPTS"); do
        podman exec "$DB" mariadb-admin ping -h 127.0.0.1 --protocol=tcp -uwp -pwp --silent >/dev/null 2>&1 && return 0
        sleep "$DB_READY_INTERVAL"
    done
    echo "MariaDB never became ready" >&2
    return 1
}

store_state() {
    echo "$1" > "$STATE_DIR/store-state"
    echo "== Shopify store state: $1"
}

etsy_state() {
    echo "$1" > "$STATE_DIR/etsy-state"
    echo "== Etsy store state: $1"
}

podman network create "$NETWORK" >/dev/null
podman volume create "$WP_VOLUME" >/dev/null
chmod 0777 "$STATE_DIR"
podman run -d --name "$DB" --network "$NETWORK" --network-alias db \
    -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp -e MARIADB_RANDOM_ROOT_PASSWORD=1 \
    "$DB_IMAGE" >/dev/null
store_state v1
etsy_state v1
podman run -d --name "$FAKE" --network "$NETWORK" --network-alias shopify-fake --network-alias etsy-fake \
    -v "$ROOT:/app:ro" -v "$STATE_DIR:/state" \
    "$PHP_IMAGE" php -S 0.0.0.0:8080 /app/tests/e2e/fake-store.php >/dev/null

podman run --rm --user 0:0 -v "$WP_VOLUME:/var/www/html" "$WP_IMAGE" chown -R 33:33 /var/www/html
wp core download --quiet
PLUGIN_MOUNT=(-v "$ROOT:$PLUGIN_PATH:ro")
wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=db --skip-check --extra-php < "$ROOT/tests/e2e/wp-config-extra.php"
wait_for_db
wp core install --url=http://localhost --title=E2E --admin_user=e2e --admin_email=e2e@example.com --skip-email --quiet
wp plugin install woocommerce --activate --quiet
podman run --rm --user 33:33 -v "$WP_VOLUME:/var/www/html" -v "$ROOT:/src:ro" "$WP_IMAGE" \
    sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cp /src/tests/e2e/mu-plugin.php /var/www/html/wp-content/mu-plugins/iim-e2e.php'
wp plugin activate "$PLUGIN_SLUG"
wp iim-e2e configure

echo "== First full import"
wp idempotent-import run --full
wp iim-e2e v1

echo "== Unchanged store: full and incremental re-runs must write nothing"
wp iim-e2e checksum /state/checksum-v1
wp idempotent-import run --full
wp iim-e2e zero-writes /state/checksum-v1
wp idempotent-import run
wp iim-e2e zero-writes /state/checksum-v1

store_state v2
echo "== Changed store: updates, a new order, a deletion and a completed draft"
wp idempotent-import run --full
wp iim-e2e v2
wp iim-e2e checksum /state/checksum-v2
wp idempotent-import run --full
wp iim-e2e zero-writes /state/checksum-v2

wp iim-e2e no-mail

echo "== Webhooks"
wp iim-e2e webhook

echo "== Etsy: first full import, beside the Shopify mirror"
wp iim-e2e etsy-configure
wp idempotent-import run --full
wp iim-e2e etsy-v1

echo "== Etsy unchanged: full and incremental re-runs must write nothing"
wp iim-e2e checksum /state/checksum-etsy-v1
wp idempotent-import run --full
wp iim-e2e zero-writes /state/checksum-etsy-v1
wp idempotent-import run
wp iim-e2e zero-writes /state/checksum-etsy-v1

etsy_state v2
echo "== Etsy changed: a price, a removed listing, a refund, a new receipt and review"
wp idempotent-import run --full
wp iim-e2e etsy-v2
wp iim-e2e checksum /state/checksum-etsy-v2
wp idempotent-import run --full
wp iim-e2e zero-writes /state/checksum-etsy-v2
wp iim-e2e no-mail

echo "== Plugin errors and warnings logged by WordPress"
podman run --rm -v "$WP_VOLUME:/var/www/html" "$WP_IMAGE" sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true'
echo "E2E PASSED"
