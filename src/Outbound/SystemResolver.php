<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * The machine's own resolver, through getaddrinfo(): the hosts file and the
 * DNS servers it is configured with, IPv4 and IPv6 -- what curl would ask
 * if it were left to look the name up itself, which it is not.
 *
 * getaddrinfo() has no timeout of its own; the resolver's configuration
 * bounds it (resolv.conf's timeout and attempts), not the request's.
 */
final class SystemResolver implements Resolver
{
    public function resolve(string $host): array
    {
        $results = @socket_addrinfo_lookup($host, null, ['ai_socktype' => SOCK_STREAM]);
        $addresses = [];
        foreach (\is_array($results) ? $results : [] as $result) {
            $address = socket_addrinfo_explain($result)['ai_addr'] ?? [];
            $text = $address['sin_addr'] ?? $address['sin6_addr'] ?? null;
            if (\is_string($text)) {
                $addresses[$text] = true;
            }
        }

        if ($addresses === []) {
            throw new OutboundFailed(OutboundFailed::DNS, "{$host} does not resolve.");
        }

        return array_keys($addresses);
    }
}
