<?php

declare(strict_types=1);

/**
 * Tenant API tokens (ADR 0193): machine tokens a tenant owns rather than a
 * user -- made by a signed-in member, kept as a digest, bound to the
 * tenant, the panel and the host, and run as the token itself
 * (TenantTokenActor), never as whoever made it. Reached only where an
 * operation or an endpoint says #[AcceptsTenantTokens], through a policy
 * that names the actor, and ended by revocation, expiry, the tenant's
 * token epoch moving, or the tenant ceasing to exist.
 */

namespace Tinvar\Tests\Api\TenantApiTokens;

use Carbon\CarbonImmutable;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Api\ApiAccess;
use Tinvar\Api\ApiCalls;
use Tinvar\Api\ApiTokenChoice;
use Tinvar\Api\ApiTokenRefused;
use Tinvar\Api\ApiTokenSecret;
use Tinvar\Api\ArrayApiTokens;
use Tinvar\Api\ArrayTenantApiTokens;
use Tinvar\Api\HoldApiToken;
use Tinvar\Api\TenantApiToken;
use Tinvar\Api\TenantApiTokens;
use Tinvar\Api\TenantApiTokenStatus;
use Tinvar\Api\TenantTokenAccess;
use Tinvar\Api\TokenHolderLookup;
use Tinvar\Attributes\AcceptsTenantTokens;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Query;
use Tinvar\Attributes\RequiresScope;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\TenantTokenActor;
use Tinvar\Calls\CallResult;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\HttpException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Calls\Fixtures\Harness;
use Tinvar\Tests\Calls\Fixtures\RackData;
use Tinvar\Tests\Calls\Fixtures\RenameRequest;
use Tinvar\Tests\Calls\Fixtures\User;
use Tinvar\Tests\Calls\Fixtures\Users;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../Calls/Fixtures/calls.php';

/** Who each call ran as, and in which tenant. */
final class Seen
{
    /** @var list<?Authenticatable> */
    public static array $actors = [];

    /** @var list<string> */
    public static array $tenants = [];

    public static function reset(): void
    {
        self::$actors = [];
        self::$tenants = [];
    }
}

/**
 * A policy that answers for a tenant's token as well as a user: it names
 * TenantTokenActor in each ability's first parameter, so PolicyGate asks it.
 * A rack named "locked" is renamed by nobody.
 */
final class TenantRackPolicy
{
    public function view(User|TenantTokenActor|null $actor, Rack $rack): bool
    {
        return $actor !== null;
    }

    public function update(User|TenantTokenActor|null $actor, Rack $rack): bool
    {
        return $actor !== null && $rack->name !== 'locked';
    }
}

/** A policy written for users alone, as RackPolicy is: it does not name the actor. */
final class UsersOnlyRackPolicy
{
    public function view(?Authenticatable $user, Rack $rack): bool
    {
        return $user !== null;
    }

    public function update(?Authenticatable $user, Rack $rack): bool
    {
        return $user !== null;
    }
}

/** Names the actor in its abilities, and has a before() that does not. */
final class BeforeRackPolicy
{
    public function before(?Authenticatable $user, string $ability, mixed $subject): ?bool
    {
        return null;
    }

    public function view(User|TenantTokenActor|null $actor, Rack $rack): bool
    {
        return true;
    }

    public function update(User|TenantTokenActor|null $actor, Rack $rack): bool
    {
        return true;
    }
}

final class TenantRackActions
{
    public function __construct(private readonly Orm $orm, private readonly AuthContext $auth) {}

    #[Action(name: 'tracks.rename'), Authorize('update', 'rack'), RequiresScope('racks:write'), AcceptsTenantTokens]
    public function rename(Rack $rack, RenameRequest $request): ActionResult
    {
        Seen::$actors[] = $this->auth->user();
        Seen::$tenants[] = $this->orm->tenant();
        $rack->name = $request->name;
        $this->orm->save($rack);

        return ActionResult::ok(new RackData($rack->id, $rack->name));
    }

    /** A user's token may reach it; a tenant's may not. */
    #[Action(name: 'tracks.inspect'), Authorize('view', 'rack'), RequiresScope('racks:inspect')]
    public function inspect(Rack $rack): ActionResult
    {
        Seen::$actors[] = $this->auth->user();

        return ActionResult::ok(new RackData($rack->id, $rack->name));
    }
}

#[Query(name: 'tracks.show'), Authorize('view', 'rack'), RequiresScope('racks:read'), AcceptsTenantTokens]
final class ShowTenantRack
{
    public function __construct(private readonly AuthContext $auth) {}

    public function __invoke(Rack $rack): RackData
    {
        Seen::$actors[] = $this->auth->user();

        return new RackData($rack->id, $rack->name);
    }
}

final class TenantRackApi
{
    #[Get('/api/tracks/{rack}', name: 'api.tracks.show'), Authorize('view', 'rack'), RequiresScope('racks:read'), AcceptsTenantTokens]
    public function show(Rack $rack): RackData
    {
        return new RackData($rack->id, $rack->name);
    }

    #[Get('/api/tracks/{rack}/inspect', name: 'api.tracks.inspect'), Authorize('view', 'rack'), RequiresScope('racks:inspect')]
    public function inspect(Rack $rack): RackData
    {
        return new RackData($rack->id, $rack->name);
    }
}

/** #[AcceptsTenantTokens] with no #[RequiresScope]: exposes nothing, so it is refused. */
final class UnscopedActions
{
    #[Action(name: 'tracks.unscoped'), Authorize('view', 'rack'), AcceptsTenantTokens]
    public function unscoped(Rack $rack): ActionResult
    {
        return ActionResult::ok();
    }
}

final class Holders implements TokenHolderLookup
{
    public function __construct(private readonly Users $users) {}

    public function find(string $id): ?Authenticatable
    {
        return $this->users->find($id);
    }

    public function epoch(Authenticatable $user): string
    {
        return $this->users->epoch($user);
    }

    public function authEpoch(Authenticatable $user): string
    {
        return $this->users->epoch($user);
    }
}

final class World
{
    public Kernel $kernel;
    public Fixture $fixture;
    public Orm $orm;
    public AuthContext $auth;
    public Users $users;
    public ArrayApiTokens $userStore;
    public ArrayTenantApiTokens $store;

    /** @var array<string, true> the tenants there are */
    public array $tenants = ['acme' => true, 'globex' => true];

    public int $now = 1_800_000_000;

    /**
     * @param class-string $policy
     * @param list<class-string> $extraActions
     */
    public function __construct(string $policy = TenantRackPolicy::class, bool $tenantTokens = true, array $extraActions = [], int $maxPerTenant = TenantApiTokens::MAX_PER_TENANT)
    {
        Seen::reset();
        $this->fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $this->orm = $orm = new Orm($this->fixture->connection, new IdentityMap('tenant-tokens'));
        $this->auth = $auth = new AuthContext();
        $this->users = new Users();
        $this->users->epochs['ada'] = 'e1';
        $this->userStore = new ArrayApiTokens();
        $this->store = new ArrayTenantApiTokens();
        $resolver = static fn (string $class): object => match ($class) {
            TenantRackActions::class => new TenantRackActions($orm, $auth),
            ShowTenantRack::class => new ShowTenantRack($auth),
            default => new $class(),
        };

        $actions = new ActionRegistry($resolver);
        $actions->registerAll([TenantRackActions::class, ...$extraActions]);
        $queries = new QueryRegistry($resolver);
        $queries->registerAll([ShowTenantRack::class]);
        $endpoints = new EndpointRegistry();
        $endpoints->registerAll([TenantRackApi::class]);

        $this->kernel = new Kernel(
            orm: $orm,
            routes: new RouteRegistry(),
            queries: $queries,
            actions: $actions,
            auth: $auth,
            policies: new PolicyRegistry()->register(Rack::class, $policy),
            resolver: $resolver,
            allowedQueries: static fn (Request $r): array => [],
            endpoints: $endpoints,
            apiAccess: new ApiAccess(
                $this->userStore,
                new Holders($this->users),
                clock: fn (): int => $this->now,
                tenantTokens: $tenantTokens ? new TenantTokenAccess($this->store, fn (string $tenant): bool => isset($this->tenants[$tenant]), maxPerTenant: $maxPerTenant) : null,
            ),
        );
    }

    public function tokens(): TenantApiTokens
    {
        return $this->kernel->tenantApiTokens ?? throw new \LogicException('No tenant API tokens.');
    }

    public function rack(): string
    {
        return $this->fixture->ids['racks'][0];
    }

    /**
     * Runs $work as ada's session would, in $tenant.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function asAda(\Closure $work, string $tenant = 'acme'): mixed
    {
        return $this->auth->actingAs(new User('ada'), fn (): mixed => $this->orm->actAs($tenant, $work), 'e1');
    }

    /** @param list<string> $scopes */
    public function issue(array $scopes, string $tenant = 'acme', int $days = 30): string
    {
        return $this->asAda(fn (): string => $this->tokens()->issue('Nightly sync', $scopes, CarbonImmutable::createFromTimestampUTC($this->now)->addDays($days))->plaintext, $tenant);
    }

    /** @param list<string> $scopes */
    public function userToken(array $scopes): string
    {
        $tokens = $this->kernel->apiTokens ?? throw new \LogicException('No API tokens.');

        return $this->asAda(fn (): string => $tokens->issue('Mine', $scopes, CarbonImmutable::createFromTimestampUTC($this->now)->addDays(30))->plaintext);
    }

    /** @param array<string, mixed> $body */
    public function call(string $token, string $operation, array $body): Response
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->kernel->handle(
            new Request('POST', "/_api/{$operation}", ['accept' => 'application/json', 'content-type' => 'application/json', 'authorization' => "Bearer {$token}"], [], $body, remoteAddress: '203.0.113.9'),
            static fn (): Response => Response::make(404),
            $json,
        );
    }

    public function get(string $token, string $path): Response
    {
        return $this->kernel->handle(
            new Request('GET', $path, ['accept' => 'application/json', 'authorization' => "Bearer {$token}"], remoteAddress: '203.0.113.9'),
            static fn (): Response => Response::make(404),
        );
    }
}

/**
 * Gives a kept token scopes issue() would not: what a token made before an
 * operation stopped accepting tenant tokens would hold.
 *
 * @param list<string> $scopes
 */
function rescope(World $w, string $plaintext, array $scopes): void
{
    $id = substr($plaintext, 4, 32);
    [$t, $digest] = $w->store->tokens[$id];
    $w->store->tokens[$id] = [new TenantApiToken(
        id: $t->id,
        name: $t->name,
        tenant: $t->tenant,
        panel: $t->panel,
        host: $t->host,
        scopes: $scopes,
        epoch: $t->epoch,
        createdBy: $t->createdBy,
        createdAt: $t->createdAt,
        expiresAt: $t->expiresAt,
    ), $digest];
}

/** @return array<string, mixed> */
function json(Response $response): array
{
    return (array) json_decode($response->body(), true);
}

describe('issuing', function () {
    it('issues a tvt_ token the tenant owns, held to its tenant, panel and host, naming who made it', function () {
        $w = new World();

        $issued = $w->asAda(fn () => $w->tokens()->issue('Nightly sync', ['racks:read'], CarbonImmutable::createFromTimestampUTC($w->now)->addDays(30)));
        [$kept, $digest] = $w->store->find($issued->token->id) ?? [null, ''];

        expect($issued->plaintext)->toMatch('/^tvt_[0-9a-f]{32}_[0-9A-Za-z]{43}\z/')
            ->and(ApiTokenSecret::presented(new Request('GET', '/', ['authorization' => "Bearer {$issued->plaintext}"])))->toBeTrue()
            ->and($kept?->tenant)->toBe('acme')
            ->and($kept?->panel)->toBe('')
            ->and($kept?->createdBy)->toBe('ada')
            ->and($kept?->scopes)->toBe(['racks:read'])
            ->and($digest)->toBe(hash('sha256', substr($issued->plaintext, 37)))
            ->and($digest)->not->toContain(substr($issued->plaintext, 37))
            ->and(print_r($issued, true))->not->toContain(substr($issued->plaintext, 37));
    });

    it('offers only the scopes of what accepts a tenant\'s token, and refuses the rest as a typo is', function () {
        $w = new World();

        expect($w->tokens()->grantable())->toBe(['racks:read', 'racks:write'])
            ->and($w->kernel->apiTokens?->grantable())->toBe(['racks:inspect', 'racks:read', 'racks:write']);

        try {
            $w->issue(['racks:inspect']);
            $this->fail('A scope nothing a tenant token reaches requires was granted.');
        } catch (ApiTokenRefused $e) {
            expect($e->choice)->toBe(ApiTokenChoice::Scopes);
        }
    });

    it('refuses a request a token signed in, an impersonation, and a creator whose epoch moved while the request ran', function () {
        $w = new World();
        $refusal = function (\Closure $work): ?ApiTokenRefused {
            try {
                $work();
            } catch (ApiTokenRefused $e) {
                return $e;
            }

            return null;
        };
        $expiry = CarbonImmutable::createFromTimestampUTC($w->now)->addDays(30);

        $w->auth->login(new User('ada'), 'e1', apiToken: str_repeat('a', 32));
        $byToken = $refusal(fn () => $w->orm->actAs('acme', fn () => $w->tokens()->issue('X', ['racks:read'], $expiry)));
        $w->auth->login(new User('ada'), 'e1', impersonator: 'staff-1');
        $impersonated = $refusal(fn () => $w->orm->actAs('acme', fn () => $w->tokens()->issue('X', ['racks:read'], $expiry)));
        $w->auth->login(null);
        $signedOut = $refusal(fn () => $w->asAda(fn () => $w->tokens()->issue('X', ['racks:read'], $expiry, epoch: 'e0')));

        expect([$byToken?->choice, $byToken?->status])->toBe([ApiTokenChoice::Caller, 403])
            ->and([$impersonated?->choice, $impersonated?->status])->toBe([ApiTokenChoice::Caller, 403])
            ->and([$signedOut?->choice, $signedOut?->status])->toBe([ApiTokenChoice::Caller, 403])
            ->and($w->store->tokens)->toBe([]);
    });

    it('asks the caller\'s check again once the epoch is read, and makes nothing it now refuses', function () {
        $w = new World();
        $order = [];
        $w->store->onEpoch = static function () use (&$order): void {
            $order[] = 'epoch';
        };

        $allowed = static function () use (&$order): bool {
            $order[] = 'allowed';

            return false;
        };

        $refused = null;
        try {
            $w->asAda(fn () => $w->tokens()->issue('X', ['racks:read'], CarbonImmutable::createFromTimestampUTC($w->now)->addDays(1), allowed: $allowed));
        } catch (ApiTokenRefused $e) {
            $refused = $e;
        }

        expect($refused?->choice)->toBe(ApiTokenChoice::Caller)
            ->and($order)->toBe(['epoch', 'allowed'])
            ->and($w->store->tokens)->toBe([]);
    });

    it('refuses nobody, no tenant and acrossTenants() as calls the application should not have made', function () {
        $w = new World();
        $expiry = CarbonImmutable::createFromTimestampUTC($w->now)->addDays(1);

        expect(fn () => $w->orm->actAs('acme', fn () => $w->tokens()->issue('X', ['racks:read'], $expiry)))->toThrow(\LogicException::class, 'signed in')
            ->and(fn () => $w->auth->actingAs(new User('ada'), fn () => $w->tokens()->issue('X', ['racks:read'], $expiry), 'e1'))->toThrow(\LogicException::class, 'tenant')
            ->and(fn () => $w->auth->actingAs(new User('ada'), fn () => $w->orm->acrossTenants(fn () => $w->tokens()->issue('X', ['racks:read'], $expiry)), 'e1'))->toThrow(\LogicException::class, 'acrossTenants');
    });

    it('holds a tenant to its most tokens, and every token to the lifetime the application allows', function () {
        $w = new World(maxPerTenant: 2);
        $w->issue(['racks:read']);
        $w->issue(['racks:read']);

        $count = null;
        try {
            $w->issue(['racks:read']);
        } catch (ApiTokenRefused $e) {
            $count = $e->choice;
        }
        $lifetime = null;
        try {
            $w->issue(['racks:read'], 'globex', days: 400);
        } catch (ApiTokenRefused $e) {
            $lifetime = $e->choice;
        }

        expect($count)->toBe(ApiTokenChoice::Count)
            ->and($lifetime)->toBe(ApiTokenChoice::Expiry)
            ->and($w->issue(['racks:read'], 'globex'))->toStartWith('tvt_');
    });

    it('lists the tenant\'s tokens alone, and revokes one only in its own tenant', function () {
        $w = new World();
        $acme = $w->issue(['racks:read']);
        $globex = $w->issue(['racks:read'], 'globex');
        $globexId = substr($globex, 4, 32);

        $listed = $w->asAda(fn (): array => $w->tokens()->listed());
        $crossTenant = $w->asAda(fn (): bool => $w->tokens()->revoke($globexId));
        $own = $w->asAda(fn (): bool => $w->tokens()->revoke(substr($acme, 4, 32)));

        expect(array_map(static fn (array $l): string => $l[0]->tenant, $listed))->toBe(['acme'])
            ->and($listed[0][1])->toBe(TenantApiTokenStatus::Active)
            ->and($crossTenant)->toBeFalse()
            ->and($own)->toBeTrue()
            ->and($w->asAda(fn (): array => $w->tokens()->listed())[0][1])->toBe(TenantApiTokenStatus::Revoked)
            ->and($w->call($globex, 'tracks.show', ['rack' => $w->rack()])->status)->toBe(200);
    });
})->group('wired');

describe('/_api', function () {
    it('runs an operation that accepts tenant tokens as the token, never as a user, in the token\'s tenant', function () {
        $w = new World();
        $token = $w->issue(['racks:read', 'racks:write']);

        $shown = $w->call($token, 'tracks.show', ['rack' => $w->rack()]);
        $renamed = $w->call($token, 'tracks.rename', ['rack' => $w->rack(), 'name' => 'By the org']);

        expect($shown->status)->toBe(200)
            ->and($renamed->status)->toBe(200)
            ->and(json($renamed)['name'])->toBe('By the org')
            ->and(Seen::$actors)->toHaveCount(2)
            ->and(Seen::$actors[0])->toBeInstanceOf(TenantTokenActor::class)
            ->and(Seen::$actors[0]?->authIdentifier())->toBe('tenant-token:' . substr($token, 4, 32))
            ->and(Seen::$actors[1] instanceof TenantTokenActor ? Seen::$actors[1]->tenant : null)->toBe('acme')
            ->and(Seen::$tenants)->toBe(['acme'])
            ->and($w->auth->user())->toBeNull();
    });

    it('refuses an operation that does not accept tenant tokens, though a user\'s token reaches it', function () {
        $w = new World();
        $tenant = $w->issue(['racks:read']);
        rescope($w, $tenant, ['racks:inspect', 'racks:read']);
        $user = $w->userToken(['racks:inspect']);

        $refused = $w->call($tenant, 'tracks.inspect', ['rack' => $w->rack()]);
        $reached = $w->call($user, 'tracks.inspect', ['rack' => $w->rack()]);

        expect($refused->status)->toBe(403)
            ->and(json($refused)['error']['code'])->toBe('tenant_token_not_accepted')
            ->and($reached->status)->toBe(200)
            ->and(Seen::$actors)->toHaveCount(1)
            ->and(Seen::$actors[0])->toBeInstanceOf(User::class);
    });

    it('refuses a scope the token lacks, and the policy\'s no', function () {
        $w = new World();
        $token = $w->issue(['racks:read']);
        $w->fixture->connection->execute("UPDATE racks SET name = 'locked'");
        $both = $w->issue(['racks:read', 'racks:write']);

        expect($w->call($token, 'tracks.rename', ['rack' => $w->rack(), 'name' => 'X'])->status)->toBe(403)
            ->and(json($w->call($token, 'tracks.rename', ['rack' => $w->rack(), 'name' => 'X']))['error']['code'])->toBe('insufficient_scope')
            ->and(json($w->call($both, 'tracks.rename', ['rack' => $w->rack(), 'name' => 'X']))['error']['code'])->toBe('forbidden')
            ->and(Seen::$actors)->toBe([]);
    });

    it('ends a token revoked, expired, ended with every token of its tenant, or whose tenant is gone -- each the same 401', function () {
        $w = new World();
        $revoked = $w->issue(['racks:read']);
        $w->asAda(fn () => $w->tokens()->revoke(substr($revoked, 4, 32)));
        $expired = $w->issue(['racks:read'], days: 1);
        $ended = $w->issue(['racks:read'], 'globex');
        $userToken = $w->userToken(['racks:read']);
        $w->asAda(fn () => $w->tokens()->endAll(), 'globex');
        $gone = $w->issue(['racks:read']);
        $w->now += 2 * 86_400;
        unset($w->tenants['acme']);

        $statuses = array_map(fn (string $t): Response => $w->call($t, 'tracks.show', ['rack' => $w->rack()]), [$revoked, $expired, $ended, $gone]);

        expect(array_map(static fn (Response $r): int => $r->status, $statuses))->toBe([401, 401, 401, 401])
            ->and(array_unique(array_map(static fn (Response $r): string => json($r)['error']['code'], $statuses)))->toBe(['api_token_invalid'])
            ->and($w->asAda(fn (): array => $w->tokens()->listed(), 'globex')[0][1])->toBe(TenantApiTokenStatus::Ended)
            ->and($w->call($userToken, 'tracks.show', ['rack' => $w->rack()])->status)->toBe(200)
            ->and($w->issue(['racks:read'], 'globex'))->toStartWith('tvt_');
    });

    it('takes no tenant token when the application gives no TenantTokenAccess', function () {
        $w = new World(tenantTokens: false);

        expect($w->kernel->tenantApiTokens)->toBeNull()
            ->and($w->call('tvt_' . str_repeat('0', 32) . '_' . str_repeat('a', 43), 'tracks.show', ['rack' => $w->rack()])->status)->toBe(401);
    });
})->group('wired');

describe('an endpoint', function () {
    it('takes a tenant token where it accepts them, and refuses one where it does not', function () {
        $w = new World();
        $token = $w->issue(['racks:read']);
        rescope($w, $token, ['racks:inspect', 'racks:read']);

        $shown = $w->get($token, "/api/tracks/{$w->rack()}");
        $refused = $w->get($token, "/api/tracks/{$w->rack()}/inspect");

        expect($shown->status)->toBe(200)
            ->and(json($shown)['id'])->toBe($w->rack())
            ->and($refused->status)->toBe(403)
            ->and(json($refused)['error']['code'])->toBe('tenant_token_not_accepted');
    });

    it('is held to the token\'s tenant: one presented in another is refused', function () {
        $w = new World();
        $presented = $w->tokens()->authenticate($w->issue(['racks:read'])) ?? throw new \LogicException('It did not hold.');
        $hold = new HoldApiToken(['racks:read'], $w->orm, tenantTokens: true);
        $request = new Request('GET', '/')->withAttribute(TenantApiTokens::REQUEST_ATTRIBUTE, $presented);
        $next = static fn (Request $r): Response => Response::make(204);
        $ranIn = null;
        $narrowed = $hold->handle($request, static function (Request $r) use ($w, &$ranIn): Response {
            $ranIn = $w->orm->tenant();

            return Response::make(204);
        });

        expect($narrowed->status)->toBe(204)
            ->and($ranIn)->toBe('acme')
            ->and($w->orm->actAs('acme', fn (): Response => $hold->handle($request, $next))->status)->toBe(204)
            ->and(fn () => $w->orm->actAs('globex', fn (): Response => $hold->handle($request, $next)))->toThrow(HttpException::class, 'tenant');
    });
})->group('wired');

describe('the Kernel', function () {
    it('refuses #[AcceptsTenantTokens] on what requires no scope', function () {
        expect(fn () => new World(extraActions: [UnscopedActions::class]))->toThrow(\LogicException::class, 'requires no scope');
    });

    it('refuses an operation that accepts tenant tokens whose policy does not name the actor', function (string $policy, string $method) {
        expect(fn () => new World(policy: $policy))->toThrow(\LogicException::class, "{$method}()");
    })->with([
        'its ability' => [UsersOnlyRackPolicy::class, 'UsersOnlyRackPolicy::update'],
        'its before()' => [BeforeRackPolicy::class, 'BeforeRackPolicy::before'],
    ]);
})->group('wired');

describe('ApiCalls', function () {
    it('refuses a tenant token in a tenant that is not its own', function () {
        $h = new Harness();
        $store = new ArrayTenantApiTokens();
        $tokens = new TenantApiTokens($store, static fn (string $t): bool => true, new Holders($h->users), $h->auth, $h->orm, ['' => ['racks:read']]);
        $plaintext = $h->as('ada', fn () => $h->orm->actAs('acme', fn () => $tokens->issue('X', ['racks:read'], CarbonImmutable::now('UTC')->addDay())))->plaintext;
        $presented = $tokens->authenticate($plaintext) ?? throw new \LogicException('It did not hold.');
        $calls = new ApiCalls(ApiCalls::discover($h->actions, $h->queries, $h->entry), $h->runner, $h->auth, $h->orm, tenantTokenOperations: ['racks.show']);

        expect($h->orm->actAs('globex', fn (): CallResult => $calls->call($presented, 'racks.show', ['rack' => $h->rack()]))->reason)->toBe('tenant')
            ->and($calls->call($presented, 'racks.rename', ['rack' => $h->rack(), 'name' => 'X'])->reason)->toBe('tenant_token_not_accepted');
    });
});

describe('PolicyGate', function () {
    it('asks a policy about a tenant\'s token only where the ability names the actor, and says no otherwise', function () {
        $auth = new AuthContext();
        $actor = new TenantTokenActor(str_repeat('a', 32), 'acme', '', ['racks:read'], 'Nightly sync');
        $rack = new \ReflectionClass(Rack::class)->newInstanceWithoutConstructor();
        $rack->name = 'Rack';
        $named = new PolicyGate(new PolicyRegistry()->register(Rack::class, TenantRackPolicy::class), $auth);
        $unnamed = new PolicyGate(new PolicyRegistry()->register(Rack::class, UsersOnlyRackPolicy::class), $auth);
        $check = new Authorize('view', 'rack');

        $asActor = static fn (PolicyGate $gate): bool => $auth->actingAs($actor, static fn (): bool => $gate->allows($check, ['rack' => $rack]));

        expect($asActor($named))->toBeTrue()
            ->and($asActor($unnamed))->toBeFalse()
            ->and($auth->actingAs(new User('ada'), static fn (): bool => $unnamed->allows($check, ['rack' => $rack])))->toBeTrue()
            ->and(PolicyGate::admitsTenantTokens(TenantRackPolicy::class, 'view'))->toBeTrue()
            ->and(PolicyGate::admitsTenantTokens(UsersOnlyRackPolicy::class, 'view'))->toBeFalse();
    });
});
