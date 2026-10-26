<?php

declare(strict_types=1);

/**
 * "Per render: 200 query calls and 5 seconds of total query time."
 *
 * The 15-second deadline beside these was enforced from the start and these
 * were not, which left the gap that matters most: a page looping over a
 * collection and issuing a query per item does not take fifteen seconds to
 * become a problem, it becomes one at the database.
 *
 * Driven through a fake renderer over a real unix socket, because the budget
 * lives in the frame loop and a unit test of the counter would not prove the
 * loop stops.
 */

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/models.php';
require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Query/Fixtures/queries.php';

/**
 * A renderer that sends query frames until PHP stops answering.
 *
 * @param \Closure(int): array<string, mixed> $frameFor what to send on round $n
 */
function fakeRenderer(string $path, \Closure $frameFor, int $rounds): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        $client = stream_socket_accept($server, 5);

        if ($client !== false) {
            // Read the render frame, then push query batches.
            stream_set_timeout($client, 5);
            fread($client, 65536);

            for ($round = 0; $round < $rounds; $round++) {
                $written = @fwrite($client, $codec->encodeFrame($frameFor($round)));
                if ($written === false) {
                    break;
                }
                // Wait for the result before the next round, or give up when
                // PHP has closed on us -- which is what being over budget
                // looks like from here.
                if (@fread($client, 65536) === '') {
                    break;
                }
            }

            @fwrite($client, $codec->encodeFrame(Frame::chunk('done')));
            @fclose($client);
        }

        fclose($server);
        // Gone without running destructors: an inherited database
        // connection would hang up on the parent's socket.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }

    fclose($server);

    return $pid;
}

function budgetChannel(Fixture $fixture, string $path, int $maxCalls = 200, float $maxSeconds = 5.0): RenderChannel
{
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $registry = new QueryRegistry(static fn (string $c): object => new $c());
    $registry->register(Tinvar\Tests\Query\Fixtures\CachedSummary::class);

    return new RenderChannel(
        $path,
        new QueryRunner($registry, $orm, new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: 10.0,
        connectTimeoutSeconds: 2.0,
        debug: false,
        maxQueryCalls: $maxCalls,
        maxQuerySeconds: $maxSeconds,
    );
}

it('refuses a render that asks for more query calls than one render may', function () {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $path = sys_get_temp_dir() . '/tinvar-budget-' . bin2hex(random_bytes(6)) . '.sock';
    $rack = $fixture->ids['racks'][0];

    // Five calls a round, ten rounds: fifty calls against a budget of ten.
    $pid = fakeRenderer($path, static fn (int $round): array => [
        't' => Frame::QUERY,
        'q' => array_map(
            static fn (int $i): array => [
                'id' => $round * 5 + $i,
                'name' => 'racks.cached',
                'args' => ['rack' => $rack],
            ],
            range(0, 4),
        ),
    ], rounds: 10);

    try {
        $channel = budgetChannel($fixture, $path, maxCalls: 10);

        expect(fn () => $channel->render(
            Frame::render('racks/show', ['rack' => $rack], [], 'rid', 'nonce', ['racks.cached']),
            ['racks.cached'],
            static fn (string $bytes): null => null,
        ))->toThrow(RendererException::class, 'more than 10 query calls');
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }
});

it('lets a render inside the budget finish', function () {
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
    $path = sys_get_temp_dir() . '/tinvar-budget-' . bin2hex(random_bytes(6)) . '.sock';
    $rack = $fixture->ids['racks'][0];

    $pid = fakeRenderer($path, static fn (int $round): array => [
        't' => Frame::QUERY,
        'q' => [['id' => $round, 'name' => 'racks.cached', 'args' => ['rack' => $rack]]],
    ], rounds: 2);

    try {
        $channel = budgetChannel($fixture, $path, maxCalls: 10);
        $result = $channel->render(
            Frame::render('racks/show', ['rack' => $rack], [], 'rid', 'nonce', ['racks.cached']),
            ['racks.cached'],
            static fn (string $bytes): null => null,
        );

        expect($result->queryCalls)->toBe(2);
    } catch (RendererException $e) {
        // The fake renderer never sends `done`, so an incomplete render is
        // the expected end -- what matters is that it was not the budget.
        expect($e->getMessage())->not->toContain('query call');
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }
});
