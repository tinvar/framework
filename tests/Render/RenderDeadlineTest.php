<?php

declare(strict_types=1);

/**
 * What the render deadline counts.
 *
 * It bounds how long a render may take on its own side of the socket. A
 * browser that reads slowly is not the render being slow: the time PHP
 * spends handing bytes to it is not counted, and bytes the renderer already
 * sent are read even once the deadline has passed. A separate overall cap,
 * a multiple of the deadline, keeps a client that never reads from holding
 * a worker forever.
 */

namespace Tinvar\Tests\Render\Deadline;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Data\Data;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

final readonly class Out extends Data
{
    public function __construct(public string $value) {}
}

#[Query(name: 'deadline.slow'), NoAuthorization(reason: 'timing is what is under test')]
final class Slow
{
    public function __invoke(): Out
    {
        usleep(600_000);

        return new Out('ok');
    }
}

/**
 * A renderer that reads the render frame, then plays a script.
 *
 *   ['send', frame]  write one frame
 *   ['sleep', ms]    pause
 *   ['read']         read whatever PHP sent
 *
 * It then waits for PHP to hang up.
 *
 * @param list<array{0: string, 1?: mixed}> $script
 */
function renderer(string $path, array $script): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        try {
            $codec = new MessagePackCodec();
            $client = stream_socket_accept($server, 5);
            if ($client !== false) {
                stream_set_timeout($client, 10);
                fread($client, 65536);
                foreach ($script as $step) {
                    match ($step[0]) {
                        'send' => fwrite($client, $codec->encodeFrame($step[1])),
                        'sleep' => usleep($step[1] * 1000),
                        'read' => fread($client, 65536),
                    };
                }
                $until = microtime(true) + 10;
                while (!feof($client) && microtime(true) < $until) {
                    @fread($client, 65536);
                }
                fclose($client);
            }
        } finally {
            fclose($server);
            // Gone without running destructors: an inherited database
            // connection would hang up on the parent's socket.
            posix_kill(getmypid(), SIGKILL);
            exit(0);
        }
    }

    fclose($server);

    return $pid;
}

/**
 * @param list<array{0: string, 1?: mixed}> $script
 * @param \Closure(string): void $sink
 */
function renderAgainst(array $script, float $deadline, \Closure $sink): mixed
{
    $path = sys_get_temp_dir() . '/tinvar-deadline-' . bin2hex(random_bytes(6)) . '.sock';
    $pid = renderer($path, $script);

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->register(Slow::class);
    $channel = new RenderChannel(
        $path,
        new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: $deadline,
    );

    try {
        return $channel->render(Frame::render('x', [], [], 'rid', 'nonce', ['deadline.slow']), ['deadline.slow'], $sink);
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }
}

/** @return list<array{0: string, 1: mixed}> */
function chunks(int $count, int $bytes): array
{
    $script = [];
    for ($i = 0; $i < $count; $i++) {
        $script[] = ['send', Frame::chunk(str_repeat((string) $i, $bytes))];
    }
    $script[] = ['send', ['t' => Frame::DONE]];

    return $script;
}

beforeEach(function () {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }
});

it('does not count the time spent writing to a slow browser', function () {
    $delivered = 0;

    // The renderer is done in milliseconds; the browser takes 0.4 s a flush,
    // so the page takes well past the 1 s deadline to deliver.
    $result = renderAgainst(chunks(6, 40_000), 1.0, function (string $bytes) use (&$delivered): void {
        $delivered += \strlen($bytes);
        usleep(400_000);
    });

    expect($delivered)->toBe(240_000)
        ->and($result->bytes)->toBe(240_000);
});

it('reads what the renderer already sent before giving up at the deadline', function () {
    // The query takes 0.6 s of a 0.5 s deadline. By then the renderer has
    // long since sent the rest of the page; it is waiting in the socket.
    $result = renderAgainst([
        ['send', ['t' => Frame::QUERY, 'q' => [['id' => 0, 'name' => 'deadline.slow', 'args' => []]]]],
        ['sleep', 100],
        ['send', Frame::chunk('<html>the whole page</html>')],
        ['send', ['t' => Frame::DONE]],
        ['read'],
    ], 0.5, static function (string $bytes): void {});

    expect($result->bytes)->toBe(\strlen('<html>the whole page</html>'));
});

it('still gives up on a renderer that sends nothing, at the deadline', function () {
    $started = microtime(true);

    expect(fn () => renderAgainst([], 0.3, static function (string $bytes): void {}))
        ->toThrow(RendererException::class, '0.3s deadline');
    expect(microtime(true) - $started)->toBeLessThan(1.5);
});

it('gives up on a browser that holds the render past the overall cap', function () {
    $delivered = 0;
    $started = microtime(true);

    // Deadline 0.25 s, so the cap is 1 s; the browser needs 0.3 s a flush
    // for eight of them.
    expect(fn () => renderAgainst(chunks(8, 1_000), 0.25, function (string $bytes) use (&$delivered): void {
        $delivered += \strlen($bytes);
        usleep(300_000);
    }))->toThrow(RendererException::class, 'overall');

    expect($delivered)->toBeLessThan(8_000)
        ->and(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(RenderChannel::OVERALL_DEADLINE_FACTOR)->toBe(4);
});
