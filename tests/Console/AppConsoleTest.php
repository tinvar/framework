<?php

declare(strict_types=1);

/**
 * The application console every bin/tinvar runs: its own commands, an
 * application's in their place or beside them, and the checks it adds.
 */

namespace Tinvar\Tests\Console\AppConsole;

use Tinvar\Console\AppConsole;
use Tinvar\Console\Prompts;
use Tinvar\Console\Prompts\Cancelled;
use Tinvar\Console\Prompts\Key;
use Tinvar\Discovery\DiscoveredClasses;
use Tinvar\Discovery\DiscoveryFailed;
use Tinvar\Discovery\Manifest;
use Tinvar\Http\FileBuildStore;
use Tinvar\Packages\PackagePolicy;

/** @param array<string, mixed> $overrides */
function console(array $overrides = []): AppConsole
{
    return new AppConsole(...[
        'root' => __DIR__ . '/../../../../examples/shop',
        'console' => __FILE__,
        'namespace' => 'Shop',
        'classes' => static fn (): DiscoveredClasses => new DiscoveredClasses(
            routes: \Shop\Kernel::classes()->routes,
            queries: \Shop\Kernel::classes()->queries,
            actions: \Shop\Kernel::classes()->actions,
        ),
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
    foreach (['check', 'config', 'codegen', 'serve', 'dev', 'build', 'build:fragments', 'discover', 'build:current', 'build:revoke', 'renderer:start', 'migrate', 'migrate:fresh', 'make:migration', 'make:page', 'make:model', 'packages:lock', 'audit'] as $command) {
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
    $clashes = console(['classes' => static fn (): DiscoveredClasses => new DiscoveredClasses(routes: \Shop\Kernel::classes()->routes, endpoints: [ApartFromEveryPage::class, ClashesWithAPage::class])]);

    expect(AppConsole::endpointViolations([ApartFromEveryPage::class], \Shop\Kernel::classes()->routes, []))->toBe([])
        ->and(implode("\n", array_map('strval', AppConsole::endpointViolations([ClashesWithAPage::class], \Shop\Kernel::classes()->routes, []))))
        ->toContain('/orders/{order}')
        ->and(ran($clashes, 'check')[0])->toBe(1);
});

// `check --json`, for an editor's problems list (ADR 0159): each problem
// with the file and line of the class it names, and the same exit status.
it('prints what the check found as JSON with --json, each problem at the class it names', function () {
    $clashes = console(['classes' => static fn (): DiscoveredClasses => new DiscoveredClasses(routes: \Shop\Kernel::classes()->routes, endpoints: [ClashesWithAPage::class])]);
    [$status, $out] = ran($clashes, 'check', '--json');
    $report = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
    $problems = array_merge(...array_column($report['sections'], 'problems'));
    $clash = array_values(array_filter($problems, static fn (array $problem): bool => str_contains($problem['message'], '/orders/{order}')))[0] ?? null;

    expect($status)->toBe(1)
        ->and($report['format'])->toBe(1)
        ->and($report['passed'])->toBeFalse()
        ->and($clash)->not->toBeNull()
        ->and(realpath((string) $clash['file']))->toBe(realpath(__FILE__))
        ->and($clash['line'])->toBe(new \ReflectionClass(ClashesWithAPage::class)->getMethod('show')->getStartLine())
        ->and(ran($clashes, 'check', '--yaml')[0])->toBe(64);

    $console = console()->withCheck('Mine failed:', static fn (): array => [], 'ok: mine');
    [$status, $out] = ran($console, 'check', '--json');
    $mine = array_values(array_filter(json_decode($out, true, 16, JSON_THROW_ON_ERROR)['sections'], static fn (array $section): bool => $section['heading'] === 'Mine failed:'))[0];

    expect($mine)->toBe(['heading' => 'Mine failed:', 'problems' => [], 'passed' => ['ok: mine']]);
});

it('refuses --worker where the application has no queue to run', function () {
    expect(ran(console(), 'serve', '--worker')[0])->toBe(64);
});

it('registers no class for the generators, which discovery finds, and a page\'s view in the route table', function () {
    expect(console(['kernel' => \Shop\Kernel::class])->registrations()->none())->toBeFalse(); // the route table alone
});

// Discovery (ADR 0146): `discover` writes the manifest production boots
// from, `discover --check` and `check` fail on one a scan disagrees with,
// and a scan refused stops every command that needs the classes.
it('writes the discovery manifest into the build, and refuses a stale one in discover --check and check', function () {
    $build = sys_get_temp_dir() . '/tinvar-discover-' . bin2hex(random_bytes(4));
    mkdir($build);
    $before = getenv('TINVAR_BUILD_DIR');
    putenv("TINVAR_BUILD_DIR={$build}");
    try {
        $console = console(['kernel' => \Shop\Kernel::class, 'classes' => \Shop\Kernel::classes(...)]);

        [$status, $out] = ran($console, 'discover');
        expect($status)->toBe(0)
            ->and($out)->toContain("wrote {$build}/discovery.php")
            ->and(Manifest::read("{$build}/discovery.php", 'Shop')->routes)->toBe(\Shop\Kernel::classes()->routes)
            ->and(ran($console, 'discover', '--check')[0])->toBe(0);

        // A manifest from before a page was added.
        $scan = Manifest::read("{$build}/discovery.php", 'Shop');
        Manifest::write("{$build}/discovery.php", 'Shop', $scan->without(\Shop\Http\Pages\Catalog\Catalog::class));

        expect(ran($console, 'discover', '--check')[0])->toBe(1);

        expect(ran($console, 'discover', '--nope')[0])->toBe(64);
    } finally {
        putenv($before === false ? 'TINVAR_BUILD_DIR' : "TINVAR_BUILD_DIR={$before}");
        exec('rm -rf ' . escapeshellarg($build));
    }
});

// `tinvar build --out=DIR` is how a test or staging build is made, and is
// served from DIR alone: with no fragment manifest beside it, no render of
// it could mint a boundary or call a query.
it('writes the fragment manifest and the discovery manifest into the build it made', function () {
    $scratch = sys_get_temp_dir() . '/tinvar-build-' . bin2hex(random_bytes(4));
    mkdir("{$scratch}/bin", 0o777, true);
    // Vite, as far as this console can tell: the build's deferred.json.
    file_put_contents("{$scratch}/bin/npx", "#!/bin/sh\nmkdir -p \"\$TINVAR_BUILD_DIR/rsc\" && cp \"\$TINVAR_TEST_DEFERRED\" \"\$TINVAR_BUILD_DIR/rsc/deferred.json\"\n");
    chmod("{$scratch}/bin/npx", 0o755);
    $path = (string) getenv('PATH');
    putenv("PATH={$scratch}/bin:{$path}");
    putenv('TINVAR_TEST_DEFERRED=' . \dirname(__DIR__, 4) . '/examples/shop/dist/rsc/deferred.json');
    try {
        $console = console(['kernel' => \Shop\Kernel::class, 'classes' => \Shop\Kernel::classes(...)]);

        [$status, $out] = ran($console, 'build', '--profile=test', '--app=' . \dirname(__DIR__, 4) . '/examples/shop', "--out={$scratch}/out");
        expect($status)->toBe(0)
            ->and($out)->toContain("wrote {$scratch}/out/fragments.json")
            ->and(json_decode((string) file_get_contents("{$scratch}/out/fragments.json"), true)['queries'] ?? null)->not->toBe([])
            ->and(Manifest::read("{$scratch}/out/discovery.php", 'Shop')->routes)->toBe(\Shop\Kernel::classes()->routes);
    } finally {
        putenv("PATH={$path}");
        putenv('TINVAR_TEST_DEFERRED');
        exec('rm -rf ' . escapeshellarg($scratch));
    }
});

// The build directory discover writes into, by default, is the one
// build:fragments writes into: TINVAR_BUILD_DIR's where it names one.
it('writes the fragment manifest into the build TINVAR_BUILD_DIR names, as discover does', function () {
    $build = sys_get_temp_dir() . '/tinvar-fragments-' . bin2hex(random_bytes(4));
    mkdir("{$build}/rsc", 0o777, true);
    copy(\dirname(__DIR__, 4) . '/examples/shop/dist/rsc/deferred.json', "{$build}/rsc/deferred.json");
    $before = getenv('TINVAR_BUILD_DIR');
    putenv("TINVAR_BUILD_DIR={$build}");
    try {
        $console = console();

        [$status, $out] = ran($console, 'build:fragments');
        expect($status)->toBe(0)
            ->and($out)->toContain("wrote {$build}/fragments.json")
            ->and(is_file("{$build}/fragments.json"))->toBeTrue()
            ->and(ran($console, 'build:fragments', '--build=')[0])->toBe(64);
    } finally {
        putenv($before === false ? 'TINVAR_BUILD_DIR' : "TINVAR_BUILD_DIR={$before}");
        exec('rm -rf ' . escapeshellarg($build));
    }
});

it('stops a command that needs the classes where discovery refuses them, saying why', function () {
    $console = console(['classes' => static fn (): DiscoveredClasses => throw new DiscoveryFailed(['Broken.php does not parse'])]);

    expect(ran($console, 'config')[0])->toBe(1)
        ->and(ran($console, 'check')[0])->toBe(1)
        ->and(ran($console, 'help')[0])->toBe(0);
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

afterEach(function () {
    Prompts::restore();
});

it('reads --no-interaction or -n anywhere on the line, the command first or not', function () {
    $console = console()->command('echo', 'echo', 'Its arguments', static fn (array $a): int => \count($a) + (Prompts::interactive() ? 0 : 10));
    Prompts::fakeTerminal();

    expect(ran($console, 'echo', 'a')[0])->toBe(1)
        ->and(ran($console, '-n', 'echo', 'a')[0])->toBe(11);
});

it('exits 130 when a prompt is cancelled, and 64 when nobody is there to answer one', function () {
    $console = console()->command('ask', 'ask', 'Asks', static fn (array $a): int => Prompts::text('Name?', required: true) === '' ? 1 : 0);

    Prompts::fakeTerminal([Key::CTRL_C]);
    expect(ran($console, 'ask')[0])->toBe(Cancelled::STATUS);

    Prompts::fakeTerminal();
    expect(ran($console, 'ask', '--no-interaction')[0])->toBe(64);
});

it('draws no progress bar to roll back nothing', function () {
    $dir = sys_get_temp_dir() . '/tinvar-app-rollback-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/migrations", 0o755, true);
    $environment = getenv('TINVAR_ENV');
    putenv('TINVAR_ENV=testing');
    try {
        $console = console(['database' => static fn (): string => "{$dir}/app.sqlite", 'migrations' => "{$dir}/migrations"]);
        expect(ran($console, 'migrate')[0])->toBe(0);

        $terminal = Prompts::fakeTerminal();
        expect(ran($console, 'migrate:rollback')[0])->toBe(0)
            ->and($terminal->screen())->not->toContain('Rolling back');
    } finally {
        putenv($environment === false ? 'TINVAR_ENV' : "TINVAR_ENV={$environment}");
        exec('rm -rf ' . escapeshellarg($dir));
    }
});

it('confirms migrate:fresh on a terminal, and runs the migrations under a progress bar', function () {
    $dir = sys_get_temp_dir() . '/tinvar-app-migrate-' . bin2hex(random_bytes(4));
    mkdir("{$dir}/migrations", 0o755, true);
    file_put_contents("{$dir}/migrations/2026_10_24_000001_create_notes.php", <<<'PHP'
        <?php

        declare(strict_types=1);

        use Tinvar\Orm\Migrations\Migration;
        use Tinvar\Orm\Schema\Schema;
        use Tinvar\Orm\Schema\Table;

        return new class extends Migration {
            public function up(Schema $schema): void
            {
                $schema->create('notes', function (Table $t): void { $t->uuid('id')->primary(); });
            }
        };
        PHP);
    $environment = getenv('TINVAR_ENV');
    putenv('TINVAR_ENV=testing');
    try {
        $console = console(['database' => static fn (): string => "{$dir}/app.sqlite", 'migrations' => "{$dir}/migrations"]);

        $terminal = Prompts::fakeTerminal(['y', Key::ENTER]);
        expect(ran($console, 'migrate:fresh')[0])->toBe(0)
            ->and($terminal->screen())->toContain('migrate:fresh: drop every table, and every row in them?')->toContain('│ Yes')
            ->toContain('┌ Migrating')->toContain('1/1')->toContain('2026_10_24_000001_create_notes');

        // No, the default, refuses as a missing --force did.
        Prompts::fakeTerminal([Key::ENTER]);
        expect(ran($console, 'migrate:fresh')[0])->toBe(1);

        // Nobody there: no question, and refused without --force as ever.
        $terminal = Prompts::fakeTerminal();
        expect(ran($console, 'migrate:fresh', '--no-interaction')[0])->toBe(1)
            ->and($terminal->output())->toBe('');
    } finally {
        putenv($environment === false ? 'TINVAR_ENV' : "TINVAR_ENV={$environment}");
        array_map(unlink(...), glob("{$dir}/migrations/*") ?: []);
        rmdir("{$dir}/migrations");
        @unlink("{$dir}/app.sqlite");
        rmdir($dir);
    }
});
