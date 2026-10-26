<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Tenancy\Host;

/**
 * What the application holds about one tenant's custom domain, as far as
 * checking its DNS goes: the host, whether it is in service, and how its
 * last checks went.
 *
 * The framework never stores a domain. The application keeps its own
 * records (CustomDomains) and hands one of these over for each domain it
 * asks about, or is asked to check.
 */
final readonly class CustomDomain
{
    /**
     * @param string $host normalized, as Host::normalize() leaves it
     * @param bool $active whether the application serves it: an inactive domain is never
     *        resolved, and never made active again by the framework
     * @param ?\DateTimeImmutable $checkedAt when it was last checked, passed or failed; null
     *        for never
     * @param ?\DateTimeImmutable $failingSince when the unbroken run of failed checks it is in
     *        began -- the first failure after its last pass -- or null when its last check
     *        passed, or it has none
     */
    public function __construct(
        public string $host,
        public bool $active,
        public ?\DateTimeImmutable $checkedAt = null,
        public ?\DateTimeImmutable $failingSince = null,
    ) {
        // One spelling per host, the one a Host header is compared in: a
        // record kept as `Shop.Example.` would be looked up, resolved and
        // deactivated under a name no request ever arrives with.
        if (Host::normalize($host) !== $host) {
            throw new \InvalidArgumentException("'{$host}' is not a normalized host name: lowercase, no port, no trailing dot.");
        }
    }
}
