<?php

declare(strict_types=1);

/**
 * The application console every bin/tinvar runs: its own commands, an
 * application's in their place or beside them, and the checks it adds.
 */

namespace Tinvar\Tests\Console\AppConsole;

use Tinvar\Console\AppConsole;
use Tinvar\Http\FileBuildStore;
use Tinvar\Packages\PackagePolicy;

/** @param array<string, mixed> $overrides */
function console(array $overrides = []): AppConsole
{
    return new AppConsole(...[
        'root' => __DIR__ . '/../../../../examples/shop',
        'console' => __FILE__,
        'namespace' => 'Shop',
        'routes' => \Shop\Kernel::ROUTES,
        'queries' => \Shop\Kernel::QUERIES,
        'actions' => \Shop\Kernel::ACTIONS,
        'models' => [],
        'configs' => [],
        'packages' => static fn (): PackagePolicy => \Shop\Kernel::packages(),
        'buildStore' => static fn (): FileBuildStore => new FileBuildStore(sys_get_temp_dir() . '/unused-builds.json'),
        'database' => static fn (): string => ':memory:',
        'migrations' => sys_get_temp_dir(),
        'projectRoot' => __DIR__ . '/../../../..',
        ...$overrides,
    ]);
}

/** @return array{int, string} */
function ran(AppConsole $console, string ...$argv): array
{
    ob_start();
    $status = $console->run([__FILE__, ...$argv]);

    return [$status, (string) ob_get_clean()];
}

it('has every command an application has, and lists them', function () {
    $console = console();
    foreach (['check', 'config', 'codegen', 'serve', 'dev', 'build', 'build:fragments', 'build:current', 'build:revoke', 'renderer:start', 'migrate', 'migrate:fresh', 'make:migration', 'make:page', 'make:model', 'packages:lock', 'audit'] as $command) {
        expect($console->has($command))->toBeTrue($command);
    }
    [$status, $help] = ran($console, 'help');

    expect($status)->toBe(0)
        ->and($help)->toContain('make:page <Name> --path=/racks/{rack}')
        ->and($help)->toContain('make:migration <name> [--blank] [--rename=TABLE.OLD:NEW]')
        ->and($console->has('db:seed'))->toBeFalse();
});

it('takes an application\'s command beside its own, or in a built-in\'s place', function () {
    $console = console()
        ->command('orders:report', 'orders:report', 'Totals for a day', static fn (array $a): int => 7)
        ->command('audit', 'audit', 'Mine', static fn (array $a): int => \count($a));

    expect(ran($console, 'orders:report')[0])->toBe(7)
        ->and(ran($console, 'audit', 'a', 'b')[0])->toBe(2)
        ->and(ran($console, 'nope')[0])->toBe(64);
});

it('says codegen is missing where the install left it out', function () {
    expect(ran(console(), 'codegen')[0])->toBe(1);
});

it('fails the check on an application\'s own check, naming it', function () {
    $console = console()->withCheck('Mine failed:', static fn (): array => ['one thing'], 'ok: mine');

    expect(ran($console, 'check')[0])->toBe(1);
});

// The registry's own check -- an endpoint that does not compile, a path a
// page also matches (ADR 0067) -- ran only where an application wired it in.
it('holds the endpoints among its entry points to the registry\'s check', function () {
    $clashes = console(['entryPoints' => [ApartFromEveryPage::class, ClashesWithAPage::class]]);

    expect(AppConsole::endpointViolations([ApartFromEveryPage::class], \Shop\Kernel::ROUTES, []))->toBe([])
        ->and(implode("\n", array_map('strval', AppConsole::endpointViolations([ClashesWithAPage::class], \Shop\Kernel::ROUTES, []))))
        ->toContain('/orders/{order}')
        ->and(ran($clashes, 'check')[0])->toBe(1);
});

it('refuses --worker where the application has no queue to run', function () {
    expect(ran(console(), 'serve', '--worker')[0])->toBe(64);
});

it('registers what the generators write in the Kernel\'s own lists by default', function () {
    $registrations = console(['kernel' => \Shop\Kernel::class])->registrations();

    expect($registrations->none())->toBeFalse()
        ->and(console()->registrations()->none())->toBeFalse(); // the route table alone
});

final class ApartFromEveryPage
{
    #[\Tinvar\Attributes\Get('/api/apart'), \Tinvar\Attributes\NoAuthorization(reason: 'a fixture')]
    public function show(): void {}
}

final class ClashesWithAPage
{
    #[\Tinvar\Attributes\Get('/orders/{id}'), \Tinvar\Attributes\NoAuthorization(reason: 'a fixture')]
    public function show(string $id): void {}
}
