<?php

declare(strict_types=1);

/**
 * The frame loop's remaining branches, driven over a real unix socket.
 *
 * Each test scripts what a renderer sends and checks how PHP's side of the
 * channel ends: with a result, or with the classified error the caller turns
 * into a response. A fake on the other side of a real socket is the only way
 * to reach these -- they live in the loop and in the socket handling, not in
 * anything a unit could hold directly.
 */

namespace Tinvar\Tests\Render\ChannelSurface;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

function channelSocketPath(): string
{
    return sys_get_temp_dir() . '/tinvar-surface-' . bin2hex(random_bytes(6)) . '.sock';
}

/**
 * A renderer that reads the render frame, then plays a script.
 *
 * Steps:
 *   ['send', frame]  write one frame
 *   ['echo']         read PHP's reply and send it back inside a chunk, as JSON
 *   ['wait']         block until PHP hangs up
 *
 * The socket is closed when the script ends.
 *
 * @param list<array{0: string, 1?: mixed}> $script
 */
function scriptedRenderer(string $path, array $script): int
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

                foreach ($script as $step) {
                    if ($step[0] === 'send') {
                        @fwrite($client, $codec->encodeFrame($step[1]));
                    } elseif ($step[0] === 'echo') {
                        $reply = @fread($client, 65536);
                        $offset = 0;
                        $frame = \is_string($reply) && $reply !== '' ? $codec->decodeFrame($reply, $offset) : null;
                        @fwrite($client, $codec->encodeFrame(Frame::chunk(json_encode($frame ?? []))));
                    } elseif ($step[0] === 'wait') {
                        $until = microtime(true) + 10;
                        while (!feof($client) && microtime(true) < $until) {
                            @fread($client, 65536);
                        }
                    }
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

function surfaceChannel(string $path, float $deadline = 5.0, float $maxQuerySeconds = 5.0): RenderChannel
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    return new RenderChannel(
        $path,
        new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: $deadline,
        maxQuerySeconds: $maxQuerySeconds,
    );
}

/** @return array<string, mixed> */
function surfaceRenderFrame(): array
{
    return Frame::render('racks/show', [], [], 'rid', 'nonce', []);
}

/**
 * Runs one render against a scripted renderer and always reaps the child.
 *
 * @param list<array{0: string, 1?: mixed}> $script
 * @param \Closure(RenderChannel, string): mixed $render
 */
function againstRenderer(array $script, \Closure $render, float $deadline = 5.0, float $maxQuerySeconds = 5.0): mixed
{
    $path = channelSocketPath();
    $pid = scriptedRenderer($path, $script);

    try {
        return $render(surfaceChannel($path, $deadline, $maxQuerySeconds), $path);
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

describe('frames the renderer sends', function () {
    it('collects meta frames into the result', function () {
        $meta = ['t' => Frame::META, 'title' => 'Rack R-14'];

        $result = againstRenderer(
            [['send', $meta], ['send', Frame::chunk('<p>ok</p>')], ['send', ['t' => Frame::DONE]]],
            static fn (RenderChannel $channel): mixed => $channel->render(
                surfaceRenderFrame(),
                [],
                static fn (string $bytes): null => null,
            ),
        );

        expect($result->meta)->toBe([$meta])
            ->and($result->chunks)->toBe(1);
    });

    it('refuses a chunk frame that carries something other than bytes', function () {
        againstRenderer(
            [['send', ['t' => Frame::CHUNK, 'b' => 123]], ['wait']],
            static fn (RenderChannel $channel): mixed => expect(fn () => $channel->render(
                surfaceRenderFrame(),
                [],
                static fn (string $bytes): null => null,
            ))->toThrow(ProtocolException::class, "A 'chunk' frame must carry bytes in 'b'."),
        );
    });

    // The renderer runs third-party code: its message stays out of the
    // exception unless debug is on, but its code and digest come through.
    it('turns an error frame into a classified renderer exception', function () {
        $error = againstRenderer(
            [['send', ['t' => Frame::ERROR, 'code' => 'component_threw', 'digest' => 'd41d8cd9', 'message' => 'at /srv/app.js:12']], ['wait']],
            static function (RenderChannel $channel): RendererException {
                try {
                    $channel->render(surfaceRenderFrame(), [], static fn (string $bytes): null => null);
                } catch (RendererException $e) {
                    return $e;
                }

                throw new \LogicException('the render should have failed');
            },
        );

        expect($error->code_)->toBe('component_threw')
            ->and($error->digest)->toBe('d41d8cd9')
            ->and($error->getMessage())->toBe('The renderer reported an error.');
    });
});

describe('the query time budget', function () {
    it('stops a render whose queries have used up the time one render may', function () {
        againstRenderer(
            [['send', ['t' => Frame::QUERY, 'q' => [['id' => 0, 'name' => 'racks.summary', 'args' => []]]]], ['wait']],
            static fn (RenderChannel $channel): mixed => expect(fn () => $channel->render(
                surfaceRenderFrame(),
                [],
                static fn (string $bytes): null => null,
            ))->toThrow(RendererException::class, 'more than 0.0s of query time'),
            maxQuerySeconds: 0.0,
        );
    });
});

describe('minting', function () {
    // Answered, not left out: the renderer's promise for a boundary with no
    // answer stayed pending, and the render with it, until the deadline.
    it('answers a boundary the minter refuses with no token and signs the rest', function () {
        $signer = new Signer('test-app-key', FragmentToken::PURPOSE);
        $minter = new FragmentMinter($signer, 'racks.show', [], 'admin', 'acme', 'build-1', 'session-abc');
        $seen = '';

        againstRenderer(
            [
                ['send', ['t' => Frame::MINT, 'm' => [
                    ['id' => 0, 'component' => 'UnitHistory', 'props' => []],
                    ['id' => 1, 'component' => '<not a component>', 'props' => []],
                ]]],
                ['echo'],
                ['send', ['t' => Frame::DONE]],
            ],
            static function (RenderChannel $channel) use (&$seen, $minter): mixed {
                return $channel->render(
                    surfaceRenderFrame(),
                    [],
                    static function (string $bytes) use (&$seen): void {
                        $seen .= $bytes;
                    },
                    $minter->closure(),
                );
            },
        );

        $reply = json_decode($seen, true);

        expect($reply['t'])->toBe(Frame::MINTED)
            ->and(array_column($reply['m'], 'id'))->toBe([0, 1])
            ->and(FragmentToken::decode($reply['m'][0]['token'], $signer)->component)->toBe('UnitHistory')
            ->and($reply['m'][1])->toBe(['id' => 1, 'token' => null]);
    });
});

describe('the socket', function () {
    it('fails a render whose deadline has passed before it waits', function () {
        againstRenderer(
            [['wait']],
            static fn (RenderChannel $channel): mixed => expect(fn () => $channel->render(
                surfaceRenderFrame(),
                [],
                static fn (string $bytes): null => null,
            ))->toThrow(RendererException::class, 'Render exceeded the 0.0s deadline.'),
            deadline: 0.0,
        );
    });

    it('fails a render when the renderer goes quiet past the deadline', function () {
        againstRenderer(
            [['wait']],
            static fn (RenderChannel $channel): mixed => expect(fn () => $channel->render(
                surfaceRenderFrame(),
                [],
                static fn (string $bytes): null => null,
            ))->toThrow(RendererException::class, 'Render exceeded the 0.2s deadline.'),
            deadline: 0.2,
        );
    });

});
