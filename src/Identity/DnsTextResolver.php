<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * DNS TXT records, as a custom domain's proof of ownership is read
 * (tinvar/tenancy's DomainOwnership, ADR 0155).
 *
 * A separate interface from DnsResolver, which DomainVerifier asks: where a
 * domain points says nothing of who controls it -- a name a tenant gave up
 * can go on pointing at the platform for years -- so a claim is proved by a
 * record only the name's owner can publish, and that is a TXT record.
 * SystemDnsResolver answers both; an application's own resolver answers
 * this one too wherever its tenants add domains themselves.
 *
 * As DnsResolver: no records is an empty answer, a lookup that did not
 * complete throws.
 */
interface DnsTextResolver
{
    /**
     * Every TXT record at $name, each one's strings joined as DNS splits
     * them; [] when it has none.
     *
     * @param string $name lowercase, no trailing dot; it may carry a leading `_label`
     * @param float $timeout seconds the lookup may take
     * @return list<string>
     * @throws DnsLookupFailed
     */
    public function texts(string $name, float $timeout): array;
}
