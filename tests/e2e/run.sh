#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# End-to-end suite: a real WordPress + WooCommerce (HPOS) + MariaDB, importing from fake stores of every
# built source (SOURCES, in import order; the same list as SOURCES in assert.php) that serve the recorded
# fixtures. For each source it proves the import, that an unchanged re-run writes nothing (content tables
# byte-identical), that changes and deletions land; that one source's runs never touch another's mirror;
# and Shopify and Square webhook authentication. Everything it creates is removed on exit.
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
ALL_SOURCES=(shopify etsy square_online bigcommerce squarespace wix magento lightspeed ecwid webflow hubspot salesforce pipedrive zoho freshbooks wave)
# What changes between each source's two fake store states.
declare -A CHANGES=(
    [shopify]="updates, a new order, a deletion and a completed draft"
    [etsy]="a price, a removed listing, a refund, a new receipt and review"
    [square_online]="a price, a deleted item, a refund, a return, an exchange, a new order and customer, and an order WooCommerce Square pushed"
    [bigcommerce]="a price, a deleted product, a refund, a new order, customer, post and review, and a gift certificate spent"
    [squarespace]="a price, a deleted product, a refund, a new order and customer"
    [wix]="a price, a deleted product, a refund, a new order, contact and post"
    [magento]="a price, a deleted product, a refund, a new order, customer and page"
    [lightspeed]="a price, a deleted product, a credit note, a new order, customer, article and review"
    [ecwid]="a price, a deleted product, a refund, a new order, customer and review"
    [webflow]="a price, a refunded order, a new order and customer, a new post and a removed redirect"
    [hubspot]="a custom property and a company field, a contact leaving and one joining a segment, a new contact with a note, a price and a new order"
    [salesforce]="a custom account field, a new lead in a campaign with a note, a changed note, a price and a new draft order"
    [pipedrive]="a removed label, a person leaving and one joining a filter, a custom organization field, a changed note and a price"
    [zoho]="a custom account field, a new lead in a campaign with a note, a changed note, a price and a new sales order"
    [freshbooks]="a price and stock count, a changed phone, a new client and organization, a payment settling an invoice, and a disputed invoice"
    [wave]="a price, a changed phone, a payment settling a guest invoice, and a new partly paid invoice"
)
# E2E_SOURCES (space-separated) runs only those sources, in the order above, for a quicker check while developing
# one; the full suite (the default, and CI) runs them all, each beside the mirrors of the ones before it.
SOURCES=()
for source in "${ALL_SOURCES[@]}"; do
    if [ -z "${E2E_SOURCES:-}" ] || [[ " ${E2E_SOURCES} " == *" ${source} "* ]]; then
        SOURCES+=("$source")
    fi
done
# FluentCRM (free, GPL), the WordPress home of CRM data, at the version the importer is analysed against.
FLUENTCRM_VERSION=3.2.6

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

# Which fixture state (v1, v2) a source's fake store serves.
source_state() {
    echo "$2" > "$STATE_DIR/$1-state"
    echo "== $1 store state: $2"
}

# One source, imported beside the mirrors of the sources before it (tests/e2e/checks/<source>.php): the
# first full import, full and incremental re-runs of the unchanged store that must write nothing, then the
# changed store ($2 says what changed) and a re-run of it that must write nothing.
import_source() {
    local source="$1" changes="$2"
    echo "== $source: first full import"
    wp iim-e2e configure "$source"
    wp idempotent-import run --full
    wp iim-e2e state "$source" v1

    echo "== $source unchanged: full and incremental re-runs must write nothing"
    wp iim-e2e checksum "/state/checksum-$source-v1"
    wp idempotent-import run --full
    wp iim-e2e zero-writes "/state/checksum-$source-v1"
    wp idempotent-import run
    wp iim-e2e zero-writes "/state/checksum-$source-v1"

    source_state "$source" v2
    echo "== $source changed: $changes"
    wp idempotent-import run --full
    wp iim-e2e state "$source" v2
    wp iim-e2e checksum "/state/checksum-$source-v2"
    wp idempotent-import run --full
    wp iim-e2e zero-writes "/state/checksum-$source-v2"
    wp iim-e2e no-mail
}

podman network create "$NETWORK" >/dev/null
podman volume create "$WP_VOLUME" >/dev/null
chmod 0777 "$STATE_DIR"
echo "${SOURCES[*]}" > "$STATE_DIR/sources"
podman run -d --name "$DB" --network "$NETWORK" --network-alias db \
    -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp -e MARIADB_RANDOM_ROOT_PASSWORD=1 \
    "$DB_IMAGE" >/dev/null
FAKE_ALIASES=(--network-alias square_stored_value-fake)
source_state square_stored_value v1
for source in "${SOURCES[@]}"; do
    source_state "$source" v1
    FAKE_ALIASES+=(--network-alias "$source-fake")
done
podman run -d --name "$FAKE" --network "$NETWORK" "${FAKE_ALIASES[@]}" \
    -v "$ROOT:/app:ro" -v "$STATE_DIR:/state" \
    "$PHP_IMAGE" php -S 0.0.0.0:8080 /app/tests/e2e/fake-store.php >/dev/null

podman run --rm --user 0:0 -v "$WP_VOLUME:/var/www/html" "$WP_IMAGE" chown -R 33:33 /var/www/html
wp core download --quiet
PLUGIN_MOUNT=(-v "$ROOT:$PLUGIN_PATH:ro")
wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=db --skip-check --extra-php < "$ROOT/tests/e2e/wp-config-extra.php"
wait_for_db
wp core install --url=http://localhost --title=E2E --admin_user=e2e --admin_email=e2e@example.com --skip-email --quiet
wp plugin install woocommerce --activate --quiet
wp plugin install fluent-crm --version="$FLUENTCRM_VERSION" --activate --quiet
podman run --rm --user 33:33 -v "$WP_VOLUME:/var/www/html" -v "$ROOT:/src:ro" "$WP_IMAGE" \
    sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cp /src/tests/e2e/mu-plugin.php /var/www/html/wp-content/mu-plugins/iim-e2e.php'
wp plugin activate "$PLUGIN_SLUG"

for source in "${SOURCES[@]}"; do
    import_source "$source" "${CHANGES[$source]}"
    case "$source" in
        shopify) echo "== Shopify webhooks"; wp iim-e2e webhook ;;
        square_online) echo "== Square webhooks"; wp iim-e2e square-webhook ;;
    esac
done

echo "== Lockout safety: a rate-limited source pauses the run; rejected credentials stop it and hold scheduled runs"
wp iim-e2e lockout

echo "== Plugin errors and warnings logged by WordPress"
podman run --rm -v "$WP_VOLUME:/var/www/html" "$WP_IMAGE" sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true'
echo "E2E PASSED"
