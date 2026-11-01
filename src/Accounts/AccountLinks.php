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
    public string $origin;

    public function __construct(
        public EmailLinks $links,
        string $origin,
        public AccountPaths $paths = new AccountPaths(),
    ) {
        $this->origin = rtrim($origin, '/');
        if (BrowserOrigin::hostOf($this->origin, localHttp: true) === null) {
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
        return str_starts_with($this->origin, 'http://');
    }

    /** The URL of the link that makes an account for $email. */
    public function signUp(string $email, string $name): string
    {
        return $this->links->issue(EmailLinks::SIGN_UP, $this->origin, $this->paths->completeSignUp, '', null, ['email' => $email, 'name' => $name])['url'];
    }

    public function reset(AccountUser $user): string
    {
        return $this->links->issue(EmailLinks::PASSWORD_RESET, $this->origin, $this->paths->resetPassword, '', $user)['url'];
    }

    public function changeEmail(AccountUser $user, string $email): string
    {
        return $this->links->issue(EmailLinks::CHANGE_EMAIL, $this->origin, $this->paths->confirmEmail, '', $user, ['email' => $email])['url'];
    }

    /** The link a token is, for $purpose, if it would open now; null otherwise. */
    public function peek(string $token, string $purpose): ?EmailLink
    {
        return $token === '' ? null : $this->links->peek($token, $purpose, $this->origin, '');
    }

    /** The link a token is, used up by this call; null for every reason it is refused. */
    public function consume(string $token, string $purpose): ?EmailLink
    {
        return $token === '' ? null : $this->links->consume($token, $purpose, $this->origin, '');
    }

    /** An absolute URL on the origin, for a page with no token. */
    public function page(string $path): string
    {
        return $this->origin . $path;
    }
}
