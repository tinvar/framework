<?php

declare(strict_types=1);

namespace Tinvar\Mail;

use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;

/**
 * The token an email's link to its reader's mail preferences carries, so
 * the page it opens needs no sign-in (ADR 0107): what a marketing email's
 * body links to as its unsubscribe link, beside the one-click
 * List-Unsubscribe its headers carry (UnsubscribeLinks).
 *
 * Signed under its own purpose, and bound to what this capability is: one
 * subscriber, in one tenant, until it expires. Not a session, an auth epoch,
 * a panel or a build, which an email outlives, and which a reader who
 * cannot sign in has none of -- the reason the page is opened by a link at
 * all. It lets its holder read and change that one reader's marketing
 * preferences and nothing else: no sign-in, no account, no other reader's.
 * A replay changes nothing a second time, so no nonce is kept.
 *
 * The token alone, not the URL: the page is the application's, on its
 * canonical address, as every emailed link is built ("never from the
 * request's Host header", tinvar-spec.md). The page that reads it sends no
 * Referer and is kept by nothing, since its URL carries the token.
 *
 * Signed, not encrypted: the subscriber is readable by anyone who has the
 * email. Name them by an opaque id, never an address.
 *
 * And bound to the address it was sent to, where one is given (ADR 0116):
 * a keyed hash of it, which says nothing of the address to whoever reads
 * the token. A reader whose address has changed since -- the mailbox given
 * up, or someone else's now -- has every link sent to the old one revoked
 * at once: isFor() is false for any address but that one, and for a token
 * that named none.
 */
final readonly class PreferenceLinks
{
    public const string PURPOSE = 'mail-preferences';

    /** Ninety days: as long as the one-click unsubscribe beside it. */
    public const int LIFETIME = 7_776_000;

    /** Longer than any token this makes. */
    private const string TOKEN_SHAPE = '/^v1\.[A-Za-z0-9_-]{1,1200}\.[A-Za-z0-9_-]{1,100}\z/';

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(private Signer $signer, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /** @param (\Closure(): int)|null $clock */
    public static function fromKey(#[\SensitiveParameter] string $appKey, ?\Closure $clock = null): self
    {
        return new self(new Signer($appKey, self::PURPOSE), $clock);
    }

    /**
     * The token that opens $subscriber's preferences in $tenant, for
     * LIFETIME from now, while their address is still $address.
     */
    public function token(string $subscriber, string $tenant = '', ?string $address = null): string
    {
        self::assertClaims($subscriber, $tenant);
        $now = ($this->clock)();

        return SignedToken::encode($this->signer, self::PURPOSE, [
            'p' => $subscriber,
            'y' => $tenant,
            'a' => $address === null ? '' : $this->addressHash($address),
            'i' => $now,
            'e' => $now + self::LIFETIME,
        ]);
    }

    /**
     * Whether the claims verify() read were made for $address, the
     * subscriber's address now: false for any other, and for a token made
     * for none -- an application that binds its links checks this, and a
     * link from before it did opens nothing.
     *
     * @param array{subscriber: string, tenant: string, address: string} $claims
     */
    public function isFor(array $claims, string $address): bool
    {
        return $claims['address'] !== '' && hash_equals($claims['address'], $this->addressHash($address));
    }

    /** The address as the token carries it: a keyed hash, case and spaces aside. */
    private function addressHash(string $address): string
    {
        return bin2hex(substr($this->signer->sign('address:' . strtolower(trim($address))), 0, 16));
    }

    /**
     * The subscriber and tenant a token names, or null for one this
     * application did not make for this, has expired, or is not a token at
     * all -- the same answer for every reason.
     *
     * @return array{subscriber: string, tenant: string, address: string}|null the address
     *         as a keyed hash, '' for a token made for none (isFor())
     */
    public function verify(string $token): ?array
    {
        if (preg_match(self::TOKEN_SHAPE, $token) !== 1) {
            return null;
        }

        try {
            $claims = SignedToken::decode($token, $this->signer, self::PURPOSE);
        } catch (TokenError) {
            return null;
        }

        $subscriber = $claims['p'] ?? null;
        $tenant = $claims['y'] ?? null;
        $address = $claims['a'] ?? '';
        $issued = $claims['i'] ?? null;
        $expires = $claims['e'] ?? null;
        if (!\is_string($subscriber) || !\is_string($tenant) || !\is_string($address) || !\is_int($issued) || !\is_int($expires)) {
            return null;
        }

        try {
            self::assertClaims($subscriber, $tenant);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $now = ($this->clock)();
        if ($expires <= $now || $expires - $issued > self::LIFETIME || $issued > $now + 60) {
            return null;
        }

        return ['subscriber' => $subscriber, 'tenant' => $tenant, 'address' => $address];
    }

    private static function assertClaims(string $subscriber, string $tenant): void
    {
        if ($subscriber === '' || \strlen($subscriber) > 255 || preg_match('/[\x00-\x1f\x7f]/', $subscriber) === 1) {
            throw new \InvalidArgumentException('A preferences link names its subscriber by an id of up to 255 printable bytes.');
        }
        if (\strlen($tenant) > 255 || preg_match('/[\x00-\x1f\x7f]/', $tenant) === 1) {
            throw new \InvalidArgumentException('A preferences link names its tenant by an id of up to 255 printable bytes.');
        }
    }
}
