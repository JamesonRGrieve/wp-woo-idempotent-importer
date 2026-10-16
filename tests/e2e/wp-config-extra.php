// SPDX-License-Identifier: AGPL-3.0-or-later
// End-to-end environment: the importer talks to the fake Shopify container.
define('IIM_SHOPIFY_ENDPOINT', 'http://shopify-fake:8080/admin/api/graphql.json');
define('IIM_SHOPIFY_ACCESS_TOKEN', 'e2e-token');
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
