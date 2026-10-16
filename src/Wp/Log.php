<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use WC_Logger_Interface;

/** The WooCommerce logger (WooCommerce → Status → Logs, source "idempotent-import"). */
final class Log
{
    public const SOURCE = 'idempotent-import';

    public function __construct(private readonly WC_Logger_Interface $logger) {}

    public static function woocommerce(): self
    {
        return new self(wc_get_logger());
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, ['source' => self::SOURCE, ...$context]);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, ['source' => self::SOURCE, ...$context]);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, ['source' => self::SOURCE, ...$context]);
    }
}
