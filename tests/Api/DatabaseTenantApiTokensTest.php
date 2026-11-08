<?php

declare(strict_types=1);

/**
 * DatabaseTenantApiTokens (ADR 0193): a tenant's API tokens in a table of
 * the application's database -- the digest of each secret, never the
 * secret; each moment as the ORM stores one; every statement about a
 * tenant's tokens held to the tenant -- and each tenant's token epoch, which
 * ends every token the tenant holds when it moves. On SQLite, and on
 * Postgres and MySQL where they are there.
 */

namespace Tinvar\Tests\Api\DatabaseTenantApiTokens;

use Carbon\CarbonImmutable;
use Tinvar\Api\DatabaseTenantApiTokens;
use Tinvar\Api\TenantApiToken;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tests\Support\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

function token(string $id, string $tenant = 'acme', string $panel = '', int $at = 1_800_000_000, ?int $expires = null, string $epoch = '0'): TenantApiToken
{
    return new TenantApiToken(
        id: str_pad($id, 32, '0', STR_PAD_LEFT),
        name: "Token {$id}",
        tenant: $tenant,
        panel: $panel,
        host: 'shop.test',
        scopes: ['orders:read', 'orders:write'],
        epoch: $epoch,
        createdBy: 'ada',
        createdAt: CarbonImmutable::createFromTimestampUTC($at),
        expiresAt: $expires === null ? null : CarbonImmutable::createFromTimestampUTC($expires)->addMicroseconds(250_000),
    );
}

function storeHolds(PdoConnection $connection): void
{
    DatabaseTenantApiTokens::define(new Schema($connection, Dialect::for($connection)));
    $store = new DatabaseTenantApiTokens($connection);

    $first = token('a1', expires: 1_900_000_000);
    $store->insert($first, str_repeat('d', 64));
    $store->insert(token('a2', at: 1_800_000_100), str_repeat('e', 64));
    $store->insert(token('b1', panel: 'admin'), str_repeat('f', 64));
    $store->insert(token('c1', tenant: 'globex'), str_repeat('0', 64));

    [$found, $digest] = $store->find($first->id) ?? throw new \LogicException('not found');
    expect($found)->toEqual($first)
        ->and($found->expiresAt?->format('Y-m-d H:i:s.u'))->toBe($first->expiresAt?->format('Y-m-d H:i:s.u'))
        ->and($digest)->toBe(str_repeat('d', 64))
        ->and($store->find(str_repeat('9', 32)))->toBeNull()
        ->and(array_map(static fn (TenantApiToken $t): string => ltrim($t->id, '0'), $store->forTenant('acme', '')))->toBe(['a2', 'a1'])
        ->and(array_map(static fn (TenantApiToken $t): string => ltrim($t->id, '0'), $store->forTenant('acme', 'admin')))->toBe(['b1'])
        ->and($store->forTenant('initech', ''))->toBe([]);

    $at = CarbonImmutable::createFromTimestampUTC(1_800_000_500);
    expect($store->revoke('globex', $first->id, $at))->toBeFalse()
        ->and($store->revoke('acme', $first->id, $at))->toBeTrue()
        ->and($store->revoke('acme', $first->id, $at->addMinute()))->toBeFalse()
        ->and($store->find($first->id)[0]->revokedAt?->getTimestamp())->toBe(1_800_000_500);

    $used = CarbonImmutable::createFromTimestampUTC(1_800_001_000);
    $store->touch($first->id, $used, $used->subMinute());
    $store->touch($first->id, $used->addSeconds(30), $used->addSeconds(30)->subMinute());
    expect($store->find($first->id)[0]->lastUsedAt?->getTimestamp())->toBe(1_800_001_000);

    // Each tenant's epoch: '0' before it ever moved, and moved for that tenant alone.
    expect($store->epoch('acme'))->toBe('0');
    $store->advance('acme');
    $store->advance('acme');
    $connection->transaction(static fn () => $store->advance('globex'));
    expect($store->epoch('acme'))->toBe('2')
        ->and($store->epoch('globex'))->toBe('1')
        ->and($store->epoch('initech'))->toBe('0');

    // No column holds anything but a digest of a secret.
    $rows = $connection->select('SELECT * FROM tenant_api_tokens');
    expect(array_keys($rows[0]))->toContain('secret_digest')->not->toContain('secret');
}

it('keeps tenant tokens on SQLite', function () {
    storeHolds(PdoConnection::sqlite(':memory:'));
});

it('keeps tenant tokens on Postgres', function () {
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo): void {
        storeHolds(new PdoConnection($pdo, 'pgsql'));
    });
})->group('pgsql')->skip(Databases::postgresMissing() !== null, Databases::postgresMissing() ?? '');

it('keeps tenant tokens on MySQL', function () {
    Databases::inScratchSchema('mysql', static function (\PDO $pdo): void {
        storeHolds(new PdoConnection($pdo, 'mysql'));
    });
})->group('mysql')->skip(Databases::mysqlMissing() !== null, Databases::mysqlMissing() ?? '');

it('refuses a table name it would not write', function () {
    expect(fn () => new DatabaseTenantApiTokens(PdoConnection::sqlite(':memory:'), 'tenant tokens; drop'))->toThrow(\InvalidArgumentException::class);
});
