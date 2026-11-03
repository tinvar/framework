<?php

declare(strict_types=1);

/**
 * An #[Audited] model saved inside acrossTenants() during an action -- an
 * announcement open (Orm::tenantsEnteredBy()) -- is recorded in its row's
 * tenant's log (ADR 0156). The log writes its entry and head as raw SQL,
 * which the ORM's guard refuses inside acrossTenants() where it could name
 * no tenant (ADR 0122); the log names its tenant, so it writes as that
 * tenant rather than failing the action with a 500.
 */

namespace Tinvar\Tests\Audit\AcrossTenants;

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

#[Table('audit_across_notes'), TenantScoped, Audited]
final class Note extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $body;
}

final class Flat implements TenantSubtrees
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

it('records an audited write made inside acrossTenants() during an action, under the row\'s tenant', function () {
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute('CREATE TABLE audit_across_notes (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, body TEXT NOT NULL)');
    AuditTable::define(new Schema($connection, Dialect::for($connection)));
    $orm = new Orm($connection, new IdentityMap('audit-across'), new Flat());
    $audit = new AuditLog($orm, new AuthContext(), 'audit-across-tenants-test-key-of-at-least-32-bytes', clock: static fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-01T10:00:00Z'));
    $orm->recordChangesWith($audit);

    $id = $orm->actAs('acme', static function () use ($orm): string {
        $note = new Note();
        $note->body = 'one';
        $orm->save($note);

        return $note->id;
    });

    [, $entered] = $orm->actAs('globex', static fn (): array => $orm->tenantsEnteredBy(static fn (): mixed => $orm->acrossTenants(static function () use ($orm, $id): void {
        $note = $orm->find(Note::class, $id);
        \assert($note instanceof Note);
        $note->body = 'two';
        $orm->save($note);
    })));

    expect($connection->select('SELECT body FROM audit_across_notes')[0]['body'])->toBe('two')
        ->and(array_column($connection->select("SELECT event FROM audit_entries WHERE tenant = 'acme' ORDER BY sequence"), 'event'))->toBe(['created', 'updated'])
        ->and($connection->select("SELECT COUNT(*) AS n FROM audit_entries WHERE tenant = 'globex'")[0]['n'])->toEqual(0)
        ->and($entered)->toContain('acme')
        ->and($orm->actAs('acme', static fn (): bool => $audit->verify('acme')->intact))->toBeTrue();
});
