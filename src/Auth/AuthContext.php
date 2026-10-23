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

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function id(): ?string
    {
        return $this->user?->authIdentifier();
    }

    public function check(): bool
    {
        return $this->user !== null;
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
        $previous = $this->user;
        $this->user = $user;

        try {
            return $work();
        } finally {
            $this->user = $previous;
        }
    }

    /** Called at the end of every request and job. */
    public function reset(): void
    {
        $this->user = null;
    }
}
