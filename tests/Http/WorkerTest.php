<?php

declare(strict_types=1);

/**
 * The worker loop, without FrankenPHP: the request-handling function, the
 * globals and the output are stand-ins, and the application records what
 * it was asked to do.
 */

namespace Tinvar\Tests\Http\Worker;

use Tinvar\Http\Application;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Worker;

final class RecordingApplication implements Application
{
    /** @var list<string> */
    public array $log = [];

    /** @var (\Closure(Request): Response)|null */
    public ?\Closure $answer = null;

    public ?\Throwable $resetFails = null;

    public function handle(Request $request, string $rawBody = ''): Response
    {
        $this->log[] = "handle {$request->method} {$request->path} {$rawBody}";

        return $this->answer === null ? Response::make(200, "ok {$request->path}") : ($this->answer)($request);
    }

    public function reset(): void
    {
        $this->log[] = 'reset';
        if ($this->resetFails !== null) {
            throw $this->resetFails;
        }
    }
}

/**
 * A stand-in for FrankenPHP: serves the queued requests one per call, then
 * says to stop.
 */
final class FakeServer
{
    /** @var list<array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string}> */
    public array $queue = [];

    /** @var array{server: array<string, mixed>, query: array<mixed>, body: array<mixed>, cookies: array<mixed>, input: string}|null */
    public ?array $current = null;

    /** @var list<array{status: int, body: string}> */
    public array $sent = [];

    public int $calls = 0;

    public function request(string $method, string $uri, string $input = '', array $cookies = []): self
    {
        $this->queue[] = [
            'server' => ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1'],
            'query' => [],
            'body' => [],
            'cookies' => $cookies,
            'input' => $input,
        ];

        return $this;
    }

    public function worker(\Closure $boot, int $max = Worker::DEFAULT_MAX_REQUESTS, ?\Closure $log = null): Worker
    {
        return new Worker(
            boot: $boot,
            maxRequests: $max,
            handleRequest: function (callable $handler): bool {
                $this->calls++;
                $this->current = array_shift($this->queue);
                if ($this->current !== null) {
                    $handler();
                }

                return $this->queue !== [];
            },
            globals: fn (): array => $this->current ?? throw new \LogicException('No request.'),
            send: function (Response $response): void {
                $this->sent[] = ['status' => $response->status, 'body' => $response->body()];
            },
            log: $log ?? static function (\Throwable $e): void {},
        );
    }
}

it('boots the application once for every request it serves', function () {
    $server = new FakeServer()->request('GET', '/a')->request('GET', '/b')->request('GET', '/c');
    $boots = 0;
    $application = new RecordingApplication();

    $served = $server->worker(function () use (&$boots, $application): Application {
        $boots++;

        return $application;
    })->run();

    expect($boots)->toBe(1)
        ->and($served)->toBe(3)
        ->and($server->sent)->toBe([
            ['status' => 200, 'body' => 'ok /a'],
            ['status' => 200, 'body' => 'ok /b'],
            ['status' => 200, 'body' => 'ok /c'],
        ]);
});

// Built from this request's globals, raw body included, read inside the
// handler: FrankenPHP replaces them before each call.
it('builds each request from its own globals', function () {
    $server = new FakeServer()->request('POST', '/_action/x?y=1', '{"a":1}')->request('GET', '/next');
    $application = new RecordingApplication();

    $server->worker(fn (): Application => $application)->run();

    expect(array_values(array_filter($application->log, static fn (string $l): bool => $l !== 'reset')))
        ->toBe(['handle POST /_action/x {"a":1}', 'handle GET /next ']);
});

// "The Kernel's reset contract runs after every request" -- here, whatever
// the request did.
it('runs the reset contract after every request, including one that crashed', function () {
    $server = new FakeServer()->request('GET', '/ok')->request('GET', '/boom')->request('GET', '/after');
    $application = new RecordingApplication();
    $application->answer = static fn (Request $r): Response => $r->path === '/boom'
        ? throw new \RuntimeException('escaped the application')
        : Response::make(200, $r->path);

    $server->worker(fn (): Application => $application)->run();

    expect($application->log)->toBe([
        'handle GET /ok ', 'reset',
        'handle GET /boom ', 'reset',
        'handle GET /after ', 'reset',
    ]);
});

// One crashed request is not a reason to drop every visitor queued behind it.
it('answers a crashed request with a 500 and carries on', function () {
    $server = new FakeServer()->request('GET', '/boom')->request('GET', '/after');
    $application = new RecordingApplication();
    $application->answer = static fn (Request $r): Response => $r->path === '/boom'
        ? throw new \Error('a TypeError, say')
        : Response::make(200, 'fine');
    $logged = [];

    $served = $server->worker(fn (): Application => $application, log: function (\Throwable $e) use (&$logged): void {
        $logged[] = $e->getMessage();
    })->run();

    expect($served)->toBe(2)
        ->and($server->sent)->toBe([
            ['status' => 500, 'body' => 'Something went wrong.'],
            ['status' => 200, 'body' => 'fine'],
        ])
        ->and($logged)->toBe(['a TypeError, say']);
});

// Once a response has started, a second cannot follow it.
it('sends nothing more for a request whose response failed mid-way', function () {
    $server = new FakeServer()->request('GET', '/stream');
    $application = new RecordingApplication();
    $application->answer = static fn (): Response => Response::stream(static function (\Closure $sink): void {
        $sink('half');
        throw new \RuntimeException('the render broke');
    });

    $server->worker(fn (): Application => $application)->run();

    expect($server->sent)->toBe([]);
});

// Review: the worker counted a response as started once it was handed to
// the SAPI, and a body that failed before a byte of it was written -- a
// stream spooled to a file, which runs its producer before any status goes
// out -- was answered with no 500: the server sent an empty 200.
it('answers a 500 for a response that failed before any of it was written to the real output', function () {
    $server = new FakeServer()->request('GET', '/stream');
    $application = new RecordingApplication();
    $application->answer = static fn (): Response => Response::stream(static function (\Closure $sink): void {
        throw new \RuntimeException('the render broke before its first byte');
    }, headers: ['content-type' => 'text/plain', 'x-failed' => 'yes'])->withCookie('__Host-failed', 'yes');
    $worker = new Worker(
        boot: fn (): Application => $application,
        handleRequest: function (callable $handler) use ($server): bool {
            $server->current = array_shift($server->queue);
            $handler();

            return false;
        },
        globals: fn (): array => $server->current ?? throw new \LogicException('No request.'),
        log: static function (\Throwable $e): void {},
    );

    ob_start();
    try {
        $worker->run();
    } finally {
        $written = (string) ob_get_clean();
        $status = http_response_code();
        header_remove();
        http_response_code(200);
    }

    expect($written)->toBe('Something went wrong.')
        ->and($status)->toBe(500);
});

it('answers a path no route could hold with a 404, without the application', function () {
    $server = new FakeServer()->request('GET', '/a/%2e%2e/b');
    $application = new RecordingApplication();

    $server->worker(fn (): Application => $application)->run();

    expect($server->sent)->toBe([['status' => 404, 'body' => 'Not found']])
        ->and($application->log)->toBe(['reset']);
});

// After enough requests the loop ends and FrankenPHP starts a fresh worker,
// so whatever leaks a little per request cannot grow without bound.
// Review: a worker told to stop is handed no request, and was counted as
// having served one.
it('counts no request when the server stops it without one', function () {
    $served = new FakeServer()->worker(fn (): Application => new RecordingApplication())->run();

    expect($served)->toBe(0);
});

it('recycles after its request limit', function () {
    $server = new FakeServer();
    for ($i = 0; $i < 10; $i++) {
        $server->request('GET', "/{$i}");
    }

    $served = $server->worker(fn (): Application => new RecordingApplication(), max: 4)->run();

    expect($served)->toBe(4)
        ->and($server->calls)->toBe(4)
        ->and($server->queue)->toHaveCount(6);
});

it('stops when the server says to', function () {
    $server = new FakeServer()->request('GET', '/only');

    expect($server->worker(fn (): Application => new RecordingApplication())->run())->toBe(1);
});

// A reset that failed left something behind, and the next request would
// inherit it: the worker ends instead, to be replaced by a clean one.
it('ends the worker when the reset contract itself fails', function () {
    $server = new FakeServer()->request('GET', '/a')->request('GET', '/b');
    $application = new RecordingApplication();
    $application->resetFails = new \RuntimeException('could not clear the identity map');

    $served = $server->worker(fn (): Application => $application)->run();

    expect($served)->toBe(1)
        ->and($server->queue)->toHaveCount(1);
});

it('refuses to recycle after fewer than one request', function () {
    expect(fn () => new FakeServer()->worker(fn (): Application => new RecordingApplication(), max: 0))
        ->toThrow(\InvalidArgumentException::class);
});

it('says plainly that it needs FrankenPHP', function () {
    expect(fn () => new Worker(fn (): Application => new RecordingApplication()))
        ->toThrow(\LogicException::class, 'FrankenPHP');
})->skip(\function_exists('frankenphp_handle_request'), 'Running under FrankenPHP.');
