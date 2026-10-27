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
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\Arrival;
use Tinvar\Identity\HandoffCode;
use Tinvar\Identity\HandoffConsent;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Identity\HandoffSessions;
use Tinvar\Identity\Realm;
use Tinvar\Identity\Realms;
use Tinvar\Auth\UserLookup;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Http\HostName;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Tests\Orm\Fixtures\Tenancy;
use Tinvar\Http\ArrayNonceStore;

require_once __DIR__ . '/../../../../database/tests/Orm/Fixtures/tenancy.php';

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
 * Registered with the Orm as tenancy would register a tree, through its
 * port, so the framework's suite runs without tinvar/tenancy.
 */
final class ResellerOverAcme implements TenantSubtrees
{
    public function hierarchical(): bool
    {
        return true;
    }

    public function ancestors(string $tenant): array
    {
        return $tenant === 'acme' ? ['reseller'] : [];
    }

    public function subtree(string $root): array
    {
        return $root === 'reseller' ? ['reseller', 'acme'] : [$root];
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
        if ($row !== null && $row['realm'] === HostName::normalize($request->header('host') ?? '')) {
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
    public readonly CsrfTokens $csrf;
    public int $now = 1_800_000_000;

    /** @var list<string> the message of everything the Kernel logged: why each refusal was one */
    public array $log = [];

    /**
     * Hosts whose record has changed since the App was built: a custom
     * domain moved to another tenant or panel, or gone (null).
     *
     * @var array<string, Realm|null>
     */
    public array $moved = [];

    /**
     * @param (\Closure(Request, HandoffConsent): Response)|null $consent the application's own
     *        consent screen; the framework's unless given
     * @param bool $tenancy whether tenancy is registered with the Orm; without it, no tree
     */
    public function __construct(?TenantSubtrees $tree = null, ?\Closure $consent = null, bool $tenancy = true)
    {
        $this->orm = new Orm(
            new PdoConnection(new \PDO('sqlite::memory:'), 'identity-test'),
            new IdentityMap('identity-test'),
            match (true) {
                $tree !== null => $tree,
                $tenancy => Tenancy::flat(),
                default => null,
            },
        );
        $this->auth = new AuthContext();
        $this->directory = new Directory($this->orm);
        $this->sessions = new SessionStore();
        $this->arrivals = new Arrivals($this->sessions);
        $this->signer = new Signer(KEY, HandoffCode::PURPOSE);
        $this->realms = self::realms($this);
        $sessionId = static fn (Request $r): string => $r->cookie(SESSION_COOKIE) ?? '';
        $this->csrf = new CsrfTokens(new Signer(KEY, CsrfTokens::PURPOSE), $sessionId);

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
            sessionId: $sessionId,
            auth: $this->auth,
            orm: $this->orm,
            users: $this->directory,
            sessions: $this->arrivals,
            signIn: static fn (string $back): Response => Response::redirect('/sign-in?next=' . rawurlencode($back)),
            memberships: $this->directory,
            staff: static fn (Authenticatable $user): bool => $directory->isStaff($user),
            clock: fn (): int => $this->now,
            consent: $consent,
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
            csrf: $this->csrf,
            handoff: $this->endpoint,
        );
    }

    /** @param App|null $app whose records have moved since, if any */
    public static function realms(?self $app = null): Realms
    {
        return new Realms(
            Realm::platform('https://id.platform.test'),
            [Realm::platform('https://admin.platform.test', panel: 'admin')],
            static fn (string $host): ?Realm => $app !== null && \array_key_exists($host, $app->moved) ? $app->moved[$host] : match ($host) {
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
        return $this->send('GET', $url, [], $cookies);
    }

    /**
     * A form posted to $url, as a browser posts one without script.
     *
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     */
    public function post(string $url, array $body, array $cookies = []): Response
    {
        return $this->send('POST', $url, $body, $cookies);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     */
    private function send(string $method, string $url, array $body, array $cookies): Response
    {
        $parts = parse_url($url);
        \assert(\is_array($parts) && isset($parts['host']));
        $raw = $parts['query'] ?? '';
        parse_str($raw, $query);
        /** @var array<string, string|list<string>> $query */
        $headers = ['host' => $parts['host'], 'accept' => 'application/json'];
        if ($method === 'POST') {
            $headers = ['host' => $parts['host'], 'accept' => 'text/html', 'content-type' => 'application/x-www-form-urlencoded'];
        }

        return $this->kernel->handle(
            new Request($method, $parts['path'] ?? '/', $headers, $query, $body, $cookies, $raw),
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
 * server issues one on a first visit. Following, it answers a consent
 * screen as $consent says -- confirming it, unless told otherwise.
 */
final class Browser
{
    /** @var array<string, string> host => session cookie */
    public array $jar = [];

    /** @var array<string, string> host => the CSRF cookie a page there set */
    public array $xsrf = [];

    /** @var list<string> each URL visited, in order */
    public array $trail = [];

    /** What follow() answers a consent screen with; null stops at the screen. */
    public ?string $consent = HandoffConsent::CONFIRM;

    /** @var array<int, string> each consent screen shown, by object id => the host it was shown on */
    private array $shownOn = [];

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

        return $this->keep($host, $this->app->get($url, $this->cookiesFor($host)));
    }

    /**
     * A form posted to $url with this browser's cookies for its host.
     *
     * @param array<string, string> $body
     */
    public function post(string $url, array $body): Response
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $this->trail[] = 'POST ' . $url;

        return $this->keep($host, $this->app->post($url, $body, $this->cookiesFor($host)));
    }

    /**
     * The consent screen $screen answered, as a person pressing one of its
     * buttons does: that form's fields, posted where it says, on the host
     * the screen was shown on -- with $edit laid over them.
     *
     * @param array<string, string> $edit
     */
    public function answer(Response $screen, string $decision = HandoffConsent::CONFIRM, array $edit = []): Response
    {
        [$action, $fields] = self::form($screen, $decision);
        $shownOn = $this->shownOn[spl_object_id($screen)] ?? throw new \LogicException('This browser was never shown that screen.');

        return $this->post("https://{$shownOn}{$action}", [...$fields, ...$edit]);
    }

    /**
     * The action and hidden fields of the form on $screen that posts $decision.
     *
     * @return array{string, array<string, string>}
     */
    public static function form(Response $screen, string $decision): array
    {
        preg_match_all('#<form method="post" action="([^"]*)">(.*?)</form>#s', $screen->body(), $forms, PREG_SET_ORDER);
        foreach ($forms as [, $action, $inner]) {
            preg_match_all('#<input type="hidden" name="([^"]*)" value="([^"]*)">#', $inner, $inputs, PREG_SET_ORDER);
            $fields = [];
            foreach ($inputs as [, $name, $value]) {
                $fields[html_entity_decode($name)] = html_entity_decode($value);
            }
            if (($fields[HandoffConsent::DECISION] ?? null) === $decision) {
                return [html_entity_decode($action), $fields];
            }
        }

        throw new \RuntimeException("The response has no form posting '{$decision}': " . $screen->body());
    }

    /** Whether $response is a consent screen. */
    public static function isConsent(Response $response): bool
    {
        return $response->status === 200 && str_contains($response->body(), 'name="' . HandoffConsent::TICKET . '"');
    }

    /**
     * $url, and every redirect after it -- answering any consent screen on
     * the way as $consent says -- until a response that is neither.
     */
    public function follow(string $url, int $hops = 8): Response
    {
        $response = $this->get($url);
        while ($hops-- > 0) {
            if ($this->consent !== null && self::isConsent($response)) {
                $response = $this->answer($response, $this->consent);
                $url = str_replace('POST ', '', (string) end($this->trail));
            } elseif (\in_array($response->status, [302, 303], true)) {
                $url = self::resolve($url, (string) $response->header('location'));
                $response = $this->get($url);
            } else {
                break;
            }
        }

        return $response;
    }

    /** @return array<string, string> */
    private function cookiesFor(string $host): array
    {
        $cookies = [SESSION_COOKIE => $this->sessionAt($host)];
        if (isset($this->xsrf[$host])) {
            $cookies[VerifyCsrfToken::COOKIE] = $this->xsrf[$host];
        }

        return $cookies;
    }

    private function keep(string $host, Response $response): Response
    {
        if (self::isConsent($response)) {
            $this->shownOn[spl_object_id($response)] = $host;
        }

        foreach ([SESSION_COOKIE, VerifyCsrfToken::COOKIE] as $name) {
            $line = $response->cookies()[$name] ?? null;
            if ($line === null) {
                continue;
            }
            $value = explode(';', substr($line, \strlen($name) + 1))[0];
            if ($name === SESSION_COOKIE) {
                $this->jar[$host] = $value;
            } else {
                $this->xsrf[$host] = $value;
            }
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
