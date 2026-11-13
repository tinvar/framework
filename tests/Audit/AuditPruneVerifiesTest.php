<?php

declare(strict_types=1);

/**
 * prune() takes a tenant's oldest entries away and signs a new anchor over
 * the last one it took (ADR 0156). It picks how far from each entry's
 * stored occurred_at, which anyone who can write the database can change;
 * so it walks the chain from the anchor in force through the last entry it
 * would take, and takes nothing, and signs nothing, where that walk breaks
 * (ADR 0209). Otherwise a writer without the key could backdate an entry
 * and have the nightly prune erase it under a valid anchor.
 */

namespace Tinvar\Tests\Audit\PruneVerifies;

use Carbon\CarbonImmutable;
use Tinvar\Audit\AuditLog;
use Tinvar\Audit\AuditTable;
use Tinvar\Auth\AuthContext;
use Tinvar\Orm\Attributes\Audited;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\TenantSubtrees;

#[Table('audit_notes'), TenantScoped, Audited]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

final class Tenants implements TenantSubtrees
{
    public function hierarchical(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function ancestors(string $tenant): array
    {
        return [];
    }

    /** @return non-empty-list<string> */
    public function subtree(string $root): array
    {
        return [$root];
    }
}

final class Clock
{
    public CarbonImmutable $now;

    public function __construct()
    {
        $this->now = CarbonImmutable::parse('2026-11-01T10:00:00Z');
    }
}

/** @return array{Orm, AuditLog, PdoConnection, Clock} */
function log(): array
{
    $clock = new Clock();
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute('CREATE TABLE audit_notes (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, body TEXT NOT NULL)');
    AuditTable::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('audit-prune'), new Tenants());
    $audit = new AuditLog($orm, new AuthContext(), 'audit-prune-verify-test-key-of-at-least-32-bytes', retentionDays: 30, clock: static fn (): CarbonImmutable => $clock->now);

    return [$orm, $audit, $connection, $clock];
}

function note(Orm $orm, string $body): void
{
    $note = new Note();
    $note->body = $body;
    $orm->save($note);
}

/**
 * acme's log: two entries long past the retention, then two recent ones.
 *
 * @return array{Orm, AuditLog, PdoConnection, CarbonImmutable}
 */
function written(): array
{
    [$orm, $audit, $connection, $clock] = log();
    $orm->actAs('acme', function () use ($orm): void {
        note($orm, 'old one');
        note($orm, 'old two');
    });
    $clock->now = $clock->now->addDays(60);
    $orm->actAs('acme', function () use ($orm): void {
        note($orm, 'recent three');
        note($orm, 'recent four');
    });

    return [$orm, $audit, $connection, $clock->now];
}

/** @return array<string, mixed> */
function head(PdoConnection $connection): array
{
    return $connection->select("SELECT pruned_through, anchor, anchor_mac FROM audit_heads WHERE tenant = 'acme'")[0];
}

function entries(PdoConnection $connection): int
{
    return (int) $connection->select('SELECT COUNT(*) AS n FROM audit_entries')[0]['n'];
}

it('takes nothing, and signs no anchor, over an entry backdated past the retention', function () {
    [, $audit, $connection, $now] = written();
    $before = head($connection);

    // A writer without the key moves entry 3 into the past: its hash no
    // longer covers what the row says.
    $connection->execute('UPDATE audit_entries SET occurred_at = (SELECT occurred_at FROM audit_entries WHERE sequence = 1) WHERE sequence = 3');

    expect(fn () => $audit->prune($now))->toThrow(\RuntimeException::class, "tenant 'acme'")
        ->and(entries($connection))->toBe(4)
        ->and(head($connection))->toBe($before)
        ->and($audit->verify('acme')->problem)->toBe('Entry 3 is not as it was written.');
});

it('takes nothing over an entry edited, or a link broken, below where it prunes to', function () {
    [, $audit, $connection, $now] = written();
    $connection->execute("UPDATE audit_entries SET changes = REPLACE(changes, 'old one', 'nothing') WHERE sequence = 1");
    expect(fn () => $audit->prune($now))->toThrow(\RuntimeException::class, 'Entry 1 is not as it was written')
        ->and(entries($connection))->toBe(4);

    [, $audit, $connection, $now] = written();
    $connection->execute("UPDATE audit_entries SET previous_hash = 'f00d' WHERE sequence = 2");
    expect(fn () => $audit->prune($now))->toThrow(\RuntimeException::class, 'Entry 2 is not chained')
        ->and(entries($connection))->toBe(4);
});

it('takes nothing where the anchor it would start from has been changed', function () {
    [$orm, $audit, $connection, $now] = written();
    expect($audit->prune($now))->toBe(2);
    $connection->execute("UPDATE audit_heads SET anchor = 'f00d' WHERE tenant = 'acme'");

    expect(fn () => $audit->prune($now->addDays(60)))->toThrow(\RuntimeException::class, 'Where pruning left the log has been changed')
        ->and(entries($connection))->toBe(2);
});

it('still prunes every other tenant\'s log, and names the one it refused', function () {
    [$orm, $audit, $connection, $clock] = log();
    foreach (['acme', 'globex'] as $tenant) {
        $orm->actAs($tenant, fn () => note($orm, 'old'));
    }
    $connection->execute("UPDATE audit_entries SET changes = REPLACE(changes, 'old', 'new') WHERE tenant = 'acme'");

    expect(fn () => $audit->prune($clock->now->addDays(60)))->toThrow(\RuntimeException::class, "tenant 'acme'")
        ->and($connection->select('SELECT tenant FROM audit_entries'))->toBe([['tenant' => 'acme']]);
});

it('prunes an intact log as before, batch by batch', function () {
    [, $audit, $connection, $now] = written();
    expect($audit->prune($now, batch: 1))->toBe(2)
        ->and($audit->verify('acme')->intact)->toBeTrue()
        ->and((int) head($connection)['pruned_through'])->toBe(2);
});
