<?php

declare(strict_types=1);

use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PanelContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\Duration;
use Tinvar\Cache\QueryCache;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Orm\Fixtures\Fixture;

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
    })->with([
        '5', 'm', '5 minutes', '-1m', '1y', '',
        // A zero TTL writes an entry that is already expired: the cache would
        // look enabled and never hit.
        '0s',
        // Would overflow int*int to float and TypeError out of an int method.
        '9999999999999999999d',
    ]);
});

describe('the key', function () {
    // The panel now comes from a context rather than a constructor constant:
    // baked in at construction, every entry was keyed with whichever panel
    // the instance happened to be built for, so one worker serving two panels
    // could answer a narrow panel from a wide panel's entry.
    $cache = function (AuthContext $auth, ?string $panel = null): QueryCache {
        $panels = new PanelContext();
        if ($panel !== null) {
            $panels->within($panel, static fn (): bool => true);
        }

        return new QueryCache(new ArrayStore(), $auth, $panels);
    };

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

    it('separates two panels', function () {
        $auth = new AuthContext();
        $declaration = new QueryAttribute(cache: '1m');
        $panels = new PanelContext();
        $cache = new QueryCache(new ArrayStore(), $auth, $panels);

        $user = $panels->within('user', static fn (): string => $cache->key('q', $declaration, ['x' => 1], 'acme'));
        $admin = $panels->within('admin', static fn (): string => $cache->key('q', $declaration, ['x' => 1], 'acme'));

        expect($user)->not->toBe($admin);
    });

    // One instance, two panels, which is the case a constructor constant
    // could not express at all.
    it('keys one instance differently per panel', function () {
        $panels = new PanelContext();
        $cache = new QueryCache(new ArrayStore(), new AuthContext(), $panels);
        $declaration = new QueryAttribute(cache: '1m');

        $outside = $cache->key('q', $declaration, ['x' => 1], 'acme');
        $inside = $panels->within('admin', static fn (): string => $cache->key('q', $declaration, ['x' => 1], 'acme'));

        expect($outside)->not->toBe($inside, 'no panel is not the same as some panel');
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

    // ADR 0049: the spec's key names the user; this one names their auth
    // epoch beside them, so what was computed before the application moved
    // it is never looked up again.
    it('separates one user at two auth epochs', function () use ($cache) {
        $declaration = new QueryAttribute(cache: '1m');
        $at = function (string $epoch) use ($cache, $declaration): string {
            $auth = new AuthContext();
            $auth->login(user('u1'), $epoch);

            return $cache($auth)->key('q', $declaration, ['x' => 1], 'acme');
        };

        expect($at('1'))->not->toBe($at('2'))
            ->and($at('1'))->toBe($at('1'), 'the same epoch is the same key');
    });

    // AuthContext keeps a guest's epoch '', whatever is handed in, so every
    // guest still shares the one guest key -- and a user signing out lands on
    // it, never on the entries kept for them.
    it('keys a guest the same whatever epoch is handed in', function () use ($cache) {
        $declaration = new QueryAttribute(cache: '1m');
        $handed = new AuthContext();
        $handed->login(null, '7');
        $user = new AuthContext();
        $user->login(user('u1'), '7');

        expect($cache($handed)->key('q', $declaration, ['x' => 1], 'acme'))
            ->toBe($cache(new AuthContext())->key('q', $declaration, ['x' => 1], 'acme'))
            ->not->toBe($cache($user)->key('q', $declaration, ['x' => 1], 'acme'));
    });

    // Shared drops the user, and the epoch is the user's.
    it('drops the epoch with the user when the query is shared', function () use ($cache) {
        $shared = new QueryAttribute(cache: '1m', shared: true);
        $before = new AuthContext();
        $before->login(user('u1'), '1');
        $after = new AuthContext();
        $after->login(user('u1'), '2');

        expect($cache($before)->key('q', $shared, ['x' => 1], 'acme'))
            ->toBe($cache($after)->key('q', $shared, ['x' => 1], 'acme'));
    });

    // The epoch is the application's string. One that is not UTF-8 -- raw
    // random bytes, say -- has no JSON form, and would have left its user
    // uncached rather than keyed.
    it('keys an epoch of any bytes', function () {
        $store = new ArrayStore();
        $auth = new AuthContext();
        $auth->login(user('u1'), random_bytes(8) . "\xff");
        $cache = new QueryCache($store, $auth);
        $declaration = new QueryAttribute(cache: '1m');

        $cache->put('q', $declaration, ['x' => 1], 'acme', 'value');

        expect($cache->get('q', $declaration, ['x' => 1], 'acme'))->toBe(['hit' => true, 'value' => 'value']);
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
    it('builds them from the attribute closure, namespaced by tenant', function () {
        $cache = new QueryCache(new ArrayStore(), new AuthContext());
        $declaration = new QueryAttribute(
            cache: '1m',
            tags: static function (string $rack): array {
                return ["rack:{$rack}"];
            },
        );

        expect($cache->tagsFor($declaration, ['rack' => 'r1'], 'acme'))->toBe(["acme\0rack:r1"]);
    });

    it('refuses a tag that is not a string', function () {
        $cache = new QueryCache(new ArrayStore(), new AuthContext());
        $declaration = new QueryAttribute(
            cache: '1m',
            tags: static function (string $rack): array {
                return [['nested']];
            },
        );

        expect(fn () => $cache->tagsFor($declaration, ['rack' => 'r1'], 'acme'))
            ->toThrow(LogicException::class, 'must return strings');
    });

    // A flat namespace let a write in one tenant drop another's entries.
    it('does not let one tenant invalidate another', function () {
        $store = new ArrayStore();
        $cache = new QueryCache($store, new AuthContext());
        $declaration = new QueryAttribute(
            cache: '1m',
            tags: static function (string $rack): array {
                return ["rack:{$rack}"];
            },
        );

        $cache->put('q', $declaration, ['rack' => 'r1'], 'acme', 'acme value');
        $cache->put('q', $declaration, ['rack' => 'r1'], 'globex', 'globex value');

        expect($cache->forgetTags(['rack:r1'], 'globex', null))->toBe(1)
            ->and($cache->get('q', $declaration, ['rack' => 'r1'], 'acme')['hit'])
            ->toBeTrue('the other tenant is untouched');
    });

    // A mistyped closure is only reachable on a miss, after the body has run.
    it('does not cache, rather than failing, when the tag closure is wrong', function () {
        $store = new ArrayStore();
        $cache = new QueryCache($store, new AuthContext());
        $declaration = new QueryAttribute(
            cache: '1m',
            tags: static function (string $somethingElse): array {
                return ['x'];
            },
        );

        $cache->put('q', $declaration, ['rack' => 'r1'], 'acme', 'value');

        expect($store->stats()['entries'])->toBe(0)
            ->and($cache->failures())->toHaveCount(1);
    });

    it('invalidates every entry holding a tag', function () {
        $store = new ArrayStore();
        $cache = new QueryCache($store, new AuthContext());
        $declaration = new QueryAttribute(
            cache: '1m',
            tags: static function (string $rack): array {
                return ["rack:{$rack}"];
            },
        );

        $cache->put('q', $declaration, ['rack' => 'r1'], 'acme', 'one');
        $cache->put('q', $declaration, ['rack' => 'r2'], 'acme', 'two');

        expect($cache->forgetTags(['rack:r1'], 'acme', null))->toBe(1)
            ->and($cache->get('q', $declaration, ['rack' => 'r1'], 'acme')['hit'])->toBeFalse()
            ->and($cache->get('q', $declaration, ['rack' => 'r2'], 'acme')['hit'])->toBeTrue();
    });
});

it('expires an entry once its TTL has passed', function () {
    $now = 1000.0;
    $store = new ArrayStore(function () use (&$now): float {
        return $now;
    });
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
        // Tenant-namespaced, as QueryCache writes them. The ORM's tenant is
        // empty here, so the prefix is empty too.
        expect($store->forgetTags(["\0rack:{$rack}"]))->toBe(1);

        $query->run($call, allowed: ['racks.cached']);

        expect(Tinvar\Tests\Query\Fixtures\CachedSummary::$runs)->toBe(2, 'the body ran again');
    });
});

/**
 * ADR 0049. The gate runs before every lookup, but what a body that reads its
 * viewer returned is not asked again on a hit: keyed by the user alone, a
 * role taken away went on being served what the old role saw until the TTL
 * ran out. The application moves the user's auth epoch at that moment.
 */
describe('a moved auth epoch', function () {
    it('misses for the same user, and serves nothing kept before it moved', function () {
        $store = new ArrayStore();
        $auth = new AuthContext();
        $cache = new QueryCache($store, $auth);
        $declaration = new QueryAttribute(cache: '5m');

        $auth->login(user('u1'), '1');
        $cache->put('q', $declaration, ['x' => 1], 'acme', 'what staff see');

        $auth->login(user('u1'), '2');
        $miss = $cache->get('q', $declaration, ['x' => 1], 'acme');
        $cache->put('q', $declaration, ['x' => 1], 'acme', 'what a customer sees');

        expect($miss)->toBe(['hit' => false, 'value' => null])
            ->and($cache->get('q', $declaration, ['x' => 1], 'acme'))
            ->toBe(['hit' => true, 'value' => 'what a customer sees']);
    });

    it('leaves every other user\'s entries where they were', function () {
        $store = new ArrayStore();
        $auth = new AuthContext();
        $cache = new QueryCache($store, $auth);
        $declaration = new QueryAttribute(cache: '5m');

        $auth->login(user('u2'), '1');
        $cache->put('q', $declaration, ['x' => 1], 'acme', 'u2 at 1');
        $auth->login(user('u1'), '1');
        $cache->put('q', $declaration, ['x' => 1], 'acme', 'u1 at 1');

        // u1's role changes; u2's does not.
        $auth->login(user('u1'), '2');
        expect($cache->get('q', $declaration, ['x' => 1], 'acme')['hit'])->toBeFalse();

        $auth->login(user('u2'), '1');
        expect($cache->get('q', $declaration, ['x' => 1], 'acme'))->toBe(['hit' => true, 'value' => 'u2 at 1']);
    });

    // Through the runner, with a body that shapes its answer by the viewer's
    // role. The stale answer is the thing to rule out, not merely a miss.
    it('runs the body again as who the user is now, and only for them', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 3);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $auth = new AuthContext();
        $registry = new QueryRegistry(static fn (string $c): object => new $c($auth));
        $registry->register(Tinvar\Tests\Query\Fixtures\CachedForViewer::class);
        $runner = new QueryRunner($registry, $orm, new AllowAllGate(), cache: new QueryCache(new ArrayStore(), $auth));

        $viewer = Tinvar\Tests\Query\Fixtures\CachedForViewer::class;
        $viewer::$runs = 0;
        $viewer::$roles = ['u1' => 'staff', 'u2' => 'staff'];
        $call = [['id' => 0, 'name' => 'racks.cachedForViewer', 'args' => ['rack' => $fixture->ids['racks'][0]]]];
        /** @return array{name: string, units: int} */
        $as = static fn (string $id, string $epoch): array => $auth->actingAs(
            user($id),
            static fn (): array => $runner->run($call, allowed: ['racks.cachedForViewer'])[0]['value'],
            $epoch,
        );

        $staff = $as('u1', '1');
        $as('u2', '1');
        expect($staff['units'])->toBe(3)
            ->and($viewer::$runs)->toBe(2);

        // u1's staff role is taken away, and the application moves their epoch.
        $viewer::$roles['u1'] = 'customer';
        $now = $as('u1', '2');

        expect($now['name'])->toEndWith('as customer')
            ->and($now['units'])->toBe(0, 'nothing the staff role saw')
            ->and($viewer::$runs)->toBe(3, 'the body ran again for u1');

        // Then both are served from the cache again: u1 at the new epoch, and
        // u2, whose epoch never moved, as before.
        $again = $as('u1', '2');
        $other = $as('u2', '1');

        expect($again)->toBe($now)
            ->and($other['name'])->toEndWith('as staff')
            ->and($other['units'])->toBe(3)
            ->and($viewer::$runs)->toBe(3);
    });

    it('never serves a signed-out visitor what was kept for the user', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 3);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $auth = new AuthContext();
        $registry = new QueryRegistry(static fn (string $c): object => new $c($auth));
        $registry->register(Tinvar\Tests\Query\Fixtures\CachedForViewer::class);
        $runner = new QueryRunner($registry, $orm, new AllowAllGate(), cache: new QueryCache(new ArrayStore(), $auth));

        $viewer = Tinvar\Tests\Query\Fixtures\CachedForViewer::class;
        $viewer::$runs = 0;
        $viewer::$roles = ['u1' => 'staff'];
        $call = [['id' => 0, 'name' => 'racks.cachedForViewer', 'args' => ['rack' => $fixture->ids['racks'][0]]]];
        /** @return array{name: string, units: int} */
        $run = static fn (): array => $runner->run($call, allowed: ['racks.cachedForViewer'])[0]['value'];

        $auth->actingAs(user('u1'), $run, '1');
        // Signed out, with an epoch handed in all the same: a guest has none.
        $guest = $auth->actingAs(null, $run, '1');
        $another = $auth->actingAs(null, $run);

        expect($guest['name'])->toEndWith('as guest')
            ->and($guest['units'])->toBe(0)
            ->and($another)->toBe($guest)
            ->and($viewer::$runs)->toBe(2, 'one run for the user, one shared by every guest');
    });
});

// A store that outlives the code -- PdoStore across a deploy, or shared by
// servers on the old build and the new during a rolling one -- handed a
// build the answer another build's code gave, in that code's shape.
describe('another build', function () {
    it('misses for the same query, arguments and user, and keeps each build\'s answer apart', function () {
        $store = new ArrayStore();
        $auth = new AuthContext();
        $auth->login(user('u1'), '1');
        $declaration = new QueryAttribute(cache: '5m', tags: static fn (int $x): array => ['orders']);
        $old = new QueryCache($store, $auth, build: 'b-old');
        $new = new QueryCache($store, $auth, build: 'b-new');

        $old->put('q', $declaration, ['x' => 1], 'acme', ['total' => 3]);

        expect($new->get('q', $declaration, ['x' => 1], 'acme'))->toBe(['hit' => false, 'value' => null]);

        $new->put('q', $declaration, ['x' => 1], 'acme', ['total' => 3, 'currency' => 'USD']);

        expect($old->get('q', $declaration, ['x' => 1], 'acme'))->toBe(['hit' => true, 'value' => ['total' => 3]])
            ->and($new->get('q', $declaration, ['x' => 1], 'acme'))
            ->toBe(['hit' => true, 'value' => ['total' => 3, 'currency' => 'USD']]);
    });

    // Tags are not the build's: a write on a server of one build must reach
    // what a server of the other cached, or that one goes on serving it.
    it('is reached by a forget made from either build', function () {
        $store = new ArrayStore();
        $auth = new AuthContext();
        $declaration = new QueryAttribute(cache: '5m', tags: static fn (int $x): array => ['orders']);
        $old = new QueryCache($store, $auth, build: 'b-old');
        $new = new QueryCache($store, $auth, build: 'b-new');

        $old->put('q', $declaration, ['x' => 1], 'acme', 'old');
        $new->put('q', $declaration, ['x' => 1], 'acme', 'new');
        $new->forgetTags(['orders'], 'acme', null);

        expect($old->get('q', $declaration, ['x' => 1], 'acme')['hit'])->toBeFalse()
            ->and($new->get('q', $declaration, ['x' => 1], 'acme')['hit'])->toBeFalse();
    });
});

describe('the store keeps its index honest', function () {
    // forgetTags used to delete the entry and unset only the tag it was asked
    // about; every other tag kept pointing at a key that no longer existed.
    it('leaves no tag pointing at a key it deleted', function () {
        $store = new ArrayStore();
        $store->put('k1', 'v', 60, ['t1', 't2']);

        expect($store->forgetTags(['t1']))->toBe(1)
            ->and($store->stats()['entries'])->toBe(0)
            ->and($store->stats()['tags'])->toBe(0, 't2 no longer points at a deleted key');
    });

    it('replaces a key\'s tags on re-put rather than adding to them', function () {
        $store = new ArrayStore();
        $store->put('k1', 'first', 60, ['old']);
        $store->put('k1', 'second', 60, ['new']);

        expect($store->forgetTags(['old']))->toBe(0, 'the stale tag no longer reaches it')
            ->and($store->get('k1')['value'])->toBe('second')
            ->and($store->forgetTags(['new']))->toBe(1);
    });

    it('forgets a key from every tag index it was in', function () {
        $store = new ArrayStore();
        $store->put('k1', 'v', 60, ['a', 'b', 'c']);
        $store->forget('k1');

        expect($store->stats()['tags'])->toBe(0);
    });

    // Expiry is read-triggered, so an entry nobody reads is otherwise
    // immortal and a caller can mint keys without limit.
    it('caps how much it will hold', function () {
        $store = new ArrayStore(maxEntries: 50);

        for ($i = 0; $i < 500; $i++) {
            $store->put("k{$i}", $i, 60, ["t{$i}"]);
        }

        expect($store->stats()['entries'])->toBeLessThanOrEqual(50)
            ->and($store->stats()['tags'])->toBeLessThanOrEqual(50, 'the tag index was capped with it');
    });

    it('sweeps expired entries nobody read', function () {
        $now = 1000.0;
        $store = new ArrayStore(function () use (&$now): float {
            return $now;
        }, maxEntries: 10);

        for ($i = 0; $i < 10; $i++) {
            $store->put("old{$i}", $i, 30);
        }

        $now += 100;
        $store->put('fresh', 'v', 30);

        expect($store->stats()['entries'])->toBeLessThanOrEqual(10)
            ->and($store->get('fresh')['hit'])->toBeTrue();
    });
});

/**
 * "Revalidation reaches mounted fragments. When an action revalidates a tag,
 * the client re-fetches any mounted fragment that read it, not the whole
 * page."
 *
 * A fragment can only declare what it read if something records it. Nothing
 * did: ActionEndpoint shipped x-invalidated-tags, and the symmetric half --
 * a render declaring the tags it DEPENDS on -- did not exist.
 */
describe('tags read during a render', function () {
    it('records the tags a cacheable query depends on', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $cache = new QueryCache(new ArrayStore(), new AuthContext());

        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\CachedSummary::class);
        $runner = new QueryRunner(
            registry: $registry,
            orm: $orm,
            gate: new AllowAllGate(),
            cache: $cache,
        );

        $rack = $fixture->ids['racks'][0];
        $runner->run([['id' => 0, 'name' => 'racks.cached', 'args' => ['rack' => $rack]]], ['racks.cached']);

        expect($runner->tagsRead())->toHaveCount(1)
            ->and($runner->tagsRead()[0])->toContain("rack:{$rack}");
    });

    // A fragment served from cache still depends on its tags. Recording only
    // on a miss would stop the client re-fetching exactly the boundaries the
    // cache is keeping warm.
    it('records them on a cache hit too', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $cache = new QueryCache(new ArrayStore(), new AuthContext());

        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\CachedSummary::class);
        $runner = new QueryRunner(
            registry: $registry,
            orm: $orm,
            gate: new AllowAllGate(),
            cache: $cache,
        );

        $call = [['id' => 0, 'name' => 'racks.cached', 'args' => ['rack' => $fixture->ids['racks'][0]]]];

        $runner->run($call, ['racks.cached']);
        $runner->forgetTagsRead();
        $runner->run($call, ['racks.cached']);

        expect($runner->tagsRead())->toHaveCount(1);
    });

    // The runner may be shared across requests on a worker, and a fragment
    // declaring another request's tags would have the client re-fetching
    // boundaries that never read them.
    it('forgets them between renders', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\CachedSummary::class);
        $runner = new QueryRunner(
            registry: $registry,
            orm: $orm,
            gate: new AllowAllGate(),
            cache: new QueryCache(new ArrayStore(), new AuthContext()),
        );

        $runner->run(
            [['id' => 0, 'name' => 'racks.cached', 'args' => ['rack' => $fixture->ids['racks'][0]]]],
            ['racks.cached'],
        );
        $runner->forgetTagsRead();

        expect($runner->tagsRead())->toBe([]);
    });
});
