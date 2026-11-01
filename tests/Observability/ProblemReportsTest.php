<?php

declare(strict_types=1);

/**
 * /_reports: what a browser reports of its own accord -- a CSP violation, a
 * deprecation -- through the Reporting API or `report-uri` (ADR 0093).
 *
 * A browser sends no CSRF token with a report, so the endpoint is answered
 * ahead of the global stack: no session is opened, no cookie read or set.
 * What holds in its place: the telemetry budget by address; a body over the
 * cap refused unread; labels only from fixed lists; logged fields rebuilt
 * and bounded, so nothing a page's script wrote into a sample can forge a
 * line or a label; and nothing sent back.
 */

namespace Tinvar\Tests\Observability\ProblemReports;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\CsrfTokens;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\ProblemReports;
use Tinvar\Observability\Telemetry;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\Signer;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';

#[Page('/catalog', view: 'catalog', name: 'shop.catalog')]
final class CatalogPage {}

/** The application's session, in its global middleware: what a report must never reach. */
final class StartSession implements Middleware
{
    public static int $runs = 0;

    public function handle(Request $request, \Closure $next): Response
    {
        self::$runs++;

        return $next($request)->withCookie('sid', 'fresh-session');
    }
}

/**
 * A Kernel with problem reports on, reporting into memory.
 *
 * @param array<string, mixed> $more the Kernel's other arguments
 * @return array{Kernel, MemorySink, Metrics}
 */
function kernel(array $more = []): array
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $routes = new RouteRegistry();
    $routes->registerAll([CatalogPage::class]);
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
        'globalMiddleware' => [StartSession::class],
        'csrf' => new CsrfTokens(new Signer(str_repeat('k', 32), CsrfTokens::PURPOSE), static fn (Request $r): string => $r->cookie('sid') ?? ''),
        'throttle' => new Throttle(new ArrayThrottleStore(throttleClock()), $auth, static fn (Request $r): string => $r->remoteAddress ?? ''),
        'telemetry' => new Telemetry(new Logger($sink, level: 'debug'), $metrics),
        'problemReports' => true,
        ...$more,
    ]);

    return [$kernel, $sink, $metrics];
}

function send(Kernel $kernel, string $raw, string $type = 'application/reports+json', string $method = 'POST', string $address = '10.0.0.1'): Response
{
    $request = new Request(
        $method,
        ProblemReports::PATH,
        ['content-type' => $type, 'host' => 'shop.test', 'cookie' => 'sid=someone'],
        cookies: ['sid' => 'someone'],
        remoteAddress: $address,
    );

    return $kernel->handle($request, static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'), $raw);
}

/** @param array<string, mixed> $body */
function csp(array $body = []): string
{
    return json_encode([[
        'type' => 'csp-violation',
        'age' => 12,
        'url' => 'https://shop.test/orders?token=secret',
        'user_agent' => 'Mozilla/5.0 (a visitor)',
        'body' => [
            'documentURL' => 'https://shop.test/orders?token=secret#frag',
            'blockedURL' => 'https://user:pass@evil.test/x.js?k=v',
            'effectiveDirective' => 'script-src-elem',
            'disposition' => 'enforce',
            'sourceFile' => 'https://shop.test/assets/app.js?v=1',
            'lineNumber' => 10,
            'columnNumber' => 4,
            'statusCode' => 200,
            'sample' => 'alert(1)',
            ...$body,
        ],
    ]], JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function reported(MemorySink $sink): array
{
    return array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'problem report'));
}

beforeEach(function () {
    StartSession::$runs = 0;
});

describe('a problem report', function () {
    it('is counted by type, directive and disposition, logged as bounded fields, and answered with nothing', function () {
        [$kernel, $sink, $metrics] = kernel();

        $response = send($kernel, csp());

        expect($response->status)->toBe(204)
            ->and($response->body())->toBe('')
            ->and($response->header('set-cookie'))->toBeNull()
            ->and($metrics->exposition())->toContain('tinvar_problem_reports_total{type="csp-violation",directive="script-src-elem",disposition="enforce"} 1');

        $lines = reported($sink);
        expect($lines)->toHaveCount(1)
            ->and($lines[0])->toMatchArray([
                'level' => 'notice',
                'type' => 'csp-violation',
                'directive' => 'script-src-elem',
                'disposition' => 'enforce',
                // Credentials, queries and fragments cut; the origin and path kept.
                'blocked' => 'https://evil.test/x.js',
                'document' => 'https://shop.test/orders',
                'source_file' => 'https://shop.test/assets/app.js',
                'line' => 10,
                'column' => 4,
                'status' => 200,
                'sample' => 'alert(1)',
            ]);

        $line = implode("\n", $sink->lines);
        expect($line)->not->toContain('secret')
            ->and($line)->not->toContain('pass@')
            ->and($line)->not->toContain('Mozilla')
            ->and($line)->not->toContain('frag');
    });

    it('arrives from report-uri as a csp-report object, with its older names', function () {
        [$kernel, $sink, $metrics] = kernel();

        $response = send($kernel, json_encode(['csp-report' => [
            'document-uri' => 'https://shop.test/',
            'blocked-uri' => 'inline',
            'violated-directive' => "style-src 'self'",
            'disposition' => 'report',
            'script-sample' => 'color: red',
        ]], JSON_THROW_ON_ERROR), 'application/csp-report');

        expect($response->status)->toBe(204)
            ->and($metrics->exposition())->toContain('tinvar_problem_reports_total{type="csp-violation",directive="style-src",disposition="report"} 1')
            ->and(reported($sink)[0])->toMatchArray(['blocked' => 'inline', 'document' => 'https://shop.test/', 'sample' => 'color: red']);
    });

    it('counts deprecations and anything else by type, never by a name it does not know', function () {
        [$kernel, $sink, $metrics] = kernel();

        send($kernel, json_encode([
            ['type' => 'deprecation', 'body' => ['id' => 'UnloadHandler', 'message' => 'Unload handlers are deprecated.', 'sourceFile' => 'chrome-extension://abc/inject.js']],
            ['type' => 'made-up-type-' . str_repeat('x', 50), 'body' => []],
            ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'script-src"} 1\nfake_metric 99', 'disposition' => 'whatever']],
        ], JSON_THROW_ON_ERROR));

        $exposition = $metrics->exposition();
        expect($exposition)->toContain('tinvar_problem_reports_total{type="deprecation",directive="none",disposition="none"} 1')
            ->and($exposition)->toContain('tinvar_problem_reports_total{type="other",directive="none",disposition="none"} 1')
            ->and($exposition)->toContain('tinvar_problem_reports_total{type="csp-violation",directive="other",disposition="other"} 1')
            ->and($exposition)->not->toContain('fake_metric')
            ->and($exposition)->not->toContain('made-up');

        // An extension's script is named by its scheme alone.
        expect(reported($sink)[0])->toMatchArray(['id' => 'UnloadHandler', 'detail' => 'Unload handlers are deprecated.', 'source_file' => 'chrome-extension']);
    });

    // A page's own script chooses what a violation's sample holds, and anyone
    // can post here: nothing in it may break a line, turn text around, or
    // run long.
    it('takes every control and formatting character out of free text, and cuts it short', function () {
        [$kernel, $sink] = kernel();

        send($kernel, csp([
            'sample' => "x\n{\"level\":\"critical\",\"message\":\"forged\"}\r\u{202E}evil\u{200B}" . str_repeat('y', 200),
            'documentURL' => "https://shop.test/a b\n/c",
        ]));

        $line = reported($sink)[0];
        expect($line['sample'])->not->toContain("\n")
            ->and($line['sample'])->not->toContain("\u{202E}")
            ->and($line['sample'])->not->toContain("\u{200B}")
            ->and(\strlen($line['sample']))->toBeLessThanOrEqual(ProblemReports::MAX_SAMPLE)
            ->and($sink->lines)->toHaveCount(\count($sink->records()));

        foreach ($sink->lines as $written) {
            expect($written)->not->toContain("\n");
        }
    });

    it('drops a URL of no scheme, and a number that is not a whole one in range', function () {
        [$kernel, $sink] = kernel();

        send($kernel, csp([
            'blockedURL' => '//no-scheme.test/x',
            'lineNumber' => -1,
            'columnNumber' => 1.5,
            'statusCode' => 70000,
        ]));
        $line = reported($sink)[0];

        expect($line)->not->toHaveKey('blocked')
            ->and($line)->not->toHaveKey('line')
            ->and($line)->not->toHaveKey('column')
            ->and($line)->not->toHaveKey('status');
    });

    it('takes no more than its cap of reports from one batch', function () {
        [$kernel, , $metrics] = kernel();

        $batch = array_fill(0, 50, ['type' => 'intervention', 'body' => ['message' => 'x']]);
        send($kernel, json_encode($batch, JSON_THROW_ON_ERROR));

        expect($metrics->exposition())->toContain('tinvar_problem_reports_total{type="intervention",directive="none",disposition="none"} ' . ProblemReports::MAX_REPORTS);
    });
});

describe('the session a report never reaches', function () {
    it('runs no global middleware, needs no CSRF token, and sets no cookie', function () {
        [$kernel] = kernel();

        $response = send($kernel, csp());

        expect($response->status)->toBe(204)
            ->and(StartSession::$runs)->toBe(0)
            ->and($response->header('set-cookie'))->toBeNull();
    });

    it('is told to the application, which then issues no session cookie of its own', function () {
        [$kernel] = kernel();

        expect($kernel->opensNoSession(new Request('POST', ProblemReports::PATH)))->toBeTrue()
            ->and($kernel->opensNoSession(new Request('GET', '/catalog')))->toBeFalse();
    });
});

describe('a report refused', function () {
    it('is refused with a fixed answer that quotes nothing it was sent', function (string $raw, string $type, string $method, int $status) {
        [$kernel, $sink] = kernel();

        $response = send($kernel, $raw, $type, $method);

        expect($response->status)->toBe($status)
            ->and($response->body())->not->toContain('canary')
            ->and(reported($sink))->toBe([]);
    })->with([
        'over the cap' => [str_repeat('canary', 3000), 'application/reports+json', 'POST', 413],
        'not a report type' => ['[{"type":"canary"}]', 'text/plain', 'POST', 415],
        'not JSON' => ['canary{', 'application/reports+json', 'POST', 400],
        'nested too deep' => ['[{"type":"canary","body":{"a":{"b":{"c":{"d":1}}}}}]', 'application/reports+json', 'POST', 400],
        'not a list' => ['{"canary":1}', 'application/reports+json', 'POST', 422],
        'a report without a type' => ['[{"canary":1}]', 'application/reports+json', 'POST', 422],
        'fetched' => ['', 'application/reports+json', 'GET', 405],
    ]);

    it('is refused past the telemetry budget, by address', function () {
        [$kernel] = kernel(['limits' => ['telemetry' => 3]]);

        $statuses = [];
        for ($i = 0; $i < 4; $i++) {
            $statuses[] = send($kernel, csp())->status;
        }

        expect($statuses)->toBe([204, 204, 204, 429])
            ->and(send($kernel, csp(), address: '10.0.0.2')->status)->toBe(204);
    });

    it('is not answered at all by a Kernel not asked to', function () {
        [$kernel, $sink] = kernel(['problemReports' => false]);

        expect(send($kernel, csp())->status)->toBe(404)
            ->and(reported($sink))->toBe([]);
    });

    it('needs metrics to be counted in', function () {
        expect(fn () => kernel(['telemetry' => new Telemetry(new Logger(new MemorySink()))]))
            ->toThrow(\InvalidArgumentException::class, 'Problem reports are counted as metrics');
    });
});

describe('what a document names', function () {
    it('is the endpoint, for the Reporting API and for report-uri', function () {
        expect(ProblemReports::reportingEndpoints())->toBe('default="/_reports"')
            ->and(ProblemReports::cspDirectives())->toBe('report-uri /_reports; report-to default');
    });
});
