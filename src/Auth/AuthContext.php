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
     * The id of the API token the user was signed in with (ADR 0159); null
     * when they were signed in by a session, or nobody is. Read by what
     * must refuse a token's caller -- issuing another token, above all --
     * and kept for the request alone: actingAs() does not change it.
     */
    private ?string $apiToken = null;

    /**
     * Who is signed in as the user, while staff impersonate them: the
     * impersonator's id; null when nobody is. Handed in by the middleware
     * that signs the user in, as the epoch is, and read by what must refuse
     * or record an impersonated request -- issuing an API token, which would
     * outlive the impersonation as the user's own credential (ADR 0159), and
     * the audit log and the job dispatcher where the application gives them
     * no answer of their own. Kept for the request alone: actingAs() does
     * not change it.
     */
    private ?string $impersonator = null;

    /**
     * The impersonation this request's session is (ADR 0194): its id, which
     * epoch() carries beside the user's own epoch, so everything bound to
     * (user, epoch) while staff act as the user -- tokens, grants, cached
     * answers -- is bound to the impersonation too, and taken nowhere else:
     * not by the user's own sessions, which run on the same epoch meanwhile.
     */
    private ?string $impersonation = null;

    /** The user's own epoch, as the application handed it in: epoch() without the impersonation. */
    private string $accountEpoch = '';

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
     * @var \WeakMap<object, array{Authenticatable|null, string, string|null}>
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
     * keeps none, or nobody is signed in. While the request's session is an
     * impersonation, the impersonation too (boundEpoch(), ADR 0194): what is
     * bound to it is bound to that impersonation alone.
     */
    public function epoch(): string
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber !== null && isset($this->byFiber[$fiber])) {
            return $this->byFiber[$fiber][1];
        }

        return $this->epoch;
    }

    /**
     * The user's own epoch, as the application keeps it: epoch() without an
     * impersonation. What is checked later against the epoch the application
     * reports for the user (UserLookup::epoch()) -- a job running as them --
     * is bound to this, and ends when the epoch moves at the impersonation's
     * end.
     */
    public function accountEpoch(): string
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber !== null && isset($this->byFiber[$fiber])) {
            return $this->byFiber[$fiber][1];
        }

        return $this->accountEpoch;
    }

    /**
     * The epoch $epoch, bound to the impersonation $impersonation: what
     * epoch() is while a session is one, and what a check of a token minted
     * then recomputes from the user's epoch and the impersonation the token
     * names. $epoch itself for none.
     */
    public static function boundEpoch(string $epoch, ?string $impersonation): string
    {
        return $impersonation === null || $impersonation === '' ? $epoch : $epoch . '~impersonation:' . $impersonation;
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
     *        a token authenticated (ADR 0159); null for a session's
     * @param string|null $impersonator who is signed in as them, while staff impersonate the
     *        user; null when nobody is
     * @param string|null $impersonation the impersonation the session is, with an impersonator
     *        (ADR 0194): epoch() carries it
     */
    public function login(?Authenticatable $user, string $epoch = '', ?string $apiToken = null, ?string $impersonator = null, ?string $impersonation = null): void
    {
        $this->user = $user;
        $this->apiToken = $user === null ? null : $apiToken;
        $this->impersonator = $user === null || $impersonator === '' ? null : $impersonator;
        $this->impersonation = $this->impersonator === null || $impersonation === '' ? null : $impersonation;
        $this->accountEpoch = $user === null ? '' : $epoch;
        $this->epoch = self::boundEpoch($this->accountEpoch, $this->impersonation);
        $this->first ??= [$this->user?->authIdentifier(), $this->epoch];
    }

    /**
     * The id of the API token this request's user was signed in with; null
     * for a session's user, or nobody (ADR 0159).
     */
    public function apiToken(): ?string
    {
        return $this->apiToken;
    }

    /**
     * Who is signed in as this request's user, while staff impersonate
     * them; null when nobody is.
     */
    public function impersonator(): ?string
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->byFiber[$fiber]) && $this->byFiber[$fiber][2] !== null) {
            return $this->byFiber[$fiber][2];
        }

        return $this->impersonator;
    }

    /**
     * The impersonation this request's session is (ADR 0194), as epoch()
     * carries it; null for every other request, and inside actingAs().
     */
    public function impersonation(): ?string
    {
        $fiber = \Fiber::getCurrent();

        return $fiber !== null && isset($this->byFiber[$fiber]) ? null : $this->impersonation;
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
     * Whether the first login() of the request signed someone in: the
     * request came with a signed-in session, whatever it leaves with. A
     * request that ends it -- a sign-out, a reset, a sign-in as someone
     * else, a role change -- sends the browser on with a document load
     * (Kernel, ADR 0164).
     */
    public function cameSignedIn(): bool
    {
        return $this->first !== null && $this->first[0] !== null;
    }

    /**
     * Runs $work as $user, on $epoch: no impersonation, and the request's
     * impersonator unless $impersonator names who is acting as $user -- as
     * for a call made with a token minted during an impersonation, which
     * carries its impersonator (ADR 0194).
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function actingAs(?Authenticatable $user, \Closure $work, string $epoch = '', ?string $impersonator = null): mixed
    {
        $epoch = $user === null ? '' : $epoch;
        $impersonator = $user === null || $impersonator === '' ? null : $impersonator;
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            $previous = [$this->user, $this->epoch, $this->accountEpoch, $this->impersonation, $this->impersonator];
            [$this->user, $this->epoch, $this->accountEpoch, $this->impersonation] = [$user, $epoch, $epoch, null];
            $this->impersonator = $impersonator ?? $this->impersonator;

            try {
                return $work();
            } finally {
                [$this->user, $this->epoch, $this->accountEpoch, $this->impersonation, $this->impersonator] = $previous;
            }
        }

        $had = isset($this->byFiber[$fiber]);
        $previous = $had ? $this->byFiber[$fiber] : null;
        $this->byFiber[$fiber] = [$user, $epoch, $impersonator];

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
        $this->impersonator = null;
        $this->impersonation = null;
        $this->accountEpoch = '';
        $this->first = null;
        $this->byFiber = new \WeakMap();
    }
}
