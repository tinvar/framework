<?php

declare(strict_types=1);

/**
 * DatabaseApiTokens (ADR 0158): API tokens in a table of the application's
 * database -- the digest of each secret, never the secret; each moment as
 * the ORM stores one; every statement about a user's tokens held to the
 * tenant. On SQLite, and on Postgres and MySQL where they are there.
 */

namespace Tinvar\Tests\Api\DatabaseApiTokens;

use Carbon\CarbonImmutable;
use Tinvar\Api\ApiToken;
use Tinvar\Api\DatabaseApiTokens;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

function token(string $id, string $tenant = 'acme', string $user = 'ada', int $at = 1_800_000_000, ?int $expires = null): ApiToken
{
    return new ApiToken(
        id: str_pad($id, 32, '0', STR_PAD_LEFT),
        name: "Token {$id}",
        user: $user,
        epoch: 'e1',
        tenant: $tenant,
        panel: '',
        host: 'shop.test',
        scopes: ['orders:read', 'orders:write'],
        createdAt: CarbonImmutable::createFromTimestampUTC($at),
        expiresAt: $expires === null ? null : CarbonImmutable::createFromTimestampUTC($expires)->addMicroseconds(250_000),
    );
}

function storeHolds(PdoConnection $connection): void
{
    DatabaseApiTokens::define(new Schema($connection, Dialect::for($connection)));
    $store = new DatabaseApiTokens($connection);

    $first = token('a1', expires: 1_900_000_000);
    $store->insert($first, str_repeat('d', 64));
    $store->insert(token('a2', at: 1_800_000_100), str_repeat('e', 64));
    $store->insert(token('b1', user: 'bob'), str_repeat('f', 64));
    $store->insert(token('c1', tenant: 'globex'), str_repeat('0', 64));

    [$found, $digest] = $store->find($first->id) ?? throw new \LogicException('not found');
    expect($found)->toEqual($first)
        ->and($found->expiresAt?->format('Y-m-d H:i:s.u'))->toBe($first->expiresAt?->format('Y-m-d H:i:s.u'))
        ->and($digest)->toBe(str_repeat('d', 64))
        ->and($store->find(str_repeat('9', 32)))->toBeNull()
        ->and(array_map(static fn (ApiToken $t): string => ltrim($t->id, '0'), $store->forUser('acme', '', 'ada')))->toBe(['a2', 'a1'])
        ->and($store->forUser('globex', '', 'bob'))->toBe([])
        ->and($store->forUser('acme', 'admin', 'ada'))->toBe([]);

    $at = CarbonImmutable::createFromTimestampUTC(1_800_000_500);
    expect($store->revoke('globex', 'ada', $first->id, $at))->toBeFalse()
        ->and($store->revoke('acme', 'bob', $first->id, $at))->toBeFalse()
        ->and($store->revoke('acme', 'ada', $first->id, $at))->toBeTrue()
        ->and($store->revoke('acme', 'ada', $first->id, $at->addMinute()))->toBeFalse()
        ->and($store->find($first->id)[0]->revokedAt?->getTimestamp())->toBe(1_800_000_500);

    $used = CarbonImmutable::createFromTimestampUTC(1_800_001_000);
    $store->touch($first->id, $used, $used->subMinute());
    $store->touch($first->id, $used->addSeconds(30), $used->addSeconds(30)->subMinute());
    expect($store->find($first->id)[0]->lastUsedAt?->getTimestamp())->toBe(1_800_001_000);
    $store->touch($first->id, $used->addMinute(), $used);
    expect($store->find($first->id)[0]->lastUsedAt?->getTimestamp())->toBe(1_800_001_060);

    // No column holds anything but a digest of a secret.
    $rows = $connection->select('SELECT * FROM api_tokens');
    expect(array_keys($rows[0]))->toContain('secret_digest')->not->toContain('secret');
}

it('keeps tokens on SQLite', function () {
    storeHolds(PdoConnection::sqlite(':memory:'));
});

it('keeps tokens on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
        storeHolds(new PdoConnection($pdo, 'pgsql'));
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('keeps tokens on MySQL', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        storeHolds(new PdoConnection($pdo, 'mysql'));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('refuses a table name it would not write', function () {
    expect(fn () => new DatabaseApiTokens(PdoConnection::sqlite(':memory:'), 'api tokens; drop'))->toThrow(\InvalidArgumentException::class);
});
