<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Webflow\Mapping;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Mapper;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Source\Node;

/**
 * The site → store settings: its name, timezone and (for an e-commerce site) its default currency. Webflow's
 * API has no store address, email, weight unit or tax-inclusive setting, so those settings are left as they
 * are. The site's short name, domains, locales and data collection setting are kept with the shop details.
 * Webflow has no policy pages of its own (they are ordinary pages), so policy pages are left alone.
 */
final class SiteMapper implements Mapper
{
    private const VERSION = 1;
    private const DETAILS = ['shortName', 'customDomains', 'locales', 'createdOn', 'dataCollectionEnabled', 'dataCollectionType'];

    public function kind(): Kind
    {
        return Kind::Shop;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function map(Node $node): Desired
    {
        return new Desired(Kind::Shop, $node->id(), [
            'name' => $node->optionalString('displayName') ?? '',
            'email' => null,
            'currency' => $node->child('ecommerce')?->optionalString('defaultCurrency'),
            'weight_unit' => null,
            'timezone' => $node->optionalString('timeZone'),
            'prices_include_tax' => null,
            'address' => null,
            'store' => $node->only(self::DETAILS),
            'policies' => null,
        ]);
    }
}
