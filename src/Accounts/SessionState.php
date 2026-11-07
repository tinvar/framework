<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\Request;

/**
 * This request's session: whose it is, who is half-way through signing in
 * on it, the tenant it runs as, and the cookie to replace it with if a
 * sign-in, a sign-out or a switch of tenant rotated it.
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
     * @param int|null $confirmedAt when the owner last proved it was them on this session --
     *        a password, a passkey or an authenticator code -- in Unix seconds
     * @param string|null $credentialEpoch the account's credential epoch the session was held
     *        to when this request opened it; null signed out
     * @param string|null $tenant the tenant its user switched it to (Sessions::switchTenant());
     *        null signed out, and until they switch
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $id,
        public readonly string $realm = '',
        private ?AccountUser $user = null,
        private string $epoch = '',
        private ?AccountUser $pending = null,
        private ?int $confirmedAt = null,
        private ?string $credentialEpoch = null,
        private ?string $tenant = null,
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

    /** When the owner last proved it was them on this session; null if not since it began. */
    public function confirmedAt(): ?int
    {
        return $this->confirmedAt;
    }

    /**
     * The credential epoch the session was held to when this request opened
     * it: a change the session asks for moves the epoch on from this one, or
     * a revocation since has ended the session (Accounts, ADR 0165). Null
     * signed out, and once the session is rotated.
     */
    public function credentialEpoch(): ?string
    {
        return $this->credentialEpoch;
    }

    /**
     * The tenant the session runs as: the one its user chose, on a host that
     * serves many tenants. StartSession runs the rest of the request as it.
     * Null signed out, and from every sign-in until a switch: a new sign-in
     * starts with no tenant chosen.
     */
    public function tenant(): ?string
    {
        return $this->tenant;
    }

    /** Called by Sessions when the owner proves it is them. */
    public function confirmed(int $at): void
    {
        $this->confirmedAt = $at;
    }

    /**
     * Called by Sessions when it rotates the id; the middleware sets the
     * cookie. $tenant is the new session's: null for all but a switch.
     */
    public function replaceWith(#[\SensitiveParameter] string $cookie, ?AccountUser $user, ?AccountUser $pending = null, ?string $tenant = null): void
    {
        $this->replacement = $cookie;
        $this->user = $user;
        $this->pending = $pending;
        $this->epoch = '';
        $this->confirmedAt = null;
        $this->credentialEpoch = null;
        $this->tenant = $tenant;
    }

    /** The cookie the response must set, or null if the session was not rotated. */
    public function replacement(): ?string
    {
        return $this->replacement;
    }
}
