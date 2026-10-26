<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Outbound\Cidr;
use Tinvar\Http\HostName;

/**
 * Whether a custom domain's DNS points at the platform.
 *
 * tinvar-spec.md: custom domains are "deactivated if their DNS no longer
 * points at the platform". A domain points at the platform when either:
 *
 *   - it is an alias (CNAME) of one of the platform's targets, directly or
 *     through a chain of aliases -- the platform answers for the target,
 *     so the target's own addresses are not asked; or
 *   - at the end of its aliases, every A and AAAA address it has is within
 *     the platform's addresses. Every one: a name with one address of the
 *     platform's and one of somebody else's sends part of its visitors --
 *     and a certificate authority's validation -- to that somebody.
 *
 * Three answers (DomainPointing), which CustomDomainVerification acts on:
 * Platform, the check passed; Elsewhere, DNS answered with something that
 * is not the platform, and the domain is deactivated at once; Unknown, a
 * lookup failed, timed out or came back empty, and the domain stays as it
 * was for a grace period. Whatever DNS answered and the platform cannot
 * read as its own -- an alias that is no host name, an address that is no
 * address -- is Elsewhere: someone has pointed the name at something, and
 * it is not here.
 *
 * Bounded, so one slow domain does not hold up the rest: a check makes at
 * most $maxAliases + 2 lookups, each handed what is left of the check's
 * $timeout, and an answer that comes after it is not read. How far a single
 * lookup can be cut short is the resolver's (SystemDnsResolver says how far
 * dns_get_record() can).
 */
final readonly class DomainVerifier
{
    /** @var array<string, true> */
    private array $targets;

    /** @var list<Cidr> */
    private array $addresses;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param list<string> $targets the host names a custom domain may be an alias of, as
     *        `domains.platform.example`: exact names, normalized; a wildcard is not one
     * @param list<string> $addresses the platform's addresses and prefixes, as
     *        `203.0.113.10` or `2001:db8::/48`, that a domain's A and AAAA records may name
     * @param float $timeout seconds one domain's check may take, every lookup in it: under
     *        Caddy's 10 seconds for an ask, with room for the rest of the request
     * @param int $maxAliases how many aliases are followed before the chain is given up as
     *        one that does not end
     * @param (\Closure(): float)|null $clock seconds on a monotonic clock; hrtime()'s unless given
     */
    public function __construct(
        array $targets,
        array $addresses,
        private DnsResolver $resolver = new SystemDnsResolver(),
        private float $timeout = 4.0,
        private int $maxAliases = 8,
        ?\Closure $clock = null,
    ) {
        if ($targets === [] && $addresses === []) {
            throw new \InvalidArgumentException(
                'A DomainVerifier is given the platform\'s CNAME targets, its addresses, or both: with neither, no domain points at it.',
            );
        }

        $named = [];
        foreach ($targets as $target) {
            if (HostName::normalize($target) !== $target) {
                throw new \InvalidArgumentException("'{$target}' is not a CNAME target: a host name, normalized.");
            }
            $named[$target] = true;
        }
        $this->targets = $named;
        $this->addresses = array_map(Cidr::parse(...), $addresses);

        if ($timeout <= 0 || $maxAliases < 1) {
            throw new \InvalidArgumentException('A check is given some time, and at least one alias to follow.');
        }

        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /** Where $host, normalized, points now. A lookup that fails is an answer of its own, Unknown. */
    public function check(string $host): DomainCheck
    {
        $deadline = ($this->clock)() + $this->timeout;
        $name = $host;
        $seen = [$host => true];

        try {
            for ($hop = 0; ; $hop++) {
                $alias = $this->resolver->alias($name, $this->left($deadline, $name));
                $this->inTime($deadline, $name);
                if ($alias === null) {
                    break;
                }

                $next = self::name($alias);
                if ($next === null) {
                    return new DomainCheck(DomainPointing::Elsewhere, "{$name} is an alias of '{$alias}', which is not a host name the platform answers for");
                }
                if (isset($this->targets[$next])) {
                    return new DomainCheck(DomainPointing::Platform, $name === $host ? "an alias of {$next}" : "an alias of {$next}, through {$name}");
                }
                // A loop, or a chain longer than resolvers follow: DNS gives
                // no address for either, and says nothing of whose it is.
                if (isset($seen[$next])) {
                    return new DomainCheck(DomainPointing::Unknown, "its aliases loop back to {$next}");
                }
                if ($hop + 1 >= $this->maxAliases) {
                    return new DomainCheck(DomainPointing::Unknown, "its aliases go on past {$this->maxAliases}");
                }
                $seen[$next] = true;
                $name = $next;
            }

            $addresses = $this->resolver->addresses($name, $this->left($deadline, $name));
            $this->inTime($deadline, $name);
        } catch (DnsLookupFailed $e) {
            return new DomainCheck(DomainPointing::Unknown, $e->getMessage());
        }

        if ($addresses === []) {
            return new DomainCheck(DomainPointing::Unknown, $name === $host ? 'no A or AAAA records' : "no A or AAAA records at {$name}, the end of its aliases");
        }

        foreach ($addresses as $address) {
            if (!$this->ours($address)) {
                return new DomainCheck(DomainPointing::Elsewhere, "it resolves to {$address}, which is not the platform's");
            }
        }

        return new DomainCheck(DomainPointing::Platform, 'it resolves to the platform\'s addresses alone');
    }

    /**
     * An alias as a host name, or null when it is not one. Not
     * HostName::normalize() alone, which reads a Host header and drops a port
     * from its end: a DNS label may hold a colon, and an alias of
     * `domains.platform.example:443` -- a name under a top-level domain
     * that does not exist -- would have been read as the platform's target.
     */
    private static function name(string $alias): ?string
    {
        $name = rtrim(strtolower($alias), '.');

        return HostName::normalize($name) === $name ? $name : null;
    }

    private function ours(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($this->addresses as $prefix) {
            if ($prefix->contains($packed)) {
                return true;
            }
        }

        return false;
    }

    /** What is left of the check's time for the next lookup; a failure when nothing is. */
    private function left(float $deadline, string $name): float
    {
        $left = $deadline - ($this->clock)();
        if ($left <= 0) {
            throw new DnsLookupFailed("{$name} was not looked up: the check ran out of time.");
        }

        return $left;
    }

    /** An answer that came after the check's time is not read: the check failed, as a timeout. */
    private function inTime(float $deadline, string $name): void
    {
        if (($this->clock)() > $deadline) {
            throw new DnsLookupFailed("{$name} answered after the check's time ran out.");
        }
    }
}
