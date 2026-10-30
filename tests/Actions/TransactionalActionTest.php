<?php

declare(strict_types=1);

/**
 * #[Action(transaction: true)]: the body, its tags and the check of its
 * answer in one transaction, so an answer that is refused -- a location()
 * off every allowed origin, a redirect() off this one, a revalidate()
 * naming no page -- leaves nothing written (ADR 0107). An action that does
 * not ask keeps its writes, as it always has: a refused redirect is a 500
 * after them.
 */

namespace Tinvar\Tests\Actions\Transactional;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Cache\ArrayStore;
use Tinvar\Cache\QueryCache;
use Tinvar\Cache\TagNamespace;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Live\LiveUpdates;
use Tinvar\Live\MemoryHub;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Uuid;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

final class Writes
{
    public static ?Orm $orm = null;

    public static ?string $site = null;

    private static function add(string $name): void
    {
        self::$orm?->connection()->execute('INSERT INTO racks VALUES (?, ?, 42, ?)', [Uuid::v7(), $name, self::$site]);
    }

    #[Action(name: 'pay.refused', transaction: true), NoAuthorization(reason: 'fixture')]
    public function refused(): ActionResult
    {
        self::add('refused');

        return ActionResult::location('https://evil.example/collect')->withTags('racks');
    }

    #[Action(name: 'pay.allowed', transaction: true), NoAuthorization(reason: 'fixture')]
    public function allowed(): ActionResult
    {
        self::add('allowed');

        return ActionResult::location('https://pay.example/checkout')->withTags('racks');
    }

    #[Action(name: 'pay.offsite', transaction: true), NoAuthorization(reason: 'fixture')]
    public function offsite(): ActionResult
    {
        self::add('offsite');

        return ActionResult::redirect('//evil.example/');
    }

    #[Action(name: 'pay.throws', transaction: true), NoAuthorization(reason: 'fixture')]
    public function throws(): ActionResult
    {
        self::add('throws');

        throw new \RuntimeException('the body failed');
    }

    #[Action(name: 'pay.untransacted'), NoAuthorization(reason: 'fixture')]
    public function untransacted(): ActionResult
    {
        self::add('untransacted');

        return ActionResult::location('https://evil.example/collect')->withTags('racks');
    }
}

/** @return array{Kernel, Fixture, MemoryHub, ArrayStore} */
function world(): array
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    Writes::$orm = $orm;
    Writes::$site = (string) $fixture->ids['sites'][0];
    $auth = new AuthContext();
    $auth->login(new User('u-1'));
    $actions = new ActionRegistry();
    $actions->register(Writes::class);
    $hub = new MemoryHub();
    $store = new ArrayStore();

    return [new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: $actions,
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        cache: new QueryCache($store, $auth),
        log: static function (\Throwable $e): void {},
        redirectOrigins: ['https://pay.example'],
        live: LiveUpdates::fromKey('transactional-action-test-key-0123456789', $hub, static fn (Request $r): string => 'session'),
    ), $fixture, $hub, $store];
}

function call(Kernel $kernel, string $action): Response
{
    return $kernel->handle(
        new Request(
            'POST',
            "/_action/{$action}",
            ['content-type' => 'application/json', 'accept' => 'application/json', 'rsc' => '1', 'x-xsrf-token' => 'tok'],
            [],
            [],
            [VerifyCsrfToken::COOKIE => 'tok'],
        ),
        static fn (): Response => throw new \LogicException('no page here'),
        '{}',
    );
}

/** @return list<string> */
function names(Fixture $fixture): array
{
    return array_map(static fn (array $row): string => (string) $row['name'], $fixture->connection->select('SELECT name FROM racks ORDER BY name'));
}

it('leaves nothing written, and tells no one, when its answer is refused', function (string $action) {
    [$kernel, $fixture, $hub, $store] = world();
    $before = names($fixture);
    $store->put('racks-list', 'kept', null, TagNamespace::written(['racks'], '', null));

    $response = call($kernel, $action);

    expect($response->status)->toBe(500)
        ->and($response->header('x-location'))->toBeNull()
        ->and(names($fixture))->toBe($before)
        ->and($hub->published)->toBe([], 'the live clients are told only of a commit');
})->with(['a location() off the allowlist' => ['pay.refused'], 'a redirect() off this origin' => ['pay.offsite'], 'a body that throws' => ['pay.throws']]);

it('commits, and tells the clients, when its answer is allowed', function () {
    [$kernel, $fixture, $hub] = world();

    $response = call($kernel, 'pay.allowed');

    expect($response->status)->toBe(409)
        ->and($response->header('x-location'))->toBe('https://pay.example/checkout')
        ->and(names($fixture))->toContain('allowed')
        ->and($hub->published)->not->toBe([]);
});

// Not asked for, the writes stay as they always did -- and are announced,
// so no page is left showing what they changed.
it('keeps the writes of an action that did not ask for a transaction, and announces them', function () {
    [$kernel, $fixture, $hub, $store] = world();
    $store->put('racks-list', 'kept', null, TagNamespace::written(['racks'], '', null));

    expect(call($kernel, 'pay.untransacted')->status)->toBe(500)
        ->and(names($fixture))->toContain('untransacted')
        ->and($store->get('racks-list')['hit'])->toBeFalse()
        ->and($hub->published)->not->toBe([]);
});
