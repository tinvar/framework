<?php

declare(strict_types=1);

use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\Duration;
use Tinvar\Cache\QueryCache;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Validation\Validator;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';

function user(string $id): Authenticatable
{
    return new class ($id) implements Authenticatable {
        public function __construct(private string $id) {}

        public function authIdentifier(): string
        {
            return $this->id;
        }
    };
}

describe('durations', function () {
    it('parses the forms attributes carry', function (string $ttl, int $seconds) {
        expect(Duration::seconds($ttl))->toBe($seconds);
    })->with([['30s', 30], ['5m', 300], ['1h', 3600], ['1d', 86400], ['2w', 1209600]]);

    it('refuses anything else', function (string $ttl) {
        expect(fn () => Duration::seconds($ttl))->toThrow(InvalidArgumentException::class);
    })->with(['5', 'm', '5 minutes', '-1m', '1y', '']);
});

describe('the key', function () {
    $cache = fn (AuthContext $auth, string $panel = 'default'): QueryCache
        => new QueryCache(new ArrayStore(), $auth, $panel);

    // "The default cache key is the query name, its arguments, the tenant, the
    // user, the panel and its effective scope, the locale."
    it('separates two users', function () use ($cache) {
        $a = new AuthContext();
        $a->login(user('u1'));
        $b = new AuthContext();
        $b->login(user('u2'));

        $declaration = new QueryAttribute(cache: '1m');

        expect($cache($a)->key('q', $declaration, ['x' => 1], 'acme'))
            ->not->toBe($cache($b)->key('q', $declaration, ['x' => 1], 'acme'));
    });

    it('separates two tenants', function () use ($cache) {
        $auth = new AuthContext();
        $declaration = new QueryAttribute(cache: '1m');

        expect($cache($auth)->key('q', $declaration, ['x' => 1], 'acme'))
            ->not->toBe($cache($auth)->key('q', $declaration, ['x' => 1], 'globex'));
    });

    it('separates two panels', function () use ($cache) {
        $auth = new AuthContext();
        $declaration = new QueryAttribute(cache: '1m');

        expect($cache($auth, 'user')->key('q', $declaration, ['x' => 1], 'acme'))
            ->not->toBe($cache($auth, 'admin')->key('q', $declaration, ['x' => 1], 'acme'));
    });

    // "shared: true drops the user from the key."
    it('drops the user when the query is shared', function () use ($cache) {
        $a = new AuthContext();
        $a->login(user('u1'));
        $b = new AuthContext();
        $b->login(user('u2'));

        $shared = new QueryAttribute(cache: '1m', shared: true);

        expect($cache($a)->key('q', $shared, ['x' => 1], 'acme'))
            ->toBe($cache($b)->key('q', $shared, ['x' => 1], 'acme'));
    });

    it('keys a model argument on its class and id', function () use ($cache) {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $declaration = new QueryAttribute(cache: '1m');
        $c = $cache(new AuthContext());

        $first = $orm->find(Tinvar\Tests\Orm\Fixtures\Rack::class, $fixture->ids['racks'][0]);
        $second = $orm->find(Tinvar\Tests\Orm\Fixtures\Rack::class, $fixture->ids['racks'][1]);

        expect($c->key('q', $declaration, ['rack' => $first], 'acme'))
            ->not->toBe($c->key('q', $declaration, ['rack' => $second], 'acme'));
    });

    it('does not cache at all when the key cannot be built', function () {
        $store = new ArrayStore();
        $cache = new QueryCache($store, new AuthContext());
        $declaration = new QueryAttribute(cache: '1m');

        // A resource cannot be encoded; a lossy key would be one caller
        // reading another's result.
        $handle = fopen('php://memory', 'r');
        $cache->put('q', $declaration, ['h' => $handle], 'acme', 'value');

        expect($cache->get('q', $declaration, ['h' => $handle], 'acme')['hit'])->toBeFalse()
            ->and($store->stats()['entries'])->toBe(0);

        fclose($handle);
    });
});

describe('tags', function () {
    it('builds them from the attribute closure', function () {
        $cache = new QueryCache(new ArrayStore(), new AuthContext());
        $declaration = new QueryAttribute(
            cache: '1m',
            tags: static function (string $rack): array { return ["rack:{$rack}"]; },
        );

        expect($cache->tagsFor($declaration, ['rack' => 'r1']))->toBe(['rack:r1']);
    });

    it('invalidates every entry holding a tag', function () {
        $store = new ArrayStore();
        $cache = new QueryCache($store, new AuthContext());
        $declaration = new QueryAttribute(
            cache: '1m',
            tags: static function (string $rack): array { return ["rack:{$rack}"]; },
        );

        $cache->put('q', $declaration, ['rack' => 'r1'], 'acme', 'one');
        $cache->put('q', $declaration, ['rack' => 'r2'], 'acme', 'two');

        expect($cache->forgetTags(['rack:r1']))->toBe(1)
            ->and($cache->get('q', $declaration, ['rack' => 'r1'], 'acme')['hit'])->toBeFalse()
            ->and($cache->get('q', $declaration, ['rack' => 'r2'], 'acme')['hit'])->toBeTrue();
    });
});

it('expires an entry once its TTL has passed', function () {
    $now = 1000.0;
    $store = new ArrayStore(function () use (&$now): float { return $now; });
    $cache = new QueryCache($store, new AuthContext());
    $declaration = new QueryAttribute(cache: '30s');

    $cache->put('q', $declaration, [], 'acme', 'value');
    expect($cache->get('q', $declaration, [], 'acme')['hit'])->toBeTrue();

    $now += 31;
    expect($cache->get('q', $declaration, [], 'acme')['hit'])->toBeFalse();
});

describe('through the runner', function () {
    /** @return array{0: QueryRunner, 1: Fixture, 2: ArrayStore} */
    $runner = function (): array {
        $fixture = new Fixture(racks: 2, unitsPerRack: 3);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\CachedSummary::class);
        $store = new ArrayStore();

        return [
            new QueryRunner(
                $registry,
                $orm,
                new AllowAllGate(),
                cache: new QueryCache($store, new AuthContext()),
            ),
            $fixture,
            $store,
        ];
    };

    it('serves a second identical call from the cache', function () use ($runner) {
        [$query, $fixture, $store] = $runner();
        Tinvar\Tests\Query\Fixtures\CachedSummary::$runs = 0;
        $call = [['id' => 0, 'name' => 'racks.cached', 'args' => ['rack' => $fixture->ids['racks'][0]]]];

        $first = $query->run($call, allowed: ['racks.cached']);
        $second = $query->run($call, allowed: ['racks.cached']);

        // Counted rather than inferred from statements: with the identity map
        // warm, a body that re-ran might issue none.
        expect($second[0]['value'])->toBe($first[0]['value'])
            ->and($store->stats()['hits'])->toBeGreaterThan(0)
            ->and(Tinvar\Tests\Query\Fixtures\CachedSummary::$runs)->toBe(1, 'the body ran once');
    });

    it('does not cache a failed call', function () use ($runner) {
        [$query, , $store] = $runner();

        $query->run(
            [['id' => 0, 'name' => 'racks.cached', 'args' => ['rack' => '0192f3a1-0000-7000-8000-000000000000']]],
            allowed: ['racks.cached'],
        );

        expect($store->stats()['entries'])->toBe(0);
    });

    it('misses again once the tag is revalidated', function () use ($runner) {
        [$query, $fixture, $store] = $runner();
        $rack = $fixture->ids['racks'][0];
        $call = [['id' => 0, 'name' => 'racks.cached', 'args' => ['rack' => $rack]]];

        Tinvar\Tests\Query\Fixtures\CachedSummary::$runs = 0;
        $query->run($call, allowed: ['racks.cached']);
        expect($store->forgetTags(["rack:{$rack}"]))->toBe(1);

        $query->run($call, allowed: ['racks.cached']);

        expect(Tinvar\Tests\Query\Fixtures\CachedSummary::$runs)->toBe(2, 'the body ran again');
    });
});
