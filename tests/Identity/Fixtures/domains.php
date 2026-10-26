<?php

declare(strict_types=1);

/**
 * Custom domains for the re-verification's tests: DNS from a table, the
 * application's records in memory, and a clock a test moves.
 */

namespace Tinvar\Tests\Identity\Fixtures\Domains;

use Tinvar\Identity\CustomDomain;
use Tinvar\Identity\CustomDomains;
use Tinvar\Identity\CustomDomainVerification;
use Tinvar\Identity\DnsLookupFailed;
use Tinvar\Identity\DnsResolver;
use Tinvar\Identity\DomainVerifier;

/** The platform's CNAME target and its addresses. */
const TARGET = 'domains.platform.example';
const ADDRESSES = ['203.0.113.0/24', '2001:db8:10::/48'];

/**
 * DNS from a table. Each name answers a CNAME, addresses, or a failure;
 * a name not in the table has nothing at all, as NXDOMAIN. Every lookup
 * is kept, and may cost time on a clock the verifier reads.
 */
final class Dns implements DnsResolver
{
    /** @var list<string> every name asked about, in order */
    public array $asked = [];

    public float $now = 0.0;

    /**
     * @param array<string, string|list<string>|DnsLookupFailed> $names a CNAME target, the A and
     *        AAAA addresses, or the failure a lookup of the name meets
     * @param array<string, float> $costs seconds a lookup of a name takes
     */
    public function __construct(public array $names = [], private readonly array $costs = []) {}

    public function alias(string $name, float $timeout): ?string
    {
        $answer = $this->ask($name);

        return \is_string($answer) ? $answer : null;
    }

    public function addresses(string $name, float $timeout): array
    {
        $answer = $this->ask($name);

        return \is_array($answer) ? $answer : [];
    }

    /** @return string|list<string>|null */
    private function ask(string $name): string|array|null
    {
        $this->asked[] = $name;
        $this->now += $this->costs[$name] ?? 0.001;
        $answer = $this->names[$name] ?? null;
        if ($answer instanceof DnsLookupFailed) {
            throw $answer;
        }

        return $answer;
    }

    public function clock(): \Closure
    {
        return fn (): float => $this->now;
    }
}

/** The application's custom-domain records, in memory. */
final class Records implements CustomDomains
{
    /** @var array<string, CustomDomain> */
    public array $domains = [];

    /** @var array<string, string> host => why it was deactivated */
    public array $reasons = [];

    /** @var list<string> every host find() was asked for */
    public array $found = [];

    /** @var array<string, true> hosts whose writes throw, a database that is down for them */
    public array $broken = [];

    public function __construct(CustomDomain ...$domains)
    {
        foreach ($domains as $domain) {
            $this->domains[$domain->host] = $domain;
        }
    }

    public function due(\DateTimeImmutable $since, int $limit): array
    {
        $due = array_filter($this->domains, static fn (CustomDomain $d): bool => $d->active && ($d->checkedAt === null || $d->checkedAt < $since));
        usort($due, static fn (CustomDomain $a, CustomDomain $b): int => ($a->checkedAt?->getTimestamp() ?? 0) <=> ($b->checkedAt?->getTimestamp() ?? 0));

        return \array_slice($due, 0, $limit);
    }

    public function find(string $host): ?CustomDomain
    {
        $this->found[] = $host;

        return $this->domains[$host] ?? null;
    }

    public function passed(string $host, \DateTimeImmutable $at): void
    {
        $this->write($host, true, $at, null);
    }

    public function failed(string $host, \DateTimeImmutable $at, \DateTimeImmutable $failingSince): void
    {
        $this->write($host, true, $at, $failingSince);
    }

    public function deactivate(string $host, string $reason, \DateTimeImmutable $at): void
    {
        $this->write($host, false, $at, $this->domains[$host]->failingSince);
        $this->reasons[$host] = $reason;
    }

    private function write(string $host, bool $active, \DateTimeImmutable $at, ?\DateTimeImmutable $since): void
    {
        if (isset($this->broken[$host])) {
            throw new \RuntimeException("The records of {$host} cannot be written.");
        }
        $this->domains[$host] = new CustomDomain($host, $active, $at, $since);
    }
}

final class Clock
{
    public \DateTimeImmutable $now;

    public function __construct(string $at = '2026-10-26T03:41:00Z')
    {
        $this->now = new \DateTimeImmutable($at);
    }

    public function advance(string $by): void
    {
        $this->now = $this->now->modify($by);
    }
}

/**
 * Everything one check needs: DNS, the records, the clock and the
 * verification over them, with three days' grace.
 *
 * @param array<string, string|list<string>|DnsLookupFailed> $dns
 * @param array<string, float> $costs
 * @return array{CustomDomainVerification, Records, Dns, Clock}
 */
function verification(array $dns, array $domains, array $costs = [], float $timeout = 4.0): array
{
    $resolver = new Dns($dns, $costs);
    $records = new Records(...array_map(static fn (string $host): CustomDomain => new CustomDomain($host, true), $domains));
    $clock = new Clock();
    $verifier = new DomainVerifier([TARGET], ADDRESSES, $resolver, $timeout, clock: $resolver->clock());

    return [new CustomDomainVerification($records, $verifier, 3 * 86400, static fn (): \DateTimeImmutable => $clock->now), $records, $resolver, $clock];
}

function failure(): DnsLookupFailed
{
    return new DnsLookupFailed('The lookup failed: a server failure, or no answer in time.');
}
