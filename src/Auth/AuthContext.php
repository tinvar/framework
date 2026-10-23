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
 */
final class AuthContext
{
    private ?Authenticatable $user = null;

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
     * @var \WeakMap<\Fiber, array{Authenticatable|null}>
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

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** Set once per request, by the authentication middleware. */
    public function login(?Authenticatable $user): void
    {
        $this->user = $user;
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function actingAs(?Authenticatable $user, \Closure $work): mixed
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $previous = $this->user;
            $this->user = $user;

            try {
                return $work();
            } finally {
                $this->user = $previous;
            }
        }

        $had = isset($this->byFiber[$fiber]);
        $previous = $had ? $this->byFiber[$fiber] : null;
        $this->byFiber[$fiber] = [$user];

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
        $this->byFiber = new \WeakMap();
    }
}
