<?php

declare(strict_types=1);

namespace Tinvar\Accounts;

use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Support\Signer;

/**
 * The session cookie: which browser this is, under the app key's MAC.
 *
 * `<id>.<issued>.<expires>.<mac>` -- a random id, when it was issued and
 * when it expires. Signed, so a client cannot invent an id; that is all
 * signing does. Fixation is bounded otherwise: the `__Host-` prefix, so
 * nothing outside this origin can plant one; the expiry under the MAC, so a
 * planted id dies on schedule; and rotation on every privilege change,
 * which Sessions does.
 *
 * Minted under one of two purposes: a signed-out visitor's, and one a
 * sign-in made, which a session row stood behind. A cookie of the second
 * kind whose row is gone was ended by the server -- "sign out everywhere",
 * a password reset elsewhere -- and is replaced rather than read as an
 * ordinary visitor's, so the browser's live grant goes with it (ADR 0075).
 */
final readonly class SessionCookie
{
    /**
     * How long one id is accepted, in seconds, from when it was issued:
     * fixed, never extended, so a planted or leaked id stops working on
     * schedule.
     */
    public const int LIFETIME = 43_200;

    private const string PURPOSE = 'session-cookie';

    private const string SIGNED_IN_PURPOSE = 'session-cookie:signed-in';

    /** One spelling per value: decimal times without leading zeros, a MAC as long as a SHA-256 one encodes to. */
    private const string SHAPE = '/^([0-9a-f]{32})\.(0|[1-9][0-9]{0,11})\.(0|[1-9][0-9]{0,11})\.([A-Za-z0-9_-]{43})\z/';

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param string $name the cookie's name, `__Host-` and the app's
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $key,
        public string $name = '__Host-app_session',
        ?\Closure $clock = null,
    ) {
        if (!str_starts_with($name, '__Host-')) {
            throw new \InvalidArgumentException("The session cookie '{$name}' must be named __Host-..., so neither a sibling subdomain nor a plain-http response can plant it.");
        }
        $this->clock = $clock ?? time(...);
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /** A new cookie value; $signedIn when a session row stands behind it. */
    public function issue(bool $signedIn = false): string
    {
        $now = $this->now();
        $claims = bin2hex(random_bytes(16)) . '.' . $now . '.' . ($now + self::LIFETIME);

        return $claims . '.' . Signer::encode(new Signer($this->key, $signedIn ? self::SIGNED_IN_PURPOSE : self::PURPOSE)->sign($claims));
    }

    /** The id a cookie carries, or null unless this key signed it and it has not expired. */
    public function id(#[\SensitiveParameter] ?string $cookie): ?string
    {
        return $this->verify($cookie)[0] ?? null;
    }

    /** Whether a cookie that verifies was minted by a sign-in; false for one that does not verify. */
    public function signedIn(#[\SensitiveParameter] ?string $cookie): bool
    {
        return ($this->verify($cookie)[1] ?? false) === true;
    }

    /** The id of the session $request carries, or '' for none: what every token is bound through. */
    public function of(Request $request): string
    {
        return $this->id($request->cookie($this->name)) ?? '';
    }

    /** When a cookie this wrote expires, as a unix time. */
    public static function expiry(#[\SensitiveParameter] string $cookie): int
    {
        return (int) (explode('.', $cookie)[2] ?? 0);
    }

    /**
     * $response, setting $cookie -- Secure, HttpOnly, SameSite=Lax, Path=/,
     * as Response::withCookie() writes them -- and the new session's CSRF
     * token beside it, so a form on the page the browser lands on posts with
     * the token of the session it now has.
     */
    public function set(Response $response, #[\SensitiveParameter] string $cookie, ?CsrfTokens $csrf = null): Response
    {
        $response = $response->withCookie($this->name, $cookie, httpOnly: true, maxAge: max(0, self::expiry($cookie) - $this->now()));

        $token = $csrf?->for(new Request('GET', '/', cookies: [$this->name => $cookie]));
        if ($token === null) {
            return $response;
        }

        // Readable by script, which copies it into the header, and per
        // visitor: no shared cache may keep it.
        return $response
            ->withCookie(VerifyCsrfToken::COOKIE, $token, httpOnly: false)
            ->withHeader('cache-control', 'private, no-store')
            ->withAddedHeader('vary', 'Cookie');
    }

    /** @return array{string, bool}|null the id, and whether it was minted signed in */
    private function verify(#[\SensitiveParameter] ?string $cookie): ?array
    {
        if ($cookie === null || preg_match(self::SHAPE, $cookie, $m) !== 1) {
            return null;
        }
        [, $id, $issued, $expires, $mac] = $m;

        // Compared as the string this writes, not as decoded bytes, which
        // would accept a second spelling of the same MAC.
        $claims = "{$id}.{$issued}.{$expires}";
        $signedIn = false;
        if (!hash_equals(Signer::encode(new Signer($this->key, self::PURPOSE)->sign($claims)), $mac)) {
            if (!hash_equals(Signer::encode(new Signer($this->key, self::SIGNED_IN_PURPOSE)->sign($claims)), $mac)) {
                return null;
            }
            $signedIn = true;
        }

        // Expired, issued in the future, or living longer than this issues
        // for: refused. A clock a minute fast elsewhere is tolerated.
        $now = $this->now();
        if ((int) $expires <= $now || (int) $issued > $now + 60 || (int) $expires - (int) $issued > self::LIFETIME) {
            return null;
        }

        return [$id, $signedIn];
    }
}
