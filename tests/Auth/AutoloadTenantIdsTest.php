<?php

declare(strict_types=1);

/**
 * tenant-scoped-ids-must-be-typed finds a model in a namespace of its own
 * through the application's composer.json PSR-4 map (ADR 0122): one no
 * Kernel lists, beside no listed model and no model an entry point takes,
 * which the registries and `tinvar check` found by nothing before.
 */

namespace Tinvar\Tests\Auth\AutoloadTenantIds;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\ApplicationClasses;
use Tinvar\Auth\BuildRules;
use Tinvar\Data\Data;

final readonly class CountData extends Data
{
    public function __construct(public int $count) {}
}

#[Query(name: 'autoload.bare'), NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
final class BareDepotQuery
{
    public function __invoke(string $depotId): CountData
    {
        return new CountData(0);
    }
}

/** A form request's field, the same bare id. */
final readonly class MoveRequest extends Data
{
    public function __construct(public string $depotId, public string $name) {}
}

#[Query(name: 'autoload.request'), NoAuthorization(reason: 'fixture: the tenant rule is what is under test')]
final class MoveQuery
{
    public function __invoke(MoveRequest $request): CountData
    {
        return new CountData(0);
    }
}

const PREFIX = 'Tinvar\\Tests\\Auth\\Discovered\\';

// The fixture's model is loaded as an application's composer.json would
// load it, and by nothing else: the test file never requires it.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, PREFIX)) {
        $file = __DIR__ . '/Fixtures/discovered/' . str_replace('\\', '/', substr($class, \strlen(PREFIX))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

function mapped(): ApplicationClasses
{
    return new ApplicationClasses([PREFIX => [__DIR__ . '/Fixtures/discovered']]);
}

it('finds a tenant-scoped model in a namespace of its own through the autoload map', function () {
    $violations = array_map(strval(...), new BuildRules([], mapped())->checkTenantScopedIds([BareDepotQuery::class, MoveQuery::class]));

    expect(implode("\n", $violations))->toContain(BareDepotQuery::class . '::__invoke($depotId)')
        ->and(implode("\n", $violations))->toContain('naming Tinvar\Tests\Auth\Discovered\Depots\Depot, which is #[TenantScoped]')
        ->and(implode("\n", $violations))->toContain(MoveRequest::class)
        ->and(\count($violations))->toBe(2);
});

it('finds nothing where the map holds nothing, as before', function () {
    expect(new BuildRules([], new ApplicationClasses([]))->checkTenantScopedIds([BareDepotQuery::class, MoveQuery::class]))->toBe([]);
});

it('looks under the application\'s own top namespaces alone', function () {
    $elsewhere = new ApplicationClasses(['Elsewhere\\' => [__DIR__ . '/Fixtures/discovered']]);

    expect($elsewhere->named('Depot', ['Tinvar']))->toBe([])
        // Under its own root the file is found by name, and declares a
        // class of another namespace than the map says: not handed back.
        ->and($elsewhere->named('Depot', ['Elsewhere']))->toBe([])
        ->and(mapped()->named('depot', ['Tinvar']))->toBe(['Tinvar\Tests\Auth\Discovered\Depots\Depot'])
        ->and(new BuildRules([], $elsewhere)->checkTenantScopedIds([BareDepotQuery::class]))->toBe([]);
});

// Review of ADR 0122: a walk that stopped at its cap stopped in silence, and
// a file named like a class was loaded whatever it held.
it('says when its walk stopped at the cap, and tinvar check refuses it', function () {
    $capped = new ApplicationClasses([PREFIX => [__DIR__ . '/Fixtures/discovered']], maxFiles: 1);

    $warned = '';
    set_error_handler(static function (int $level, string $message) use (&$warned): bool {
        $warned = $message;

        return true;
    }, E_USER_WARNING);
    try {
        $truncated = $capped->truncated();
    } finally {
        restore_error_handler();
    }

    expect($truncated)->toBeTrue()
        ->and($warned)->toContain('stopped at 1 files')
        ->and($capped->check())->toHaveCount(1)
        ->and($capped->check()[0])->toContain('stopped at 1 files')
        ->and(mapped()->check())->toBe([])
        ->and(mapped()->truncated())->toBeFalse();
});

it('loads no file named like a class that does not declare it', function () {
    $loaded = get_included_files();
    $found = mapped()->named('Ledger', ['Tinvar']);

    expect($found)->toBe([])
        ->and(array_diff(get_included_files(), $loaded))->toBe([]);
});

it('reads the root package\'s composer.json, its autoload and not its autoload-dev', function () {
    $composer = ApplicationClasses::fromComposer();

    // This workspace's root maps the shop under Shop\ and its benchmarks,
    // a dev-only namespace, under Tinvar\Bench\.
    expect($composer->maps(['Shop']))->toBeTrue()
        ->and($composer->named('Order', ['Shop']))->toContain('Shop\Database\Models\Orders\Order')
        ->and($composer->named('Shop', ['Tinvar']))->toBe([]);
})->skip(
    \Composer\InstalledVersions::getRootPackage()['name'] !== 'tinvar/workspace',
    'the root package is not the workspace: the framework\'s suite alone runs in a project of its own (ADR 0055).',
);
