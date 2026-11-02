<?php

declare(strict_types=1);

namespace Tinvar\Auth;

/**
 * The current user, for one request.
 *
 * Request-bound state on a long-lived worker: "Tenant, auth, panel, identity
 * map and #[Scoped] services reset at the end of each request and job, under a
 * tested reset contract." Switching is scoped to a closure for the same reason
 * tenant switching is -- a bare setter leaks into whatever runs next.
 *
 * With the user, an **auth epoch**: a value the application keeps per user
 * and changes whenever what the user may see changes without the session id
 * changing -- a role granted or taken away, impersonation started or stopped
 * -- and hands in with the user. "Client-held data ... is bound to (user,
 * tenant). It is cleared ... on role change, impersonation start and stop"
 * (tinvar-spec.md): the route token, the #[Once] binding and the history key
 * name the user and the epoch, so a change of either purges what the client
 * holds and stops it being claimed, as a change of session does.
 */
final class AuthContext
{
    private ?Authenticatable $user = null;

    private string $epoch = '';

    /**
     * The id of the API token the user was signed in with (ADR 0151); null
     * when they were signed in by a session, or nobody is. Read by what
     * must refuse a token's caller -- issuing another token, above all --
     * and kept for the request alone: actingAs() does not change it.
     */
    private ?string $apiToken = null;

    /**
     * Whom the first login() of the request signed in, with their epoch:
     * the session's user, as the stack found them. Null before any.
     *
     * @var array{?string, string}|null
     */
    private ?array $first = null;

    /**
     * Per-fiber overrides.
     *
     * "Context is fiber-local, so a suspended fiber can't leak a switch to
     * another." Query bodies run inside the render scheduler's fibers, which
     * interleave at every relation suspend; a plain property meant one fiber's
     * actingAs() became the current user for every other fiber in the tick,
     * and every policy decision and cache key in them was computed for the
     * wrong person.
     *
     * @var \WeakMap<object, array{Authenticatable|null, string}>
     */
    private \WeakMap $byFiber;

    public function __construct()
    {
        $this->byFiber = new \WeakMap();
    }

    public function user(): ?Authenticatable
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber !== null && isset($this->byFiber[$fiber])) {
            return $this->byFiber[$fiber][0];
        }

        return $this->user;
    }

    public function id(): ?string
    {
        return $this->user()?->authIdentifier();
    }

    /**
     * The auth epoch the application handed in with the user: '' when it
     * keeps none, or nobody is signed in.
     */
    public function epoch(): string
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber !== null && isset($this->byFiber[$fiber])) {
            return $this->byFiber[$fiber][1];
        }

        return $this->epoch;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Set once per request, by the authentication middleware.
     *
     * @param string $epoch the user's auth epoch, which the application changes on a role
     *        change and on impersonation starting or stopping
     * @param string|null $apiToken the id of the API token that signed them in, for a request
     *        a token authenticated (ADR 0151); null for a session's
     */
    public function login(?Authenticatable $user, string $epoch = '', ?string $apiToken = null): void
    {
        $this->user = $user;
        $this->epoch = $user === null ? '' : $epoch;
        $this->apiToken = $user === null ? null : $apiToken;
        $this->first ??= [$this->user?->authIdentifier(), $this->epoch];
    }

    /**
     * The id of the API token this request's user was signed in with; null
     * for a session's user, or nobody (ADR 0151).
     */
    public function apiToken(): ?string
    {
        return $this->apiToken;
    }

    /**
     * Whether the request has moved who is signed in since its first
     * login(): signed someone in or out, or handed in another epoch -- a
     * sign-in, a sign-out, a role change. What the browser was let in to
     * hear for the session as it was is then no longer its (ADR 0069).
     */
    public function rebound(): bool
    {
        return $this->first !== null && $this->first !== [$this->user?->authIdentifier(), $this->epoch];
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function actingAs(?Authenticatable $user, \Closure $work, string $epoch = ''): mixed
    {
        $epoch = $user === null ? '' : $epoch;
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            [$previous, $previousEpoch] = [$this->user, $this->epoch];
            [$this->user, $this->epoch] = [$user, $epoch];

            try {
                return $work();
            } finally {
                [$this->user, $this->epoch] = [$previous, $previousEpoch];
            }
        }

        $had = isset($this->byFiber[$fiber]);
        $previous = $had ? $this->byFiber[$fiber] : null;
        $this->byFiber[$fiber] = [$user, $epoch];

        try {
            return $work();
        } finally {
            if ($had) {
                $this->byFiber[$fiber] = $previous;
            } else {
                unset($this->byFiber[$fiber]);
            }
        }
    }

    /** Called at the end of every request and job. */
    public function reset(): void
    {
        $this->user = null;
        $this->epoch = '';
        $this->apiToken = null;
        $this->first = null;
        $this->byFiber = new \WeakMap();
    }
}
