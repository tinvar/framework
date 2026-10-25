<?php

declare(strict_types=1);

/**
 * RenderResult::$waitingOnRendererMs: how long PHP sat blocked on the socket
 * while the renderer worked. It is the number that says whether a slow page
 * is slow in Node or slow in PHP, so it must not borrow from the other side.
 */

namespace Tinvar\Tests\Render\Waiting;

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
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

final readonly class Out extends Data
{
    public function __construct(public string $value) {}
}

#[Query(name: 'waiting.slow'), NoAuthorization(reason: 'timing is what is under test')]
final class Slow
{
    public function __invoke(): Out
    {
        usleep(300_000);

        return new Out('ok');
    }
}

/**
 * A renderer that works for $workMs, asks one query, and finishes as soon
 * as the answer arrives.
 */
function workingRenderer(string $path, int $workMs): int
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
                usleep($workMs * 1000);
                fwrite($client, $codec->encodeFrame(['t' => Frame::QUERY, 'q' => [
                    ['id' => 0, 'name' => 'waiting.slow', 'args' => []],
                ]]));
                fread($client, 65536);
                fwrite($client, $codec->encodeFrame(['t' => Frame::DONE]));
                $until = microtime(true) + 5;
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

beforeEach(function () {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }
});

it('reports the time spent waiting on the renderer, and not the time spent answering it', function () {
    $path = sys_get_temp_dir() . '/tinvar-waiting-' . bin2hex(random_bytes(6)) . '.sock';
    $pid = workingRenderer($path, 200);

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $registry = new QueryRegistry();
    $registry->register(Slow::class);
    $channel = new RenderChannel(
        $path,
        new QueryRunner($registry, new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
    );

    try {
        $result = $channel->render(
            Frame::render('x', [], [], 'rid', 'nonce', ['waiting.slow']),
            ['waiting.slow'],
            static fn (string $bytes) => null,
        );
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }

    // The renderer worked for 200 ms; the query took 300 ms of PHP's time.
    expect($result->queryMs)->toBeGreaterThanOrEqual(280.0)
        ->and($result->waitingOnRendererMs)->toBeGreaterThanOrEqual(180.0)
        ->and($result->waitingOnRendererMs)->toBeLessThan($result->totalMs - $result->queryMs + 1.0);
});
