<?php

declare(strict_types=1);

/**
 * The spec's renderer lifecycle -- renderer:check, renderer:restart and
 * renderer:stop with and without --graceful -- against real pools started
 * with renderer:start (ADR 0028).
 *
 * Skipped without pcntl and posix, which renderer:start and the tests'
 * forked renders need, or without the shop's build.
 */

namespace Tinvar\Tests\Server\RendererLifecycle;

use Shop\Kernel;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Bench\Shop;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\Request;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Server\RendererCommand;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

$root = \dirname(__DIR__, 4);

beforeAll(function () {
    if (!\function_exists('pcntl_exec') || !\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
        test()->markTestSkipped('needs pcntl and posix');
    }
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * A pool started with renderer:start, as an operator would, once every
 * renderer is listening.
 *
 * @return array{resource, int, string} the process, its pid (the supervisor's), and its output file
 */
function startPool(string $root, string $socket, string $app, int $processes): array
{
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-lifecycle-');
    $process = proc_open(
        [PHP_BINARY, "{$root}/bin/tinvar", 'renderer:start', "--processes={$processes}", "--app={$app}", "--socket={$socket}"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        $root,
        ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
    );
    if (!\is_resource($process)) {
        throw new \RuntimeException('Could not run renderer:start.');
    }

    $deadline = microtime(true) + 20;
    while (\count(socketsIn($socket)) < $processes) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            proc_terminate($process, SIGKILL);
            throw new \RuntimeException("The pool did not start:\n" . file_get_contents($log));
        }
        usleep(50_000);
    }

    return [$process, proc_get_status($process)['pid'], $log];
}

/** @param resource $process */
function reap($process, string $log, string $socket): void
{
    if (proc_get_status($process)['running']) {
        proc_terminate($process, SIGKILL);
    }
    proc_close($process);
    @unlink($log);
    foreach (glob("{$socket}/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($socket);
}

/** @return list<string> */
function socketsIn(string $directory): array
{
    return array_values(array_map(basename(...), glob("{$directory}/*.sock") ?: []));
}

/**
 * The command, its output kept.
 *
 * @return array{RendererCommand, \ArrayObject<int, string>, \ArrayObject<int, string>}
 */
function lifecycle(string $root): array
{
    $out = new \ArrayObject();
    $err = new \ArrayObject();

    return [
        new RendererCommand(
            $root,
            static function (string $line) use ($out): void {
                $out[] = $line;
            },
            static function (string $line) use ($err): void {
                $err[] = $line;
            },
        ),
        $out,
        $err,
    ];
}

/** An app whose render takes $ms to come back: a render that is in flight for as long as a test needs. */
function slowApp(int $ms): string
{
    $app = '/tmp/tvslow-' . bin2hex(random_bytes(4));
    mkdir("{$app}/dist/rsc", 0700, true);
    file_put_contents("{$app}/dist/rsc/index.js", <<<JS
        const once = (text) => new ReadableStream({ start(c) { c.enqueue(new TextEncoder().encode(text)); c.close(); } });
        export async function renderHtml() {
          await new Promise((resolve) => setTimeout(resolve, {$ms}));
          return { htmlStream: once('slow render done'), rscStream: once('0:"row"\\n') };
        }
        export function renderRsc() { return once('0:"row"\\n'); }
        JS);

    return $app;
}

function removeApp(string $app): void
{
    @unlink("{$app}/dist/rsc/index.js");
    @rmdir("{$app}/dist/rsc");
    @rmdir("{$app}/dist");
    @rmdir($app);
}

/**
 * A render through the pool in a forked process. It says how the render
 * went over a socket pair rather than by its exit status, which the test
 * runner's own shutdown handlers in the child are free to change: 'whole'
 * when the render came back complete, 'render_incomplete' (or another
 * code) when it failed.
 *
 * @return array{int, resource} the child's pid, and the end it reports on
 */
function forkedRender(string $socket): array
{
    [$parentEnd, $childEnd] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) ?: throw new \RuntimeException('no socket pair');
    $pid = pcntl_fork();
    if ($pid !== 0) {
        fclose($childEnd);

        return [$pid, $parentEnd];
    }
    fclose($parentEnd);

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $channel = new RenderChannel(
        $socket,
        new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
    );
    $html = '';
    try {
        $channel->render(['t' => 'render'], [], static function (string $bytes) use (&$html): void {
            $html .= $bytes;
        });
        $outcome = str_contains($html, 'slow render done') ? 'whole' : 'partial';
    } catch (RendererException $e) {
        $outcome = $e->code_;
    } catch (\Throwable $e) {
        $outcome = $e::class;
    }
    fwrite($childEnd, $outcome);
    fclose($childEnd);
    exit(0);
}

/**
 * How the forked render went, once it is done.
 *
 * @param array{int, resource} $render
 */
function renderOutcome(array $render): string
{
    [$pid, $end] = $render;
    $outcome = (string) stream_get_contents($end);
    fclose($end);
    pcntl_waitpid($pid, $status);

    return $outcome;
}

it('checks every renderer, restarts them one at a time with no render refused, and drains them', function () use ($root) {
    $socket = '/tmp/tvlife-' . bin2hex(random_bytes(4));
    [$process, $pid, $log] = startPool($root, $socket, "{$root}/examples/shop", 2);

    try {
        // renderer:check: the supervisor, and each renderer answering.
        [$command, $out] = lifecycle($root);
        $checked = $command->run('renderer:check', ["--socket={$socket}"]);
        $checkOutput = $out->getArrayCopy();

        // renderer:restart while the shop renders without pause: every render
        // completes, and every renderer is replaced.
        $before = socketsIn($socket);
        $restart = proc_open(
            [PHP_BINARY, "{$root}/bin/tinvar", 'renderer:restart', "--socket={$socket}"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $kernel = Kernel::fromEnvironment($socket, database: Shop::database());
        $rendered = 0;
        $failures = [];
        do {
            try {
                $html = '';
                $kernel->render(Request::get('/', ['accept' => 'text/html']), static function (string $bytes) use (&$html): void {
                    $html .= $bytes;
                });
                substr_count($html, 'data-availability=') === 24 ? $rendered++ : $failures[] = 'an incomplete catalog';
            } catch (\Throwable $e) {
                $failures[] = $e::class . ': ' . $e->getMessage();
            }
        } while (proc_get_status($restart)['running']);
        $restartOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $restarted = proc_close($restart);
        $after = socketsIn($socket);

        // renderer:stop --graceful: drained, gone, and nothing left behind.
        [$command, $out] = lifecycle($root);
        $stopped = $command->run('renderer:stop', ['--graceful', "--socket={$socket}"]);
        $exit = proc_get_status($process);
    } finally {
        $output = (string) file_get_contents($log);
        reap($process, $log, $socket);
    }

    expect($checked)->toBe(0)
        ->and($checkOutput[0])->toBe("supervisor  pid {$pid}  running")
        ->and(\array_slice($checkOutput, 1))->toHaveCount(2)
        ->each->toMatch('/^renderer-\d+-\d+\.sock  pid \d+  \d+ rendering  \d+ served$/');

    expect($restarted)->toBe(0, $restartOutput . $output)
        ->and($failures)->toBe([])
        ->and($rendered)->toBeGreaterThan(5)
        ->and($after)->toHaveCount(2)
        ->and(array_intersect($before, $after))->toBe([]);

    expect($stopped)->toBe(0)
        ->and($exit['running'])->toBeFalse()
        ->and($output)->toContain('pool drained and stopped');
});

// The spec's renderer:stop --graceful: a render in flight when it is asked
// is finished before the pool goes.
it('waits for a render in flight before a graceful stop is done', function () use ($root) {
    $app = slowApp(1500);
    $socket = '/tmp/tvlife-' . bin2hex(random_bytes(4));
    [$process, , $log] = startPool($root, $socket, $app, 1);

    try {
        $render = forkedRender($socket);
        usleep(400_000);

        [$command] = lifecycle($root);
        $started = microtime(true);
        $stopped = $command->run('renderer:stop', ['--graceful', "--socket={$socket}"]);
        $took = microtime(true) - $started;
        $rendered = renderOutcome($render);
    } finally {
        reap($process, $log, $socket);
        removeApp($app);
    }

    expect($rendered)->toBe('whole', 'the render in flight completed')
        ->and($stopped)->toBe(0)
        ->and($took)->toBeGreaterThan(0.8, 'the stop waited for it');
});

it('stops at once without --graceful, and the render in flight fails as incomplete', function () use ($root) {
    $app = slowApp(3000);
    $socket = '/tmp/tvlife-' . bin2hex(random_bytes(4));
    [$process, , $log] = startPool($root, $socket, $app, 1);

    try {
        $render = forkedRender($socket);
        usleep(400_000);

        [$command] = lifecycle($root);
        $started = microtime(true);
        $stopped = $command->run('renderer:stop', ["--socket={$socket}"]);
        $took = microtime(true) - $started;
        $rendered = renderOutcome($render);
    } finally {
        reap($process, $log, $socket);
        removeApp($app);
    }

    expect($stopped)->toBe(0)
        ->and($took)->toBeLessThan(2.0)
        ->and($rendered)->toBe('render_incomplete', 'the classified error a render gets when its renderer goes');
});

describe('what the lifecycle commands refuse', function () use ($root) {
    it('asks which renderer when there is no --socket and no TINVAR_SOCKET', function () use ($root) {
        $saved = getenv('TINVAR_SOCKET');
        putenv('TINVAR_SOCKET');
        try {
            [$command, , $err] = lifecycle($root);
            $status = $command->run('renderer:stop', []);
        } finally {
            if ($saved !== false) {
                putenv("TINVAR_SOCKET={$saved}");
            }
        }

        expect($status)->toBe(RendererCommand::USAGE)
            ->and(implode("\n", $err->getArrayCopy()))->toContain('Pass --socket=PATH, or set TINVAR_SOCKET');
    });

    it('finds no pool where no supervisor has written its pid', function () use ($root) {
        $dir = '/tmp/tvlife-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            [$command, , $err] = lifecycle($root);
            $status = $command->run('renderer:restart', ["--socket={$dir}"]);
        } finally {
            rmdir($dir);
        }

        expect($status)->toBe(RendererCommand::FAILED)
            ->and(implode("\n", $err->getArrayCopy()))->toContain('there is no renderer.pid there');
    });

    // A pool killed outright leaves its pid file, and the pid may since be
    // anything else this user runs: it is never signalled on the file's word.
    it('signals no process the pid file names unless it is a renderer supervisor', function () use ($root) {
        $dir = '/tmp/tvlife-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents("{$dir}/renderer.pid", (string) getmypid());
        try {
            [$command, , $err] = lifecycle($root);
            $status = $command->run('renderer:stop', ['--graceful', "--socket={$dir}"]);
        } finally {
            unlink("{$dir}/renderer.pid");
            rmdir($dir);
        }

        expect($status)->toBe(RendererCommand::FAILED)
            ->and(implode("\n", $err->getArrayCopy()))->toContain('is not its supervisor');
    });

    it('refuses a timeout that is not a number of seconds', function () use ($root) {
        [$command, , $err] = lifecycle($root);

        expect($command->run('renderer:stop', ['--socket=/tmp/x', '--timeout=soon']))->toBe(RendererCommand::USAGE)
            ->and(implode("\n", $err->getArrayCopy()))->toContain('--timeout must be a whole number of seconds');
    });

    it('takes --graceful as a flag and nothing else', function () use ($root) {
        [$command, , $err] = lifecycle($root);

        expect($command->run('renderer:stop', ['--graceful=yes']))->toBe(RendererCommand::USAGE)
            ->and(implode("\n", $err->getArrayCopy()))->toContain("Unknown argument '--graceful=yes'");
    });

    it('fails a check when nothing answers', function () use ($root) {
        $dir = '/tmp/tvlife-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            [$command, , $err] = lifecycle($root);
            $status = $command->run('renderer:check', ["--socket={$dir}"]);
        } finally {
            rmdir($dir);
        }

        expect($status)->toBe(RendererCommand::FAILED)
            ->and(implode("\n", $err->getArrayCopy()))->toContain('No renderer is listening');
    });
});
