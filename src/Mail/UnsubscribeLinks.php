<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * Builds the one-click unsubscribe URL an email's List-Unsubscribe header
 * carries, and reads it back (ADR 0048).
 *
 * The URL is UnsubscribeEndpoint's, on the application's canonical origin
 * -- "Every emailed link (magic link, reset, invite, export, unsubscribe) is
 * built from the user's canonical domain, never from the request's Host
 * header" (tinvar-spec.md) -- so the origin is configuration, handed in,
 * and https: RFC 8058 has a client POST only to an https URL.
 *
 * Its token is signed under its own purpose and names what it may do and
 * nothing more: one list, for one recipient, in one tenant -- the binding
 * this capability has. Not a session or an auth epoch, which a mail client
 * has neither of, and which the shop moves on every sign-in: a link bound to
 * one would stop working the next time its reader signed in, and an
 * unsubscribe link must go on working long after the email was sent. Not a
 * panel or a build, which an email outlives too. It expires after LIFETIME
 * -- longer than the thirty days a link must keep working for -- and does
 * nothing but turn that one list off for that one reader, which is why a
 * replay of it is harmless and no nonce is kept.
 *
 * The token's payload is signed, not encrypted: the recipient is readable in
 * the URL by anyone who has the email. Name the recipient by an opaque id,
 * never an address.
 */
final readonly class UnsubscribeLinks
{
    public const string PURPOSE = 'mail-unsubscribe';

    /** Where UnsubscribeEndpoint answers; the token follows it. */
    public const string PATH = '/_mail/unsubscribe';

    /** Ninety days. */
    public const int LIFETIME = 7_776_000;

    private const string LIST_SHAPE = '/^[a-z0-9][a-z0-9._-]{0,63}\z/';

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param string $origin the application's canonical https origin, such as
     *        https://shop.example -- never one read from a request
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(private Signer $signer, private string $origin, ?\Closure $clock = null)
    {
        $parts = parse_url($origin);
        if (
            !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            throw new \InvalidArgumentException('Unsubscribe links are built on an https origin alone, such as https://shop.example.');
        }
        $this->clock = $clock ?? time(...);
    }

    /** @param (\Closure(): int)|null $clock */
    public static function fromKey(#[\SensitiveParameter] string $appKey, string $origin, ?\Closure $clock = null): self
    {
        return new self(new Signer($appKey, self::PURPOSE), $origin, $clock);
    }

    /**
     * What an Email says for its List-Unsubscribe headers: the one-click URL
     * for $list and $recipient, and $mailto for clients that POST nothing.
     */
    public function for(string $list, string $recipient, string $tenant = '', ?Address $mailto = null): Unsubscribe
    {
        return new Unsubscribe($this->url($list, $recipient, $tenant), $mailto);
    }

    /** The one-click URL alone -- for the body's unsubscribe link too. */
    public function url(string $list, string $recipient, string $tenant = ''): string
    {
        self::assertClaims($list, $recipient, $tenant);
        $now = ($this->clock)();

        return rtrim($this->origin, '/') . self::PATH . '/' . SignedToken::encode($this->signer, self::PURPOSE, [
            'l' => $list,
            'p' => $recipient,
            'y' => $tenant,
            'i' => $now,
            'e' => $now + self::LIFETIME,
        ]);
    }

    /**
     * The list, recipient and tenant a token names, or null for one this
     * application did not make, has expired, or is not a token at all --
     * the same answer for every reason.
     *
     * @return array{list: string, recipient: string, tenant: string}|null
     */
    public function verify(string $token): ?array
    {
        try {
            $claims = SignedToken::decode($token, $this->signer, self::PURPOSE);
        } catch (TokenError) {
            return null;
        }

        $list = $claims['l'] ?? null;
        $recipient = $claims['p'] ?? null;
        $tenant = $claims['y'] ?? null;
        $issued = $claims['i'] ?? null;
        $expires = $claims['e'] ?? null;
        if (!\is_string($list) || !\is_string($recipient) || !\is_string($tenant) || !\is_int($issued) || !\is_int($expires)) {
            return null;
        }

        try {
            self::assertClaims($list, $recipient, $tenant);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $now = ($this->clock)();
        if ($expires <= $now || $expires - $issued > self::LIFETIME || $issued > $now + 60) {
            return null;
        }

        return ['list' => $list, 'recipient' => $recipient, 'tenant' => $tenant];
    }

    private static function assertClaims(string $list, string $recipient, string $tenant): void
    {
        if (preg_match(self::LIST_SHAPE, $list) !== 1) {
            throw new \InvalidArgumentException("'{$list}' is not a mailing list name: lowercase, digits, '.', '_' or '-'.");
        }
        if ($recipient === '' || \strlen($recipient) > 255 || preg_match('/[\x00-\x1f\x7f]/', $recipient) === 1) {
            throw new \InvalidArgumentException('An unsubscribe link names its recipient by an id of up to 255 printable bytes.');
        }
        if (\strlen($tenant) > 255 || preg_match('/[\x00-\x1f\x7f]/', $tenant) === 1) {
            throw new \InvalidArgumentException('An unsubscribe link names its tenant by an id of up to 255 printable bytes.');
        }
    }
}
