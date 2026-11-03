<?php

declare(strict_types=1);

/**
 * verify() walks a tenant's log from where pruning left it to its head
 * (ADR 0156). An entry put into the table below that start -- at a place
 * of 0 or less, or at one pruning has passed -- is read by trail() as any
 * other, so it must break the walk too, or a forged entry reads as part of
 * the log that "verifies".
 */

namespace Tinvar\Tests\Audit\VerifyBelowStart;

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

/** @return array{Orm, AuditLog, PdoConnection} */
function log(): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute('CREATE TABLE audit_notes (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, body TEXT NOT NULL)');
    AuditTable::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('audit-below'), new Tenants());
    $audit = new AuditLog($orm, new AuthContext(), 'audit-below-start-test-key-of-at-least-32-bytes', retentionDays: 1, clock: static fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-01T10:00:00Z'));

    return [$orm, $audit, $connection];
}

function note(Orm $orm, string $body): void
{
    $note = new Note();
    $note->body = $body;
    $orm->save($note);
}

/** A forged entry at $sequence, copied from the newest entry of acme's log. */
function forge(PdoConnection $connection, int $sequence): void
{
    $connection->execute(
        'INSERT INTO audit_entries SELECT ? AS id, tenant, ? AS sequence, occurred_at, event, subject_type, ? AS subject_id, actor_type, actor_id,'
        . ' impersonator_id, job, job_id, started_by, api_token_id, action, panel, host, request_id, ip, ? AS changes, data, previous_hash, hash'
        . " FROM audit_entries WHERE tenant = 'acme' ORDER BY sequence DESC LIMIT 1",
        ['forged-' . $sequence, $sequence, 'order-forged', '{"body":[null,"forged"]}'],
    );
}

it('finds an entry put in at a place of 0 or less, which trail() reads', function () {
    [$orm, $audit, $connection] = log();
    $orm->actAs('acme', function () use ($orm): void {
        note($orm, 'one');
        note($orm, 'two');
    });
    expect($audit->verify('acme')->intact)->toBeTrue();

    forge($connection, 0);
    forge($connection, -5);

    $read = $orm->actAs('acme', fn (): array => $audit->trail()->recent());
    expect(array_map(static fn ($e): int => $e->sequence, $read))->toBe([2, 1, 0, -5]);

    $verified = $audit->verify('acme');
    expect($verified->intact)->toBeFalse()
        ->and($verified->problem)->toBe('Entry -5 is below where the log starts.');
});

it('finds an entry put in at a place pruning has passed, which trail() reads', function () {
    [$orm, $audit, $connection] = log();
    $orm->actAs('acme', function () use ($orm): void {
        foreach (['one', 'two', 'three'] as $body) {
            note($orm, $body);
        }
    });
    expect($audit->prune(CarbonImmutable::parse('2026-11-10T10:00:00Z')))->toBe(3);
    $orm->actAs('acme', fn () => note($orm, 'four'));
    expect($audit->verify('acme')->intact)->toBeTrue()
        ->and($audit->verify('acme')->entries)->toBe(1);

    forge($connection, 2);

    $read = $orm->actAs('acme', fn (): array => $audit->trail()->recent());
    expect(array_map(static fn ($e): int => $e->sequence, $read))->toBe([4, 2]);

    $verified = $audit->verify('acme');
    expect($verified->intact)->toBeFalse()
        ->and($verified->problem)->toBe('Entry 2 is below where the log starts.');
});
