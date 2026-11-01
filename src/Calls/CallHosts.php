<?php

declare(strict_types=1);

namespace Tinvar\Calls;

use Tinvar\Http\HostName;
use Tinvar\Routing\Domains;
use Tinvar\Routing\HostMatch;

/**
 * The host a call from outside a browser is held to (ADR 0149): the one
 * place a tool grant and a service context token are bound to a host, and
 * an operation's #[Domain] -- or its panel's -- is held over HTTP.
 *
 * A token is bound to the host it was issued on: the host of the request
 * in hand, or one named for it (a job issuing a token a service will call
 * back with names the host it will call on). Issued with no request in
 * hand and none named -- a job running a model's loop -- it carries the
 * explicit no-host marker (ContextToken::NO_HOST), which is good only
 * outside an HTTP request: a call arriving over HTTP with it is refused,
 * as one presented on any host but the token's own is.
 *
 * An operation held to a host runs, over HTTP, only on that host; outside
 * HTTP, on the host its token names when it names one, and anywhere when
 * it names none, since no request is there to hold.
 *
 * Where the application routes on no host (no Domains) nothing is held,
 * and a token carries the empty host as every token did before.
 */
final readonly class CallHosts
{
    /**
     * @param Domains|null $domains the hosts the application serves; none, and nothing is held
     * @param (\Closure(): ?HostMatch)|null $inHand the host of the request being answered
     *        (the HTTP kernel's hostInHand()); null outside any request
     */
    public function __construct(
        private ?Domains $domains = null,
        private ?\Closure $inHand = null,
    ) {}

    /** Whether the application routes by host, and so holds calls to one. */
    public function routes(): bool
    {
        return $this->domains !== null;
    }

    /** The host of the request being answered; null outside one. */
    public function inHand(): ?HostMatch
    {
        return $this->inHand === null ? null : ($this->inHand)();
    }

    /**
     * The host a token issued now is bound to: the request's, or $named,
     * or ContextToken::NO_HOST with neither -- each refused when an
     * operation it names is held to another host.
     *
     * @param list<Operation> $operations what it names
     * @param string|null $named a host the token is for, named by code issuing it outside a
     *        request; inside one, it must be the request's own
     * @throws \InvalidArgumentException for a named host the application does not serve
     * @throws \LogicException for a host an operation is not held to
     */
    public function issuing(array $operations, ?string $named = null): string
    {
        if ($this->domains === null) {
            if ($named !== null) {
                throw new \LogicException('A token names a host, and the application routes on none.');
            }

            return ContextToken::NO_HOST;
        }

        $current = $this->inHand();
        $match = $current;
        if ($named !== null) {
            $normal = HostName::normalize($named);
            $match = $normal === null ? null : $this->domains->match($normal);
            if ($match === null) {
                throw new \InvalidArgumentException("The token names '{$named}', which is not a host this application serves.");
            }
            if ($current !== null && !hash_equals($current->host, $match->host)) {
                throw new \LogicException("The token names '{$named}', and it is issued in a request on {$current->host}: it is that host's.");
            }
        }
        if ($match === null) {
            return ContextToken::NO_HOST;
        }

        foreach ($operations as $operation) {
            foreach ($operation->hosts as $pattern) {
                if (!$match->serves($pattern)) {
                    throw new \LogicException("'{$operation->name}' is held to {$pattern}, and this token would be for {$match->host}.");
                }
            }
        }

        return $match->host;
    }

    /**
     * Whether a token bound to $host may be used now: on its own host over
     * HTTP, never with the no-host marker there; outside HTTP, whatever
     * host it names. A token carrying no host at all was issued before
     * tokens did, and is refused where the application routes by host.
     */
    public function admits(?string $host): bool
    {
        if ($this->domains === null) {
            return true;
        }
        if ($host === null) {
            return false;
        }

        $current = $this->inHand();

        return $current === null || ($host !== ContextToken::NO_HOST && hash_equals($host, $current->host));
    }

    /**
     * Whether $operation may run now, with a token bound to $host: over
     * HTTP, on the host it is held to; outside HTTP, on the token's host if
     * it names one.
     */
    public function holds(Operation $operation, ?string $host): bool
    {
        if ($this->domains === null || $operation->hosts === []) {
            return true;
        }

        $match = $this->inHand() ?? ($host === null || $host === ContextToken::NO_HOST ? null : $this->domains->match($host));
        if ($match === null) {
            // Outside HTTP, with no host to hold to: a job's call.
            return $host !== null;
        }
        foreach ($operation->hosts as $pattern) {
            if (!$match->serves($pattern)) {
                return false;
            }
        }

        return true;
    }
}
