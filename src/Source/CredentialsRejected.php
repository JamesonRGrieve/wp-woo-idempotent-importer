<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source;

/**
 * The source refused the credentials (401, or 403 for a missing scope or a block). Retrying with the same
 * credentials can get the key or account locked, so the run stops and scheduled runs hold off until the
 * operator saves the settings or starts a run by hand (see Sync\CredentialHold).
 */
final class CredentialsRejected extends SourceError {}
