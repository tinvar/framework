<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\Request;

/**
 * This request's session: whose it is, who is half-way through signing in
 * on it, and the cookie to replace it with if a sign-in or sign-out rotated
 * it.
 *
 * Carried on the Request rather than held by a service: the middleware that
 * reads the session and the action that rotates it are long-lived, and one
 * that kept this would hand one visitor's session to the next request on
 * the worker. A Request attribute lives exactly as long as the request.
 */
final class SessionState
{
    public const string ATTRIBUTE = 'tinvar.accounts.session';

    private ?string $replacement = null;

    /**
     * @param string $id the session id the cookie carries, or '' with none
     * @param string $realm the host the request came to (Sessions::realmOf()): the only
     *        one a session made in this request is good on
     * @param string $epoch the user's auth epoch, '' signed out
     * @param AccountUser|null $pending whose password was right, waiting on a second factor
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $id,
        public readonly string $realm = '',
        private ?AccountUser $user = null,
        private string $epoch = '',
        private ?AccountUser $pending = null,
    ) {}

    public static function of(Request $request): self
    {
        $state = $request->attribute(self::ATTRIBUTE);
        if (!$state instanceof self) {
            throw new \LogicException('No session on this request: put Tinvar\Accounts\StartSession in the Kernel\'s global middleware.');
        }

        return $state;
    }

    public function user(): ?AccountUser
    {
        return $this->user;
    }

    public function epoch(): string
    {
        return $this->epoch;
    }

    /** Whose password was right, waiting on a second factor; null otherwise. */
    public function pending(): ?AccountUser
    {
        return $this->pending;
    }

    /** Called by Sessions when it rotates the id; the middleware sets the cookie. */
    public function replaceWith(#[\SensitiveParameter] string $cookie, ?AccountUser $user, ?AccountUser $pending = null): void
    {
        $this->replacement = $cookie;
        $this->user = $user;
        $this->pending = $pending;
        $this->epoch = '';
    }

    /** The cookie the response must set, or null if the session was not rotated. */
    public function replacement(): ?string
    {
        return $this->replacement;
    }
}
