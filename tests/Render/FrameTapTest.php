<?php

declare(strict_types=1);

/**
 * The channel's tap, which the dev toolbar records renders through: every
 * frame both ways, decoded, in the order they crossed, then the render's
 * measurements -- over a real socket, against a scripted renderer.
 */

namespace Tinvar\Tests\Render\FrameTap;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FrameTap;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RenderResult;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';

final class Tap implements FrameTap
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $frames = [];

    /** @var list<RenderResult> */
    public array $results = [];

    public function frame(string $direction, array $frame): void
    {
        $this->frames[] = [$direction, $frame];
    }

    public function rendered(RenderResult $result): void
    {
        $this->results[] = $result;
    }
}

/**
 * A renderer that reads the render frame, then sends each frame in turn;
 * `null` reads PHP's reply before going on.
 *
 * @param list<array<string, mixed>|null> $script
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
                stream_set_timeout($client, 5);
                fread($client, 65536);
                foreach ($script as $frame) {
                    $frame === null ? @fread($client, 65536) : @fwrite($client, $codec->encodeFrame($frame));
                }
                @fclose($client);
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

/** @param list<array<string, mixed>|null> $script */
function rendered(array $script, Tap $tap, ?\Closure $sink = null): RenderResult
{
    $path = sys_get_temp_dir() . '/tinvar-tap-' . bin2hex(random_bytes(6)) . '.sock';
    $pid = renderer($path, $script);
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $channel = new RenderChannel(
        $path,
        new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: 5.0,
        tap: $tap,
    );

    try {
        return $channel->render(Frame::render('racks/show', ['rack' => '7'], ['rack' => ['name' => 'R-14']], 'rid', 'nonce', ['stats']), ['stats'], $sink ?? static function (string $bytes): void {});
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }
}

beforeEach(function () {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }
});

it('sees every frame both ways, decoded, in the order they crossed', function () {
    $tap = new Tap();
    $result = rendered([
        ['t' => 'query', 'q' => [['id' => 1, 'name' => 'stats', 'args' => ['rack' => '7']]]],
        null,
        Frame::chunk('<p>R-14</p>'),
        ['t' => 'meta', 'title' => 'R-14'],
        ['t' => 'done'],
    ], $tap);

    expect(array_map(static fn (array $f): string => "{$f[0]}:{$f[1]['t']}", $tap->frames))
        ->toBe(['out:render', 'in:query', 'out:result', 'in:chunk', 'in:meta', 'in:done'])
        // The props the renderer was given, as PHP sent them.
        ->and($tap->frames[0][1]['data'])->toBe(['rack' => ['name' => 'R-14']])
        ->and($tap->frames[0][1]['params'])->toBe(['rack' => '7'])
        ->and($tap->frames[1][1]['q'][0]['name'])->toBe('stats')
        // The answer to the tick, as the renderer received it.
        ->and($tap->frames[2][1]['r'][0]['id'])->toBe(1)
        ->and($tap->frames[3][1]['b'])->toBe('<p>R-14</p>');

    expect($tap->results)->toBe([$result])
        ->and($result->queryBatches)->toBe(1);
});

it('counts the time spent handing output to the sink as streaming', function () {
    $tap = new Tap();
    $result = rendered([Frame::chunk('a'), Frame::chunk('b'), ['t' => 'done']], $tap, static function (): void {
        usleep(20_000);
    });

    expect($result->streamingMs)->toBeGreaterThanOrEqual(35.0)
        ->and($result->totalMs)->toBeGreaterThanOrEqual($result->streamingMs);
});

// Seen before it is acted on: the toolbar shows what broke the render.
it('sees a frame that breaks the protocol, and is told of no finished render', function () {
    $tap = new Tap();

    expect(fn () => rendered([['t' => 'render', 'route' => 'from the wrong side']], $tap))->toThrow(ProtocolException::class);
    expect(array_column(array_column($tap->frames, 1), 't'))->toBe(['render', 'render'])
        ->and($tap->frames[1][0])->toBe(FrameTap::FROM_RENDERER)
        ->and($tap->results)->toBe([]);
});
