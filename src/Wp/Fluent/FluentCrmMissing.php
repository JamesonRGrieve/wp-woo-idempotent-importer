<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

use RuntimeException;

/** CRM data can't be written: FluentCRM, its WordPress home, isn't active. The object fails and is retried next run. */
final class FluentCrmMissing extends RuntimeException {}
