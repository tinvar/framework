<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * Names answered from a table, for tests: a name pointing at the local
 * server a test runs, or a closure answering differently each time it is
 * asked -- a rebinding DNS server, which OutboundHttp asks only once.
 *
 * A name not in the table does not resolve. Every answer is at once, so
 * the time a lookup is given is never short.
 */
final class StaticResolver implements Resolver
{
    /**
     * How many lookups each name has been asked for.
     *
     * @var array<string, int>
     */
    public array $lookups = [];

    /** @param array<string, list<string>|\Closure(int): list<string>> $names a closure is handed which lookup this is, from 1 */
    public function __construct(private readonly array $names) {}

    public function resolve(string $host, float $timeout): array
    {
        $this->lookups[$host] = ($this->lookups[$host] ?? 0) + 1;
        $answer = $this->names[$host] ?? null;
        $addresses = $answer instanceof \Closure ? $answer($this->lookups[$host]) : $answer;
        if ($addresses === null || $addresses === []) {
            throw new OutboundFailed(OutboundFailed::DNS, "{$host} does not resolve.");
        }

        return $addresses;
    }
}
