<?php

declare(strict_types=1);

namespace Tinvar\Api;

use Carbon\CarbonImmutable;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\TenantTokenActor;
use Tinvar\Calls\CallHosts;
use Tinvar\Orm\Orm;
use Tinvar\Support\Uuid;

/**
 * API tokens a tenant owns rather than a user (ADR 0193): machine
 * credentials for an organization's integrations, which outlive the member
 * who made them and act as nobody but themselves.
 *
 *     $issued = $tokens->issue('Warehouse sync', [ApiScope::OrdersRead], expiresAt: $now->addDays(90),
 *         allowed: fn (): bool => $gate->allows(new Authorize('manageTenantApiTokens', TenantAdministration::class), []));
 *     // Show $issued->plaintext once; it is never shown again.
 *
 * **Issued** by a member signed in by a session -- never a token, of
 * either kind, nor staff impersonating someone -- in the tenant and panel
 * in force, outside acrossTenants(); who may is the application's policy,
 * asked by the page before issue() is called, and asked again through
 * `allowed:` once the tenant's token epoch is read (below). Bound to the
 * tenant, the panel, the request's host where the application routes by
 * host (CallHosts, ADR 0149), and the tenant's token epoch; the member who
 * made it is recorded, and binds it to nothing. Its scopes are some of
 * those declared with #[RequiresScope] on what also says
 * #[AcceptsTenantTokens]: a scope no tenant token could use is refused, as
 * a typo is. Its expiry is held to the lifetime the application allows.
 * Code that knows the epoch the request asking was checked at -- a
 * session's -- hands it to issue() (ADR 0167): a member signed out
 * everywhere while the request ran makes nothing.
 *
 * **Ended together** by endAll(), which moves the tenant's token epoch:
 * what an application does when an owner leaves, or a token is feared
 * leaked and nobody knows which. issue() reads the epoch before it asks
 * `allowed:` again, so a member removed -- and the epoch moved -- in one
 * transaction committing while their request ran either is refused by
 * that check, or made a token held to the epoch before, which the move
 * ended.
 *
 * **Checked** at every use (authenticate()): found by its id, its secret
 * compared with the digest in constant time, and refused if revoked,
 * expired, on another host, its tenant gone, or its tenant's epoch moved
 * on. A call it makes runs as a TenantTokenActor, never as a user, and
 * only where #[AcceptsTenantTokens] says (ApiCalls, HoldApiToken).
 */
final readonly class TenantApiTokens
{
    /** The request attribute a tenant's token that signed a request in travels under. */
    public const string REQUEST_ATTRIBUTE = 'tinvar.tenant-api-token';

    /** The most tokens one tenant may hold in a panel, unless the application says otherwise. */
    public const int MAX_PER_TENANT = 50;

    /** Why a member the caller's check no longer allows was refused, with a 403 (ApiTokenChoice::Caller). */
    public const string NOT_ALLOWED = 'You may no longer make tokens for this organization.';

    /** A digest no secret has, compared against when no token has the id presented. */
    private const string NO_DIGEST = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param \Closure(string): bool $tenants whether a tenant exists now
     * @param TokenHolderLookup $users how the member issuing is held to the epoch the request
     *        asking was checked at (issue()'s $epoch)
     * @param array<string, list<string>> $scopes the scopes a tenant token may hold, by panel
     *        ('' for none): those of what accepts tenant tokens
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        private TenantApiTokenStore $store,
        private \Closure $tenants,
        private TokenHolderLookup $users,
        private AuthContext $auth,
        private Orm $orm,
        private array $scopes,
        private ?PanelContext $panels = null,
        private ?CallHosts $hosts = null,
        private ?\Closure $clock = null,
        private int $maxPerTenant = self::MAX_PER_TENANT,
        private ?int $maxLifetimeDays = null,
    ) {}

    /**
     * A new token for the tenant in force, in the panel in force, made by
     * the member signed in.
     *
     * @param list<string|\BackedEnum> $scopes some of grantable()
     * @param CarbonImmutable|null $expiresAt when it stops working; never, unless given
     * @param string|null $host the host it is for, named by code issuing it outside a request;
     *        in a request, the request's host, and none other
     * @param string|null $epoch the epoch the request asking was checked at, as the
     *        TokenHolderLookup counts it: refused unless the lookup reports it still (ADR 0167)
     * @param (\Closure(): bool)|null $allowed the caller's own check of who may make one, asked
     *        again once the tenant's token epoch is read: a member it no longer allows is refused
     * @throws ApiTokenRefused for a name, scopes or an expiry that will not do, a tenant holding as
     *         many as it may (ApiTokenChoice); with a 403 (ApiTokenChoice::Caller), for a request a
     *         token signed in, an impersonation, an epoch moved on, or a caller no longer allowed
     * @throws \LogicException for a call the application should not have made: nobody signed in,
     *         no tenant, acrossTenants()
     */
    public function issue(string $name, array $scopes, ?CarbonImmutable $expiresAt = null, ?string $host = null, ?string $epoch = null, ?\Closure $allowed = null): IssuedTenantApiToken
    {
        $user = $this->auth->user();
        if ($user === null) {
            throw new \LogicException('A tenant API token is made by a member signed in, and nobody is.');
        }
        if ($user instanceof TenantTokenActor || $this->auth->apiToken() !== null) {
            throw new ApiTokenRefused(ApiTokenChoice::Caller, 'An API token cannot make an organization\'s token: sign in to the app itself to make one.', 403);
        }
        // The token outlives the impersonation, and is the tenant's: staff
        // signed in as a member would leave behind a way in for anyone.
        if ($this->auth->impersonator() !== null) {
            throw new ApiTokenRefused(ApiTokenChoice::Caller, 'An organization\'s API token cannot be made while you are impersonating someone: it would outlive the impersonation. Stop impersonating, and let a member make it.', 403);
        }
        if ($this->orm->isUnscoped()) {
            throw new \LogicException('A tenant API token cannot be issued inside acrossTenants(): it belongs to one tenant.');
        }
        $tenant = $this->orm->tenant();
        if ($tenant === '') {
            throw new \LogicException('A tenant API token belongs to a tenant, and no tenant is in force: issue it in a request that runs as one.');
        }
        if ($epoch !== null && !hash_equals($this->users->epoch($user), $epoch)) {
            throw new ApiTokenRefused(ApiTokenChoice::Caller, ApiTokens::SIGNED_OUT, 403);
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
                'Nothing an organization\'s token may call requires ' . implode(', ', $unknown)
                . ($grantable === [] ? '. Nothing here is marked #[AcceptsTenantTokens].' : '. Choose from ' . implode(', ', $grantable) . '.'),
            );
        }
        $now = $this->now();
        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($now)) {
            throw new ApiTokenRefused(ApiTokenChoice::Expiry, 'A token\'s expiry is in the future: choose a later date' . ($this->maxLifetimeDays === null ? ', or none.' : '.'));
        }
        if ($this->maxLifetimeDays !== null && ($expiresAt === null || $expiresAt->greaterThan($now->addDays($this->maxLifetimeDays)))) {
            throw new ApiTokenRefused(ApiTokenChoice::Expiry, "A token lasts at most {$this->maxLifetimeDays} days here: choose an expiry within that.");
        }

        $host = ($this->hosts ?? new CallHosts())->issuing([], $host);
        $panel = $this->panels?->currentOrNull() ?? '';

        // Counted and kept holding the tenant (exclusively()): two made at
        // once are counted one after the other, and the second sees the
        // first, so neither passes the most a tenant may hold.
        return $this->store->exclusively($tenant, function () use ($tenant, $panel, $host, $name, $scopes, $now, $expiresAt, $allowed, $user): IssuedTenantApiToken {
            // The epoch first, then the caller's check again: a removal that
            // moves the epoch, committing between the two, is seen by the
            // check; one committing after it ends the token made on the
            // epoch before.
            $tokenEpoch = $this->store->epoch($tenant);
            if ($allowed !== null && $allowed() !== true) {
                throw new ApiTokenRefused(ApiTokenChoice::Caller, self::NOT_ALLOWED, 403);
            }

            $held = array_filter(
                $this->store->forTenant($tenant, $panel),
                static fn (TenantApiToken $t): bool => $t->statusAt($now, $tokenEpoch) === TenantApiTokenStatus::Active,
            );
            if (\count($held) >= $this->maxPerTenant) {
                throw new ApiTokenRefused(ApiTokenChoice::Count, "This organization holds {$this->maxPerTenant} tokens, the most it may: revoke one it no longer uses first.");
            }

            $id = str_replace('-', '', Uuid::v7());
            [$plaintext, $digest] = ApiTokenSecret::issue($id, ApiTokenKind::Tenant);
            $token = new TenantApiToken(
                id: $id,
                name: $name,
                tenant: $tenant,
                panel: $panel,
                host: $host,
                scopes: $scopes,
                epoch: $tokenEpoch,
                createdBy: $user->authIdentifier(),
                createdAt: $now,
                expiresAt: $expiresAt?->setTimezone('UTC'),
            );
            $this->store->insert($token, $digest);

            return new IssuedTenantApiToken($token, $plaintext);
        });
    }

    /**
     * The scopes a tenant token issued now may hold: those declared on what
     * accepts tenant tokens in the panel in force, sorted.
     *
     * @return list<string>
     */
    public function grantable(): array
    {
        return $this->scopes[$this->panels?->currentOrNull() ?? ''] ?? [];
    }

    /**
     * Every token the tenant in force holds in the panel in force, newest
     * first, each with where it stands now; none outside a tenant, or for a
     * caller no member is.
     *
     * @return list<array{TenantApiToken, TenantApiTokenStatus}>
     */
    public function listed(): array
    {
        $tenant = $this->managedTenant();
        if ($tenant === null) {
            return [];
        }
        $now = $this->now();
        $epoch = $this->store->epoch($tenant);

        return array_map(
            static fn (TenantApiToken $t): array => [$t, $t->statusAt($now, $epoch)],
            $this->store->forTenant($tenant, $this->panels?->currentOrNull() ?? ''),
        );
    }

    /** Where one of the tenant's tokens stands now. */
    public function statusOf(TenantApiToken $token): TenantApiTokenStatus
    {
        return $token->statusAt($this->now(), $this->store->epoch($token->tenant));
    }

    /**
     * Revokes one of the tenant's tokens, in the tenant in force; false for
     * an id that is none of its, or one revoked already, or a caller no
     * member is. Effective at once, on every server.
     */
    public function revoke(string $id): bool
    {
        $tenant = $this->managedTenant();

        return $tenant !== null && $this->store->revoke($tenant, $this->panels?->currentOrNull() ?? '', $id, $this->now());
    }

    /**
     * Ends every token the tenant in force holds, in every panel, by moving
     * its token epoch: from a request, a job or a command, inside the
     * transaction that makes the change it is ended for -- an owner's
     * membership taken away -- where there is one. New tokens may be made
     * at once, on the epoch after.
     *
     * @throws ApiTokenRefused with a 403, for a tenant's token: it may not end its own tenant's
     * @throws \LogicException outside a tenant, or inside acrossTenants()
     */
    public function endAll(): void
    {
        if ($this->auth->user() instanceof TenantTokenActor) {
            throw new ApiTokenRefused(ApiTokenChoice::Caller, 'An organization\'s token cannot end the organization\'s tokens.', 403);
        }
        if ($this->orm->isUnscoped() || $this->orm->tenant() === '') {
            throw new \LogicException('Every token of a tenant is ended in that tenant: run endAll() as it, outside acrossTenants().');
        }
        $this->store->advance($this->orm->tenant());
    }

    /**
     * The tenant token $presented is, with the actor its call runs as, if
     * it holds now; null for anything else -- not a tenant's token, unknown,
     * a secret that does not match, revoked, expired, another host's, its
     * tenant gone or its tenant's epoch moved on. Every refusal is the same
     * null: a caller is told its token is no good, never which part of it.
     */
    public function authenticate(#[\SensitiveParameter] string $presented): ?PresentedTenantApiToken
    {
        $parsed = ApiTokenSecret::parse($presented, ApiTokenKind::Tenant);
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
        if ($token->tenant === '' || $token->revokedAt !== null || ($token->expiresAt !== null && $token->expiresAt->lessThanOrEqualTo($now))) {
            return null;
        }
        if (!($this->hosts ?? new CallHosts())->admits($token->host)) {
            return null;
        }
        if (($this->tenants)($token->tenant) !== true) {
            return null;
        }
        if (!hash_equals($token->epoch, $this->store->epoch($token->tenant))) {
            return null;
        }

        $this->store->touch($token->id, $now, $now->subSeconds(ApiTokens::USE_RECORDED_EVERY));

        return new PresentedTenantApiToken($token, new TenantTokenActor($token->id, $token->tenant, $token->panel, $token->scopes, $token->name));
    }

    /** The tenant in force, for a member managing its tokens; null outside one, or for a tenant's token. */
    private function managedTenant(): ?string
    {
        $user = $this->auth->user();
        if ($user === null || $user instanceof TenantTokenActor || $this->orm->isUnscoped() || $this->orm->tenant() === '') {
            return null;
        }

        return $this->orm->tenant();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->clock === null ? time() : ($this->clock)());
    }
}
