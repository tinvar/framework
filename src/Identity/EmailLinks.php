<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\UserLookup;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

/**
 * The links an email signs someone in with, resets their password with, or
 * invites them with: single-use, short-lived, and bound to what they are
 * for (ADR 0076).
 *
 * A link's token is a SignedToken under the link's own purpose -- a sign-in
 * token is not a reset token, whatever else it says -- carrying a random
 * 256-bit secret, the tenant, the https origin it was built on, an expiry,
 * and, for a link about an account, the account and its auth epoch as one
 * keyed hash. Its record is kept beside it (EmailLinkStore), found by a
 * hash of the secret: the token proves the server made it, and the record
 * is what makes it single-use and revocable. A copy of the table opens
 * nothing.
 *
 * Opening one checks, in order, and refuses with the same null whatever
 * fails:
 *
 * - the token's shape, signature and purpose;
 * - its expiry, never further out than its purpose's lifetime;
 * - the origin and tenant it is opened on, against the ones it was built
 *   for -- a link made for a tenant's custom domain is no credential on the
 *   platform's host, nor one tenant's in another;
 * - its record: there, of that purpose, origin and tenant, unused,
 *   unrevoked, unexpired;
 * - the account's auth epoch, where it names one: a sign-out everywhere, a
 *   role change, a reset password -- anything that moves the epoch -- ends
 *   every link minted before it.
 *
 * peek() stops there, for the page a link lands on: a mail scanner or a
 * browser's prefetch that GETs the link spends nothing. consume() then
 * marks the record used in one conditional write, which one caller wins.
 *
 * The application builds every link's origin from CanonicalOrigins, never
 * from a request's Host, and hands it in.
 */
final readonly class EmailLinks
{
    /** Signs the holder in: the magic link. */
    public const string SIGN_IN = 'sign-in';

    /** Lets the holder choose the account's password. */
    public const string PASSWORD_RESET = 'password-reset';

    /** Joins the holder's address to a panel in a tenant (Invitations). */
    public const string INVITE = 'invite';

    /**
     * How long each kind of link opens: fifteen minutes for one that signs
     * in or sets a password, the time it takes to read the email; a week
     * for an invite, the time it takes someone to get round to it.
     */
    public const array LIFETIMES = [
        self::SIGN_IN => 900,
        self::PASSWORD_RESET => 900,
        self::INVITE => 604_800,
    ];

    /** The signer's own purpose; each link's purpose is bound into its MAC beside it. */
    public const string PURPOSE = 'email-link';

    /** The query parameter a link carries its token in. */
    public const string PARAMETER = 'token';

    /** Expired records forgotten per link issued, at most. */
    public const int PRUNE_BATCH = 100;

    /** Longer than any token issue() makes. */
    private const string TOKEN_SHAPE = '/^v1\.[A-Za-z0-9_-]{1,1500}\.[A-Za-z0-9_-]{1,100}\z/';

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param Signer $signer under PURPOSE
     * @param UserLookup $users finds a link's account again, at the epoch it is on now
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        private Signer $signer,
        private EmailLinkStore $store,
        private UserLookup $users,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @param (\Closure(): int)|null $clock */
    public static function fromKey(#[\SensitiveParameter] string $appKey, EmailLinkStore $store, UserLookup $users, ?\Closure $clock = null): self
    {
        return new self(new Signer($appKey, self::PURPOSE), $store, $users, $clock);
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Makes a link, keeps its record, and returns the URL to email:
     * `$origin$path?token=...`.
     *
     * @param string $origin the https origin it is built on -- CanonicalOrigins', never a request's
     * @param string $path the path of the page it lands on, which shows it and POSTs it back
     * @param Authenticatable|null $user the account it is about, bound with its auth epoch now;
     *        null only for an invite, which names an address
     * @param array<string, string> $data kept with the record, never put in the token
     * @return array{url: string, link: EmailLink}
     */
    public function issue(string $purpose, string $origin, string $path, string $tenant, ?Authenticatable $user, array $data = []): array
    {
        $lifetime = self::LIFETIMES[$purpose] ?? throw new \InvalidArgumentException("'{$purpose}' is not a kind of emailed link.");
        $origin = CanonicalOrigins::origin($origin);
        if (preg_match('#^/(?!/)[A-Za-z0-9/_.-]*\z#', $path) !== 1) {
            throw new \InvalidArgumentException("'{$path}' is not a path an emailed link lands on: root-relative, no query.");
        }
        if (($user === null) !== ($purpose === self::INVITE)) {
            throw new \InvalidArgumentException('A sign-in or reset link is about an account; an invite is about an address and no account.');
        }
        foreach ($data as $key => $value) {
            if (\strlen($key) > 64 || \strlen($value) > 1024) {
                throw new \InvalidArgumentException('A link keeps short strings, by short keys.');
            }
        }

        $now = $this->now();
        $secret = Signer::encode(random_bytes(32));
        $userId = $user?->authIdentifier() ?? '';
        $link = new EmailLink(
            id: Uuid::v7(),
            purpose: $purpose,
            tenant: $tenant,
            origin: $origin,
            user: $userId,
            data: $data,
            createdAt: $now,
            expiresAt: $now + $lifetime,
        );

        $this->store->prune($now, self::PRUNE_BATCH);
        $this->store->add($link, self::hash($secret));

        $token = SignedToken::encode($this->signer, self::tokenPurpose($purpose), [
            's' => $secret,
            'y' => $tenant,
            'o' => $origin,
            'u' => $user === null ? '' : TokenClaims::hashAuth($this->signer, $userId, $this->users->epoch($user)),
            'i' => $now,
            'e' => $link->expiresAt,
        ]);

        return ['url' => $origin . $path . '?' . self::PARAMETER . '=' . $token, 'link' => $link];
    }

    /**
     * The link a token is, if it would open now on $origin in $tenant,
     * without using it: for the page it lands on. Null for every reason it
     * would not.
     */
    public function peek(string $token, string $purpose, string $origin, string $tenant): ?EmailLink
    {
        return $this->verify($token, $purpose, $origin, $tenant)[0] ?? null;
    }

    /**
     * Uses the link a token is: checked as peek() checks it, then marked
     * used in one write only one caller can win. Null for every reason it
     * is refused, the race lost among them.
     *
     * Inside the caller's transaction, it is part of it: a password reset
     * that fails after it gives the link back.
     */
    public function consume(string $token, string $purpose, string $origin, string $tenant): ?EmailLink
    {
        $verified = $this->verify($token, $purpose, $origin, $tenant);
        if ($verified === null) {
            return null;
        }
        [$link, $secretHash] = $verified;
        $now = $this->now();
        if (!$this->store->consume($secretHash, $purpose, $tenant, $now)) {
            return null;
        }

        return new EmailLink($link->id, $link->purpose, $link->tenant, $link->origin, $link->user, $link->data, $link->createdAt, $link->expiresAt, $now);
    }

    /** Revokes an unused link by its id; true when one was. */
    public function revoke(string $id, string $purpose, string $tenant): bool
    {
        return $this->store->revoke($id, $purpose, $tenant, $this->now());
    }

    /** The record with this id, used or not; null for none. */
    public function byId(string $id, string $purpose, string $tenant): ?EmailLink
    {
        return $this->store->byId($id, $purpose, $tenant);
    }

    /**
     * Links of a purpose in a tenant still open, newest first.
     *
     * @param int<1, max> $limit
     * @return list<EmailLink>
     */
    public function open(string $purpose, string $tenant, int $limit = 100): array
    {
        return $this->store->open($purpose, $tenant, $this->now(), $limit);
    }

    /** @return array{EmailLink, string}|null the link and its secret's hash */
    private function verify(string $token, string $purpose, string $origin, string $tenant): ?array
    {
        $lifetime = self::LIFETIMES[$purpose] ?? null;
        if ($lifetime === null || preg_match(self::TOKEN_SHAPE, $token) !== 1) {
            return null;
        }

        try {
            $claims = SignedToken::decode($token, $this->signer, self::tokenPurpose($purpose));
        } catch (TokenError) {
            return null;
        }

        $secret = $claims['s'] ?? null;
        $claimedTenant = $claims['y'] ?? null;
        $claimedOrigin = $claims['o'] ?? null;
        $auth = $claims['u'] ?? null;
        $issued = $claims['i'] ?? null;
        $expires = $claims['e'] ?? null;
        if (!\is_string($secret) || \strlen($secret) !== 43 || !\is_string($claimedTenant) || !\is_string($claimedOrigin)
            || !\is_string($auth) || !\is_int($issued) || !\is_int($expires)) {
            return null;
        }

        $now = $this->now();
        if ($expires <= $now || $expires - $issued > $lifetime || $issued > $now + 60) {
            return null;
        }
        if (!hash_equals($claimedOrigin, $origin) || !hash_equals($claimedTenant, $tenant)) {
            return null;
        }

        $secretHash = self::hash($secret);
        $link = $this->store->find($secretHash);
        if ($link === null || $link->purpose !== $purpose || $link->tenant !== $tenant || $link->origin !== $origin || !$link->isOpen($now)) {
            return null;
        }

        if ($link->user === '') {
            return $purpose === self::INVITE && $auth === '' ? [$link, $secretHash] : null;
        }

        // The account as it is now, at the epoch it is on now: one that has
        // moved since the link was made -- a sign-out everywhere, a role
        // change, a new password -- has ended it.
        $user = $this->users->find($link->user);
        if ($user === null || $user->authIdentifier() !== $link->user
            || !hash_equals(TokenClaims::hashAuth($this->signer, $link->user, $this->users->epoch($user)), $auth)) {
            return null;
        }

        return [$link, $secretHash];
    }

    private static function tokenPurpose(string $purpose): string
    {
        return self::PURPOSE . ':' . $purpose;
    }

    private static function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }
}
