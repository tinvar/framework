<?php

declare(strict_types=1);

/**
 * Two prunes of one log at once -- two workers' schedulers, a command run
 * by hand beside the nightly one -- take turns at its head row (ADR 0209).
 * The second waits for the first to commit, then starts from where it
 * left the log, rather than from a head it read before that commit: from
 * there the entries the first took read as missing, and the second refused
 * the log as broken when nothing had been changed.
 */

namespace Tinvar\Tests\Audit\PruneConcurrent;

use Carbon\CarbonImmutable;
use Tinvar\Audit\AuditLog;
use Tinvar\Audit\AuditTable;
use Tinvar\Auth\AuthContext;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/../Support/Fixtures/databases.php';

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

/** An audit log over $connection, its clock at $now. */
function auditOn(PdoConnection $connection, CarbonImmutable $now): AuditLog
{
    $orm = new Orm($connection, new IdentityMap('audit-prune-concurrent'), new Flat());

    return new AuditLog($orm, new AuthContext(), 'audit-prune-concurrent-test-key-of-32-bytes', retentionDays: 30, clock: static fn (): CarbonImmutable => $now);
}

/** A connection for the ORM: MySQL reads its quoted names with ANSI_QUOTES. */
function connection(\PDO $pdo, string $driver): PdoConnection
{
    if ($driver === 'mysql') {
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
    }

    return new PdoConnection($pdo, 'audit');
}

it('lets a second prune wait for the first, and start from where it left the log', function (string $driver) {
    $missing = $driver === 'pgsql' ? Databases::postgresMissing() : Databases::mysqlMissing();
    if ($missing !== null) {
        test()->markTestSkipped($missing);
    }

    Databases::inScratchSchema($driver, static function (\PDO $pdo, string $schema) use ($driver): void {
        $mode = $driver === 'mysql' ? $pdo->query('SELECT @@SESSION.sql_mode')?->fetchColumn() : null;
        try {
            $connection = connection($pdo, $driver);
            AuditTable::define(new Schema($connection, Dialect::for($connection)));
            $written = CarbonImmutable::parse('2026-09-01T10:00:00Z');
            $log = auditOn($connection, $written);
            $orm = new Orm($connection, new IdentityMap('audit-prune-concurrent-writer'), new Flat());
            foreach (['one', 'two', 'three'] as $what) {
                $orm->actAs('acme', static fn () => $log->event('note.made', data: ['what' => $what]));
            }
            $later = $written->addDays(60);

            // Another worker, pruning the same log, on a connection of its own
            // that gives up on a lock after a second.
            $other = Databases::another($driver);
            $other->exec($driver === 'pgsql' ? "SET search_path TO {$schema}" : "USE {$schema}");
            $other->exec($driver === 'pgsql' ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
            $otherLog = auditOn(connection($other, $driver), $later);

            // It runs once this prune has read the head, inside its batch.
            $outcome = null;
            $connection->observeStatements(static function (string $sql) use ($connection, $otherLog, &$outcome): void {
                if (!str_contains($sql, 'SELECT pruned_through')) {
                    return;
                }
                $connection->observeStatements(null);
                try {
                    $outcome = $otherLog->prune();
                } catch (\Throwable $e) {
                    $outcome = $e;
                }
            });

            $taken = auditOn($connection, $later)->prune();

            // This prune held the head: the other waited on it, and was
            // turned away by its lock timeout rather than taking the entries
            // from under it.
            expect($outcome)->toBeInstanceOf(\PDOException::class)
                ->and($taken)->toBe(3)
                ->and($log->verify('acme')->intact)->toBeTrue();

            // Run again now, it finds nothing left to take, and nothing wrong.
            expect($otherLog->prune())->toBe(0);
        } finally {
            if (\is_string($mode)) {
                $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($mode));
            }
        }
    });
})->with(['pgsql', 'mysql']);
