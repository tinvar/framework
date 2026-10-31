<?php

declare(strict_types=1);

/**
 * The retry hook on the renderer client (ADR 0093): a render is repeated
 * only where repeating it changes nothing -- its caller says it may be (a
 * GET's, never an action's), nothing it wrote has reached the browser or
 * the caller can take it back, and the failure is the renderer's and
 * passing.
 */

namespace Tinvar\Tests\Render\Retry;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\Request;
use Tinvar\Http\RequestAttributes;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\Telemetry;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\FragmentMinter;
use Tinvar\Render\FragmentToken;
use Tinvar\Render\Minter;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderRetry;
use Tinvar\Render\StaticMinter;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

/**
 * A renderer that answers each connection by the next of $answers, in
 * order: `crash` hangs up at once, `crash-after-chunk` sends a chunk and
 * hangs up, `error` sends an error frame with the code `render_incomplete`
 * -- a renderer naming a passing failure -- and anything else is sent as one
 * chunk and done.
 *
 * @param list<string> $answers
 */
function scriptedRenderer(string $path, array $answers): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        foreach ($answers as $answer) {
            $client = @stream_socket_accept($server, 10);
            if ($client === false) {
                break;
            }
            fread($client, 65536);
            $out = match ($answer) {
                'crash' => '',
                'crash-after-chunk' => $codec->encodeFrame(Frame::chunk('partial')),
                'report-then-crash' => $codec->encodeFrame(['t' => Frame::REPORT, 'code' => 'query_failed', 'digest' => 'abc123']),
                'error' => $codec->encodeFrame(['t' => Frame::ERROR, 'code' => 'render_incomplete', 'digest' => 'd1']),
                default => $codec->encodeFrame(Frame::chunk($answer)) . $codec->encodeFrame(['t' => Frame::DONE]),
            };
            fwrite($client, $out);
            fclose($client);
        }
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
    fclose($server);

    return $pid;
}

function retryChannel(string $path, ?RenderRetry $retry, ?Telemetry $telemetry = null): RenderChannel
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    return new RenderChannel(
        $path,
        new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: 5.0,
        telemetry: $telemetry,
        retry: $retry,
    );
}

/**
 * Renders against a renderer scripted with $answers, and says what was
 * written, what failed, and how many connections it took.
 *
 * @param list<string> $answers
 * @return array{written: string, failure: ?\Throwable, rewound: int}
 */
function attempt(array $answers, ?RenderRetry $retry, bool $repeatable, bool $rewindable = false, ?Telemetry $telemetry = null): array
{
    $path = '/tmp/tvretry-' . bin2hex(random_bytes(4)) . '.sock';
    $pid = scriptedRenderer($path, $answers);
    $written = '';
    $rewound = 0;
    $failure = null;

    try {
        retryChannel($path, $retry, $telemetry)->render(
            ['t' => 'render'],
            [],
            static function (string $bytes) use (&$written): void {
                $written .= $bytes;
            },
            repeatable: $repeatable,
            rewind: $rewindable ? static function () use (&$written, &$rewound): void {
                $written = '';
                $rewound++;
            } : null,
        );
    } catch (\Throwable $e) {
        $failure = $e;
    } finally {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        @unlink($path);
    }

    return ['written' => $written, 'failure' => $failure, 'rewound' => $rewound];
}

describe('a render the retry hook may repeat', function () {
    it('is tried again when the renderer hung up before sending anything', function () {
        $result = attempt(['crash', 'second'], new RenderRetry(backoffMs: 0), repeatable: true);

        expect($result['failure'])->toBeNull()
            ->and($result['written'])->toBe('second');
    });

    it('is tried no more often than the hook says', function () {
        $result = attempt(['crash', 'crash', 'third'], new RenderRetry(retries: 1, backoffMs: 0), repeatable: true);
        expect($result['failure'])->toBeInstanceOf(RendererException::class)
            ->and($result['failure']->code_)->toBe('render_incomplete');

        $twice = attempt(['crash', 'crash', 'third'], new RenderRetry(retries: 2, backoffMs: 0), repeatable: true);
        expect($twice['failure'])->toBeNull()
            ->and($twice['written'])->toBe('third');
    });

    it('is repeated from a clean start after its first byte only for a caller that can take it back', function () {
        $streamed = attempt(['crash-after-chunk', 'second'], new RenderRetry(backoffMs: 0), repeatable: true);
        expect($streamed['failure'])->toBeInstanceOf(RendererException::class)
            ->and($streamed['written'])->toBe('partial');

        $buffered = attempt(['crash-after-chunk', 'second'], new RenderRetry(backoffMs: 0), repeatable: true, rewindable: true);
        expect($buffered['failure'])->toBeNull()
            ->and($buffered['written'])->toBe('second')
            ->and($buffered['rewound'])->toBe(1);
    });

    it('is counted and logged each time it is tried again', function () {
        $sink = new MemorySink();
        $metrics = new Metrics();
        $telemetry = new Telemetry(new Logger($sink), $metrics);

        attempt(['crash', 'second'], new RenderRetry(backoffMs: 0), repeatable: true, telemetry: $telemetry);

        expect($metrics->exposition())->toContain('tinvar_render_retries_total{kind="document",failure="render_incomplete"} 1')
            ->and(implode("\n", $sink->lines))->toContain('"message":"render retried"');
    });

    // A try that is tried again is not a failed render: the page was drawn.
    // Counted as failed too, every retried render showed on a dashboard as
    // a failure and a success both.
    it('counts a render that succeeded on its retry as one that went well, and none that failed', function () {
        $metrics = new Metrics();
        $telemetry = new Telemetry(new Logger(new MemorySink()), $metrics);

        attempt(['crash', 'second'], new RenderRetry(backoffMs: 0), repeatable: true, telemetry: $telemetry);

        expect($metrics->exposition())->toContain('tinvar_renders_total{kind="document",outcome="ok"} 1')
            ->and($metrics->exposition())->not->toContain('outcome="render_incomplete"');

        $failed = new Metrics();
        attempt(['crash', 'crash'], new RenderRetry(retries: 1, backoffMs: 0), repeatable: true, telemetry: new Telemetry(new Logger(new MemorySink()), $failed));
        expect($failed->exposition())->toContain('tinvar_renders_total{kind="document",outcome="render_incomplete"} 1');
    });

    // The subtrees a failed try reported are not the page's: the retry draws
    // it again, and says its own.
    it('reports no boundary of a try that was tried again', function () {
        $path = '/tmp/tvretry-' . bin2hex(random_bytes(4)) . '.sock';
        $pid = scriptedRenderer($path, ['report-then-crash', 'second']);
        $reported = 0;
        $boundaries = 0;
        $metrics = new Metrics();
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

        try {
            new RenderChannel(
                $path,
                new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
                new MessagePackCodec(),
                deadlineSeconds: 5.0,
                report: static function (\Throwable $e, ?string $digest) use (&$reported): string {
                    $reported++;

                    return (string) $digest;
                },
                telemetry: new Telemetry(new Logger(new MemorySink()), $metrics),
                retry: new RenderRetry(backoffMs: 0),
                boundaryFailed: static function () use (&$boundaries): void {
                    $boundaries++;
                },
            )->render(['t' => 'render'], [], static function (string $bytes): void {}, repeatable: true);
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            @unlink($path);
        }

        expect($reported)->toBe(0)
            ->and($boundaries)->toBe(0)
            ->and($metrics->exposition())->not->toContain('tinvar_render_boundary_failures_total{');
    });
});

describe('a render that is not repeated', function () {
    it('is one its caller did not say may be: an action, or a write', function () {
        $result = attempt(['crash', 'second'], new RenderRetry(backoffMs: 0), repeatable: false);

        expect($result['failure'])->toBeInstanceOf(RendererException::class);
    });

    it('is any, on a channel given no hook', function () {
        $result = attempt(['crash', 'second'], null, repeatable: true);

        expect($result['failure'])->toBeInstanceOf(RendererException::class);
    });

    it('is one whose renderer named a passing failure itself', function () {
        $result = attempt(['error', 'second'], new RenderRetry(backoffMs: 0), repeatable: true);

        expect($result['failure'])->toBeInstanceOf(RendererException::class)
            ->and($result['failure']->reported)->toBeTrue();
    });
});

describe('RenderRetry', function () {
    it('lets a GET or a HEAD be repeated, never a write or the re-render inside an action', function () {
        expect(RenderRetry::permits(new Request('GET', '/')))->toBeTrue()
            ->and(RenderRetry::permits(new Request('HEAD', '/')))->toBeTrue()
            ->and(RenderRetry::permits(new Request('POST', '/_action/x')))->toBeFalse()
            ->and(RenderRetry::permits((new Request('GET', '/orders'))->withAttribute(RequestAttributes::ACTION_RERENDER, true)))->toBeFalse();
    });

    it('takes the application hook in place of the passing failures, never over the ones not retried', function () {
        $always = new RenderRetry(retries: 2, when: static fn (): bool => true);

        expect($always->retries(new RendererException('x', 'render_failed'), 1))->toBeTrue()
            ->and($always->retries(new RendererException('x', 'render_deadline'), 2))->toBeTrue()
            ->and($always->retries(new RendererException('x', 'render_failed'), 3))->toBeFalse()
            ->and($always->retries(RendererException::queryBudget('too much'), 1))->toBeFalse()
            ->and($always->retries(RendererException::untrusted('/s', 'not its user'), 1))->toBeFalse()
            ->and($always->retries(new \RuntimeException('protocol'), 1))->toBeFalse()
            // A renderer naming a protocol violation in its own error frame
            // is held to it, whatever the hook says.
            ->and($always->retries(RendererException::fromErrorFrame(['code' => 'protocol_violation']), 1))->toBeFalse();

        $default = new RenderRetry();
        expect($default->retries(RendererException::closedEarly(), 1))->toBeTrue()
            ->and($default->retries(RendererException::cannotConnect('/s', 'refused'), 1))->toBeTrue()
            ->and($default->retries(RendererException::deadline(5.0), 1))->toBeFalse()
            ->and($default->retries(new RendererException('x', 'render_failed'), 1))->toBeFalse();
    });

    it('waits longer before each try, within its bound', function () {
        $retry = new RenderRetry(retries: 2, backoffMs: 600);

        expect($retry->backoff(1))->toBe(600)
            ->and($retry->backoff(2))->toBe(RenderRetry::MAX_BACKOFF_MS);
    });

    it('refuses more tries or a longer pause than it allows', function () {
        expect(fn () => new RenderRetry(retries: 3))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new RenderRetry(backoffMs: 5000))->toThrow(\InvalidArgumentException::class);
    });
});

/**
 * A renderer whose every connection asks for $mints tokens at once, then
 * hangs up -- all but the last, which writes how many came back with none.
 */
function mintingRenderer(string $path, int $connections, int $mints): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        for ($c = 1; $c <= $connections; $c++) {
            $client = @stream_socket_accept($server, 10);
            if ($client === false) {
                break;
            }
            fread($client, 65536);
            $requests = [];
            for ($i = 0; $i < $mints; $i++) {
                $requests[] = ['id' => $i, 'component' => 'Box', 'props' => ['i' => $i]];
            }
            fwrite($client, $codec->encodeFrame(['t' => Frame::MINT, 'm' => $requests]));
            $buffer = '';
            $offset = 0;
            $reply = null;
            while ($reply === null) {
                $read = fread($client, 65536);
                if ($read === false || $read === '') {
                    break;
                }
                $buffer .= $read;
                $reply = $codec->decodeFrame($buffer, $offset);
            }
            if ($c < $connections) {
                fclose($client);

                continue;
            }
            $refused = \count(array_filter($reply['m'] ?? [], static fn (array $minted): bool => $minted['token'] === null));
            fwrite($client, $codec->encodeFrame(Frame::chunk("refused {$refused}")) . $codec->encodeFrame(['t' => Frame::DONE]));
            fclose($client);
        }
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
    fclose($server);

    return $pid;
}

// The mint cap is one render's: a try that failed after minting must not
// leave the next with what it spent, or the retried page draws its
// boundaries as refused.
describe('a retried render that mints', function () {
    it('has a whole budget again on its next try', function (\Closure $make) {
        $path = '/tmp/tvretry-' . bin2hex(random_bytes(4)) . '.sock';
        $pid = mintingRenderer($path, 2, 40);
        $written = '';

        try {
            retryChannel($path, new RenderRetry(retries: 1, backoffMs: 0))->render(
                ['t' => 'render'],
                [],
                static function (string $bytes) use (&$written): void {
                    $written .= $bytes;
                },
                $make(),
                repeatable: true,
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            @unlink($path);
        }

        expect($written)->toBe('refused 0');
    })->with([
        'a page render\'s minter' => [static fn (): Minter => new FragmentMinter(new Signer(str_repeat('k', 32), FragmentToken::PURPOSE), 'home', [], '', '', 'b1', 'session')],
        'a static build\'s minter, as the closure its build hands the render' => [static fn (): \Closure => new StaticMinter('home', [])->closure()],
    ]);

    it('records only the boundaries of the try that finished, in a static build', function () {
        $minter = new StaticMinter('home', []);
        $minter->mint('Box', ['i' => 1]);
        $minter->reset();
        $minter->mint('Box', ['i' => 2]);

        expect(array_column($minter->boundaries(), 'props'))->toBe([['i' => 2]]);
    });
});
