<?php

declare(strict_types=1);

namespace Tinvar\Identity;

/**
 * The machine's own resolver, through dns_get_record(): the nameservers in
 * its resolv.conf.
 *
 * dns_get_record() has no timeout of its own, and a lookup inside it cannot
 * be cut short from PHP: how long one can take is the system resolver's
 * configuration -- resolv.conf's `options timeout:` and `attempts:`, times
 * its nameservers (glibc's defaults allow 5 seconds, twice, for each of up
 * to three). The RES_OPTIONS variable would set them per process, but
 * putenv() is the whole process's, and a FrankenPHP worker is one thread of
 * many. So what a deadline can do here, it does: with no time left nothing
 * is looked up, and an answer that came after the deadline is thrown away,
 * so the check fails as a timeout instead of going on late. Set resolv.conf
 * to `options timeout:1 attempts:2` on servers that answer TlsAskEndpoint,
 * so no one lookup outlasts a check.
 *
 * Every name is asked about with a trailing dot, as an absolute name. Left
 * relative, a name the resolver finds no record for is tried again with
 * each of resolv.conf's `search` domains appended -- `shop.example.com`
 * asked for as `shop.example.com.corp.internal` -- and a domain whose own
 * DNS is gone could be judged by a record of the platform's own network.
 *
 * dns_get_record() answers an empty list for a name that does not exist
 * (NXDOMAIN) and for one with no records of the kind asked (NODATA), with
 * no way to tell the two apart; both are an empty answer here.
 */
final class SystemDnsResolver implements DnsResolver, DnsTextResolver
{
    public function alias(string $name, float $timeout): ?string
    {
        $aliases = [];
        foreach ($this->records($name, DNS_CNAME, $timeout) as $record) {
            $owner = \is_string($record['host'] ?? null) ? rtrim(strtolower($record['host']), '.') : '';
            if (($record['type'] ?? null) === 'CNAME' && $owner === $name && \is_string($record['target'] ?? null)) {
                $aliases[strtolower($record['target'])] = true;
            }
        }

        // DNS allows a name one CNAME and nothing beside it; an answer with
        // two is one this cannot read as an answer at all.
        if (\count($aliases) > 1) {
            throw new DnsLookupFailed("{$name} answered with more than one CNAME.");
        }

        return array_key_first($aliases);
    }

    public function addresses(string $name, float $timeout): array
    {
        $started = hrtime(true);
        $addresses = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $left = $timeout - (hrtime(true) - $started) / 1e9;
            foreach ($this->records($name, $type, $left) as $record) {
                if (\is_string($record[$field] ?? null)) {
                    $addresses[$record[$field]] = true;
                }
            }
        }

        return array_map('strval', array_keys($addresses));
    }

    public function texts(string $name, float $timeout): array
    {
        $texts = [];
        foreach ($this->records($name, DNS_TXT, $timeout) as $record) {
            $owner = \is_string($record['host'] ?? null) ? rtrim(strtolower($record['host']), '.') : '';
            if (($record['type'] ?? null) !== 'TXT' || $owner !== $name) {
                continue;
            }
            // A record longer than 255 bytes is split into strings: PHP
            // gives them in `entries`, and `txt` joined.
            $entries = $record['entries'] ?? null;
            $texts[] = \is_array($entries)
                ? implode('', array_filter($entries, \is_string(...)))
                : (\is_string($record['txt'] ?? null) ? $record['txt'] : '');
        }

        return $texts;
    }

    /** @return list<array<array-key, mixed>> */
    private function records(string $name, int $type, float $timeout): array
    {
        if ($timeout <= 0) {
            throw new DnsLookupFailed("{$name} was not looked up: its check had no time left.");
        }

        $started = hrtime(true);
        try {
            $records = @dns_get_record("{$name}.", $type);
        } catch (\ErrorException) {
            // An error handler that throws on warnings, @ or not: the same
            // failure dns_get_record() reports by returning false.
            $records = false;
        }
        if ((hrtime(true) - $started) / 1e9 > $timeout) {
            throw new DnsLookupFailed("{$name} did not answer within its check's time.");
        }
        if ($records === false) {
            throw new DnsLookupFailed("The lookup of {$name} failed: a server failure, or no answer in time.");
        }

        return array_values(array_filter($records, \is_array(...)));
    }
}
