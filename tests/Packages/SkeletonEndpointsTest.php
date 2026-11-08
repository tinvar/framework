<?php

declare(strict_types=1);

/**
 * An app made from the skeleton serves its own endpoints (ADR 0067): a
 * method with #[Get] or #[Post] under App\ answers at its path, through
 * the app's Kernel, as the shop's do -- a sessionless one with the exact
 * bytes its body arrived as (ADR 0183).
 *
 * Through the skeleton's own Kernel, in a copy of the skeleton, in a
 * process of its own, as SkeletonCommandsTest runs it: its App\ namespace
 * is loaded there and nowhere else.
 */

namespace Tinvar\Tests\Packages\SkeletonEndpoints;

const ROOT = __DIR__ . '/../../../..';

/** The skeleton copied with two endpoints of the app's own, and a vendor/autoload.php loading the workspace's packages. */
function skeleton(): string
{
    $skeleton = ROOT . '/packages/skeleton';
    if (!is_dir("{$skeleton}/app") || !is_file(ROOT . '/vendor/autoload.php')) {
        test()->markTestSkipped('The skeleton is not beside this package.');
    }

    $app = sys_get_temp_dir() . '/tinvar-skeleton-endpoints-' . bin2hex(random_bytes(5));
    foreach (['app', 'bin', 'database', 'resources'] as $directory) {
        copyTree("{$skeleton}/{$directory}", "{$app}/{$directory}");
    }
    copy("{$skeleton}/composer.json", "{$app}/composer.json");
    foreach (glob("{$app}/database/*.sqlite*") ?: [] as $file) {
        unlink($file);
    }
    $app = (string) realpath($app);
    $root = (string) realpath(ROOT);

    mkdir("{$app}/app/Http/Endpoints", 0o755, true);
    file_put_contents("{$app}/app/Http/Endpoints/Ping.php", <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Http\Endpoints;

        use Tinvar\Attributes\Get;
        use Tinvar\Attributes\Post;
        use Tinvar\Attributes\PublicAccess;
        use Tinvar\Attributes\WithoutSession;
        use Tinvar\Http\RawBody;
        use Tinvar\Http\Response;

        final class Ping
        {
            #[Get('/api/ping', name: 'ping'), PublicAccess]
            public function ping(): Response
            {
                return Response::make(200, 'pong', ['content-type' => 'text/plain']);
            }

            #[Post('/api/echo', name: 'echo'), PublicAccess(reason: 'a test caller'), WithoutSession(reason: 'a test caller with no cookie')]
            public function echo(RawBody $body): Response
            {
                return Response::make(200, $body->hash(), ['content-type' => 'text/plain']);
            }
        }

        PHP);
    file_put_contents("{$app}/call.php", <<<'PHP'
        <?php

        require __DIR__ . '/vendor/autoload.php';

        $kernel = App\Kernel::fromEnvironment();
        $answer = static fn (Tinvar\Http\Request $request, string $body = ''): array => [
            ($response = $kernel->handleWith($request, static fn (): Tinvar\Http\Response => Tinvar\Http\Response::make(404), $body))->status,
            $response->body(),
        ];
        echo json_encode([
            'ping' => $answer(new Tinvar\Http\Request('GET', '/api/ping', ['host' => 'localhost'], remoteAddress: '127.0.0.1')),
            'echo' => $answer(new Tinvar\Http\Request('POST', '/api/echo', ['host' => 'localhost', 'content-type' => 'application/json'], remoteAddress: '127.0.0.1'), '{"b": 1,  "a":2}'),
        ]);

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
 * @param list<string> $command
 * @return array{int, string}
 */
function run(string $app, array $command): array
{
    $own = ['TINVAR_ENV', 'TINVAR_PROFILE', 'TINVAR_BUILD_DIR', 'TINVAR_SOCKET', 'TINVAR_DEV', 'APP_ENV', 'NODE_ENV'];
    $env = [
        ...array_filter(
            array_filter(getenv(), \is_string(...)),
            static fn (string $name): bool => !str_starts_with($name, 'APP_') && !\in_array($name, $own, true),
            ARRAY_FILTER_USE_KEY,
        ),
        'TINVAR_ENV' => 'local',
        'TINVAR_APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
        'TINVAR_PROBE_TOKEN' => str_repeat('p', 48),
        'APP_DATABASE' => "{$app}/database/app.sqlite",
    ];
    $process = proc_open([PHP_BINARY, ...$command], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $env);
    \assert(\is_resource($process));
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output . ($errors === '' ? '' : "\n{$errors}")];
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

it('serves the app\'s own endpoints through its Kernel, a sessionless one with its exact bytes', function () {
    $app = skeleton();
    try {
        [$migrated, $migration] = run($app, ["{$app}/bin/tinvar", 'migrate']);
        [$status, $output] = run($app, ["{$app}/call.php"]);
        $said = json_decode(trim($output), true);

        expect($migrated)->toBe(0, $migration)
            ->and($status)->toBe(0, $output)
            ->and($said['ping'] ?? null)->toBe([200, 'pong'], $output)
            ->and($said['echo'] ?? null)->toBe([200, hash('sha256', '{"b": 1,  "a":2}')], $output);
    } finally {
        remove($app);
    }
});
