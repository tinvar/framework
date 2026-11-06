<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Identity\BrowserOrigin;
use Tinvar\Identity\EmailLink;
use Tinvar\Identity\EmailLinks;
use Tinvar\Support\DevMode;

/**
 * The account emails' links (ADR 0076, 0150), each built on the app's one
 * origin -- never a request's Host header, which is whatever its sender
 * typed -- and opened on the page its path names, which shows it and POSTs
 * it back.
 *
 * The origin is the app's configured URL (APP_URL in the skeleton), https.
 * With none configured, development has a stand-in: the address `tinvar
 * serve` and `tinvar dev` serve on (TINVAR_URL), spelled on `localhost`,
 * which a browser treats as secure and which passkeys accept as their
 * relying party -- 127.0.0.1 is neither. Wherever the environment says
 * production, nothing stands in: no URL, or a plain-http one, stops the
 * boot with what to set.
 */
final readonly class AccountLinks
{
    /**
     * The origin every link is built and opened on; null for an app that has
     * no https origin of its own -- the example shop on plain http off this
     * machine -- which builds no link and opens none (ADR 0162).
     */
    public ?string $origin;

    public function __construct(
        public EmailLinks $links,
        ?string $origin,
        public AccountPaths $paths = new AccountPaths(),
    ) {
        $this->origin = $origin === null ? null : rtrim($origin, '/');
        if ($this->origin !== null && BrowserOrigin::hostOf($this->origin, localHttp: true) === null) {
            throw new \InvalidArgumentException("'{$origin}' is not the app's origin: a scheme and a host, perhaps a port, as https://app.example.");
        }
    }

    /**
     * The origin account links are built on: $configured where it is set,
     * else -- outside production -- $served on localhost, else
     * http://localhost.
     *
     * @param string|null $configured the app's own URL (APP_URL)
     * @param string|null $served where `tinvar serve` says it serves (TINVAR_URL)
     * @param array<string, string>|null $env a test's environment; the process's when null
     */
    public static function origin(?string $configured, ?string $served = null, ?array $env = null): string
    {
        $marker = DevMode::productionMarker($env ?? array_filter(getenv(), \is_string(...)));
        $configured = $configured === null ? null : rtrim(trim($configured), '/');
        if ($configured !== null && $configured !== '') {
            if (BrowserOrigin::hostOf($configured) !== null) {
                return $configured;
            }
            if ($marker === null && BrowserOrigin::hostOf($configured, localHttp: true) !== null) {
                return $configured;
            }

            throw new \InvalidArgumentException(
                "APP_URL is '{$configured}', and account links are built on an https origin -- a scheme and a host, perhaps a port, no path -- as https://app.example"
                . ($marker === null ? ', or http://localhost:PORT in development.' : '.'),
            );
        }
        if ($marker !== null) {
            throw new \InvalidArgumentException("APP_URL is not set, and {$marker}=production: set it to the https origin the app is served on, as https://app.example. Sign-up, reset and change-of-address links are built on it.");
        }
        if ($served !== null && preg_match('#^http://(?:127\.0\.0\.1|localhost|\[::1\])(:\d{1,5})?\z#', rtrim($served, '/'), $m) === 1) {
            return 'http://localhost' . ($m[1] ?? '');
        }

        return 'http://localhost';
    }

    /** Whether the origin is plain http, as only development's can be: EmailLinks must be told. */
    public function local(): bool
    {
        return $this->origin !== null && str_starts_with($this->origin, 'http://');
    }

    /** The URL of the link that makes an account for $email. */
    public function signUp(string $email, string $name): string
    {
        return $this->links->issue(EmailLinks::SIGN_UP, $this->linkOrigin(), $this->paths->completeSignUp, '', null, ['email' => $email, 'name' => $name])['url'];
    }

    /** The URL of the link that signs $user in, for an app that sends them (SignInLinkMails). */
    public function signIn(AccountUser $user): string
    {
        return $this->links->issue(EmailLinks::SIGN_IN, $this->linkOrigin(), $this->paths->signInLink, '', $user)['url'];
    }

    public function passwordReset(AccountUser $user): string
    {
        return $this->links->issue(EmailLinks::PASSWORD_RESET, $this->linkOrigin(), $this->paths->resetPassword, '', $user)['url'];
    }

    /**
     * The URL of the link that moves $user to $email, held to $epoch: the
     * auth epoch the session asking for the change was opened at, so a
     * revocation since leaves it opening nothing (ADR 0170).
     */
    public function changeEmail(AccountUser $user, string $email, string $epoch): string
    {
        return $this->links->issue(EmailLinks::CHANGE_EMAIL, $this->linkOrigin(), $this->paths->confirmEmail, '', $user, ['email' => $email], $epoch)['url'];
    }

    /** The link a token is, for $purpose, if it would open now; null otherwise, and always with no origin. */
    public function peek(string $token, string $purpose): ?EmailLink
    {
        return $token === '' || $this->origin === null ? null : $this->links->peek($token, $purpose, $this->origin, '');
    }

    /** The link a token is, used up by this call; null for every reason it is refused, and always with no origin. */
    public function consume(string $token, string $purpose): ?EmailLink
    {
        return $token === '' || $this->origin === null ? null : $this->links->consume($token, $purpose, $this->origin, '');
    }

    /** An absolute URL on the origin, for a page with no token. */
    public function page(string $path): string
    {
        return $this->linkOrigin() . $path;
    }

    /** The origin a link is built on; none is a mistake, since nothing asks for a link without one (Accounts::deliver()). */
    private function linkOrigin(): string
    {
        return $this->origin ?? throw new \LogicException('An account link is built on the app\'s origin, and this app has none.');
    }
}
