<?php

declare(strict_types=1);

/**
 * /_vitals and /_errors: a page's reports about itself, as
 * @tinvar/react/telemetry sends them.
 *
 * tinvar-spec.md, "Endpoints": "`/_vitals`, `/_errors` ... | `throttle:telemetry`,
 * CSRF | 16 KB bodies; route names checked against the manifest". Behind the
 * global stack, the session's CSRF token and the telemetry budget; a body
 * over the cap refused unread; a route, metric or kind the server does not
 * know refused rather than made a label; vitals counted as histograms and
 * errors written as redacted log lines under the request's id; and nothing
 * sent back -- no body, no cookie.
 */

namespace Tinvar\Tests\Observability\ClientTelemetry;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\RequestId;
use Tinvar\Observability\Telemetry;
use Tinvar\Observability\TelemetryEndpoint;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

const SESSION = 'sid';

#[Page('/catalog', view: 'catalog', name: 'shop.catalog')]
final class CatalogPage {}

#[Page('/about', view: 'about')]
final class UnnamedPage {}

function tokens(): CsrfTokens
{
    return new CsrfTokens(new Signer(str_repeat('k', 32), CsrfTokens::PURPOSE), static fn (Request $r): string => $r->cookie(SESSION) ?? '');
}

/**
 * A Kernel with client telemetry on, reporting into memory.
 *
 * @param array<string, mixed> $more the Kernel's other arguments
 * @return array{Kernel, MemorySink, Metrics}
 */
function kernel(array $more = []): array
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $routes = new RouteRegistry();
    $routes->registerAll([CatalogPage::class, UnnamedPage::class]);
    $sink = new MemorySink();
    $metrics = new Metrics();
    $auth = new AuthContext();

    $kernel = new Kernel(...[
        'orm' => new Orm($fixture->connection, new IdentityMap('test')),
        'routes' => $routes,
        'queries' => new QueryRegistry(),
        'actions' => new ActionRegistry(),
        'auth' => $auth,
        'policies' => new PolicyRegistry(),
        'resolver' => static fn (string $class): object => new $class(),
        'allowedQueries' => static fn (Request $r): array => [],
        'csrf' => tokens(),
        'throttle' => new Throttle(new ArrayThrottleStore(), $auth, static fn (Request $r): string => $r->remoteAddress ?? ''),
        'telemetry' => new Telemetry(new Logger($sink, level: 'debug'), $metrics),
        'clientTelemetry' => true,
        ...$more,
    ]);

    return [$kernel, $sink, $metrics];
}

/** A report from a page whose session is `s1`, carrying that session's token unless told otherwise. */
function report(string $path, string $body, ?string $token = null, string $method = 'POST', string $type = 'application/json', string $address = '10.0.0.1'): Request
{
    $session = [SESSION => 's1'];
    $token ??= (string) tokens()->for(new Request('GET', '/', cookies: $session));

    return new Request(
        $method,
        $path,
        ['content-type' => $type, 'x-xsrf-token' => $token, 'host' => 'shop.test'],
        cookies: [...$session, VerifyCsrfToken::COOKIE => $token],
        remoteAddress: $address,
    );
}

/** @param array<string, mixed> $body */
function send(Kernel $kernel, string $path, array $body, ?string $token = null): Response
{
    $raw = json_encode($body, JSON_THROW_ON_ERROR);

    return $kernel->handle(report($path, $raw, $token), page(), $raw);
}

/** @return \Closure(LoadedRoute, Request): Response */
function page(): \Closure
{
    return static fn (LoadedRoute $loaded, Request $request): Response => Response::make(200, 'page');
}

/** @return list<array<string, mixed>> */
function clientErrors(MemorySink $sink): array
{
    return array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'client error'));
}

/** @return array<string, mixed> */
function errorCode(Response $response): array
{
    /** @var array{error: array<string, mixed>} $decoded */
    $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

    return $decoded['error'];
}

describe('vitals', function () {
    it('records each vital as a histogram by route and metric, and answers 204 with nothing', function () {
        [$kernel, , $metrics] = kernel();

        $response = send($kernel, '/_vitals', ['route' => 'shop.catalog', 'metrics' => [
            'LCP' => 2400, 'INP' => 180.5, 'CLS' => 0.08, 'FCP' => 900, 'TTFB' => 120,
        ]]);
        $exposition = $metrics->exposition();

        expect($response->status)->toBe(204)
            ->and($response->body())->toBe('')
            ->and($response->cookies())->toBe([])
            ->and($exposition)->toContain('tinvar_web_vitals_seconds_bucket{route="shop.catalog",metric="LCP",le="2.5"} 1')
            ->and($exposition)->toContain('tinvar_web_vitals_seconds_bucket{route="shop.catalog",metric="LCP",le="1.8"} 0')
            ->and($exposition)->toContain('tinvar_web_vitals_seconds_sum{route="shop.catalog",metric="INP"} 0.1805')
            ->and($exposition)->toContain('tinvar_web_vitals_seconds_bucket{route="shop.catalog",metric="TTFB",le="0.2"} 1')
            ->and($exposition)->toContain('tinvar_web_vitals_seconds_count{route="shop.catalog",metric="FCP"} 1')
            ->and($exposition)->toContain('tinvar_web_vitals_score_bucket{route="shop.catalog",metric="CLS",le="0.1"} 1')
            ->and($exposition)->toContain('tinvar_web_vitals_score_bucket{route="shop.catalog",metric="CLS",le="0.05"} 0')
            ->and($exposition)->not->toContain('tinvar_web_vitals_seconds_count{route="shop.catalog",metric="CLS"}');
    });

    // By the endpoint's name, as every other endpoint is, never its path.
    it('is counted as _vitals, and logged under the id the response carries', function () {
        [$kernel, $sink, $metrics] = kernel();

        $response = send($kernel, '/_vitals', ['route' => 'shop.catalog', 'metrics' => ['LCP' => 1000]]);
        $access = array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'request'));

        expect($metrics->exposition())->toContain('tinvar_http_requests_total{method="POST",route="_vitals",status="204"} 1')
            ->and($access[0]['route'] ?? null)->toBe('_vitals')
            ->and(RequestId::isValid((string) $response->header('x-request-id')))->toBeTrue();
    });

    it('refuses a metric it does not take', function (array $metrics) {
        [$kernel, , $registry] = kernel();

        $response = send($kernel, '/_vitals', ['route' => 'shop.catalog', 'metrics' => $metrics]);

        expect($response->status)->toBe(422)
            ->and(errorCode($response)['code'])->toBe('unknown_metric')
            ->and($registry->exposition())->not->toContain('tinvar_web_vitals_seconds_count');
    })->with([
        'a made-up name' => [['LCP' => 1000, 'FID' => 20]],
        'a name in the wrong case' => [['lcp' => 1000]],
        'a label in disguise' => [['LCP"} 1' => 1]],
    ]);

    // A report is recorded whole or not at all: one bad value refuses the
    // vitals beside it too.
    it('refuses a value that is not a finite number within bounds', function (mixed $value, string $metric) {
        [$kernel, , $registry] = kernel();
        $raw = '{"route":"shop.catalog","metrics":{"FCP":800,"' . $metric . '":' . $value . '}}';

        $response = $kernel->handle(report('/_vitals', $raw), page(), $raw);

        expect($response->status)->toBe(422)
            ->and(errorCode($response)['code'])->toBe('bad_value')
            ->and($registry->exposition())->not->toContain('metric="FCP"');
    })->with([
        'negative' => ['-1', 'LCP'],
        'past its ceiling' => ['120001', 'TTFB'],
        'a layout shift past its' => ['11', 'CLS'],
        'infinite' => ['1e400', 'INP'],
        'a string' => ['"1000"', 'LCP'],
        'null' => ['null', 'LCP'],
        'a list' => ['[1]', 'LCP'],
        'true' => ['true', 'CLS'],
    ]);

    it('refuses a report with no metrics, or with fields it does not take', function (string $raw, string $code) {
        [$kernel] = kernel();

        $response = $kernel->handle(report('/_vitals', $raw), page(), $raw);

        expect($response->status)->toBe(422)->and(errorCode($response)['code'])->toBe($code);
    })->with([
        'none' => ['{"route":"shop.catalog","metrics":{}}', 'bad_metrics'],
        'a list' => ['{"route":"shop.catalog","metrics":[1,2]}', 'bad_metrics'],
        'absent' => ['{"route":"shop.catalog"}', 'bad_metrics'],
        'an extra field' => ['{"route":"shop.catalog","metrics":{"LCP":1},"url":"/catalog?q=1"}', 'unexpected_field'],
    ]);
});

describe('route names', function () {
    // "Route names checked against the manifest": a client that could
    // choose a label could make a series per request.
    it('refuses a route the application does not have, and records nothing', function (mixed $route) {
        [$kernel, $sink, $metrics] = kernel();

        $vitals = send($kernel, '/_vitals', ['route' => $route, 'metrics' => ['LCP' => 1000]]);
        $errors = send($kernel, '/_errors', ['route' => $route, 'errors' => [['kind' => 'error', 'message' => 'x']]]);

        expect($vitals->status)->toBe(422)
            ->and(errorCode($vitals)['code'])->toBe('unknown_route')
            ->and($errors->status)->toBe(422)
            ->and(clientErrors($sink))->toBe([])
            ->and($metrics->exposition())->not->toContain('tinvar_web_vitals_seconds_count')
            ->and($metrics->exposition())->not->toContain('tinvar_client_errors_total{');
    })->with([
        'unknown' => ['shop.nowhere'],
        'a path' => ['/catalog'],
        'a view' => ['catalog'],
        'absent' => [null],
        'a number' => [42],
        'too long' => [str_repeat('a.', 100) . 'b'],
    ]);

    // Nothing in the answer is the client's: the code and the reason are
    // fixed, whatever was sent.
    it('never echoes what was sent', function () {
        [$kernel] = kernel();

        $response = send($kernel, '/_vitals', ['route' => 'shop.<script>', 'metrics' => ['LCP' => 1000]]);

        expect($response->status)->toBe(422)->and($response->body())->not->toContain('script');
    });
});

describe('errors', function () {
    function assets(): string
    {
        $directory = sys_get_temp_dir() . '/tinvar-telemetry-assets-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/index-Bx12.js', '');

        return $directory;
    }

    it('logs each error as one redacted line under the request id, and counts it by route and kind', function () {
        [$kernel, $sink, $metrics] = kernel(['clientAssets' => assets()]);

        $response = send($kernel, '/_errors', ['route' => 'shop.catalog', 'errors' => [
            [
                'kind' => 'uncaught',
                'message' => 'Cannot read properties of undefined, for jane@example.com with Bearer abcdefghijklmnop',
                'digest' => '3f9a2b7c1d4e5f60',
                'file' => '/assets/index-Bx12.js',
                'line' => 12,
                'stack' => "TypeError: x\n    at render (https://shop.test/assets/index-Bx12.js?v=1:12:34)\n    at /assets/index-Bx12.js#top:1:2",
            ],
            ['kind' => 'rejection', 'message' => 'Failed to fetch'],
        ]]);
        $lines = clientErrors($sink);

        expect($response->status)->toBe(204)
            ->and($response->body())->toBe('')
            ->and($response->cookies())->toBe([])
            ->and($lines)->toHaveCount(2)
            ->and($lines[0])->toMatchArray([
                'level' => 'warning',
                'request_id' => $response->header('x-request-id'),
                'route' => 'shop.catalog',
                'kind' => 'uncaught',
            ])
            ->and($lines[0]['error'])->toBe([
                'message' => 'Cannot read properties of undefined, for [email] with Bearer [redacted]',
                'digest' => '3f9a2b7c1d4e5f60',
                'file' => '/assets/index-Bx12.js',
                'line' => 12,
                'stack' => [
                    'TypeError: x',
                    'at render (https://shop.test/assets/index-Bx12.js:12:34)',
                    'at /assets/index-Bx12.js:1:2',
                ],
            ])
            ->and($lines[1]['error'])->toBe(['message' => 'Failed to fetch'])
            ->and($metrics->exposition())->toContain('tinvar_client_errors_total{route="shop.catalog",kind="uncaught"} 1')
            ->and($metrics->exposition())->toContain('tinvar_client_errors_total{route="shop.catalog",kind="rejection"} 1')
            ->and($metrics->exposition())->toContain('tinvar_http_requests_total{method="POST",route="_errors",status="204"} 1');
    });

    // The source only when it is one of the build's same-origin assets:
    // anything else is dropped, with its line, and the error still logged.
    it('keeps a source file only when it is one of the build assets', function (string $file, ?string $assets) {
        [$kernel, $sink] = kernel(['clientAssets' => $assets === null ? null : assets()]);

        send($kernel, '/_errors', ['route' => 'shop.catalog', 'errors' => [['kind' => 'error', 'message' => 'x', 'file' => $file, 'line' => 3]]]);

        expect(clientErrors($sink)[0]['error'] ?? null)->toBe(['message' => 'x']);
    })->with([
        'no build to hold it' => ['/assets/index-Bx12.js', null],
        'not on disk' => ['/assets/index-Gone.js', 'built'],
        'out of the directory' => ['/assets/../index-Bx12.js', 'built'],
        'a subdirectory' => ['/assets/x/index-Bx12.js', 'built'],
        'a full URL' => ['https://shop.test/assets/index-Bx12.js', 'built'],
        'another origin' => ['https://cdn.example/assets/index-Bx12.js', 'built'],
        'a source module' => ['/resources/pages/catalog.tsx', 'built'],
        'not a script' => ['/assets/index-Bx12.css', 'built'],
        'with a query' => ['/assets/index-Bx12.js?token=abc', 'built'],
    ]);

    it('cuts a long message and trims the stack to a few short frames', function () {
        [$kernel, $sink] = kernel();
        $stack = implode("\n", array_map(static fn (int $i): string => "at f{$i} (/assets/a.js:{$i}:1)" . str_repeat('x', 300), range(1, 30)));

        $response = send($kernel, '/_errors', ['route' => 'shop.catalog', 'errors' => [['kind' => 'caught', 'message' => str_repeat('é', 600), 'stack' => $stack]]]);
        $error = clientErrors($sink)[0]['error'];

        expect($response->status)->toBe(204)
            ->and(\strlen($error['message']))->toBeLessThanOrEqual(TelemetryEndpoint::MAX_MESSAGE)
            ->and(mb_check_encoding($error['message'], 'UTF-8'))->toBeTrue()
            ->and($error['stack'])->toHaveCount(TelemetryEndpoint::MAX_STACK_LINES)
            ->and(max(array_map(\strlen(...), $error['stack'])))->toBeLessThanOrEqual(TelemetryEndpoint::MAX_STACK_LINE);
    });

    it('refuses a kind it does not take, or fields of the wrong shape, and logs nothing', function (array $error, string $code) {
        [$kernel, $sink, $metrics] = kernel();

        $response = send($kernel, '/_errors', ['route' => 'shop.catalog', 'errors' => [['kind' => 'error', 'message' => 'fine'], $error]]);

        expect($response->status)->toBe(422)
            ->and(errorCode($response)['code'])->toBe($code)
            ->and(clientErrors($sink))->toBe([])
            ->and($metrics->exposition())->not->toContain('tinvar_client_errors_total{');
    })->with([
        'an unknown kind' => [['kind' => 'fatal', 'message' => 'x'], 'unknown_kind'],
        'no kind' => [['message' => 'x'], 'unknown_kind'],
        'no message' => [['kind' => 'error'], 'bad_errors'],
        'a digest of another shape' => [['kind' => 'error', 'message' => 'x', 'digest' => 'not a digest'], 'bad_errors'],
        'a line that is not one' => [['kind' => 'error', 'message' => 'x', 'line' => -1], 'bad_errors'],
        'cookies' => [['kind' => 'error', 'message' => 'x', 'cookies' => 'sid=s1'], 'unexpected_field'],
    ]);

    it('refuses more errors than one report carries', function () {
        [$kernel] = kernel();
        $errors = array_fill(0, TelemetryEndpoint::MAX_ERRORS + 1, ['kind' => 'error', 'message' => 'x']);

        expect(send($kernel, '/_errors', ['route' => 'shop.catalog', 'errors' => $errors])->status)->toBe(422);
    });
});

describe('the stack in front', function () {
    it('refuses a report without the session CSRF token', function (string $token) {
        [$kernel, $sink, $metrics] = kernel();

        $response = send($kernel, '/_vitals', ['route' => 'shop.catalog', 'metrics' => ['LCP' => 1000]], $token);

        expect($response->status)->toBe(419)
            ->and($metrics->exposition())->not->toContain('tinvar_web_vitals_seconds_count')
            ->and(clientErrors($sink))->toBe([]);
    })->with([
        'made up' => ['made-up-token'],
        'another session' => [fn (): string => (string) tokens()->for(new Request('GET', '/', cookies: [SESSION => 's2']))],
    ]);

    it('throttles reports in their own bucket', function () {
        [$kernel] = kernel(['limits' => ['telemetry' => 3]]);

        $statuses = array_map(
            static fn (): int => send($kernel, '/_vitals', ['route' => 'shop.catalog', 'metrics' => ['LCP' => 1000]])->status,
            range(1, 4),
        );

        expect($statuses)->toBe([204, 204, 204, 429])
            ->and(Kernel::DEFAULT_LIMITS)->toHaveKey('telemetry');
    });

    // "16 KB bodies", counted before a byte is parsed -- so a body over the
    // cap is refused for its size even when it is not JSON at all.
    it('refuses a body over 16 KB unread', function () {
        [$kernel] = kernel();
        $raw = '{' . str_repeat(' ', TelemetryEndpoint::MAX_BODY_BYTES);

        $response = $kernel->handle(report('/_vitals', $raw), page(), $raw);

        expect($response->status)->toBe(413);
    });

    it('takes a body at the cap', function () {
        [$kernel] = kernel();
        $raw = '{"route":"shop.catalog","metrics":{"LCP":1000}}';
        $raw .= str_repeat(' ', TelemetryEndpoint::MAX_BODY_BYTES - \strlen($raw));

        expect($kernel->handle(report('/_vitals', $raw), page(), $raw)->status)->toBe(204);
    });

    it('refuses a body nested deeper than a report, and one that is not JSON', function (string $raw, int $status) {
        [$kernel] = kernel();

        expect($kernel->handle(report('/_errors', $raw), page(), $raw)->status)->toBe($status);
    })->with([
        'a stack as a list' => ['{"route":"shop.catalog","errors":[{"kind":"error","message":"x","stack":["a"]}]}', 400],
        'not JSON' => ['route=shop.catalog', 400],
        'a list' => ['[1]', 400],
    ]);

    it('refuses a body that is not labelled as JSON', function () {
        [$kernel] = kernel();
        $raw = '{"route":"shop.catalog","metrics":{"LCP":1000}}';

        expect($kernel->handle(report('/_vitals', $raw, type: 'text/plain'), page(), $raw)->status)->toBe(415);
    });

    it('answers a GET with 405 and the method that would do', function (string $path) {
        [$kernel] = kernel();

        $response = $kernel->handle(report($path, '', method: 'GET'), page());

        expect($response->status)->toBe(405)->and($response->header('allow'))->toBe('POST');
    })->with(['/_vitals', '/_errors']);
});

describe('mounting', function () {
    it('mounts nothing unless the application asks', function () {
        [$kernel] = kernel(['clientTelemetry' => false]);
        $raw = '{"route":"shop.catalog","metrics":{"LCP":1000}}';

        expect($kernel->handle(report('/_vitals', $raw), page(), $raw)->status)->toBe(404);
    });

    it('refuses to mount without metrics to record into', function (?Telemetry $telemetry) {
        expect(fn () => kernel(['telemetry' => $telemetry]))->toThrow(\InvalidArgumentException::class, 'Telemetry with Metrics');
    })->with([
        'no telemetry' => [null],
        'telemetry without metrics' => [fn (): Telemetry => new Telemetry(new Logger(new MemorySink()))],
    ]);
});
