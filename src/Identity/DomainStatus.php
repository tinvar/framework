<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/** What became of a custom domain when it was checked (CustomDomainVerification). */
enum DomainStatus: string
{
    /** Its DNS points at the platform, recorded as a passed check. */
    case Verified = 'verified';

    /**
     * Its DNS could not be read, recorded as a failed check; it stays as it
     * was, inside its grace period.
     */
    case Unverified = 'unverified';

    /** Deactivated: its DNS points elsewhere, or could not be read for longer than the grace period. */
    case Deactivated = 'deactivated';

    /** Not checked: the application holds it inactive already, so it is not resolved. */
    case Inactive = 'inactive';
}
