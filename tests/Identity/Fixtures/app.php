<?php

declare(strict_types=1);

namespace Tinvar\Tests\Identity\Fixtures;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\Membership;
use Tinvar\Auth\Memberships;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Auth\TenantMemberships;
use Tinvar\Container\Container;
use Tinvar\Data\Data;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\Arrival;
use Tinvar\Identity\HandoffCode;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\HandoffSessions;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Jobs\UserLookup;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\TenantHierarchy;
use Tinvar\Tenancy\TenantTree;
use Tinvar\Webhooks\ArrayNonceStore;

/**
 * A platform with hosts of every kind: the platform host sign-in happens on,
 * a panel's own host, a tenant's subdomain of the platform's domain, two
 * custom domains of one tenant and one of another -- and a session layer
 * of its own that keeps each session to the host it was made on, as
 * HandoffSessions asks an application to.
 */
const KEY = 'identity-test-key-of-at-least-thirty-two-bytes';

const SESSION_COOKIE = '__Host-sid';

final readonly class User implements Authenticatable
{
    public function __construct(public string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/**
 * The users, their epochs and their memberships. Sam is staff, and a member
 * of acme's portal as well, so a refusal on a custom domain is the staff
 * rule and not a missing membership.
 */
final class Directory implements UserLookup, Memberships, TenantMemberships
{
    /** @var array<string, int> */
    public array $epochs = ['alice' => 1, 'bob' => 1, 'carol' => 1, 'sam' => 1];

    /** @var array<string, array<string, list<string>>> user => panel => tenants */
    public array $members = [
        'alice' => ['portal' => ['acme']],
        'carol' => ['portal' => ['acme']],
        'bob' => ['portal' => ['globex']],
        'sam' => ['admin' => [''], 'portal' => ['acme']],
    ];

    /**
     * What tenantsOf() lists in place of the memberships, for a test of a
     * listing that does not agree with them.
     *
     * @var array<string, array<string, list<string>>> user => panel => tenants
     */
    public array $listed = [];

    /** @var list<string> who is staff */
    public array $staff = ['sam'];

    /** @var list<string> the tenant the ORM ran as each time a membership was asked */
    public array $askedAs = [];

    public function __construct(private readonly Orm $orm) {}

    public function find(string $id): ?Authenticatable
    {
        return isset($this->epochs[$id]) ? new User($id) : null;
    }

    public function epoch(Authenticatable $user): string
    {
        return (string) ($this->epochs[$user->authIdentifier()] ?? 0);
    }

    public function membership(Authenticatable $user, string $panel, string $tenant): ?Membership
    {
        $this->askedAs[] = $this->orm->tenant();

        return \in_array($tenant, $this->members[$user->authIdentifier()][$panel] ?? [], true) ? new Membership() : null;
    }

    public function tenantsOf(Authenticatable $user, string $panel): array
    {
        return $this->listed[$user->authIdentifier()][$panel] ?? $this->members[$user->authIdentifier()][$panel] ?? [];
    }

    public function isStaff(Authenticatable $user): bool
    {
        return \in_array($user->authIdentifier(), $this->staff, true);
    }
}

/** Server-side sessions: id => whose, and the host it was made on. */
final class SessionStore
{
    /** @var array<string, array{user: string, realm: string, tenant?: string}> */
    public array $sessions = [];
}

/**
 * A reseller over acme, when a test asks for a hierarchy: the reseller's
 * custom domain is then a realm whose subtree holds acme's members too.
 */
final class ResellerOverAcme implements TenantTree
{
    public function parentOf(string $tenant): ?string
    {
        return $tenant === 'acme' ? 'reseller' : null;
    }

    public function descendantsOf(string $tenant): array
    {
        return $tenant === 'reseller' ? ['acme'] : [];
    }
}

/** This request's session, and the id to replace it with. */
final class SessionBox
{
    public ?string $replacement = null;

    public function __construct(public readonly string $id) {}
}

/**
 * Global: signs in whoever the session cookie's row names -- on the host the
 * row was made on, and nowhere else -- and sets the new cookie a handoff
 * made.
 */
final readonly class StartSession implements Middleware
{
    public function __construct(private SessionStore $store, private AuthContext $auth, private Directory $directory) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $id = $request->cookie(SESSION_COOKIE) ?? '';
        $row = $this->store->sessions[$id] ?? null;
        if ($row !== null && $row['realm'] === Host::normalize($request->header('host') ?? '')) {
            $user = new User($row['user']);
            $this->auth->login($user, $this->directory->epoch($user));
        }

        $box = new SessionBox($id);
        $response = $next($request->withAttribute(SessionBox::class, $box));

        return $box->replacement === null ? $response : $response->withCookie(SESSION_COOKIE, $box->replacement);
    }
}

/** A new session for each arrival -- or, told to misbehave, the one the browser came with. */
final class Arrivals implements HandoffSessions
{
    public function __construct(private readonly SessionStore $store, public bool $keepId = false) {}

    public function arrive(Request $request, Arrival $arrival): string
    {
        $box = $request->attribute(SessionBox::class);
        \assert($box instanceof SessionBox);
        $row = ['user' => $arrival->user->authIdentifier(), 'realm' => $arrival->realm->host, 'tenant' => $arrival->tenant];

        if ($this->keepId) {
            $this->store->sessions[$box->id] = $row;

            return $box->id;
        }

        unset($this->store->sessions[$box->id]);
        $fresh = bin2hex(random_bytes(16));
        $this->store->sessions[$fresh] = $row;
        $box->replacement = $fresh;

        return $fresh;
    }
}

final readonly class WhoData extends Data
{
    public function __construct(public string $user) {}
}

/** Where a handoff goes on to: who this host's session signs in. */
#[Page('/whoami', view: 'whoami', name: 'whoami')]
final readonly class WhoAmI
{
    public function __construct(private AuthContext $auth) {}

    public function __invoke(): WhoData
    {
        return new WhoData($this->auth->id() ?? '');
    }
}

final class App
{
    public readonly Kernel $kernel;
    public readonly Orm $orm;
    public readonly AuthContext $auth;
    public readonly Directory $directory;
    public readonly SessionStore $sessions;
    public readonly Arrivals $arrivals;
    public readonly Signer $signer;
    public readonly Realms $realms;
    public readonly HandoffEndpoint $endpoint;
    public int $now = 1_800_000_000;

    /** @var list<string> the message of everything the Kernel logged: why each refusal was one */
    public array $log = [];

    public function __construct(?TenantTree $tree = null)
    {
        $this->orm = new Orm(
            new PdoConnection(new \PDO('sqlite::memory:'), 'identity-test'),
            new IdentityMap('identity-test'),
            $tree === null ? null : new TenantHierarchy($tree),
        );
        $this->auth = new AuthContext();
        $this->directory = new Directory($this->orm);
        $this->sessions = new SessionStore();
        $this->arrivals = new Arrivals($this->sessions);
        $this->signer = new Signer(KEY, HandoffCode::PURPOSE);
        $this->realms = self::realms();

        $container = (new Container())
            ->instance(SessionStore::class, $this->sessions)
            ->instance(Directory::class, $this->directory);

        $routes = new RouteRegistry();
        $routes->register(WhoAmI::class);

        $directory = $this->directory;
        $this->endpoint = new HandoffEndpoint(
            realms: $this->realms,
            signer: $this->signer,
            nonces: new ArrayNonceStore(),
            sessionId: static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '',
            auth: $this->auth,
            orm: $this->orm,
            users: $this->directory,
            sessions: $this->arrivals,
            signIn: static fn (string $back): Response => Response::redirect('/sign-in?next=' . rawurlencode($back)),
            memberships: $this->directory,
            staff: static fn (Authenticatable $user): bool => $directory->isStaff($user),
            clock: fn (): int => $this->now,
        );

        $this->kernel = new Kernel(
            orm: $this->orm,
            routes: $routes,
            queries: new QueryRegistry(),
            actions: new ActionRegistry(),
            auth: $this->auth,
            policies: new PolicyRegistry(),
            resolver: $container->resolver(),
            allowedQueries: static fn (Request $r): array => [],
            globalMiddleware: [StartSession::class],
            gate: new AllowAllGate(),
            log: function (\Throwable $e): void {
                $this->log[] = $e->getMessage();
            },
            container: $container,
            handoff: $this->endpoint,
        );
    }

    public static function realms(): Realms
    {
        return new Realms(
            Realm::platform('https://id.platform.test'),
            [Realm::platform('https://admin.platform.test', panel: 'admin')],
            static fn (string $host): ?Realm => match ($host) {
                'acme.platform.test' => Realm::platform('https://acme.platform.test', 'acme', 'portal'),
                'acme.example' => Realm::domain('https://acme.example', 'acme', 'portal'),
                'shop.acme.example' => Realm::domain('https://shop.acme.example', 'acme', 'portal'),
                'globex.example' => Realm::domain('https://globex.example', 'globex', 'portal'),
                'reseller.example' => Realm::domain('https://reseller.example', 'reseller', 'portal'),
                // A record nobody should have been able to save.
                'evil.example' => Realm::domain('https://evil.example', 'acme', 'admin'),
                default => null,
            },
            platformPanels: ['admin'],
        );
    }

    /** The last thing the Kernel logged. */
    public function lastLogged(): string
    {
        return $this->log === [] ? '' : $this->log[array_key_last($this->log)];
    }

    /**
     * One request, as a browser sends it to $url with these cookies.
     *
     * @param array<string, string> $cookies
     */
    public function get(string $url, array $cookies = []): Response
    {
        $parts = parse_url($url);
        \assert(\is_array($parts) && isset($parts['host']));
        $raw = $parts['query'] ?? '';
        parse_str($raw, $query);
        /** @var array<string, string|list<string>> $query */

        return $this->kernel->handle(
            new Request('GET', $parts['path'] ?? '/', ['host' => $parts['host'], 'accept' => 'application/json'], $query, [], $cookies, $raw),
            static fn (LoadedRoute $loaded): Response => Response::make(200, json_encode(
                $loaded->page === null ? [] : get_object_vars($loaded->page),
                JSON_THROW_ON_ERROR,
            )),
        );
    }
}

/**
 * A browser: a cookie jar per host, following redirects across them. A
 * host it has not been to before gives it a session of its own, as a
 * server issues one on a first visit.
 */
final class Browser
{
    /** @var array<string, string> host => session cookie */
    public array $jar = [];

    /** @var list<string> each URL visited, in order */
    public array $trail = [];

    public function __construct(private readonly App $app) {}

    /** Signed in on $host as $user, as a sign-in there would leave it. */
    public function signedInAt(string $host, string $user): self
    {
        $id = bin2hex(random_bytes(16));
        $this->app->sessions->sessions[$id] = ['user' => $user, 'realm' => $host];
        $this->jar[$host] = $id;

        return $this;
    }

    /** The session this browser holds on $host, making one up the first time. */
    public function sessionAt(string $host): string
    {
        return $this->jar[$host] ??= bin2hex(random_bytes(16));
    }

    /** One request to $url, keeping whatever cookie it sets. */
    public function get(string $url): Response
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $this->trail[] = $url;
        $response = $this->app->get($url, [SESSION_COOKIE => $this->sessionAt($host)]);

        $line = $response->cookies()[SESSION_COOKIE] ?? null;
        if ($line !== null) {
            $this->jar[$host] = explode(';', substr($line, \strlen(SESSION_COOKIE) + 1))[0];
        }

        return $response;
    }

    /** $url, and every redirect after it, until a response that is not one. */
    public function follow(string $url, int $hops = 8): Response
    {
        $response = $this->get($url);
        while ($hops-- > 0 && \in_array($response->status, [302, 303], true)) {
            $url = self::resolve($url, (string) $response->header('location'));
            $response = $this->get($url);
        }

        return $response;
    }

    /** A Location, made absolute against the URL it answered. */
    public static function resolve(string $from, string $location): string
    {
        if (str_starts_with($location, 'https://') || str_starts_with($location, 'http://')) {
            return $location;
        }

        $parts = parse_url($from);
        \assert(\is_array($parts));

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $location;
    }
}
