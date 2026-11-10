<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\UserLookup;
use Tinvar\Render\SignedToken;
use Tinvar\Render\TokenClaims;
use Tinvar\Render\TokenError;
use Tinvar\Support\DevMode;
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

    /**
     * Lets the holder choose the first password of an account made for them
     * -- by an operator, a billing system, an import -- rather than by
     * signing up (ADR 0189). About the account and its epoch, as a reset is,
     * so setting a password, or a sign-out everywhere, ends it; open a week,
     * as an invite is, since its reader did not ask for it.
     */
    public const string SET_PASSWORD = 'set-password';

    /** Joins the holder's address to a panel in a tenant (Invitations). */
    public const string INVITE = 'invite';

    /**
     * Confirms that the holder of an address said yes to mail sent to it:
     * the second step of a double opt-in (ADR 0116). About an address, as an
     * invite is, and not an account's auth epoch, which a sign-in moves in
     * some applications long before a reader gets round to their inbox; the
     * application holds the address it names to the one its subscriber has
     * when it is used.
     */
    public const string OPT_IN = 'opt-in';

    /**
     * Proves the holder reads the address a new account is asked for at,
     * before any account exists (ADR 0153): about an address, as an invite
     * is, and the account is made only when it is used.
     */
    public const string SIGN_UP = 'sign-up';

    /**
     * Proves the holder reads the new address an account asked to move to
     * (ADR 0153): about the account and its epoch, with the new address kept
     * in the link's record, never in its token.
     */
    public const string CHANGE_EMAIL = 'change-email';

    /** The purposes about an address rather than an account. */
    public const array ADDRESSED = [self::INVITE, self::OPT_IN, self::SIGN_UP];

    /**
     * How long each kind of link opens: fifteen minutes for one that signs
     * in or resets a password, the time it takes to read the email asked
     * for; a week for an invite, an opt-in or a welcome, the time it takes
     * someone to get round to one they did not ask for.
     */
    public const array LIFETIMES = [
        self::SIGN_IN => 900,
        self::PASSWORD_RESET => 900,
        self::SET_PASSWORD => 604_800,
        self::INVITE => 604_800,
        self::OPT_IN => 604_800,
        self::SIGN_UP => 86_400,
        self::CHANGE_EMAIL => 3_600,
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
     * @param bool $localHttp whether a link may be built on plain http for `localhost`
     *        and its subdomains, which resolve to this machine: development's, where the
     *        app is served on http://localhost:PORT and no TLS stands in front. Refused
     *        wherever the environment says production
     */
    public function __construct(
        private Signer $signer,
        private EmailLinkStore $store,
        private UserLookup $users,
        ?\Closure $clock = null,
        private bool $localHttp = false,
    ) {
        $this->clock = $clock ?? time(...);
        $marker = $localHttp ? DevMode::productionMarker(array_filter(getenv(), \is_string(...))) : null;
        if ($marker !== null) {
            throw new \InvalidArgumentException("Emailed links are built on https alone where {$marker}=production: set the app's https URL, and links are built on it.");
        }
    }

    /** @param (\Closure(): int)|null $clock */
    public static function fromKey(#[\SensitiveParameter] string $appKey, EmailLinkStore $store, UserLookup $users, ?\Closure $clock = null, bool $localHttp = false): self
    {
        return new self(new Signer($appKey, self::PURPOSE), $store, $users, $clock, $localHttp);
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
     *        null only for an invite or an opt-in, which name an address (ADDRESSED)
     * @param array<string, string> $data kept with the record, never put in the token
     * @param string|null $epoch the auth epoch to bind it to in place of the account's now: the one
     *        a request asking for it was opened at, for a link made later on the queue worker, so it
     *        opens nothing once a revocation since has moved the epoch (ADR 0170)
     * @return array{url: string, link: EmailLink}
     */
    public function issue(string $purpose, string $origin, string $path, string $tenant, ?Authenticatable $user, array $data = [], ?string $epoch = null): array
    {
        $lifetime = self::LIFETIMES[$purpose] ?? throw new \InvalidArgumentException("'{$purpose}' is not a kind of emailed link.");
        if ($epoch !== null && $user === null) {
            throw new \InvalidArgumentException('Only a link about an account is bound to an epoch.');
        }

        return $this->make($purpose, $origin, $path, $tenant, $user, $data, $this->now() + $lifetime, $epoch);
    }

    /**
     * Carries a link opened on one origin to another -- from a tenant's
     * custom domain to the platform's host, where a password is typed
     * (ADR 0097) -- and returns the URL to send the browser on to, or null
     * for every reason the link would not open here.
     *
     * The link is used up here, in the same one write consume() makes, and
     * a new one made on $to for the same purpose, tenant, account and
     * data, bound to the account's epoch as it is now, and ending when the
     * first did: carrying it gains no time, and the first opens nothing
     * once carried. So the custom domain's page never takes a password,
     * and the platform's host takes one only with a link it made itself.
     *
     * A reset or an invite only. A sign-in link is never carried: one made
     * for a custom domain signs in to that domain's realm, held to its
     * subtree, and carried to the platform's host it would sign in to
     * every membership the account holds.
     *
     * @param string $to the origin to carry it to: an emailed link's origin, not the one it is on
     * @param string $path the page it lands on there
     */
    public function carry(string $token, string $purpose, string $origin, string $tenant, string $to, string $path): ?string
    {
        if ($purpose !== self::PASSWORD_RESET && $purpose !== self::INVITE) {
            throw new \InvalidArgumentException('Only a reset or an invite is carried to another origin; a sign-in link signs in where it was built for.');
        }
        $to = CanonicalOrigins::origin($to);
        if ($to === $origin) {
            throw new \InvalidArgumentException('A link is carried to another origin than the one it is on.');
        }
        // Before the link is used up: a path it cannot land on would spend
        // the visitor's link and make them nothing.
        self::assertPath($path);

        $link = $this->consume($token, $purpose, $origin, $tenant);
        if ($link === null) {
            return null;
        }
        $user = $link->user === '' ? null : $this->users->find($link->user);
        if ($link->user !== '' && ($user === null || $user->authIdentifier() !== $link->user)) {
            return null;
        }

        return $this->make($purpose, $to, $path, $tenant, $user, $link->data, $link->expiresAt)['url'];
    }

    /** @param array<string, string> $data short strings by short keys, or an InvalidArgumentException */
    private static function assertData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (\strlen($key) > 64 || \strlen($value) > 1024) {
                throw new \InvalidArgumentException('A link keeps short strings, by short keys.');
            }
        }
    }

    /** A path an emailed link lands on: root-relative, no query; or an InvalidArgumentException. */
    private static function assertPath(string $path): void
    {
        if (preg_match('#^/(?!/)[A-Za-z0-9/_.-]*\z#', $path) !== 1) {
            throw new \InvalidArgumentException("'{$path}' is not a path an emailed link lands on: root-relative, no query.");
        }
    }

    /**
     * @param array<string, string> $data
     * @return array{url: string, link: EmailLink}
     */
    private function make(string $purpose, string $origin, string $path, string $tenant, ?Authenticatable $user, array $data, int $expiresAt, ?string $epoch = null): array
    {
        $origin = CanonicalOrigins::origin($origin, $this->localHttp);
        self::assertPath($path);
        if (($user === null) !== \in_array($purpose, self::ADDRESSED, true)) {
            throw new \InvalidArgumentException('A sign-in, reset or change-of-address link is about an account; an invite, an opt-in or a sign-up is about an address and no account.');
        }
        self::assertData($data);

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
            expiresAt: $expiresAt,
        );

        $this->store->prune($now, self::PRUNE_BATCH);
        $this->store->add($link, self::hash($secret));

        $token = SignedToken::encode($this->signer, self::tokenPurpose($purpose), [
            's' => $secret,
            'y' => $tenant,
            'o' => $origin,
            'u' => $user === null ? '' : TokenClaims::hashAuth($this->signer, $userId, $epoch ?? $this->users->epoch($user)),
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

    /**
     * Revokes an unused link by its id; true when one was. $note is kept
     * in its record's data beside what was there -- an invite declined
     * keeps when (ADR 0201) -- and may name none of its keys.
     *
     * @param array<string, string> $note
     */
    public function revoke(string $id, string $purpose, string $tenant, array $note = []): bool
    {
        if ($note !== []) {
            self::assertData($note);
            $link = $this->store->byId($id, $purpose, $tenant);
            if ($link === null) {
                return false;
            }
            if (array_intersect_key($note, $link->data) !== []) {
                throw new \InvalidArgumentException('A revocation notes something beside what a link keeps, never over it.');
            }
        }

        return $this->store->revoke($id, $purpose, $tenant, $this->now(), $note);
    }

    /**
     * Uses an invite by its record's id, with no token: for the account
     * signed in as the address it names, which Invitations holds it to
     * (ADR 0201). Marked used in the one conditional write consume() makes,
     * so of an emailed link and its id used at once, one wins. Null for a
     * record that is not there, of that tenant, open.
     *
     * An invite alone: a link that signs in, resets a password or proves an
     * address proves itself by its token, and is never used without it.
     */
    public function useById(string $id, string $purpose, string $tenant): ?EmailLink
    {
        self::assertById($purpose);
        $now = $this->now();
        $link = $this->store->byId($id, $purpose, $tenant);
        if ($link === null || $link->user !== '' || !$link->isOpen($now) || !$this->store->consumeById($id, $purpose, $tenant, $now)) {
            return null;
        }

        return new EmailLink($link->id, $link->purpose, $link->tenant, $link->origin, $link->user, $link->data, $link->createdAt, $link->expiresAt, $now);
    }

    /**
     * Invites in every tenant still open, newest first, whose data holds
     * each of $data's values -- an address, which it must name: the invites
     * to someone signed in as it, wherever they were sent from (ADR 0201).
     *
     * @param int<1, max> $limit
     * @param array<string, string> $data
     * @return list<EmailLink>
     */
    public function openAcrossTenants(string $purpose, int $limit, array $data): array
    {
        self::assertById($purpose);
        if (($data['email'] ?? '') === '') {
            throw new \InvalidArgumentException('Invites are listed across tenants by the address they were sent to, and no wider.');
        }

        return $this->store->openAcrossTenants($purpose, $this->now(), $limit, $data);
    }

    /** An invite, or an InvalidArgumentException: the only links used, or listed across tenants, without a token. */
    private static function assertById(string $purpose): void
    {
        if ($purpose !== self::INVITE) {
            throw new \InvalidArgumentException('Only an invite is used by its id, or listed across tenants, for the account signed in as its address; every other link proves itself by its token.');
        }
    }

    /** The record with this id, used or not; null for none. */
    public function byId(string $id, string $purpose, string $tenant): ?EmailLink
    {
        return $this->store->byId($id, $purpose, $tenant);
    }

    /**
     * Links of a purpose in a tenant still open, newest first: those whose
     * data holds each of $data's values, when it names any.
     *
     * @param int<1, max> $limit
     * @param array<string, string> $data
     * @return list<EmailLink>
     */
    public function open(string $purpose, string $tenant, int $limit = 100, array $data = []): array
    {
        return $this->store->open($purpose, $tenant, $this->now(), $limit, $data);
    }

    /**
     * The tenant a token of $purpose was made in, read from under its MAC:
     * for a page on a host that serves every tenant, where nothing else
     * says whose link it is (ADR 0182). Null for a token that is not one of
     * this purpose, signed here and unexpired.
     *
     * A claim, not a grant: it says only which tenant to peek() or consume()
     * the link in, and those hold it to the record kept in that tenant --
     * the token proves the server named the tenant, and the record is what
     * makes the link open, once.
     */
    public function tenantOf(string $token, string $purpose): ?string
    {
        return $this->claims($token, $purpose)['y'] ?? null;
    }

    /**
     * A token's claims, when it is one of $purpose, signed here, well formed
     * and unexpired; null otherwise.
     *
     * @return array{s: string, y: string, o: string, u: string, i: int, e: int}|null
     */
    private function claims(string $token, string $purpose): ?array
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
        $tenant = $claims['y'] ?? null;
        $origin = $claims['o'] ?? null;
        $auth = $claims['u'] ?? null;
        $issued = $claims['i'] ?? null;
        $expires = $claims['e'] ?? null;
        if (!\is_string($secret) || \strlen($secret) !== 43 || !\is_string($tenant) || !\is_string($origin)
            || !\is_string($auth) || !\is_int($issued) || !\is_int($expires)) {
            return null;
        }

        $now = $this->now();
        if ($expires <= $now || $expires - $issued > $lifetime || $issued > $now + 60) {
            return null;
        }

        return ['s' => $secret, 'y' => $tenant, 'o' => $origin, 'u' => $auth, 'i' => $issued, 'e' => $expires];
    }

    /** @return array{EmailLink, string}|null the link and its secret's hash */
    private function verify(string $token, string $purpose, string $origin, string $tenant): ?array
    {
        $claims = $this->claims($token, $purpose);
        if ($claims === null || !hash_equals($claims['o'], $origin) || !hash_equals($claims['y'], $tenant)) {
            return null;
        }
        $secret = $claims['s'];
        $auth = $claims['u'];
        $now = $this->now();

        $secretHash = self::hash($secret);
        $link = $this->store->find($secretHash);
        if ($link === null || $link->purpose !== $purpose || $link->tenant !== $tenant || $link->origin !== $origin || !$link->isOpen($now)) {
            return null;
        }

        if ($link->user === '') {
            return \in_array($purpose, self::ADDRESSED, true) && $auth === '' ? [$link, $secretHash] : null;
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
