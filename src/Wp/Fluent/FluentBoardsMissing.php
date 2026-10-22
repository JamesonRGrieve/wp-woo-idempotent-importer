<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Fluent;

use RuntimeException;

/** CRM pipelines, deals and tasks can't be written: FluentBoards, their WordPress home, isn't active. Retried next run. */
final class FluentBoardsMissing extends RuntimeException {}
