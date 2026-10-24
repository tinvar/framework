<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * Turns a host name into the addresses it points at, once per request:
 * OutboundHttp vets every address it gets back and connects to one of
 * them, so what a second lookup would say -- a rebinding answer, a short
 * TTL that has run out -- never reaches the connection.
 *
 * A lookup is part of its request, and has what is left of the request's
 * timeout. OutboundHttp does not use an answer that comes after it; a
 * resolver that can stop sooner -- one asking DNS over a socket with a
 * deadline of its own -- stops at it.
 */
interface Resolver
{
    /**
     * @param float $timeout seconds the lookup may take
     * @return list<string> IPv4 and IPv6 addresses as text
     * @throws OutboundFailed when the name does not resolve, or not in time (OutboundFailed::TIMEOUT)
     */
    public function resolve(string $host, float $timeout): array;
}
