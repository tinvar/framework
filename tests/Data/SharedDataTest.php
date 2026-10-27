<?php

declare(strict_types=1);

/**
 * Shared data (ADR 0060): a SharedData provider's #[Shared] methods, read by
 * a render as query calls named `$shared.key`, run lazily and once per
 * render, read-only, batched with the queries of their tick and encoded on
 * the boundary's terms; and ProvidesData, told its key, request and page.
 */

use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PanelContext;
use Tinvar\Data\DataBoundaryException;
use Tinvar\Data\DataContext;
use Tinvar\Data\Encoder;
use Tinvar\Data\SharedReads;
use Tinvar\Data\SharedRegistry;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteChain;
use Tinvar\Routing\RouteKind;
use Tinvar\Tests\Data\Fixtures\Shared\ArrayShared;
use Tinvar\Tests\Data\Fixtures\Shared\BadKeyShared;
use Tinvar\Tests\Data\Fixtures\Shared\DuplicateShared;
use Tinvar\Tests\Data\Fixtures\Shared\Greeting;
use Tinvar\Tests\Data\Fixtures\Shared\GreetingData;
use Tinvar\Tests\Data\Fixtures\Shared\NarrowingShared;
use Tinvar\Tests\Data\Fixtures\Shared\NothingShared;
use Tinvar\Tests\Data\Fixtures\Shared\NotProvider;
use Tinvar\Tests\Data\Fixtures\Shared\PageWithGreetingData;
use Tinvar\Tests\Data\Fixtures\Shared\PrivateShared;
use Tinvar\Tests\Data\Fixtures\Shared\Reads;
use Tinvar\Tests\Data\Fixtures\Shared\ServiceParameterShared;
use Tinvar\Tests\Data\Fixtures\Shared\SiteShared;
use Tinvar\Tests\Data\Fixtures\Shared\StaffShared;
use Tinvar\Tests\Data\Fixtures\Shared\StaticShared;
use Tinvar\Tests\Data\Fixtures\Shared\UntypedShared;
use Tinvar\Tests\Data\Fixtures\Shared\VagueShared;
use Tinvar\Tests\Data\Fixtures\Shared\WorkerLivedShared;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Query\Fixtures\RackSummary;
use Tinvar\Tests\Query\Fixtures\RackSummaryData;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';
require_once __DIR__ . '/Fixtures/shared.php';

final readonly class SharedMember implements Authenticatable
{
    public function __construct(private string $id) {}

    public function authIdentifier(): string
    {
        return $this->id;
    }
}

/**
 * A runner, and a render's shared data over SiteShared and StaffShared, of
 * which this render may read $keys -- every SiteShared key unless told.
 *
 * @param list<string>|null $keys
 * @return array{runner: QueryRunner, shared: SharedReads, reads: Reads, fixture: Fixture, orm: Orm, auth: AuthContext}
 */
function sharedRender(?array $keys = null, ?Request $request = null): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 3);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $reads = new Reads();
    $reads->rack = $fixture->ids['racks'][0];
    $auth = new AuthContext();

    $queries = new QueryRegistry(static fn (string $class): object => new $class($orm));
    $queries->register(RackSummary::class);

    $registry = new SharedRegistry([SiteShared::class, StaffShared::class]);
    $keys ??= ['greeting', 'rack', 'nobody', 'leak', 'boom', 'writes', 'where'];

    $shared = new SharedReads(
        registry: $registry,
        keys: array_fill_keys($keys, true),
        resolver: static fn (string $class): object => new $class($reads, $orm),
        request: $request ?? new Request('GET', '/sites/s-1', ['x-who' => 'Ada']),
        page: sharedPage(),
        parameters: ['site' => 's-1'],
        orm: $orm,
        auth: $auth,
    );

    return [
        'runner' => new QueryRunner($queries, $orm, new AllowAllGate()),
        'shared' => $shared,
        'reads' => $reads,
        'fixture' => $fixture,
        'orm' => $orm,
        'auth' => $auth,
    ];
}

function sharedPage(): CompiledRoute
{
    return new CompiledRoute(RouteKind::Page, PageWithGreetingData::class, '/sites/{site}', 'sites/show', 'sites.show', ['sites', '{site}'], ['site'], [], []);
}

/** @param list<string> $keys */
function sharedCalls(array $keys, int $from = 0): array
{
    $calls = [];
    foreach ($keys as $i => $key) {
        $calls[] = ['id' => $from + $i, 'name' => SharedReads::PREFIX . $key, 'args' => []];
    }

    return $calls;
}

describe('the registry', function () {
    it('takes a provider and types each key by what its method returns', function () {
        $registry = new SharedRegistry([SiteShared::class]);

        expect($registry->keys())->toBe(['greeting', 'rack', 'nobody', 'leak', 'boom', 'writes', 'where'])
            // A ProvidesData is typed as what its toData() returns.
            ->and($registry->typesOf('greeting'))->toBe([[GreetingData::class], false])
            ->and($registry->typesOf('rack'))->toBe([[RackSummaryData::class], false])
            ->and($registry->typesOf('nobody'))->toBe([[GreetingData::class], true]);
    });

    it('refuses what a render could not read safely', function (string $class, string $why) {
        expect(fn () => new SharedRegistry([SiteShared::class, $class]))->toThrow(InvalidArgumentException::class, $why);
    })->with([
        'a worker-lived provider' => [WorkerLivedShared::class, 'Lifetime::Worker'],
        'a static property' => [StaticShared::class, 'is static'],
        'no return type' => [UntypedShared::class, 'no return type'],
        'an array' => [ArrayShared::class, 'may return array'],
        'a service as a parameter' => [ServiceParameterShared::class, 'may take only the Request and a DataContext'],
        'a key taken twice' => [DuplicateShared::class, "Two shared values are named 'greeting'"],
        'nothing shared' => [NothingShared::class, 'has no #[Shared] method'],
        'not a provider' => [NotProvider::class, 'is not a class implementing'],
        'a narrowing panel' => [NarrowingShared::class, 'cannot be held to'],
        'a key that is no name' => [BadKeyShared::class, 'not an identifier'],
        'a private method' => [PrivateShared::class, 'only a public instance method'],
        'a vague ProvidesData' => [VagueShared::class, 'no Data class of its own'],
    ]);
});

describe('reading a key', function () {
    it('runs nothing until a key is read, and then only that key', function () {
        $render = sharedRender();

        expect($render['reads']->calls)->toBe([]);

        $results = $render['runner']->run(sharedCalls(['nobody']), [], $render['shared']);

        expect($results)->toBe([['id' => 0, 'value' => null]])
            ->and($render['reads']->calls)->toBe(['nobody']);
    });

    it('produces a key once per render, however many components read it in however many ticks', function () {
        $render = sharedRender();

        $first = $render['runner']->run(sharedCalls(['greeting', 'greeting']), [], $render['shared']);
        $second = $render['runner']->run(sharedCalls(['greeting'], from: 2), [], $render['shared']);

        expect($first[0]['value'])->toBe(['text' => 'Ada at greeting on sites/show'])
            ->and($first[1]['value'])->toBe($first[0]['value'])
            ->and($second[0]['value'])->toBe($first[0]['value'])
            ->and($render['reads']->calls)->toBe(['greeting']);
    });

    it('builds its providers afresh for each render', function () {
        $one = sharedRender(request: new Request('GET', '/', ['x-who' => 'Ada']));
        $two = sharedRender(request: new Request('GET', '/', ['x-who' => 'Grace']));

        $a = $one['runner']->run(sharedCalls(['greeting']), [], $one['shared']);
        $b = $two['runner']->run(sharedCalls(['greeting']), [], $two['shared']);

        expect($a[0]['value']['text'])->toStartWith('Ada')
            ->and($b[0]['value']['text'])->toStartWith('Grace');
    });

    it('tells a ProvidesData, and a method asking for one, its key, request and page', function () {
        $render = sharedRender();

        $results = $render['runner']->run(sharedCalls(['where']), [], $render['shared']);

        expect($results[0]['value'])->toBe(['text' => 'where on sites/show with {"site":"s-1"}']);
    });

    it('refuses a model in a shared value, as the encoder refuses one anywhere', function () {
        $render = sharedRender();

        $results = $render['runner']->run(sharedCalls(['leak']), [], $render['shared']);

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_RESULT)
            ->and($results[0])->not->toHaveKey('value');
    });

    it('fails a key that throws on its own, keeps the failure for the render, and answers the others', function () {
        $render = sharedRender();

        $results = $render['runner']->run(sharedCalls(['boom', 'nobody']), [], $render['shared']);
        $again = $render['runner']->run(sharedCalls(['boom'], from: 2), [], $render['shared']);

        expect($results[0]['error']['code'])->toBe(QueryError::FAILED)
            ->and($results[1])->toBe(['id' => 1, 'value' => null])
            ->and($again[0]['error'])->toBe($results[0]['error'])
            ->and(array_count_values($render['reads']->calls)['boom'])->toBe(1);
    });

    it('runs a key read-only, as a loader is', function () {
        $render = sharedRender();

        $results = $render['runner']->run(sharedCalls(['writes']), [], $render['shared']);

        expect($results[0]['error']['code'])->toBe(QueryError::FAILED);
    });

    it('answers a key of no provider, or of another panel, as unknown', function () {
        // `staffNote` is registered, and not among this render's keys.
        $render = sharedRender(keys: ['greeting']);

        $results = $render['runner']->run(sharedCalls(['staffNote', 'missing']), [], $render['shared']);

        expect($results[0]['error']['code'])->toBe(QueryError::UNKNOWN)
            ->and($results[1]['error']['code'])->toBe(QueryError::UNKNOWN);
    });

    it('refuses a read with arguments', function () {
        $render = sharedRender();

        $results = $render['runner']->run(
            [['id' => 0, 'name' => SharedReads::PREFIX . 'greeting', 'args' => ['as' => 'admin']]],
            [],
            $render['shared'],
        );

        expect($results[0]['error']['code'])->toBe(QueryError::BAD_ARGUMENTS)
            ->and($render['reads']->calls)->toBe([]);
    });

    it('refuses every read where there is no render to read for: /_query, a fragment', function () {
        $render = sharedRender();

        // Not even with the name on the allowlist.
        $results = $render['runner']->run(sharedCalls(['greeting']), [SharedReads::PREFIX . 'greeting']);

        expect($results[0]['error']['code'])->toBe(QueryError::NOT_ALLOWED)
            ->and($render['reads']->calls)->toBe([]);
    });

    it('refuses a read once the user it was made for is gone', function () {
        $render = sharedRender();
        $render['auth']->login(new SharedMember('someone-else'), '1');

        $results = $render['runner']->run(sharedCalls(['greeting']), [], $render['shared']);

        expect($results[0]['error']['code'])->toBe(QueryError::FORBIDDEN)
            ->and($render['reads']->calls)->toBe([]);
    });

    it('refuses a read once the render has left its panel', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));
        $panels = new PanelContext();
        $shared = $panels->within('admin', static fn (): SharedReads => new SharedReads(
            registry: new SharedRegistry([StaffShared::class]),
            keys: ['staffNote' => true],
            resolver: static fn (string $class): object => new $class(),
            request: Request::get('/admin'),
            page: sharedPage(),
            parameters: [],
            orm: $orm,
            auth: new AuthContext(),
            panels: $panels,
            panel: 'admin',
        ));
        $runner = new QueryRunner(new QueryRegistry(), $orm, new AllowAllGate());

        $inside = $panels->within('admin', static fn (): array => $runner->run(sharedCalls(['staffNote']), [], $shared));
        $outside = $runner->run(sharedCalls(['staffNote']), [], $shared);

        expect($inside[0]['value'])->toBe(['text' => 'for staff'])
            ->and($outside[0]['error']['code'])->toBe(QueryError::FORBIDDEN);
    });

    it('batches a key\'s relation loads with the queries of its tick', function () {
        $alone = sharedRender();
        $alone['fixture']->connection->resetStatementCount();
        $alone['runner']->run(sharedCalls(['rack']), [], $alone['shared']);
        $alone['runner']->run(
            [['id' => 1, 'name' => 'racks.summary', 'args' => ['rack' => $alone['fixture']->ids['racks'][1]]]],
            ['racks.summary'],
        );
        $apart = $alone['fixture']->connection->statementCount();

        $together = sharedRender();
        $together['fixture']->connection->resetStatementCount();
        $results = $together['runner']->run(
            [
                ...sharedCalls(['rack']),
                ['id' => 1, 'name' => 'racks.summary', 'args' => ['rack' => $together['fixture']->ids['racks'][1]]],
            ],
            ['racks.summary'],
            $together['shared'],
        );

        // Both racks' units in one statement, where apart they took one each.
        expect($results[0]['value']['units'])->toBe(3)
            ->and($results[1]['value']['units'])->toBe(3)
            ->and($together['fixture']->connection->statementCount())->toBe($apart - 1);
    });
});

describe('ProvidesData in a loader\'s fields', function () {
    it('becomes its Data told its dot path, the request and the page', function () {
        $page = sharedPage();
        $loaded = new LoadedRoute(new RouteChain($page, [], ['site' => 's-1']), new PageWithGreetingData(new Greeting('Ada')), [], [], ['site' => 's-1']);

        expect($loaded->encode(new Encoder(), Request::get('/sites/s-1'))['page'])
            ->toBe(['greeting' => ['text' => 'Ada at page.greeting on sites/show']]);
    });

    it('is refused where it is placed on no page', function () {
        expect(fn () => (new Encoder())->encode(new PageWithGreetingData(new Greeting('Ada'))))
            ->toThrow(DataBoundaryException::class, 'placed on none');
    });

    it('is told the context it is given', function () {
        $context = new DataContext('k', Request::get('/'), sharedPage());

        expect((new Greeting('Ada'))->toData($context)->text)->toBe('Ada at k on sites/show');
    });
});
