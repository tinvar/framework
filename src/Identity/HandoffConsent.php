<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;

/**
 * What a consent screen draws, and what its two forms post back: the
 * destination it names, the account signed in, and the hidden fields of a
 * confirm and a cancel.
 *
 * Handed to the application's screen (HandoffEndpoint's `consent`), or to
 * the framework's own (HandoffConsentShell). The screen names the
 * destination and the account; whatever else it says, its forms post
 * exactly what fields() gives, with POST, to action() -- the ticket is what
 * is being agreed to, and the CSRF token what says this page is where the
 * person agreed.
 */
final readonly class HandoffConsent
{
    /** The field the ticket is posted in. */
    public const string TICKET = 'ticket';

    /** The field the decision is posted in: CONFIRM or CANCEL. */
    public const string DECISION = 'decision';

    public const string CONFIRM = 'confirm';

    public const string CANCEL = 'cancel';

    /** The field VerifyCsrfToken reads a form's token from, matched against its cookie. */
    public const string CSRF = '_token';

    /**
     * @param Authenticatable $user who is signed in on the platform host, and would be signed in there
     * @param Realm $target the destination: always a tenant's custom domain
     * @param string $ticket the signed ConsentTicket the forms carry
     * @param string $csrfToken the session's CSRF token, for the forms' hidden field
     * @param int $expiresAt when the ticket stops being taken, in seconds since the epoch
     */
    public function __construct(
        public Authenticatable $user,
        public Realm $target,
        public string $ticket,
        #[\SensitiveParameter]
        public string $csrfToken,
        public int $expiresAt,
    ) {}

    /** Where both forms post: the step that asked, on the platform host. */
    public function action(): string
    {
        return HandoffEndpoint::AUTHORIZE;
    }

    /**
     * The destination as the screen names it: its host, with the port when
     * it has one. Normalized as every realm's host is, so an international
     * name is shown in the ASCII form the browser's address bar resolves --
     * never a lookalike spelled in other scripts.
     */
    public function destination(): string
    {
        return substr($this->target->origin, (int) strpos($this->target->origin, '://') + 3);
    }

    /**
     * The hidden fields of the form that posts $decision.
     *
     * @return array<string, string>
     */
    public function fields(string $decision): array
    {
        if ($decision !== self::CONFIRM && $decision !== self::CANCEL) {
            throw new \InvalidArgumentException("A consent screen posts '" . self::CONFIRM . "' or '" . self::CANCEL . "'.");
        }

        return [
            self::CSRF => $this->csrfToken,
            self::TICKET => $this->ticket,
            self::DECISION => $decision,
        ];
    }
}
