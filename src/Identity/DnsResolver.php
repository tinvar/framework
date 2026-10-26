<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * DNS as DomainVerifier asks it: what a name is an alias of, and what
 * addresses it has. SystemDnsResolver, through dns_get_record(), unless the
 * application gives another -- one asking a nameserver over a socket with a
 * deadline of its own, say, which can stop a lookup that dns_get_record()
 * cannot.
 *
 * A name with no records of a kind is an empty answer, not a failure: the
 * verifier decides what nothing means. A lookup that did not complete --
 * a server failure, a timeout, no time left to start it -- throws.
 */
interface DnsResolver
{
    /**
     * The name $name is an alias of, by its CNAME record, or null when it
     * has none.
     *
     * @param string $name normalized
     * @param float $timeout seconds the lookup may take
     * @throws DnsLookupFailed
     */
    public function alias(string $name, float $timeout): ?string;

    /**
     * Every A and AAAA address $name has, as text; [] when it has none.
     *
     * @param string $name normalized, and no alias
     * @param float $timeout seconds the lookups may take, together
     * @return list<string>
     * @throws DnsLookupFailed
     */
    public function addresses(string $name, float $timeout): array;
}
