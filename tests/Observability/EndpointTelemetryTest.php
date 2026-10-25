<?php

declare(strict_types=1);

/**
 * Telemetry beside the endpoints main grew after ADR 0041 was written: the
 * dev toolbar, uploads and identity handoff.
 *
 * Each is filed under its endpoint's name, never its path; a `/_file`
 * token, carried in the path as a fragment token is, is never written to
 * the log or a span; and the toolbar draws into the document before the
 * scope resets while telemetry sees the response that leaves, toolbar and
 * all, and stamps it with the request's id.
 */

namespace Tinvar\Tests\Observability\EndpointTelemetry;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Dev\DevToolbar;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Identity\HandoffEndpoint;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\Span;
use Tinvar\Observability\SpanExporter;
use Tinvar\Observability\Telemetry;
use Tinvar\Observability\Tracer;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Identity\Fixtures\App as HandoffApp;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Uploads\ArrayUploadStore;
use Tinvar\Uploads\FileToken;
use Tinvar\Uploads\Uploads;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';
require_once __DIR__ . '/../Identity/Fixtures/app.php';

const KEY = 'endpoint-telemetry-test-key-32-bytes';

#[Page('/catalog', view: 'catalog', name: 'shop.catalog')]
final class CatalogPage {}

/** Signs in whoever the `user` cookie names: the application's session. */
final readonly class SignIn implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->cookie('user');
        if ($user !== null) {
            $this->auth->login(new User($user));
        }

        return $next($request);
    }
}

final class Exported implements SpanExporter
{
    /** @var list<Span> */
    public array $spans = [];

    public function export(array $spans): void
    {
        array_push($this->spans, ...$spans);
    }
}

/** @return array{Telemetry, MemorySink, Exported} */
function telemetry(): array
{
    $sink = new MemorySink();
    $exported = new Exported();

    return [new Telemetry(new Logger($sink, level: 'debug'), new Metrics(), new Tracer($exported, exportEvery: 0.0)), $sink, $exported];
}

/** @return list<array<string, mixed>> */
function accessLines(MemorySink $sink): array
{
    return array_values(array_filter($sink->records(), static fn (array $r): bool => $r['message'] === 'request'));
}

/**
 * @param array<string, mixed> $more the Kernel's other arguments
 */
function kernel(Telemetry $telemetry, array $more = []): Kernel
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $routes = new RouteRegistry();
    $routes->register(CatalogPage::class);
    $auth = $more['auth'] ?? new AuthContext();
    unset($more['auth']);

    return new Kernel(...[
        'orm' => new Orm($fixture->connection, new IdentityMap('test')),
        'routes' => $routes,
        'queries' => new QueryRegistry(),
        'actions' => new ActionRegistry(),
        'auth' => $auth,
        'policies' => new PolicyRegistry(),
        'resolver' => static fn (string $class): object => $class === SignIn::class ? new SignIn($auth) : new $class(),
        'allowedQueries' => static fn (Request $r): array => [],
        'globalMiddleware' => [SignIn::class],
        'telemetry' => $telemetry,
        ...$more,
    ]);
}

function from(string $method, string $path, array $cookies = []): Request
{
    return new Request($method, $path, ['accept' => 'text/html', 'host' => '127.0.0.1:6123'], cookies: $cookies, remoteAddress: '127.0.0.1');
}

/** @return \Closure(LoadedRoute, Request): Response */
function document(): \Closure
{
    return static fn (LoadedRoute $loaded, Request $request): Response => Response::make(
        200,
        '<!doctype html><html><head></head><body><main>Catalog</main></body></html>',
        ['content-type' => 'text/html; charset=utf-8', 'content-security-policy' => "script-src 'nonce-abc' 'strict-dynamic'"],
    );
}

describe('uploads', function () {
    function uploads(): Uploads
    {
        return Uploads::fromKey(KEY, new MemoryStorage(), new ArrayUploadStore(), static fn (Request $r): string => $r->cookie('sid') ?? '');
    }

    // A file's token is a bearer of its own: the path carries it, as a
    // fragment's does, and the access line and the span write it redacted.
    it('writes no /_file token to the log or the trace, and files the request as _file', function () {
        [$telemetry, $sink, $exported] = telemetry();
        $uploads = uploads();
        $kernel = kernel($telemetry, ['uploads' => $uploads]);
        $token = new FileToken('uploads/2026/10/abc', 'image/png', 'receipt.png', str_repeat('s', 43), str_repeat('u', 43), time() + 3600, time())
            ->encode($uploads->fileSigner);
        [, $payload, $mac] = explode('.', $token);

        $kernel->handle(from('GET', "/_file/{$token}", ['sid' => 'session-a']), document());
        $kernel->terminate();

        $line = accessLines($sink)[0];
        $root = $exported->spans[0] ?? null;
        \assert($root instanceof Span);
        expect($line['path'])->toBe('/_file/[token]')
            ->and($line['route'])->toBe('_file')
            ->and($root->name)->toBe('GET _file')
            ->and($root->attributes['url.path'])->toBe('/_file/[token]')
            ->and(json_encode($sink->records()))->not->toContain(substr($payload, 0, 16))->not->toContain(substr($mac, 0, 16))
            ->and($telemetry->metrics?->exposition())->toContain('route="_file"')->not->toContain($payload);
    });

    it('files an upload as _upload', function () {
        [$telemetry, $sink] = telemetry();
        $kernel = kernel($telemetry, ['uploads' => uploads()]);

        $kernel->handle(from('POST', '/_upload'), document());

        expect(accessLines($sink)[0]['route'])->toBe('_upload');
    });
});

describe('identity handoff', function () {
    // The code a handoff carries is in its query, which the access line
    // never writes; the request is filed under the endpoint.
    it('files a handoff step as _handoff, and writes nothing of its query', function () {
        [$telemetry, $sink] = telemetry();
        $app = new HandoffApp();
        $kernel = kernel($telemetry, ['handoff' => $app->endpoint, 'auth' => $app->auth, 'orm' => $app->orm, 'csrf' => $app->csrf]);

        $kernel->handle(from('GET', HandoffEndpoint::CALLBACK . '?code=v1.c2VjcmV0LWhhbmRvZmYtY29kZQ.bWFjLW9mLXRoZS1jb2Rl&state=xyz'), document());

        $line = accessLines($sink)[0];
        expect($line['route'])->toBe('_handoff')
            ->and($line['path'])->toBe(HandoffEndpoint::CALLBACK)
            ->and(json_encode($sink->records()))->not->toContain('c2VjcmV0');
    });
});

describe('the dev toolbar', function () {
    function toolbar(): DevToolbar
    {
        $directory = PrivateDirectory::create('tinvar-dev-tel');
        DevToolbar::prepare($directory);
        register_shutdown_function(static fn () => PrivateDirectory::remove($directory));
        $toolbar = DevToolbar::fromEnvironment(static fn (Request $r): string => $r->cookie('sid') ?? '', env: [DevToolbar::ENV => $directory, 'TINVAR_DEV' => '1']);
        \assert($toolbar instanceof DevToolbar);

        return $toolbar;
    }

    it('is drawn into the document before the reset, and telemetry stamps and counts the response that leaves', function () {
        [$telemetry, $sink] = telemetry();
        $kernel = kernel($telemetry, ['dev' => true, 'toolbar' => toolbar()]);

        $response = $kernel->handle(from('GET', '/catalog', ['sid' => 'session-a', 'user' => 'ada']), document());

        // The toolbar, drawn for the signed-in session: its token is in the
        // document, so it ran while the session was known.
        preg_match('#<script type="application/json" id="' . DevToolbar::ID . '-config">(.*?)</script>#s', $response->body(), $m);
        $config = json_decode($m[1] ?? '{}', true);
        expect($config['token'] ?? null)->toBeString()
            // And the id telemetry gave it, on the very response.
            ->and($response->header('x-request-id'))->toMatch('/^[0-9a-f]{32}$/')
            ->and(accessLines($sink)[0])->toMatchArray([
                'route' => 'shop.catalog',
                'status' => 200,
                'request_id' => $response->header('x-request-id'),
            ]);

        // The toolbar's own endpoint, reading what it recorded, is filed
        // under its name.
        $read = $kernel->handle(
            new Request('GET', DevToolbar::PATH, ['accept' => 'application/json', 'host' => '127.0.0.1:6123', DevToolbar::HEADER => (string) $config['token']], cookies: ['sid' => 'session-a', 'user' => 'ada'], remoteAddress: '127.0.0.1'),
            document(),
        );
        expect($read->status)->toBe(200)
            ->and(accessLines($sink)[1]['route'])->toBe('_dev_toolbar');
    });
});
