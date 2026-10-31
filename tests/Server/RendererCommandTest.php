<?php

declare(strict_types=1);

/**
 * The spec's renderer lifecycle commands over a pool (ADR 0028): what each
 * refuses before starting anything, and, for real, a pool started with
 * renderer:start rendering the shop.
 */

namespace Tinvar\Tests\Server\RendererCommand;

use Shop\Kernel;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Server\RendererCommand;
use Tinvar\Server\ServeCommand;
use Tinvar\Support\PrivateDirectory;

$root = \dirname(__DIR__, 4);

/**
 * The command with its output kept and its exec recorded, never run.
 *
 * @return array{RendererCommand, \ArrayObject<int, string>, \ArrayObject<int, string>, \ArrayObject<int, array{string, list<string>, array<string, string>}>}
 */
function command(string $root): array
{
    $out = new \ArrayObject();
    $err = new \ArrayObject();
    $execs = new \ArrayObject();
    $command = new RendererCommand(
        $root,
        static function (string $line) use ($out): void {
            $out[] = $line;
        },
        static function (string $line) use ($err): void {
            $err[] = $line;
        },
        null,
        static function (string $program, array $arguments, array $env) use ($execs): int {
            $execs[] = [$program, $arguments, $env];

            return 0;
        },
    );

    return [$command, $out, $err, $execs];
}

/** A pool directory under /tmp, short enough for a socket path, removed by the test. */
function poolPath(): string
{
    return '/tmp/tvrc-' . bin2hex(random_bytes(4));
}

// From PHP's container beside the renderers', in a pid namespace of its
// own, the pid file names a pid that is another process here, or none: it
// was reported as the supervisor, running.
it('says a supervisor it cannot see is not seen, where its pid is another process here', function () use ($root) {
    $pool = poolPath();
    mkdir($pool, 0700);
    file_put_contents("{$pool}/" . RendererCommand::PID_FILE, (string) getmypid());
    [$command, $out] = command($root);

    try {
        $status = $command->run('renderer:check', ["--socket={$pool}"]);
    } finally {
        unlink("{$pool}/" . RendererCommand::PID_FILE);
        rmdir($pool);
    }

    expect($status)->toBe(RendererCommand::FAILED)
        ->and($out->getArrayCopy())->toBe([\sprintf('supervisor  pid %d  not seen: another process has that pid here (the pool in another pid namespace, or gone)', getmypid())]);
});

describe('renderer:start', function () use ($root) {
    it('becomes the pool supervisor, with N renderers and a scrubbed environment', function () use ($root) {
        [$command, $out, , $execs] = command($root);
        $socket = poolPath();
        putenv('TINVAR_DB_PASSWORD=hunter2');

        try {
            $status = $command->run('renderer:start', ['--processes=3', "--app={$root}/examples/shop", "--socket={$socket}"]);
        } finally {
            putenv('TINVAR_DB_PASSWORD');
        }

        [$program, $arguments, $env] = $execs[0];
        expect($status)->toBe(0)
            ->and(basename($program))->toBe('node')
            ->and($arguments)->toBe([
                \Tinvar\Server\RendererBinary::script($root),
                $socket,
                realpath("{$root}/examples/shop") . '/dist/rsc/index.js',
                '--processes=3',
                '--codec=msgpack',
            ])
            ->and(array_keys($env))->not->toContain('TINVAR_DB_PASSWORD')
            ->and($env['NODE_ENV'])->toBe('production')
            // What the PHP workers are to be pointed at, on standard output
            // alone, for a script to read.
            ->and($out->getArrayCopy())->toBe(["TINVAR_SOCKET={$socket}"]);
    })->skip(fn () => !is_file("{$root}/examples/shop/dist/rsc/index.js"), 'the shop is not built');

    it('runs half the cores by default, as serve does', function () use ($root) {
        [$command, , , $execs] = command($root);

        $command->run('renderer:start', ["--app={$root}/examples/shop", '--socket=' . poolPath()]);

        expect($execs[0][1][3])->toBe('--processes=' . ServeCommand::defaultRenderers(ServeCommand::cpus(), ServeCommand::cpus()));
    })->skip(fn () => !is_file("{$root}/examples/shop/dist/rsc/index.js"), 'the shop is not built');

    // Killed outright, a pool's supervisor removes nothing; its private
    // directory was left in /tmp for good.
    it('makes a private directory when none is named, and removes one a killed pool left', function () use ($root) {
        $abandoned = PrivateDirectory::create('tinvar-pool');
        // A pid no process has: the directory's owner is gone.
        file_put_contents("{$abandoned}/" . PrivateDirectory::OWNER_FILE, '999999');
        $saved = getenv('TINVAR_SOCKET');
        putenv('TINVAR_SOCKET');
        [$command, $out, , $execs] = command($root);

        try {
            $command->run('renderer:start', ["--app={$root}/examples/shop", '--processes=1']);
        } finally {
            if ($saved !== false) {
                putenv("TINVAR_SOCKET={$saved}");
            }
        }
        $made = $execs[0][1][1];
        $owner = (string) @file_get_contents("{$made}/" . PrivateDirectory::OWNER_FILE);
        PrivateDirectory::remove($made);

        expect(is_dir($abandoned))->toBeFalse()
            ->and($made)->toMatch('#^/tmp/tinvar-pool-[0-9a-f]{10}$#')
            ->and($owner)->toBe((string) getmypid())
            ->and($out->getArrayCopy())->toBe(["TINVAR_SOCKET={$made}"]);
    })->skip(fn () => !is_file("{$root}/examples/shop/dist/rsc/index.js") || !\function_exists('posix_kill'), 'the shop is not built');

    it('refuses, with a usage status and nothing started, what it cannot run', function (array $arguments, string $message) use ($root) {
        [$command, , $err, $execs] = command($root);

        expect($command->run('renderer:start', $arguments))->toBe(RendererCommand::USAGE)
            ->and(implode("\n", $err->getArrayCopy()))->toContain($message)
            ->and($execs)->toHaveCount(0);
    })->with([
        'no processes' => [['--processes=0'], '--processes must be a whole number from 1 to 64'],
        'too many' => [['--processes=65'], '--processes must be a whole number from 1 to 64'],
        'an unknown codec' => [['--codec=xml'], '--codec must be msgpack or json-lines'],
        'an unknown argument' => [['--verbose'], "Unknown argument '--verbose'"],
        'an app not built' => [['--app=/tmp'], 'is not built'],
        'a relative socket' => [['--app=' . \dirname(__DIR__, 4) . '/examples/shop', '--socket=run/pool'], '--socket must be an absolute path'],
        'root\'s group' => [['--socket-group=root'], "--socket-group must name a group other than root's"],
        'a group that is not there' => [['--socket-group=tinvar-no-such-group'], "--socket-group must name a group other than root's"],
        'a group that is not a name' => [['--socket-group=../wheel'], "--socket-group must name a group other than root's"],
    ]);

    // ADR 0028: run as a user of their own, the renderers share their
    // sockets with PHP's user through a group, which the renderer is handed
    // as a number -- it may read no group database inside its sandbox.
    it('hands the pool the group it shares its sockets with, by number', function () use ($root) {
        $group = posix_getgrgid(posix_getegid());
        \assert($group !== false);
        [$command, , , $execs] = command($root);

        $status = $command->run('renderer:start', ["--app={$root}/examples/shop", '--socket=' . poolPath(), '--processes=1', "--socket-group={$group['name']}"]);

        expect($status)->toBe(0)
            ->and($execs[0][1])->toContain('--socket-group=' . posix_getegid());
    })->skip(fn () => !is_file("{$root}/examples/shop/dist/rsc/index.js") || !\function_exists('posix_getgrgid') || posix_getegid() === 0, 'the shop is not built');

    // Started as anyone but the user PHP holds every socket to, the pool
    // would be refused by every render: refused here instead.
    it('refuses to start as anyone but the renderers\' user, where one is named', function () use ($root) {
        [$command, , $err, $execs] = command($root);
        putenv('TINVAR_RENDERER_USER=daemon');

        try {
            $status = $command->run('renderer:start', ["--app={$root}/examples/shop", '--socket=' . poolPath()]);
        } finally {
            putenv('TINVAR_RENDERER_USER');
        }

        expect($status)->toBe(RendererCommand::USAGE)
            ->and(implode("\n", $err->getArrayCopy()))->toContain('TINVAR_RENDERER_USER says the renderers run as daemon')
            ->and($execs)->toHaveCount(0);
    })->skip(fn () => !is_file("{$root}/examples/shop/dist/rsc/index.js") || posix_getpwnam('daemon') === false || posix_geteuid() === posix_getpwnam('daemon')['uid'], 'the shop is not built');

    it('starts as the renderers\' user, where it is the one named, sharing its sockets with a group', function () use ($root) {
        [$command, , $err, $execs] = command($root);
        putenv('TINVAR_RENDERER_USER=' . posix_geteuid());

        try {
            $alone = $command->run('renderer:start', ["--app={$root}/examples/shop", '--socket=' . poolPath(), '--processes=1']);
            $shared = $command->run('renderer:start', ["--app={$root}/examples/shop", '--socket=' . poolPath(), '--processes=1', '--socket-group=' . posix_getegid()]);
        } finally {
            putenv('TINVAR_RENDERER_USER');
        }

        // Without a group, PHP's user could reach none of its sockets.
        expect($alone)->toBe(RendererCommand::USAGE)
            ->and(implode("\n", $err->getArrayCopy()))->toContain('name it with --socket-group')
            ->and($shared)->toBe(0)
            ->and($execs)->toHaveCount(1);
    })->skip(fn () => !is_file("{$root}/examples/shop/dist/rsc/index.js") || posix_geteuid() === 0, 'the shop is not built');
});

// For real: renderer:start becomes the pool, the shop renders through it,
// and a SIGTERM to the pid renderer:start ran as drains it and stops it.
it('starts a pool that renders the shop, and stops it on SIGTERM', function () use ($root) {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        $this->markTestSkipped($unbuilt);
    }
    $socket = poolPath();
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-rc-log-');
    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'renderer:start', '--processes=2', "--app={$root}/examples/shop", "--socket={$socket}"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        $root,
        ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
    );
    $pid = proc_get_status($process)['pid'];

    try {
        $deadline = microtime(true) + 20;
        while (\count(glob("{$socket}/*.sock") ?: []) < 2 && microtime(true) < $deadline && proc_get_status($process)['running']) {
            usleep(50_000);
        }
        $sockets = glob("{$socket}/*.sock") ?: [];
        // The PHP process became the pool's supervisor: same pid, now Node,
        // and the pid the pool's directory records for renderer:stop.
        $became = trim((string) shell_exec("ps -o ucomm= -p {$pid}"));
        $recorded = (int) @file_get_contents("{$socket}/renderer.pid");

        $html = '';
        $kernel = Kernel::fromEnvironment($socket, database: Shop::database());
        $kernel->render(Request::get('/', ['accept' => 'text/html']), static function (string $bytes) use (&$html): void {
            $html .= $bytes;
        });
    } finally {
        posix_kill($pid, SIGTERM);
        $deadline = microtime(true) + 15;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if ($status['running']) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);
        $output = (string) file_get_contents($log);
        unlink($log);
    }
    $left = glob("{$socket}/*") ?: [];
    @rmdir($socket);

    expect($sockets)->toHaveCount(2, $output)
        ->and($became)->toBe('node')
        ->and($recorded)->toBe($pid)
        ->and($html)->toContain('Pellwick Supply')
        ->and(substr_count($html, 'data-availability='))->toBe(24)
        ->and($status['exitcode'])->toBe(0)
        // A graceful stop takes its sockets and its pid file with it.
        ->and($left)->toBe([]);
})->skip(fn () => !\function_exists('pcntl_exec') || !\function_exists('posix_kill'), 'needs pcntl and posix');
