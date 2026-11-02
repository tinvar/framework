<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\UserLookup;
use Tinvar\Calls\CallHosts;
use Tinvar\Orm\Orm;
use Tinvar\Support\Uuid;

/**
 * Personal access tokens for the application's public HTTP API (ADR 0158):
 * issued by a signed-in user, for the scopes the API declares, listed and
 * revoked by them, and checked at every use.
 *
 *     $issued = $tokens->issue('Nightly export', [ApiScope::OrdersRead], expiresAt: $now->addDays(90));
 *     // Show $issued->plaintext once; it is never shown again.
 *
 * **Issued** in a request a session signed its user in to -- never one a
 * token did, so a token cannot make another -- with someone signed in and
 * outside acrossTenants(). A token is bound to its user and the epoch the
 * application's UserLookup says they are on -- the auth epoch, unless the
 * application hands ApiAccess a lookup whose epoch moves on fewer things -- the tenant and the panel in force, and the request's host
 * where the application routes by host (CallHosts, ADR 0149). Its scopes
 * are some of those the application declares with #[RequiresScope] in that
 * panel, found by discovery; a scope nothing declares is refused, as a
 * typo. Its expiry is optional, and in the future.
 *
 * **Kept** as a record and the SHA-256 of its secret (ApiTokenSecret): the
 * text is in IssuedApiToken alone, for the response to the request that
 * asked for it.
 *
 * **Checked** at every use (authenticate()): the token is found by its id,
 * its secret compared with the digest in constant time, and refused if it
 * was revoked, has expired, or its user is gone or on another epoch -- so
 * signing out everywhere, which moves the epoch, ends every token the user
 * held, as it ends every session -- or if it is presented on another host.
 * Its scopes, tenant and panel are held where it is used: ApiCalls for
 * `/_api`, HoldApiToken for an endpoint. A use is recorded as the token's
 * last at most once a minute.
 */
final readonly class ApiTokens
{
    /** The request attribute a token that signed its user in travels under, for the checks after it. */
    public const string REQUEST_ATTRIBUTE = 'tinvar.api-token';

    /** The most tokens one user may hold in a tenant and panel, unless the application says otherwise. */
    public const int MAX_PER_USER = 50;

    /** A token's last use is written at most this often, in seconds. */
    public const int USE_RECORDED_EVERY = 60;

    /** A digest no secret has, compared against when no token has the id presented, so a miss takes as long as a mismatch. */
    private const string NO_DIGEST = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param array<string, list<string>> $scopes the scopes the application declares, by the
     *        panel they are declared in ('' for none) -- ApiScopes::of()
     * @param CallHosts|null $hosts the hosts a token is bound to; none, and the application is
     *        taken to route on no host
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        private ApiTokenStore $store,
        private UserLookup $users,
        private AuthContext $auth,
        private Orm $orm,
        private array $scopes,
        private ?PanelContext $panels = null,
        private ?CallHosts $hosts = null,
        private ?\Closure $clock = null,
        private int $maxPerUser = self::MAX_PER_USER,
    ) {}

    /**
     * A new token for the signed-in user, in the tenant and panel in force.
     *
     * @param list<string|\BackedEnum> $scopes some of grantable()
     * @param CarbonImmutable|null $expiresAt when it stops working; never, unless given
     * @param string|null $host the host it is for, named by code issuing it outside a request
     *        (a console command); in a request, the request's host, and none other
     * @throws ApiTokenRefused for a name, scopes or an expiry the user chose that will not do, or a
     *         user holding as many tokens as they may, naming which (ApiTokenChoice): say so to them
     * @throws \LogicException for a call the application should not have made: nobody signed in,
     *         a token's caller, acrossTenants()
     */
    public function issue(string $name, array $scopes, ?CarbonImmutable $expiresAt = null, ?string $host = null): IssuedApiToken
    {
        $user = $this->auth->user();
        if ($user === null) {
            throw new \LogicException('An API token is issued for the user signed in, and nobody is.');
        }
        if ($this->auth->apiToken() !== null) {
            throw new \LogicException('An API token cannot issue another: a token is issued in a request its user\'s session signed them in to.');
        }
        if ($this->orm->isUnscoped()) {
            throw new \LogicException('An API token cannot be issued inside acrossTenants(): it is held to one tenant.');
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > ApiToken::MAX_NAME_LENGTH || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            throw new ApiTokenRefused(ApiTokenChoice::Name, 'Name the token in 1 to ' . ApiToken::MAX_NAME_LENGTH . ' characters, saying what it is for.');
        }
        if ($scopes === []) {
            throw new ApiTokenRefused(ApiTokenChoice::Scopes, 'Choose at least one scope: a token with none could do nothing.');
        }
        try {
            $scopes = RequiresScope::normalize($scopes);
        } catch (\InvalidArgumentException $e) {
            throw new ApiTokenRefused(ApiTokenChoice::Scopes, $e->getMessage());
        }
        $grantable = $this->grantable();
        $unknown = array_values(array_diff($scopes, $grantable));
        if ($unknown !== []) {
            throw new ApiTokenRefused(
                ApiTokenChoice::Scopes,
                'No API here requires ' . implode(', ', $unknown) . ($grantable === [] ? '. This panel declares no #[RequiresScope] at all.' : '. Choose from ' . implode(', ', $grantable) . '.'),
            );
        }
        $now = $this->now();
        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($now)) {
            throw new ApiTokenRefused(ApiTokenChoice::Expiry, 'A token\'s expiry is in the future: choose a later date, or none.');
        }

        $tenant = $this->orm->tenant();
        $panel = $this->panels?->currentOrNull() ?? '';
        // The epoch the lookup says the user is on now: what every use of
        // the token is checked against (authenticate()).
        $epoch = $this->users->epoch($user);
        $held = array_filter(
            $this->store->forUser($tenant, $panel, $user->authIdentifier()),
            static fn (ApiToken $t): bool => $t->statusAt($now, $epoch) === ApiTokenStatus::Active,
        );
        if (\count($held) >= $this->maxPerUser) {
            throw new ApiTokenRefused(ApiTokenChoice::Count, "You hold {$this->maxPerUser} tokens, the most you may: revoke one you no longer use first.");
        }

        $id = str_replace('-', '', Uuid::v7());
        [$plaintext, $digest] = ApiTokenSecret::issue($id);
        $token = new ApiToken(
            id: $id,
            name: $name,
            user: $user->authIdentifier(),
            epoch: $epoch,
            tenant: $tenant,
            panel: $panel,
            host: ($this->hosts ?? new CallHosts())->issuing([], $host),
            scopes: $scopes,
            createdAt: $now,
            expiresAt: $expiresAt?->setTimezone('UTC'),
        );
        $this->store->insert($token, $digest);

        return new IssuedApiToken($token, $plaintext);
    }

    /**
     * The scopes a token issued now may hold: those the application declares
     * in the panel in force, sorted.
     *
     * @return list<string>
     */
    public function grantable(): array
    {
        return $this->scopes[$this->panels?->currentOrNull() ?? ''] ?? [];
    }

    /**
     * Every token the signed-in user holds in the tenant and panel in force,
     * newest first; none for nobody.
     *
     * @return list<ApiToken>
     */
    public function mine(): array
    {
        $user = $this->auth->user();
        if ($user === null || $this->orm->isUnscoped()) {
            return [];
        }

        return $this->store->forUser($this->orm->tenant(), $this->panels?->currentOrNull() ?? '', $user->authIdentifier());
    }

    /**
     * Every token the signed-in user holds, as mine() lists them, each with
     * where it stands now: the user's epoch looked up once for them all.
     *
     * @return list<array{ApiToken, ApiTokenStatus}>
     */
    public function listed(): array
    {
        $user = $this->auth->user();
        $tokens = $this->mine();
        if ($user === null || $tokens === []) {
            return [];
        }
        $now = $this->now();
        $epoch = $this->users->epoch($user);

        return array_map(static fn (ApiToken $t): array => [$t, $t->statusAt($now, $epoch)], $tokens);
    }

    /** Where one of the signed-in user's tokens stands now, on the epoch the lookup says they are on. */
    public function statusOf(ApiToken $token): ApiTokenStatus
    {
        $user = $this->auth->user();

        return $token->statusAt($this->now(), $user === null ? '' : $this->users->epoch($user));
    }

    /**
     * Revokes one of the signed-in user's tokens, in the tenant in force;
     * false for an id that is none of theirs there, or one revoked already.
     * Effective at once, on every server: the next use finds it revoked.
     */
    public function revoke(string $id): bool
    {
        $user = $this->auth->user();
        if ($user === null || $this->orm->isUnscoped()) {
            return false;
        }

        return $this->store->revoke($this->orm->tenant(), $user->authIdentifier(), $id, $this->now());
    }

    /**
     * The token $presented is, with its user, if it holds now; null for
     * anything else -- not a token, unknown, a secret that does not match,
     * revoked, expired, its user gone or on another epoch, or another host's.
     * Every refusal is the same null: a caller is told its token is no good,
     * never which part of it.
     */
    public function authenticate(#[\SensitiveParameter] string $presented): ?PresentedApiToken
    {
        $parsed = ApiTokenSecret::parse($presented);
        if ($parsed === null) {
            return null;
        }
        [$id, $secret] = $parsed;

        $found = $this->store->find($id);
        $matches = hash_equals($found[1] ?? self::NO_DIGEST, ApiTokenSecret::digest($secret));
        if ($found === null || !$matches) {
            return null;
        }
        $token = $found[0];

        $now = $this->now();
        if ($token->revokedAt !== null || ($token->expiresAt !== null && $token->expiresAt->lessThanOrEqualTo($now))) {
            return null;
        }
        // Where the application routes by host, the token's own host and no
        // other; a token issued outside any request names none, and is good
        // nowhere over HTTP.
        if (!($this->hosts ?? new CallHosts())->admits($token->host)) {
            return null;
        }

        $user = $this->users->find($token->user);
        if ($user === null) {
            return null;
        }
        $epoch = $this->users->epoch($user);
        if (!hash_equals($token->epoch, $epoch)) {
            return null;
        }

        $this->store->touch($token->id, $now, $now->subSeconds(self::USE_RECORDED_EVERY));

        return new PresentedApiToken($token, $user, $epoch);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->clock === null ? time() : ($this->clock)());
    }
}
