<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->exclude(['e2e/wordpress'])
    ->append([
        __DIR__ . '/wp-woo-idempotent-importer.php',
        __DIR__ . '/uninstall.php',
        __FILE__,
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
