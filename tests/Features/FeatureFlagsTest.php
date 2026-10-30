<?php

declare(strict_types=1);

/**
 * Feature flags (ADR 0094): declared, set per user, tenant, panel and
 * everyone, read for the scope in force -- and every read reported, so a
 * cached answer is served only to callers whose flags read as its body's
 * did, and nothing kept past its request varies by a flag no one recorded.
 */

use Tinvar\Attributes\Query as QueryAttribute;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PanelContext;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\PdoStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Data\SharedReads;
use Tinvar\Data\SharedRegistry;
use Tinvar\Features\DatabaseFlagValues;
use Tinvar\Features\Features;
use Tinvar\Features\Flag;
use Tinvar\Features\FlagReads;
use Tinvar\Features\FlagRules;
use Tinvar\Features\FlagScope;
use Tinvar\Features\FlagValues;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentManifest;
use Tinvar\Render\StaticBuilder;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\RouteKind;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Data\Fixtures\Shared\OnceShared;
use Tinvar\Tests\Data\Fixtures\Shared\PageWithGreetingData;
use Tinvar\Tests\Data\Fixtures\Shared\Reads;
use Tinvar\Tests\Features\Fixtures\FlaggedLazyRack;
use Tinvar\Tests\Features\Fixtures\FlaggedRack;
use Tinvar\Tests\Features\Fixtures\FlaggedStaticPage;
use Tinvar\Tests\Features\Fixtures\FlagReadElsewhere;
use Tinvar\Tests\Features\Fixtures\FlagReadForAnotherScope;
use Tinvar\Tests\Features\Fixtures\SharedWithFeatures;
use Tinvar\Tests\Features\Fixtures\Flags;
use Tinvar\Tests\Features\Fixtures\PlainRack;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';
require_once __DIR__ . '/../Data/Fixtures/shared-once.php';
require_once __DIR__ . '/Fixtures/queries.php';

function flagUser(string $id): Authenticatable
{
    return new class ($id) implements Authenticatable {
        public function __construct(private string $id) {}

        public function authIdentifier(): string
        {
            return $this->id;
        }
    };
}

/** A FlagValues the test changes as it goes. */
final class MutableFlagValues implements FlagValues
{
    public int $reads = 0;

    public function __construct(public FlagRules $rules) {}

    public function valueFor(string $flag, FlagScope $scope): ?bool
    {
        $this->reads++;

        return $this->rules->valueFor($flag, $scope);
    }
}

/**
 * A runner over the fixture racks, its queries built with $features, caching
 * in $store under $cacheFeatures -- $features unless told otherwise.
 *
 * @return array{QueryRunner, Fixture, AuthContext}
 */
function flaggedRunner(Features $features, AuthContext $auth, Orm $orm, Fixture $fixture, ArrayStore $store, bool $cacheReadsFlags = true): array
{
    $registry = new QueryRegistry(static fn (string $class): object => $class === PlainRack::class ? new $class() : new $class($features->reader()));
    $registry->registerAll([FlaggedRack::class, FlaggedLazyRack::class, PlainRack::class, FlagReadElsewhere::class]);
    $cache = new QueryCache($store, $auth, features: $cacheReadsFlags ? $features : null);

    return [new QueryRunner($registry, $orm, new AllowAllGate(), cache: $cache), $fixture, $auth];
}

/**
 * @param list<string> $queries
 * @return list<array{name: string, units: int}>
 */
function runFlagged(QueryRunner $runner, Fixture $fixture, array $queries): array
{
    $calls = [];
    foreach ($queries as $id => $name) {
        $calls[] = ['id' => $id, 'name' => $name, 'args' => ['rack' => $fixture->ids['racks'][0]]];
    }
    $results = $runner->run($calls, allowed: $queries);
    usort($results, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

    return array_map(static fn (array $result): array => $result['value'], $results);
}

beforeEach(function () {
    FlaggedRack::$runs = 0;
    FlaggedLazyRack::$runs = 0;
    PlainRack::$runs = 0;
    FlagReadElsewhere::$runs = 0;
    FlagReads::closeAll();
});

describe('a flag', function () {
    it('has a name a key can carry', function (string $name) {
        expect(fn () => new Flag($name))->toThrow(\InvalidArgumentException::class, 'is not a flag name');
    })->with(['', 'Upper', '1st', 'two  words', 'trailing-', 'a..b', str_repeat('a', 65)]);

    it('is the most specific value set: the user, the tenant, the panel, everyone, then its default', function () {
        $rules = FlagRules::new()
            ->forEveryone('beta', false)
            ->forPanel('beta', 'admin', true)
            ->forTenant('beta', 'acme', false)
            ->forUser('beta', 'u1', true);

        expect($rules->valueFor('beta', new FlagScope('acme', 'u1', 'admin')))->toBeTrue()
            ->and($rules->valueFor('beta', new FlagScope('acme', 'u2', 'admin')))->toBeFalse()
            ->and($rules->valueFor('beta', new FlagScope('', 'u2', 'admin')))->toBeTrue()
            ->and($rules->valueFor('beta', new FlagScope('', null, null)))->toBeFalse()
            ->and($rules->valueFor('other', new FlagScope('acme', 'u1', 'admin')))->toBeNull();
    });

    it('is set for someone, never for no one', function () {
        expect(fn () => FlagRules::new()->forTenant('beta', '', true))->toThrow(\InvalidArgumentException::class);
    });

    it('is read for the request\'s tenant, user and panel, once per request until the reset', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $auth = new AuthContext();
        $panels = new PanelContext();
        $values = new MutableFlagValues(FlagRules::new()->forPanel('beta', 'admin', true));
        $features = new Features([new Flag('beta')], $values, $auth, $orm, $panels);

        expect($features->active('beta'))->toBeFalse()
            ->and($panels->within('admin', static fn (): bool => $features->active('beta')))->toBeTrue();

        $auth->login(flagUser('u1'));
        $values->rules = $values->rules->forUser('beta', 'u1', true);
        expect($features->active('beta'))->toBeTrue();

        // Switched off mid-request: this request keeps what it read.
        $values->rules = $values->rules->forUser('beta', 'u1', false);
        $reads = $values->reads;
        expect($features->active('beta'))->toBeTrue()
            ->and($values->reads)->toBe($reads);

        $features->reset();
        expect($features->active('beta'))->toBeFalse();
    });

    it('is refused when it is not declared, rather than read as off', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $features = new Features([new Flag('beta')], FlagRules::new(), new AuthContext(), new Orm($fixture->connection, new IdentityMap('test')));

        expect(fn () => $features->active('bta'))->toThrow(\LogicException::class, "'bta' is not declared");
        expect(fn () => new Features([new Flag('beta'), new Flag('beta')], FlagRules::new(), new AuthContext(), new Orm($fixture->connection, new IdentityMap('test'))))
            ->toThrow(\InvalidArgumentException::class, 'declared twice');
    });
});

describe('a table of values', function () {
    $store = function (): DatabaseFlagValues {
        $connection = PdoConnection::sqlite(":memory:", "test");
        DatabaseFlagValues::define(new Schema($connection, Dialect::for($connection)));

        return new DatabaseFlagValues($connection);
    };

    it('reads the most specific row for the scope', function () use ($store) {
        $values = $store();
        $values->set('beta', 'everyone', '', true);
        $values->set('beta', 'tenant', 'acme', false);
        $values->set('beta', 'user', 'u1', true);

        expect($values->valueFor('beta', new FlagScope('acme', 'u1')))->toBeTrue()
            ->and($values->valueFor('beta', new FlagScope('acme', 'u2')))->toBeFalse()
            ->and($values->valueFor('beta', new FlagScope('', 'u2')))->toBeTrue()
            ->and($values->valueFor('other', new FlagScope('', 'u2')))->toBeNull();

        $values->set('beta', 'user', 'u1', null);
        expect($values->valueFor('beta', new FlagScope('acme', 'u1')))->toBeFalse();
    });

    it('refuses a row it cannot read as on or off, and a part with no one to be for', function () use ($store) {
        $connection = PdoConnection::sqlite(":memory:", "test");
        DatabaseFlagValues::define(new Schema($connection, Dialect::for($connection)));
        $connection->execute("INSERT INTO feature_flags (flag, scope, subject, active) VALUES ('beta', 'everyone', '', 7)");

        expect(fn () => new DatabaseFlagValues($connection)->valueFor('beta', new FlagScope()))
            ->toThrow(\UnexpectedValueException::class)
            ->and(fn () => $store()->set('beta', 'tenant', '', true))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $store()->set('beta', 'everyone', 'acme', true))->toThrow(\InvalidArgumentException::class);
    });
});

describe('the query cache', function () {
    $setup = function (bool $cacheReadsFlags = true): array {
        $fixture = new Fixture(racks: 1, unitsPerRack: 3);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $auth = new AuthContext();
        $values = new MutableFlagValues(FlagRules::new()->forUser('rack.units', 'u1', true)->forUser('rack.units', 'u3', true));
        $features = new Features([new Flag('rack.units')], $values, $auth, $orm);
        [$runner] = flaggedRunner($features, $auth, $orm, $fixture, new ArrayStore(), $cacheReadsFlags);

        $as = static function (string $user, array $queries = ['racks.flagged']) use ($auth, $features, $runner, $fixture): array {
            // A request each: its flags read afresh.
            $features->reset();

            return $auth->actingAs(flagUser($user), static fn (): array => runFlagged($runner, $fixture, $queries));
        };

        return [$as, $values, $features];
    };

    it('keeps a shared answer apart by the flags its body read, and shares it where they agree', function () use ($setup) {
        [$as] = $setup();

        // The first answer for a call names its flag, and is kept from the
        // lookup after: only a lookup that missed under the flag's value
        // gives the store a miss to hold the put to.
        $on = $as('u1');
        $off = $as('u2');
        $alsoOn = $as('u3');
        $alsoOff = $as('u4');
        $again = $as('u1');

        expect($on[0]['units'])->toBe(3)
            ->and($off[0]['units'])->toBe(0, 'not u1\'s answer, though the user is not in the key')
            ->and($alsoOn)->toBe($on)
            ->and($alsoOff)->toBe($off)
            ->and($again)->toBe($on)
            ->and(FlaggedRack::$runs)->toBe(3, 'the marker, then one run for each value of the flag');
    });

    it('keys an answer by a flag its Lazy field read, after the body returned', function () use ($setup) {
        [$as] = $setup();

        $on = $as('u1', ['racks.flaggedLazily']);
        $off = $as('u2', ['racks.flaggedLazily']);
        $alsoOn = $as('u3', ['racks.flaggedLazily']);
        $alsoOff = $as('u4', ['racks.flaggedLazily']);

        expect($on[0]['units'])->toBe(3)
            ->and($off[0]['units'])->toBe(0, 'not u1\'s answer: the flag was read as the result was encoded')
            ->and($alsoOn)->toBe($on)
            ->and($alsoOff)->toBe($off)
            ->and(FlaggedLazyRack::$runs)->toBe(3, 'the marker, then one run for each value of the flag');
    });

    it('runs the body again when a flag the answer read has changed', function () use ($setup) {
        [$as, $values] = $setup();

        expect($as('u1')[0]['units'])->toBe(3)
            ->and($as('u1')[0]['units'])->toBe(3);
        $values->rules = $values->rules->forUser('rack.units', 'u1', false);

        expect($as('u1')[0]['units'])->toBe(0)
            ->and(FlaggedRack::$runs)->toBe(3)
            ->and($as('u3')[0]['units'])->toBe(3, 'the answer for the flag on is still kept')
            ->and(FlaggedRack::$runs)->toBe(3);
    });

    it('keeps nothing whose read it cannot attribute, and keeps its neighbours in the same tick', function () use ($setup) {
        [$as] = $setup();

        foreach ([1, 2, 3] as $_) {
            $as('u1', ['racks.elsewhere', 'racks.plain', 'racks.flagged']);
        }

        expect(FlagReadElsewhere::$runs)->toBe(3, 'read in a fiber no recorder holds: never cached')
            ->and(PlainRack::$runs)->toBe(1)
            ->and(FlaggedRack::$runs)->toBe(2);
    });

    it('keeps no answer that read a flag when the cache has no Features to read it again', function () use ($setup) {
        [$as] = $setup(cacheReadsFlags: false);

        $as('u1', ['racks.flagged', 'racks.plain']);
        $as('u1', ['racks.flagged', 'racks.plain']);

        expect(FlaggedRack::$runs)->toBe(2)
            ->and(PlainRack::$runs)->toBe(1, 'one that read none is kept as ever');
    });

    it('never writes back an answer for other flags that was forgotten between the lookup and the put', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $auth = new AuthContext();
        $rules = FlagRules::new()->forUser('rack.units', 'u1', true);
        $features = new Features([new Flag('rack.units')], $rules, $auth, new Orm($fixture->connection, new IdentityMap('test')));
        $cache = new QueryCache(new ArrayStore(), $auth, features: $features);
        $declaration = new QueryAttribute(cache: '5m', shared: true, tags: static fn (int $x): array => ['racks']);
        $reading = static function (bool $active): FlagReads {
            $reads = new FlagReads();
            $reads->task(static fn () => FlagReads::record('rack.units', $active));

            return $reads;
        };
        $as = static function (string $user, \Closure $work) use ($auth, $features): mixed {
            $features->reset();

            return $auth->actingAs(flagUser($user), $work);
        };

        $as('u1', static fn () => $cache->put('q', $declaration, ['x' => 1], '', 'before the write', $reading(true)));

        // u2's lookup finds u1's answer, which is not theirs; a write then
        // forgets everything tagged; u2's body answers and is put.
        $as('u2', static function () use ($cache, $declaration, $reading): void {
            expect($cache->get('q', $declaration, ['x' => 1], '')['hit'])->toBeFalse();
            $cache->forgetTags(['racks'], '', null);
            $cache->put('q', $declaration, ['x' => 1], '', 'after the write', $reading(false));
        });

        expect($as('u1', static fn () => $cache->get('q', $declaration, ['x' => 1], ''))['hit'])->toBeFalse()
            ->and($as('u2', static fn () => $cache->get('q', $declaration, ['x' => 1], '')))->toBe(['hit' => true, 'value' => 'after the write']);
    });

    it('refuses an answer for one flag\'s value read before a forget another worker made after the lookup', function () {
        $directory = sys_get_temp_dir() . '/tinvar-flags-cache-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        try {
            $mine = new PdoStore(new \PDO("sqlite:{$directory}/cache.sqlite"));
            $mine->install();
            $theirs = new PdoStore(new \PDO("sqlite:{$directory}/cache.sqlite"));

            $fixture = new Fixture(racks: 1, unitsPerRack: 1);
            $auth = new AuthContext();
            $features = new Features([new Flag('rack.units')], FlagRules::new()->forUser('rack.units', 'u1', true), $auth, new Orm($fixture->connection, new IdentityMap('test')));
            $cache = new QueryCache($mine, $auth, features: $features);
            $other = new QueryCache($theirs, $auth, features: $features);
            $declaration = new QueryAttribute(cache: '5m', shared: true, tags: static fn (int $x): array => ['racks']);
            $as = static function (string $user, \Closure $work) use ($auth, $features): mixed {
                $features->reset();

                return $auth->actingAs(flagUser($user), $work);
            };
            $answer = static function (QueryCache $cache, bool $active, string $value) use ($declaration): void {
                $reads = new FlagReads();
                $reads->task(static fn () => FlagReads::record('rack.units', $active));
                $cache->put('q', $declaration, ['x' => 1], '', $value, $reads);
            };

            // The marker, then u1's answer.
            foreach ([1, 2] as $_) {
                $as('u1', static function () use ($cache, $declaration, $answer): void {
                    $cache->get('q', $declaration, ['x' => 1], '');
                    $answer($cache, true, 'on');
                });
            }

            // u2 misses under their value; another worker's write forgets
            // the tag; u2's body, which read before it, answers.
            $as('u2', static function () use ($cache, $other, $declaration, $answer): void {
                expect($cache->get('q', $declaration, ['x' => 1], '')['hit'])->toBeFalse();
                $other->forgetTags(['racks'], '', null);
                $answer($cache, false, 'read before the write');
            });

            expect($as('u2', static fn () => $cache->get('q', $declaration, ['x' => 1], ''))['hit'])->toBeFalse()
                ->and($as('u1', static fn () => $cache->get('q', $declaration, ['x' => 1], ''))['hit'])->toBeFalse();
        } finally {
            exec('rm -rf ' . escapeshellarg($directory));
        }
    });

    it('misses, never serves, an entry it did not write', function (mixed $entry) {
        $auth = new AuthContext();
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $features = new Features([new Flag('rack.units')], FlagRules::new(), $auth, new Orm($fixture->connection, new IdentityMap('test')));
        $store = new ArrayStore();
        $cache = new QueryCache($store, $auth, features: $features);
        $declaration = new QueryAttribute(cache: '5m');

        $store->put($cache->key('q', $declaration, ['x' => 1], ''), $entry);

        expect($cache->get('q', $declaration, ['x' => 1], '')['hit'])->toBeFalse();
    })->with([
        'a bare value' => ['forged'],
        'an answer with a flag that is not one' => [['k' => 'answer', 'r' => ['rack.units' => 'yes'], 'v' => 'forged']],
        'an undeclared flag' => [['k' => 'answer', 'r' => ['nope' => false], 'v' => 'forged']],
        'a flag read as it is not' => [['k' => 'answer', 'r' => ['rack.units' => true], 'v' => 'forged']],
        'extra keys' => [['k' => 'answer', 'r' => [], 'v' => 'forged', 'x' => 1]],
        'a marker naming no flag' => [['k' => 'flags', 'f' => []]],
        'a marker naming something else' => [['k' => 'flags', 'f' => [1]]],
    ]);
});

describe('a flag read for a scope the body names', function () {
    it('fails the build of a shared query handed the whole Features, which can name any scope', function () {
        $registry = new QueryRegistry(static fn (string $class): object => new $class());

        expect(fn () => $registry->register(SharedWithFeatures::class))
            ->toThrow(\InvalidArgumentException::class, 'shared-query-must-not-read-the-user');
    });

    it('keeps no answer whose body read a flag for anyone but its caller', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $auth = new AuthContext();
        $features = new Features([new Flag('rack.units')], FlagRules::new()->forUser('rack.units', 'u1', true)->forUser('rack.units', 'u2', true), $auth, $orm);
        $registry = new QueryRegistry(static fn (string $class): object => new $class($features));
        $registry->register(FlagReadForAnotherScope::class);
        $runner = new QueryRunner($registry, $orm, new AllowAllGate(), cache: new QueryCache(new ArrayStore(), $auth, features: $features));
        FlagReadForAnotherScope::$runs = 0;

        foreach ([1, 2] as $_) {
            $features->reset();
            $auth->actingAs(flagUser('u2'), static fn (): array => runFlagged($runner, $fixture, ['racks.otherScope']));
        }

        expect(FlagReadForAnotherScope::$runs)->toBe(2);
    });

    it('makes the scope itself a read no answer can be kept under', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $features = new Features([new Flag('beta')], FlagRules::new(), new AuthContext(), new Orm($fixture->connection, new IdentityMap('test')));
        $reads = new FlagReads();
        $reads->task(static fn (): FlagScope => $features->scope());

        expect($reads->tainted())->toBeTrue();
    });
});

describe('what outlives the request', function () {
    it('refuses a static page whose render read a flag, in any fiber', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $features = new Features([new Flag('beta')], FlagRules::new(), new AuthContext(), new Orm($fixture->connection, new IdentityMap('test')));
        $routes = new RouteRegistry();
        $routes->registerAll([FlaggedStaticPage::class]);
        $out = sys_get_temp_dir() . '/tinvar-flags-' . bin2hex(random_bytes(4));

        $render = static function (Request $request, \Closure $mint) use ($features): string {
            $fiber = new \Fiber(static fn (): bool => $features->active('beta'));
            $fiber->start();

            return '<!DOCTYPE html><html><body>page</body></html>';
        };

        try {
            expect(fn () => new StaticBuilder($routes, $render)->build($out, new FragmentManifest()))
                ->toThrow(\RuntimeException::class, "/flagged read the feature flag(s) beta");
        } finally {
            exec('rm -rf ' . escapeshellarg($out) . ' ' . escapeshellarg($out . '.next'));
        }
    });

    it('lets the browser keep no once value that read a flag', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $reads = new Reads();
        $shared = new SharedReads(
            registry: new SharedRegistry([OnceShared::class]),
            keys: ['motd' => true],
            resolver: static fn (string $class): object => new $class($reads),
            request: new Request('GET', '/'),
            page: new CompiledRoute(RouteKind::Page, PageWithGreetingData::class, '/', 'home', 'home', [], [], [], []),
            parameters: [],
            orm: $orm,
            auth: new AuthContext(),
            hold: static fn (string $key, int $ttl): string => "hold-{$key}",
        );

        $kept = $shared->remember('motd', ['value' => 'hi']);
        $shown = $shared->remember('motd', ['value' => 'hi'], keepable: false);

        expect($kept['value'])->toHaveKey('hold', 'hold-motd')
            ->and($shown['value'])->not->toHaveKey('hold')
            ->and($shown['value'])->toHaveKey('keep', false);
    });
});

describe('a recorder', function () {
    it('hears its own fiber, and is tainted by a read in a fiber with none', function () {
        $mine = new FlagReads();
        $theirs = new FlagReads();
        $a = new \Fiber(static fn (): mixed => $mine->task(static function (): bool {
            FlagReads::record('beta', true);
            \Fiber::suspend();

            return true;
        }));
        $b = new \Fiber(static fn (): mixed => $theirs->task(static function (): bool {
            FlagReads::record('gamma', false);
            \Fiber::suspend();

            return true;
        }));
        $a->start();
        $b->start();

        expect($mine->flags())->toBe(['beta' => true])
            ->and($theirs->flags())->toBe(['gamma' => false])
            ->and($mine->tainted())->toBeFalse();

        // In the main context, which neither is.
        FlagReads::record('beta', true);
        $a->resume();
        $b->resume();

        expect($mine->tainted())->toBeTrue()
            ->and($theirs->tainted())->toBeTrue();

        // Closed: heard nothing more.
        FlagReads::record('delta', true);
        expect($mine->flags())->toBe(['beta' => true]);
    });

    it('is tainted by one flag read as two values', function () {
        $reads = new FlagReads();
        $reads->task(static function (): void {
            FlagReads::record('beta', true);
            FlagReads::record('beta', false);
        });

        expect($reads->tainted())->toBeTrue();
    });

    it('spans every fiber', function () {
        $span = new FlagReads();
        $span->span(static function (): void {
            $fiber = new \Fiber(static fn () => FlagReads::record('beta', true));
            $fiber->start();
        });

        expect($span->flags())->toBe(['beta' => true])
            ->and($span->clean())->toBeFalse();
    });
});
