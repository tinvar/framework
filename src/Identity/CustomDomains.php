<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * The application's records of its tenants' custom domains, as the
 * re-verification reads and writes them.
 *
 * tinvar-spec.md, "Identity across domains": "Custom domains are
 * re-verified daily and at every certificate renewal, and deactivated if
 * their DNS no longer points at the platform." The framework never stores a
 * domain: what a tenant added, how they proved it was theirs and when it
 * went into service are the application's. What the framework owes is the
 * check after that -- CustomDomainVerification, daily (VerifyCustomDomains)
 * and whenever a certificate is asked for (TlsAskEndpoint) -- and it
 * reports each outcome here.
 *
 * A domain deactivated here must stop being one Realms looks up: the
 * application's realm lookup reads these same records, active ones only.
 * The framework never makes a domain active again. Putting one back in
 * service is the application's, after the tenant proves it is theirs anew,
 * since the name may by then be somebody else's.
 *
 * Every host is normalized, as HostName::normalize() leaves it.
 */
interface CustomDomains
{
    /**
     * The active domains due a check: not checked since $since -- never
     * checked, or last checked before it -- the longest unchecked first, at
     * most $limit of them. A domain recorded by passed() or failed() at or
     * after $since is not due again.
     *
     * @param int<1, max> $limit
     * @return list<CustomDomain>
     */
    public function due(\DateTimeImmutable $since, int $limit): array;

    /** The record for a host, active or not; null when the application holds none. */
    public function find(string $host): ?CustomDomain;

    /**
     * A check passed at $at: the host's DNS points at the platform. It is
     * the domain's last check, and any run of failures it was in is over:
     * its failingSince is cleared.
     */
    public function passed(string $host, \DateTimeImmutable $at): void;

    /**
     * A check at $at could not tell where the host points -- a lookup that
     * failed, timed out or came back empty. It is the domain's last check;
     * $failingSince is when the unbroken run of failures it extends began,
     * kept as the domain's failingSince. The domain stays as it was.
     */
    public function failed(string $host, \DateTimeImmutable $at, \DateTimeImmutable $failingSince): void;

    /**
     * The host is no longer to be served, from $at, because of $reason --
     * the platform's words for what its DNS said, for the tenant and the
     * operator. Afterwards find() reports it inactive, due() leaves it out,
     * and the application's realm lookup no longer answers for it.
     */
    public function deactivate(string $host, string $reason, \DateTimeImmutable $at): void;
}
