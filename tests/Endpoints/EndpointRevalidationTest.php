<?php

declare(strict_types=1);

/**
 * An endpoint that writes invalidates what it declares, as an action does
 * with the tags its result names (ADR 0075): the cached queries, every
 * client listening, every once-shared hold -- on a method that may change
 * something, once its body has returned. A GET writes nothing, and
 * invalidates nothing.
 */

namespace Tinvar\Tests\Endpoints\Revalidation;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Endpoint;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Post;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\ArrayTagVersions;
use Tinvar\Cache\QueryCache;
use Tinvar\Cache\TagNamespace;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Live\Topics;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

const KEY = 'endpoint-revalidation-key-of-32-bytes!!';

final class AnyonePolicy
{
    public function update(?Authenticatable $user, Rack $rack): bool
    {
        return $user !== null;
    }
}

final class Racks
{
    public function __construct(private readonly Orm $orm) {}

    #[Post('/api/racks/{rack}/rename', revalidates: ['racks', 'racks:names']), Authorize('update', 'rack')]
    public function rename(Rack $rack): void
    {
        $rack->name = 'Renamed';
        $this->orm->save($rack);
    }

    #[Endpoint('/api/racks/{rack}', methods: ['GET', 'PATCH'], revalidates: ['racks']), Authorize('update', 'rack')]
    public function touch(Rack $rack): void {}

    #[Post('/api/racks/{rack}/fail', revalidates: ['racks']), Authorize('update', 'rack')]
    public function fail(Rack $rack): void
    {
        throw new \RuntimeException('the write failed');
    }

    #[Post('/api/racks/{rack}/quiet'), Authorize('update', 'rack')]
    public function quiet(Rack $rack): void {}

    /** Names the record it changed by its path, as an action's result would (ADR 0106). */
    #[Post('/api/racks/{rack}/slots/{slot}', revalidates: ['racks', 'rack:{rack}', 'slot:{rack}:{slot}']), Authorize('update', 'rack')]
    public function slot(Rack $rack, string $slot): void
    {
        $rack->name = "Slot {$slot}";
        $this->orm->save($rack);
    }
}

final class UnknownPlaceholder
{
    #[Post('/api/racks/{rack}/x', revalidates: ['rack:{rck}']), NoAuthorization(reason: 'test')]
    public function x(string $rack): void {}
}

final class SignIn implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $this->auth->login(new User('owner'));

        return $next($request);
    }
}

final class World
{
    public function __construct(
        public readonly Fixture $fixture,
        public readonly Kernel $kernel,
        public readonly MemoryHub $hub,
        public readonly ArrayStore $store,
    ) {}

    public function rack(): string
    {
        return (string) $this->fixture->ids['racks'][0];
    }

    public function send(string $method, string $path): Response
    {
        return $this->kernel->handle(
            new Request($method, $path, ['x-xsrf-token' => 't'], cookies: [VerifyCsrfToken::COOKIE => 't'], remoteAddress: '192.0.2.1'),
            static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
        );
    }

    /** The tags whose topics the hub was told of, by tag. */
    public function told(string ...$tags): bool
    {
        $topics = new Topics(new Signer(KEY, Topics::PURPOSE));
        $published = array_column($this->hub->published, 'topic');
        foreach ($tags as $tag) {
            if (!\in_array($topics->for('', $tag), $published, true)) {
                return false;
            }
        }

        return true;
    }

    /** Puts a cached entry under a tag, and says later whether it is still there. */
    public function cache(string $key, string $tag): void
    {
        $this->store->put($key, 'kept', null, TagNamespace::written([$tag], '', null));
    }

    public function cached(string $key): bool
    {
        return $this->store->get($key)['hit'];
    }
}

function world(): World
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $auth = new AuthContext();
    $hub = new MemoryHub();
    $store = new ArrayStore();
    $registry = new EndpointRegistry();
    $registry->registerAll([Racks::class]);

    $kernel = new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: (new PolicyRegistry())->register(Rack::class, AnyonePolicy::class),
        resolver: static fn (string $class): object => match ($class) {
            SignIn::class => new SignIn($auth),
            Racks::class => new Racks($orm),
            default => new $class(),
        },
        allowedQueries: static fn (Request $r): array => [],
        cache: new QueryCache($store, $auth),
        globalMiddleware: [SignIn::class],
        live: LiveUpdates::fromKey(KEY, $hub, static fn (Request $r): string => 'session'),
        endpoints: $registry,
        tagVersions: new ArrayTagVersions(),
    );

    return new World($fixture, $kernel, $hub, $store);
}

it('invalidates the tags a POST declares, once its body has run', function () {
    $world = world();
    $world->cache('racks-list', 'racks');
    $before = $world->kernel->sharedTags?->stamp(['racks'], '');

    expect($world->send('POST', "/api/racks/{$world->rack()}/rename")->status)->toBe(204)
        ->and($world->cached('racks-list'))->toBeFalse()
        ->and($world->told('racks', 'racks:names'))->toBeTrue()
        ->and($world->kernel->sharedTags?->stamp(['racks'], ''))->not->toBe($before);
});

it('invalidates on the method that may write, and not on a GET of the same endpoint', function () {
    $world = world();
    $world->cache('racks-list', 'racks');

    expect($world->send('GET', "/api/racks/{$world->rack()}")->status)->toBe(204)
        ->and($world->cached('racks-list'))->toBeTrue()
        ->and($world->hub->published)->toBe([]);

    expect($world->send('PATCH', "/api/racks/{$world->rack()}")->status)->toBe(204)
        ->and($world->cached('racks-list'))->toBeFalse()
        ->and($world->told('racks'))->toBeTrue();
});

it('invalidates nothing when the body throws, and nothing it did not declare', function () {
    $world = world();
    $world->cache('racks-list', 'racks');

    expect($world->send('POST', "/api/racks/{$world->rack()}/fail")->status)->toBe(500)
        ->and($world->send('POST', "/api/racks/{$world->rack()}/quiet")->status)->toBe(204)
        ->and($world->cached('racks-list'))->toBeTrue()
        ->and($world->hub->published)->toBe([]);
});

final class GetThatRevalidates
{
    #[Endpoint('/api/thing', revalidates: ['things']), NoAuthorization(reason: 'test')]
    public function show(): void {}
}

it('refuses a GET-only endpoint that declares tags: it writes nothing to invalidate', function () {
    expect(fn () => (new EndpointRegistry())->register(GetThatRevalidates::class))
        ->toThrow(EndpointException::class, 'answers only GET, which writes nothing');
});

it('refuses a tag that is not one', function (array $tags) {
    expect(fn () => new Post('/api/x', revalidates: $tags))
        ->toThrow(\InvalidArgumentException::class, 'revalidates a tag that is not one');
})->with([
    'empty' => [['']],
    'a NUL' => [["racks\0acme"]],
]);

it('reads #[Get] as declaring none', function () {
    expect(Endpoint::declaredOn(new \ReflectionMethod(GetOnly::class, 'show'))?->revalidates)->toBe([]);
});

final class GetOnly
{
    #[Get('/api/only'), NoAuthorization(reason: 'test')]
    public function show(): void {}
}

// ADR 0106: an endpoint's tags name the record it changed, from its path,
// and its answer lists them for the caller's prefetch cache.
describe('a tag filled from the path', function () {
    it('invalidates the record the path names, and no other record\'s', function () {
        $world = world();
        $rack = $world->rack();
        $world->cache('this-rack', "rack:{$rack}");
        $world->cache('another-rack', 'rack:another');
        $world->cache('this-slot', "slot:{$rack}:a1");

        $response = $world->send('POST', "/api/racks/{$rack}/slots/a1");

        expect($response->status)->toBe(204)
            ->and($response->header('x-invalidated-tags'))->toBe("racks,rack:{$rack},slot:{$rack}:a1")
            ->and($world->cached('this-rack'))->toBeFalse()
            ->and($world->cached('this-slot'))->toBeFalse()
            ->and($world->cached('another-rack'))->toBeTrue()
            ->and($world->told("rack:{$rack}", "slot:{$rack}:a1"))->toBeTrue();
    });

    it('refuses a path segment no tag may carry, writing nothing', function (string $slot) {
        $world = world();
        $rack = $world->rack();
        $world->cache('racks-list', 'racks');

        expect($world->send('POST', "/api/racks/{$rack}/slots/{$slot}")->status)->toBe(400)
            ->and($world->cached('racks-list'))->toBeTrue()
            ->and($world->hub->published)->toBe([])
            ->and($world->fixture->connection->select('SELECT name FROM racks WHERE id = ?', [$rack])[0]['name'])->not->toStartWith('Slot');
    })->with([
        // A control character never reaches here: the request refuses it.
        'a comma' => ['a%2Cb'],
        'a bare comma' => ['a,b'],
    ]);

    it('refuses, at registration, a placeholder the path does not have', function () {
        expect(fn () => (new EndpointRegistry())->register(UnknownPlaceholder::class))
            ->toThrow(EndpointException::class, "has no parameter named 'rck'");
    });
});

it('lists the tags it invalidated for the caller, and none on a GET', function () {
    $world = world();

    expect($world->send('POST', "/api/racks/{$world->rack()}/rename")->header('x-invalidated-tags'))->toBe('racks,racks:names')
        ->and($world->send('GET', "/api/racks/{$world->rack()}")->header('x-invalidated-tags'))->toBeNull()
        ->and($world->send('PATCH', "/api/racks/{$world->rack()}")->header('x-invalidated-tags'))->toBe('racks')
        ->and($world->send('POST', "/api/racks/{$world->rack()}/quiet")->header('x-invalidated-tags'))->toBeNull();
});
