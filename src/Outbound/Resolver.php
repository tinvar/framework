<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * Turns a host name into the addresses it points at, once per request:
 * OutboundHttp vets every address it gets back and connects to one of
 * them, so what a second lookup would say -- a rebinding answer, a short
 * TTL that has run out -- never reaches the connection.
 */
interface Resolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses as text
     * @throws OutboundFailed when the name does not resolve
     */
    public function resolve(string $host): array;
}
