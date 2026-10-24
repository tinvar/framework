<?php

declare(strict_types=1);

/**
 * The `report` frame: a fragment subtree the renderer rendered as an error
 * row, logged on PHP's side under the digest the browser is shown.
 *
 * Driven over a real socket by a fake renderer that sends the frames, so the
 * branch in RenderChannel is exercised by what the renderer actually puts on
 * the wire rather than by a unit call holding it directly.
 */

namespace Tinvar\Tests\Render\ReportFrame;

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

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

/**
 * A renderer that sends these frames, then a chunk and done.
 *
 * @param list<array<string, mixed>> $frames
 */
function reportingRenderer(string $path, array $frames): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        $client = stream_socket_accept($server, 5);

        if ($client !== false) {
            stream_set_timeout($client, 5);
            fread($client, 65536);
            foreach ($frames as $frame) {
                @fwrite($client, $codec->encodeFrame($frame));
            }
            @fwrite($client, $codec->encodeFrame(Frame::chunk('0:E{"digest":"abc123"}' . "\n")));
            @fwrite($client, $codec->encodeFrame(['t' => Frame::DONE]));
            @fclose($client);
        }

        fclose($server);
        exit(0);
    }

    fclose($server);

    return $pid;
}

/**
 * Renders one fragment against a renderer sending $frames, and returns what
 * the reporter was handed and what reached the sink.
 *
 * @param list<array<string, mixed>> $frames
 * @return array{list<array{\Throwable, ?string}>, string}
 */
function renderWith(array $frames, bool $debug = false): array
{
    if (!\function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is not available.');
    }

    $path = sys_get_temp_dir() . '/tinvar-report-' . bin2hex(random_bytes(6)) . '.sock';
    $reported = [];
    $body = '';

    $channel = new RenderChannel(
        $path,
        new QueryRunner(new QueryRegistry(), new Orm(new Fixture(racks: 1, unitsPerRack: 1)->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: 10.0,
        debug: $debug,
        report: static function (\Throwable $e, ?string $digest) use (&$reported): string {
            $reported[] = [$e, $digest];

            return (string) $digest;
        },
    );

    $pid = reportingRenderer($path, $frames);
    try {
        $channel->render(
            Frame::fragment(['component' => 'ShippingUpdates', 'props' => []], 'rid', 'nonce', []),
            [],
            static function (string $bytes) use (&$body): void {
                $body .= $bytes;
            },
        );
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }

    return [$reported, $body];
}

it('logs a reported subtree under the renderer\'s digest, and still completes the render', function () {
    [$reported, $body] = renderWith([
        ['t' => Frame::REPORT, 'code' => 'query_failed', 'digest' => 'abc123', 'message' => 'Query failed (query_failed)'],
    ]);

    expect($body)->toContain('E{"digest":"abc123"}')
        ->and($reported)->toHaveCount(1)
        ->and($reported[0][0])->toBeInstanceOf(RendererException::class)
        ->and($reported[0][0]->code_)->toBe('query_failed')
        ->and($reported[0][1])->toBe('abc123');
});

// The renderer is the semi-trusted side: its message is kept only under
// debug, as an error frame's is, and a digest that is not a short token is
// not written into a log line or trusted as a reference.
it('keeps the renderer\'s message out of the log unless debugging, and drops a malformed digest', function () {
    [$quiet] = renderWith([['t' => Frame::REPORT, 'digest' => "abc\nforged line", 'message' => 'secret detail']]);
    [$loud] = renderWith([['t' => Frame::REPORT, 'digest' => 'abc123', 'message' => 'secret detail']], debug: true);

    expect($quiet[0][0]->getMessage())->not->toContain('secret detail')
        ->and($quiet[0][1])->toBeNull()
        ->and($quiet[0][0]->code_)->toBe('render_failed')
        ->and($loud[0][0]->getMessage())->toBe('secret detail');
});

it('logs no more than its cap of reports from one render', function () {
    $frames = array_fill(0, RenderChannel::MAX_REPORTS + 5, ['t' => Frame::REPORT, 'digest' => 'abc123']);

    [$reported, $body] = renderWith($frames);

    expect($reported)->toHaveCount(RenderChannel::MAX_REPORTS)
        ->and($body)->not->toBe('');
});
