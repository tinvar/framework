<?php

declare(strict_types=1);

/**
 * An app made from the skeleton runs its own #[Command] classes from
 * bin/tinvar, as the shop's console does: each by the name it declares,
 * listed in `help`, run through the app's Kernel's console.
 *
 * Through the skeleton's own bin/tinvar, in a copy of the skeleton, in a
 * process of its own, as SkeletonDevToolsTest runs it: its App\ namespace
 * is loaded there and nowhere else.
 */

namespace Tinvar\Tests\Packages\SkeletonCommands;

const ROOT = __DIR__ . '/../../../..';

/** The skeleton copied with one command of the app's own, and a vendor/autoload.php loading the workspace's packages. */
function skeleton(): string
{
    $skeleton = ROOT . '/packages/skeleton';
    if (!is_dir("{$skeleton}/app") || !is_file(ROOT . '/vendor/autoload.php')) {
        test()->markTestSkipped('The skeleton is not beside this package.');
    }

    $app = sys_get_temp_dir() . '/tinvar-skeleton-commands-' . bin2hex(random_bytes(5));
    foreach (['app', 'bin', 'database', 'resources'] as $directory) {
        copyTree("{$skeleton}/{$directory}", "{$app}/{$directory}");
    }
    copy("{$skeleton}/composer.json", "{$app}/composer.json");
    foreach (glob("{$app}/database/*.sqlite*") ?: [] as $file) {
        unlink($file);
    }
    $app = (string) realpath($app);
    $root = (string) realpath(ROOT);

    mkdir("{$app}/app/Console");
    file_put_contents("{$app}/app/Console/GreetNotes.php", <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Console;

        use Tinvar\Attributes\Command;
        use Tinvar\Console\Command as Base;
        use Tinvar\Console\Input;
        use Tinvar\Console\Output;

        #[Command('notes:greet', description: 'Greet whoever the app is run for')]
        final class GreetNotes extends Base
        {
            public function handle(Input $input, Output $output): int
            {
                $output->line('hello from the app, ' . ($input->option('name') ?? 'nobody'));

                return 3;
            }
        }

        PHP);

    mkdir("{$app}/vendor");
    file_put_contents("{$app}/vendor/autoload.php", <<<PHP
        <?php

        require '{$root}/vendor/autoload.php';
        spl_autoload_register(static function (string \$class): void {
            if (str_starts_with(\$class, 'App\\\\')) {
                \$file = '{$app}/app/' . str_replace('\\\\', '/', substr(\$class, 4)) . '.php';
                if (is_file(\$file)) {
                    require \$file;
                }
            }
        });

        PHP);

    return $app;
}

/**
 * @param list<string> $arguments
 * @return array{int, string}
 */
function tinvar(string $app, array $arguments): array
{
    $own = ['TINVAR_ENV', 'TINVAR_PROFILE', 'TINVAR_BUILD_DIR', 'TINVAR_SOCKET', 'TINVAR_DEV', 'APP_ENV', 'NODE_ENV'];
    $env = [
        ...array_filter(
            array_filter(getenv(), \is_string(...)),
            static fn (string $name): bool => !str_starts_with($name, 'APP_') && !\in_array($name, $own, true),
            ARRAY_FILTER_USE_KEY,
        ),
        'TINVAR_ENV' => 'local',
        'TINVAR_APP_KEY' => base64_encode(random_bytes(32)),
        'TINVAR_PROBE_TOKEN' => bin2hex(random_bytes(24)),
        'APP_DATABASE' => "{$app}/database/app.sqlite",
    ];
    $process = proc_open([PHP_BINARY, "{$app}/bin/tinvar", ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $env);
    \assert(\is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

function copyTree(string $from, string $to): void
{
    mkdir($to, 0o755, true);
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to . substr((string) $item, \strlen($from));
        $item->isDir() ? mkdir($target, 0o755, true) : copy((string) $item, $target);
    }
}

function remove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = (string) $item;
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
    }
    rmdir($directory);
}

it('lists and runs the app\'s own #[Command] classes through its Kernel', function () {
    $app = skeleton();
    try {
        [$helpStatus, $help] = tinvar($app, ['help']);
        // The Kernel opens the app's database, which migrate makes.
        [$migrated, $migration] = tinvar($app, ['migrate']);
        [$status, $output] = tinvar($app, ['notes:greet', '--name=Ada']);

        expect($helpStatus)->toBe(0, $help)
            ->and($migrated)->toBe(0, $migration)
            ->and($help)->toContain('notes:greet')
            ->and($help)->toContain('Greet whoever the app is run for')
            // Its own exit status, and its own output.
            ->and($status)->toBe(3, $output)
            ->and($output)->toContain('hello from the app, Ada');
    } finally {
        remove($app);
    }
});
