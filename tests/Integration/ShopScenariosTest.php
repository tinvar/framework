<?php

declare(strict_types=1);

/**
 * The shop's `#[Scenario]`s (ADR 0090): `load` makes the customers `tinvar
 * bench:load` signs in as, with passwords of their own that only the runner
 * is handed, and nothing seeds one under production's profile.
 */

namespace Tinvar\Tests\Integration\ShopScenarios;

use Shop\Kernel;
use Tinvar\Bench\Shop;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Scenarios\Scenarios;

const STAGING = ['TINVAR_PROFILE' => 'staging', 'TINVAR_ENV' => 'staging'];

/** @return array{Scenarios, \PDO} */
function scenarios(): array
{
    $connection = PdoConnection::sqlite(Shop::testDatabase(), 'shop');

    return [Kernel::scenarios($connection, new Orm($connection, new IdentityMap($connection->name()))), $connection->pdo()];
}

function customers(\PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM customers')?->fetchColumn();
}

it('makes the load test\'s customers, each with a password of its own that signs it in', function () {
    [$scenarios, $pdo] = scenarios();
    $before = customers($pdo);

    $seeded = $scenarios->seed('load', ['customers' => 5], STAGING);

    expect($seeded->users)->toHaveCount(5)
        ->and(customers($pdo))->toBe($before + 5)
        ->and(array_unique(array_column($seeded->credentials, 'password')))->toHaveCount(5)
        ->and($seeded->forTest())->not->toHaveKey('credentials');

    $hash = $pdo->prepare('SELECT id, password_hash FROM customers WHERE email = ?');
    foreach ($seeded->credentials as $i => $credential) {
        expect($credential['login'])->toStartWith('scenario-')
            ->and($credential['password'])->not->toStartWith('pellwick-');
        $hash->execute([$credential['login']]);
        $row = $hash->fetch(\PDO::FETCH_ASSOC);
        expect(\is_array($row) && password_verify($credential['password'], (string) $row['password_hash']))->toBeTrue()
            ->and(\is_array($row) ? $row['id'] : null)->toBe($seeded->users[$i]);
    }
});

it('seeds a second load beside the first without a collision', function () {
    [$scenarios] = scenarios();
    $first = $scenarios->seed('load', ['customers' => 3], STAGING);
    $second = $scenarios->seed('load', ['customers' => 3], STAGING);

    expect(array_intersect(array_column($first->credentials, 'login'), array_column($second->credentials, 'login')))->toBe([]);
});

it('seeds nothing under production\'s profile', function (array $env) {
    [$scenarios, $pdo] = scenarios();
    $before = customers($pdo);

    expect(fn () => $scenarios->seed('load', ['customers' => 5], $env))->toThrow(\LogicException::class, 'production profile')
        ->and(customers($pdo))->toBe($before);
})->with([
    'nothing said' => [[]],
    'production' => [['TINVAR_PROFILE' => 'production', 'TINVAR_ENV' => 'local']],
]);

it('refuses a load it was not asked for properly', function (mixed $customers) {
    [$scenarios] = scenarios();

    $scenarios->seed('load', ['customers' => $customers], STAGING);
})->with([0, -1, '20', 1_000_000])->throws(\InvalidArgumentException::class, '`customers`');

it('names the scenarios the browser tests and the load test start from', function () {
    [$scenarios] = scenarios();

    expect($scenarios->names())->toBe(['customer', 'demo', 'load']);
});
