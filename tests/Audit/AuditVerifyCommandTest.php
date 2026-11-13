<?php

declare(strict_types=1);

/**
 * `tinvar audit:verify`, run through the console as an operator runs it
 * (ADR 0210): it checks every tenant that has a head or any entry -- a log
 * whose head was deleted is a break, not a tenant left out -- its --tenant
 * reaches it rather than being refused as a tenant scope, and
 * --expect-head holds the log to a head the operator kept elsewhere, so a
 * log deleted whole, or cut back with its head put back to match, fails.
 */

namespace Tinvar\Tests\Audit\VerifyCommand;

use Carbon\CarbonImmutable;
use Tinvar\Audit\AuditLog;
use Tinvar\Audit\AuditTable;
use Tinvar\Auth\AuthContext;
use Tinvar\Console\Console;
use Tinvar\Console\Output;
use Tinvar\Console\VerifyAuditLog;
use Tinvar\Http\RequestScope;
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
    $orm = new Orm($connection, new IdentityMap('audit-verify-command'), new Tenants());
    $audit = new AuditLog($orm, new AuthContext(), 'audit-verify-command-test-key-of-at-least-32-bytes', retentionDays: 1, clock: static fn (): CarbonImmutable => CarbonImmutable::parse('2026-11-01T10:00:00Z'));

    return [$orm, $audit, $connection];
}

function note(Orm $orm, string $body): void
{
    $note = new Note();
    $note->body = $body;
    $orm->save($note);
}

/**
 * `audit:verify` with $arguments, through a console: its exit status and
 * every line it printed, errors after the rest.
 *
 * @param list<string> $arguments
 * @return array{int, list<string>}
 */
function verify(Orm $orm, AuditLog $audit, array $arguments = []): array
{
    $lines = [];
    $output = new Output(static function (string $line) use (&$lines): void {
        $lines[] = $line;
    }, static function (string $line) use (&$lines): void {
        $lines[] = $line;
    });
    $console = new Console([VerifyAuditLog::class], $orm, static fn (string $class): object => new $class($audit), new RequestScope());
    $status = $console->run('audit:verify', $arguments, $output);

    return [$status, $lines];
}

/** @return array{Orm, AuditLog, PdoConnection} */
function two(): array
{
    [$orm, $audit, $connection] = log();
    $orm->actAs('acme', function () use ($orm): void {
        note($orm, 'one');
        note($orm, 'two');
    });
    $orm->actAs('globex', fn () => note($orm, 'one'));

    return [$orm, $audit, $connection];
}

it('checks every log, and the one --tenant names alone', function () {
    [$orm, $audit] = two();

    expect(verify($orm, $audit))->toBe([0, ['acme: 2 entries, intact', 'globex: 1 entries, intact']])
        ->and(verify($orm, $audit, ['--tenant=globex']))->toBe([0, ['globex: 1 entries, intact']]);
});

it('refuses --tenant with no tenant named', function () {
    [$orm, $audit] = two();

    expect(verify($orm, $audit, ['--tenant']))->toBe([Console::USAGE, ['Name the tenant whose log to check with --tenant=ID.']]);
});

it('finds a log whose head was deleted and whose entries were left', function () {
    [$orm, $audit, $connection] = two();
    $connection->execute("DELETE FROM audit_heads WHERE tenant = 'acme'");

    expect(verify($orm, $audit))->toBe([1, ['acme: broken at entry 1: The log has entries and no head.', 'globex: 1 entries, intact']]);
});

it('holds a log to the head the operator kept', function () {
    [$orm, $audit, $connection] = two();
    $kept = $audit->head('acme');
    $expect = '--expect-head=' . $kept['sequence'] . ':' . $kept['hash'];

    expect(verify($orm, $audit, ['--tenant=acme', $expect]))->toBe([0, ['acme: 2 entries, intact, at the head expected']]);

    // A tail taken away with the head put back to match: the chain alone holds.
    $first = $connection->select("SELECT hash FROM audit_entries WHERE tenant = 'acme' AND sequence = 1")[0]['hash'];
    $connection->execute("DELETE FROM audit_entries WHERE tenant = 'acme' AND sequence = 2");
    $connection->execute("UPDATE audit_heads SET sequence = 1, hash = ? WHERE tenant = 'acme'", [$first]);
    expect(verify($orm, $audit, ['--tenant=acme'])[0])->toBe(0)
        ->and(verify($orm, $audit, ['--tenant=acme', $expect]))->toBe([1, ['acme: broken at entry 2: The log ends at entry 1, before the head expected at entry 2.']]);

    // The log deleted whole, head and all.
    $connection->execute("DELETE FROM audit_entries WHERE tenant = 'acme'");
    $connection->execute("DELETE FROM audit_heads WHERE tenant = 'acme'");
    expect(verify($orm, $audit)[0])->toBe(0)
        ->and(verify($orm, $audit, ['--tenant=acme', $expect]))->toBe([1, ['acme: broken at entry 1: The log has no head, and one was expected at entry 2.']]);
});

it('finds a head expected that the log does not hold, and one kept past what pruning left', function () {
    [$orm, $audit] = two();
    $kept = $audit->head('acme');

    expect(verify($orm, $audit, ['--tenant=acme', '--expect-head=2:' . str_repeat('0', 64)]))
        ->toBe([1, ['acme: broken at entry 2: Entry 2 is not the one expected.']])
        ->and(verify($orm, $audit, ['--tenant=acme', '--expect-head=2']))->toBe([Console::USAGE, ['--expect-head is SEQUENCE:HASH, as AuditLog::head() gives them.']])
        ->and(verify($orm, $audit, ['--expect-head=2:' . $kept['hash']]))->toBe([Console::USAGE, ['--expect-head names one log\'s head: name its tenant with --tenant=ID.']]);

    expect($audit->prune(CarbonImmutable::parse('2026-11-10T10:00:00Z')))->toBe(3);
    $orm->actAs('acme', fn () => note($orm, 'three'));
    expect(verify($orm, $audit, ['--tenant=acme', '--expect-head=1:' . $kept['hash']]))
        ->toBe([1, ['acme: broken at entry 1: Entry 1 has been pruned, so the head expected cannot be compared: keep a newer one.']])
        ->and(verify($orm, $audit, ['--tenant=acme', '--expect-head=2:' . $kept['hash']]))
        ->toBe([0, ['acme: 1 entries, intact, at the head expected']]);
});
