<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\OAuth2;

/** How an app authenticates at a token endpoint (RFC 6749 §2.3.1). */
enum ClientAuth
{
    /** client_id and client_secret in the form body. */
    case Form;
    /** HTTP Basic with client_id:client_secret. */
    case Basic;
}
