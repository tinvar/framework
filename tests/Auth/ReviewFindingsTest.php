<?php

declare(strict_types=1);

/**
 * Regressions for the authorization reviews.
 *
 * Every case here was reproduced against the working tree before being fixed.
 */

use Tinvar\Attributes\Authorize;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\AuthorizationException;
use Tinvar\Auth\BuildRules;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\PolicyGate;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Tests\Auth\Fixtures\Review as R;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/review.php';

function anonymousAuth(): AuthContext
{
    return new AuthContext();
}

describe('policy resolution is by specificity, not registration order', function () {
    // The same map gave opposite decisions depending on insertion order.
    it('picks the most derived ancestor whichever order they register in', function (array $map) {
        $registry = (new PolicyRegistry())->registerAll($map);

        expect($registry->for(R\Puppy::class))->toBeInstanceOf(R\DogPolicy::class);
    })->with([
        'parent first' => [[R\Animal::class => R\AnimalPolicy::class, R\Dog::class => R\DogPolicy::class]],
        'child first' => [[R\Dog::class => R\DogPolicy::class, R\Animal::class => R\AnimalPolicy::class]],
    ]);

    it('prefers an exact registration over any ancestor', function () {
        $registry = (new PolicyRegistry())->registerAll([
            R\Animal::class => R\AnimalPolicy::class,
            R\Puppy::class => R\DogPolicy::class,
        ]);

        expect($registry->for(R\Puppy::class))->toBeInstanceOf(R\DogPolicy::class);
    });

    it('refuses to choose between two unrelated matches', function () {
        $registry = (new PolicyRegistry())->registerAll([
            R\Animal::class => R\AnimalPolicy::class,
            R\Ownable::class => R\OwnablePolicy::class,
        ]);

        expect(fn () => $registry->for(R\AmbiguousSubject::class))
            ->toThrow(AuthorizationException::class, 'neither is more specific');
    });

    it('clears its instance cache on reset, because a worker outlives the request', function () {
        $registry = (new PolicyRegistry())->register(R\Animal::class, R\MemoisingPolicy::class);
        $first = $registry->for(R\Animal::class);
        $registry->reset();

        expect($registry->for(R\Animal::class))->not->toBe($first);
    });
});

describe('the gate answers or throws, never guesses', function () {
    it('refuses a policy method that is not public', function () {
        $gate = new PolicyGate(
            (new PolicyRegistry())->register(R\Animal::class, R\ProtectedBeforePolicy::class),
            anonymousAuth(),
        );

        // method_exists() sees a protected before(); calling it raises a raw
        // Error that is neither a decision nor an AuthorizationException.
        expect(fn () => $gate->allows(new Authorize('view', R\Animal::class), []))
            ->toThrow(AuthorizationException::class, 'must be public');
    });

    it('refuses a verdict that is not a bool', function () {
        $gate = new PolicyGate(
            (new PolicyRegistry())->register(R\Animal::class, R\StringyPolicy::class),
            anonymousAuth(),
        );

        // (bool) 'denied' is true: a reason string became a permit.
        expect(fn () => $gate->allows(new Authorize('view', R\Animal::class), []))
            ->toThrow(AuthorizationException::class, 'must answer with a bool');
    });

    it('lets before() deny outright', function () {
        $gate = new PolicyGate(
            (new PolicyRegistry())->register(R\Animal::class, R\BlanketDenyPolicy::class),
            anonymousAuth(),
        );

        expect($gate->allows(new Authorize('view', R\Animal::class), []))->toBeFalse();
    });
});

// "Context is fiber-local, so a suspended fiber can't leak a switch to
// another." Query bodies run inside the render scheduler's fibers.
describe('the current user is fiber-local', function () {
    $user = fn (string $id): Authenticatable => new class ($id) implements Authenticatable {
        public function __construct(private string $id) {}

        public function authIdentifier(): string
        {
            return $this->id;
        }
    };

    it('does not leak an impersonation into a sibling fiber', function () use ($user) {
        $auth = new AuthContext();
        $auth->login($user('request-user'));

        $impersonating = new Fiber(fn (): ?string => $auth->actingAs(
            $user('service-account'),
            function () use ($auth): ?string {
                Fiber::suspend();

                return $auth->id();
            },
        ));
        $impersonating->start();

        $sibling = new Fiber(fn (): ?string => $auth->id());
        $sibling->start();

        expect($sibling->getReturn())->toBe('request-user', 'a sibling fiber is unaffected');

        $impersonating->resume();

        expect($impersonating->getReturn())->toBe('service-account', 'its own switch survived the suspend')
            ->and($auth->id())->toBe('request-user', 'the main context is untouched');
    });

    it('clears per-fiber state on reset', function () use ($user) {
        $auth = new AuthContext();
        $auth->login($user('u1'));
        $auth->reset();

        expect($auth->check())->toBeFalse();
    });
});

describe('a gate failure is confined to its own call', function () {
    /** @return array{0: QueryRunner, 1: Fixture} */
    $runner = function (object $gate): array {
        $fixture = new Fixture(racks: 2, unitsPerRack: 2);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\RackSummary::class);

        return [new QueryRunner($registry, $orm, $gate), $fixture];
    };

    it('turns a throwing gate into one call error, not a lost tick', function () use ($runner) {
        // A policy is registered for nothing, so the gate throws.
        $gate = new PolicyGate(new PolicyRegistry(), anonymousAuth());
        [$query, $fixture] = $runner($gate);

        $results = $query->run([
            ['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][0]]],
            ['id' => 1, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][1]]],
        ], allowed: ['racks.summary']);

        expect($results)->toHaveCount(2)
            ->and($results[0]['error']['code'])->toBe(QueryError::MISCONFIGURED)
            ->and($results[1]['error']['code'])->toBe(QueryError::MISCONFIGURED);
    });

    it('does not put the exception text on the wire', function () use ($runner) {
        [$query, $fixture] = $runner(new PolicyGate(new PolicyRegistry(), anonymousAuth()));

        $results = $query->run(
            [['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][0]]]],
            allowed: ['racks.summary'],
        );

        expect($results[0]['error'])->not->toHaveKey('message')
            ->and(json_encode($results[0]))->not->toContain('No policy is registered');
    });

    it('answers the permitted call when another is denied', function () use ($runner) {
        [$query, $fixture] = $runner(new DenyGate());

        $results = $query->run(
            [['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][0]]]],
            allowed: ['racks.summary'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::FORBIDDEN);
    });
});

describe('call ids come from the renderer and are not trusted', function () {
    it('refuses a tick with two calls sharing an id', function () {
        $fixture = new Fixture(racks: 2, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\RackSummary::class);
        $query = new QueryRunner($registry, $orm, new Tinvar\Auth\AllowAllGate());

        expect(fn () => $query->run([
            ['id' => 7, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][0]]],
            ['id' => 7, 'name' => 'racks.summary', 'args' => ['rack' => $fixture->ids['racks'][1]]],
        ], allowed: ['racks.summary']))->toThrow(ProtocolException::class, 'share id');
    });

    it('refuses a model argument that is not an identifier', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $registry = new QueryRegistry(static fn (string $c): object => new $c());
        $registry->register(Tinvar\Tests\Query\Fixtures\RackSummary::class);
        $query = new QueryRunner($registry, $orm, new Tinvar\Auth\AllowAllGate(), debug: true);

        $results = $query->run(
            [['id' => 0, 'name' => 'racks.summary', 'args' => ['rack' => ['nested']]]],
            allowed: ['racks.summary'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_ARGUMENTS)
            ->and($results[0]['error']['message'])->toContain('identifier');
    });
});

describe('build rules see the shapes that evaded them', function () {
    it('flags a model smuggled in through a payload', function () {
        $where = array_map(
            static fn ($v): string => $v->where,
            (new BuildRules())->check([R\PayloadModelPage::class]),
        );

        expect($where)->toContain(R\PayloadModelPage::class . '::__invoke($rack)');
    });

    it('accepts the same payload when a check names the field', function () {
        expect((new BuildRules())->check([R\GuardedPayloadModelPage::class]))->toBe([]);
    });

    it("flags a batch query's declared model arguments", function () {
        $where = array_map(
            static fn ($v): string => $v->where,
            (new BuildRules())->check([R\UnguardedBatchQuery::class]),
        );

        expect($where)->toContain(R\UnguardedBatchQuery::class . '::loadMany($rack)');
    });

    it('accepts a batch query that names them', function () {
        expect((new BuildRules())->check([R\GuardedBatchQuery::class]))->toBe([]);
    });
});

/**
 * `shared: true` drops the user from the cache key, so a body that varies by
 * user would serve one caller's answer to another.
 */
describe('shared queries may not read the user', function () {
    it('accepts a shared query that reads nothing user-dependent', function () {
        expect((new BuildRules())->check([R\SharedCleanQuery::class]))->toBe([]);
    });

    it('flags one that reads the current user', function () {
        $violations = (new BuildRules())->check([R\SharedLeakyQuery::class]);

        expect($violations)->not->toBeEmpty()
            ->and($violations[0]->rule)->toBe(BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER);
    });

    it('flags one that escapes tenant scoping', function () {
        $violations = (new BuildRules())->check([R\SharedUnscopedQuery::class]);

        expect(array_map(static fn ($v): string => $v->rule, $violations))
            ->toContain(BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER);
    });

    // The case a text grep misses: the query's own source mentions nothing.
    it('flags one that reaches the user through an injected helper', function () {
        $violations = (new BuildRules())->check([R\SharedIndirectQuery::class]);

        expect($violations)->not->toBeEmpty()
            ->and($violations[0]->rule)->toBe(BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER)
            ->and($violations[0]->message)->toContain('AuthContext');
    });

    // The textual check is kept ALONGSIDE the type walk: a walk cannot see a
    // class the query builds or looks up itself.
    it('flags one that reaches the user without injection', function () {
        $violations = (new BuildRules())->check([R\SharedContainerQuery::class]);

        expect($violations)->not->toBeEmpty()
            ->and($violations[0]->rule)->toBe(BuildRules::SHARED_QUERY_MUST_NOT_READ_THE_USER);
    });

    // An interface could be anything at runtime, so the walk proves nothing.
    it('refuses a dependency it cannot analyse rather than assuming it safe', function () {
        $violations = (new BuildRules())->check([R\SharedInterfaceQuery::class]);

        expect($violations)->not->toBeEmpty()
            ->and($violations[0]->message)->toContain('cannot be checked');
    });

    it('leaves a per-user query alone, since its key separates callers', function () {
        expect((new BuildRules())->check([R\PerUserQuery::class]))->toBe([]);
    });
});
