<?php

declare(strict_types=1);

namespace Tinvar\Api;

/** What a user chooses when they ask for a token, as a refusal names it (ApiTokenRefused). */
enum ApiTokenChoice: string
{
    case Name = 'name';
    case Scopes = 'scopes';
    case Expiry = 'expiry';

    /** Not one choice: they hold as many tokens as they may. */
    case Count = 'count';

    /**
     * Not one choice: who is asking may not have one made -- a request a
     * token signed in, or staff impersonating the user. Refused with a 403.
     */
    case Caller = 'caller';
}
