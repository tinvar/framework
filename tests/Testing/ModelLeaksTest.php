<?php

declare(strict_types=1);

/**
 * What the model-leak check looks for: the undeclared columns of every row
 * a request read, wherever it read them. The model sits under a top
 * namespace of its own, so no Data class elsewhere in the run declares its
 * column for it.
 */

namespace ModelLeaksFixture;

use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Testing\ModelLeaks;

#[Table('leak_accounts'), TenantScoped]
final class Account extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $passwordHash;
}

const HASH = '$argon2id$v=19$not-for-the-page';

/** @return array{Orm, string} an Orm recording its reads, over one acme account, and the account's id */
function accounts(): array
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE leak_accounts (id TEXT PRIMARY KEY, password_hash TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    $id = Uuid::v7();
    $pdo->prepare('INSERT INTO leak_accounts VALUES (?, ?, ?)')->execute([$id, HASH, 'acme']);

    $orm = new Orm(new PdoConnection($pdo), new IdentityMap('test'));
    // As the client does around a render.
    $orm->recordReads(true);

    return [$orm, $id];
}

/** @return list<string> the values the check looks for */
function lookedFor(Orm $orm): array
{
    return array_column(ModelLeaks::snapshot($orm), 'value');
}

it('looks for an undeclared column of a row the request read', function () {
    [$orm, $id] = accounts();

    $values = $orm->actAs('acme', static function () use ($orm, $id): array {
        $orm->find(Account::class, $id);

        return lookedFor($orm);
    });

    expect($values)->toBe([HASH]);
});

// The snapshot was the identity map, and leaving acrossTenants() empties it:
// one call anywhere in a page's loaders, a count across tenants say, and
// nothing read before it was looked for.
it('still looks for a row read before an acrossTenants() call', function () {
    [$orm, $id] = accounts();

    $values = $orm->actAs('acme', static function () use ($orm, $id): array {
        $orm->find(Account::class, $id);
        $orm->acrossTenants(static fn (): int => 0);

        return lookedFor($orm);
    });

    expect($values)->toBe([HASH]);
});

// A row read across tenants never enters the map: a staff page's reads were
// never looked for at all.
it('looks for a row read inside acrossTenants()', function () {
    [$orm] = accounts();

    $values = $orm->actAs('acme', static function () use ($orm): array {
        $orm->acrossTenants(static fn (): array => $orm->all(Account::class));

        return lookedFor($orm);
    });

    expect($values)->toBe([HASH]);
});

// One request's reads are not the next one's: the reset empties the record,
// and recording goes on until the client turns it off.
it('empties the record at the reset, and records nothing once turned off', function () {
    [$orm, $id] = accounts();

    $orm->actAs('acme', static fn (): ?Model => $orm->find(Account::class, $id));
    $orm->flush();
    $afterReset = \count($orm->readsRecorded());

    $orm->actAs('acme', static fn (): ?Model => $orm->find(Account::class, $id));
    $recorded = \count($orm->readsRecorded());

    $orm->recordReads(false);
    $orm->flush();
    $orm->actAs('acme', static fn (): ?Model => $orm->find(Account::class, $id));

    expect($afterReset)->toBe(0)
        ->and($recorded)->toBe(1)
        ->and($orm->readsRecorded())->toBe([]);
});
