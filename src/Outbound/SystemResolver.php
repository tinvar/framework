<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * The machine's own resolver, through getaddrinfo(): the hosts file and the
 * DNS servers it is configured with, IPv4 and IPv6 -- what curl would ask
 * if it were left to look the name up itself, which it is not.
 *
 * getaddrinfo() has no timeout of its own, and a lookup inside it cannot
 * be cut short from PHP: how long one can take is the resolver's
 * configuration (resolv.conf's timeout and attempts). What a deadline can
 * do here, it does: with no time left nothing is looked up, and an answer
 * that came after the deadline is thrown away, so the request fails as a
 * timeout instead of going on late.
 */
final class SystemResolver implements Resolver
{
    public function resolve(string $host, float $timeout): array
    {
        if ($timeout <= 0) {
            throw new OutboundFailed(OutboundFailed::TIMEOUT, "{$host} was not looked up: its request had no time left.");
        }

        $started = hrtime(true);
        $results = @socket_addrinfo_lookup($host, null, ['ai_socktype' => SOCK_STREAM]);
        if ((hrtime(true) - $started) / 1e9 > $timeout) {
            throw new OutboundFailed(OutboundFailed::TIMEOUT, "{$host} did not resolve within its request's time.");
        }

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
