<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * A host name as a Host header names it, in the one spelling everything
 * compares hosts in.
 *
 * Lowercase, without its port or a trailing dot, so `Acme.Shop.test.:443`
 * and `acme.shop.test` are one host and not two lookups an attacker can
 * tell apart. Anything that is not a host name -- an address literal, a
 * stray character -- is no host at all. The realms, the probes, the
 * external origins an action may redirect to and tenancy's host lookup all
 * read a host through here, so no two of them can disagree about whether a
 * spelling is the same host.
 */
final class HostName
{
    /** The host name a Host header names, normalized; null for anything that is not one. */
    public static function normalize(string $header): ?string
    {
        $host = strtolower(trim($header));

        // An address literal -- [::1], or a bare IPv4 address below -- is a
        // machine, not a name.
        if ($host === '' || str_starts_with($host, '[')) {
            return null;
        }

        $host = (string) preg_replace('/:\d{1,5}\z/', '', $host);
        $host = rtrim($host, '.');

        if (\strlen($host) > 253
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/', $host) !== 1
            || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $host;
    }
}
