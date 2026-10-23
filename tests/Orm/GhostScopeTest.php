<?php

declare(strict_types=1);

namespace Tinvar\Tests\Orm\GhostScope;

use Tinvar\Attributes\BelongsTo;
use Tinvar\Attributes\Column;
use Tinvar\Attributes\Id;
use Tinvar\Attributes\Table;
use Tinvar\Attributes\TenantScoped;
use Tinvar\Orm\DanglingRelation;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;

#[Table('ghost_projects'), TenantScoped]
final class Project extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $name;
}

#[Table('ghost_tasks'), TenantScoped]
final class Task extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $title;
    #[BelongsTo] public Project $project;
}

const ACME_PROJECT = '0190d1d8-3d2a-7000-8000-00000000a001';
const GLOBEX_PROJECT = '0190d1d8-3d2a-7000-8000-00000000b001';
const STRAY_TASK = '0190d1d8-3d2a-7000-8000-00000000a101';
const GOOD_TASK = '0190d1d8-3d2a-7000-8000-00000000a102';

/**
 * Two tenants. One of acme's tasks points at globex's project -- the shape a
 * bad import or a bug elsewhere leaves behind, and the one a scoped read
 * must keep refusing.
 */
function ghostOrm(): Orm
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE ghost_projects (id TEXT PRIMARY KEY, name TEXT, tenant_id TEXT)');
    $pdo->exec('CREATE TABLE ghost_tasks (id TEXT PRIMARY KEY, title TEXT, project_id TEXT, tenant_id TEXT)');
    $pdo->exec("INSERT INTO ghost_projects VALUES ('" . ACME_PROJECT . "', 'acme project', 'acme'), ('" . GLOBEX_PROJECT . "', 'globex secrets', 'globex')");
    $pdo->exec("INSERT INTO ghost_tasks VALUES ('" . STRAY_TASK . "', 'stray', '" . GLOBEX_PROJECT . "', 'acme'), ('" . GOOD_TASK . "', 'good', '" . ACME_PROJECT . "', 'acme')");

    return new Orm(new PdoConnection($pdo), new IdentityMap('test'));
}

// Touched first inside acrossTenants(), the ghost read its row unscoped --
// globex's -- and kept it, so scoped code afterwards saw another tenant's data.
it('loads a ghost made under a scope under that scope, even when first touched inside acrossTenants', function () {
    $orm = ghostOrm();

    $orm->actAs('acme', function () use ($orm): void {
        $task = $orm->find(Task::class, STRAY_TASK);

        $inside = $orm->acrossTenants(static function () use ($task): string {
            try {
                return $task->project->name;
            } catch (DanglingRelation) {
                return 'refused';
            }
        });

        expect($inside)->toBe('refused');
        expect(fn () => $task->project->name)->toThrow(DanglingRelation::class);
    });
});

it('still loads a ghost whose row is in its own scope', function () {
    $orm = ghostOrm();

    $orm->actAs('acme', function () use ($orm): void {
        $task = $orm->find(Task::class, GOOD_TASK);

        expect($orm->acrossTenants(static fn (): string => $task->project->name))->toBe('acme project')
            ->and($task->project->name)->toBe('acme project');
    });
});

// A ghost made while unscoped carries no scope: acrossTenants() is the
// explicit choice to read every tenant's rows.
it('reads across tenants for a ghost made inside acrossTenants', function () {
    $orm = ghostOrm();

    $name = $orm->acrossTenants(fn (): string => $orm->find(Task::class, STRAY_TASK)?->project->name ?? '');

    expect($name)->toBe('globex secrets');
});
