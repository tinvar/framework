<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/** Where a custom domain's DNS was found to point (DomainVerifier). */
enum DomainPointing: string
{
    /** At the platform: an alias of one of its targets, or only its addresses. */
    case Platform = 'platform';

    /**
     * DNS answered, and the answer is not the platform: someone else now
     * controls where the name goes.
     */
    case Elsewhere = 'elsewhere';

    /**
     * DNS did not answer in a way that says either: a lookup failed or
     * timed out, came back empty, or the chain of aliases did not end.
     */
    case Unknown = 'unknown';
}
