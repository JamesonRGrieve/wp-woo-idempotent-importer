// SPDX-License-Identifier: AGPL-3.0-or-later
// End-to-end environment: the importer talks to the fake store container (Shopify, Etsy, Square and BigCommerce).
define('IIM_SHOPIFY_ENDPOINT', 'http://shopify-fake:8080/admin/api/graphql.json');
define('IIM_SHOPIFY_ACCESS_TOKEN', 'e2e-token');
define('IIM_ETSY_ENDPOINT', 'http://etsy-fake:8080');
define('IIM_ETSY_KEYSTRING', 'e2e-keystring');
define('IIM_ETSY_SHARED_SECRET', 'e2e-shared-secret');
define('IIM_SQUARE_ONLINE_ENDPOINT', 'http://square_online-fake:8080');
define('IIM_SQUARE_ONLINE_ACCESS_TOKEN', 'e2e-square-token');
define('IIM_BIGCOMMERCE_ENDPOINT', 'http://bigcommerce-fake:8080');
define('IIM_BIGCOMMERCE_STORE_HASH', 'bcfix123');
define('IIM_BIGCOMMERCE_ACCESS_TOKEN', 'e2e-bigcommerce-token');
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
