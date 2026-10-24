<?php

declare(strict_types=1);

/**
 * The dev toolbar against the framework's Kernel: refused in production and
 * outside dev; every request recorded, from loopback; documents drawn with
 * the toolbar under the response's own nonce; and an endpoint that answers a
 * loopback peer naming a loopback host, with a token this run signed for the
 * session and the user asking -- and nobody else.
 */

namespace Tinvar\Tests\Dev\Toolbar;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Dev\DevToolbar;
use Tinvar\Dev\Recording;
use Tinvar\Dev\ToolbarToken;
use Tinvar\Http\DevOverlay;
use Tinvar\Http\ExceptionHandler;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\FrameTap;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderResult;
use Tinvar\Render\TokenError;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Support\Signer;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

const NONCE = 'R4nd0m+N/once==';

#[Page('/catalog', view: 'catalog', name: 'catalog')]
final class CatalogPage {}

/** Signs in whoever the `user` cookie names, at the `epoch` cookie's epoch: the application's StartSession. */
final readonly class SignIn implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $user = $request->cookie('user');
        if ($user !== null) {
            $this->auth->login(new User($user), $request->cookie('epoch') ?? '');
        }

        return $next($request);
    }
}

/** A run's directory and key, as `tinvar dev` makes them; removed after the test. */
function run(): string
{
    $directory = PrivateDirectory::create('tinvar-dev-test');
    DevToolbar::prepare($directory);
    register_shutdown_function(static fn () => PrivateDirectory::remove($directory));

    return $directory;
}

/** @param array<string, string> $env */
function toolbar(string $directory, array $env = []): DevToolbar
{
    $toolbar = DevToolbar::fromEnvironment(
        static fn (Request $r): string => $r->cookie('sid') ?? '',
        env: [DevToolbar::ENV => $directory, 'TINVAR_DEV' => '1', ...$env],
    );
    expect($toolbar)->toBeInstanceOf(DevToolbar::class);
    \assert($toolbar instanceof DevToolbar);

    return $toolbar;
}

function kernel(?DevToolbar $toolbar, bool $dev = true): Kernel
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $routes = new RouteRegistry();
    $routes->register(CatalogPage::class);
    $auth = new AuthContext();

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => $class === SignIn::class ? new SignIn($auth) : new $class(),
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [SignIn::class],
        dev: $dev,
        toolbar: $toolbar,
    );
}

/** The renderer, as the channel would show it to the toolbar: a render frame out, a query, its result, a chunk. */
function respond(?DevToolbar $toolbar, ?\Throwable $failure = null): \Closure
{
    return static function (LoadedRoute $loaded) use ($toolbar, $failure): Response {
        $toolbar?->frame(FrameTap::TO_RENDERER, Frame::render(
            routeId: 'catalog',
            params: [],
            loaderData: ['page' => ['products' => [['name' => 'Brass Lantern', 'priceCents' => 4200]]]],
            requestId: 'rid-1',
            cspNonce: NONCE,
            allowedQueries: ['availability'],
            csrfToken: 'csrf-secret-value',
            flash: ['old' => ['email' => 'kept@shop.test', 'password' => 'hunter2']],
        ));
        $toolbar?->frame(FrameTap::FROM_RENDERER, ['t' => 'query', 'q' => [['id' => 1, 'name' => 'availability', 'args' => ['sku' => 'L-1']]]]);
        $toolbar?->frame(FrameTap::TO_RENDERER, Frame::result([['id' => 1, 'value' => ['inStock' => 3]]]));
        $toolbar?->frame(FrameTap::TO_RENDERER, Frame::minted([0 => 'v1.minted-fragment-token.mac']));
        // The output, writing back what it was given: the nonce, the form's token, a boundary's token.
        $toolbar?->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => '<main>Brass Lantern</main>']);
        $toolbar?->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => '<script nonce="' . NONCE . '">x</script><input name="_token" value="csrf-secret-value"><div data-token="v1.minted-fragment-token.mac"></div>']);
        if ($failure !== null) {
            throw $failure;
        }
        $toolbar?->frame(FrameTap::FROM_RENDERER, ['t' => 'done']);
        $toolbar?->rendered(new RenderResult([], 26, 1, 1, 1, 3.0, 9.5, 1.25, 6.0, streamingMs: 0.5));

        return Response::make(200, '<!doctype html><html><head></head><body><main>Brass Lantern</main></body></html>', [
            'content-type' => 'text/html; charset=utf-8',
            'content-security-policy' => "script-src 'nonce-" . NONCE . "' 'strict-dynamic'; object-src 'none'",
        ]);
    };
}

/**
 * A request from this machine, naming it.
 *
 * @param array<string, string> $cookies
 * @param array<string, string> $headers
 */
function local(string $path, array $cookies = ['sid' => 'session-a'], array $headers = [], string $remote = '127.0.0.1'): Request
{
    [$path, $raw] = array_pad(explode('?', $path, 2), 2, null);
    parse_str($raw ?? '', $query);

    /** @var array<string, string> $query */
    return new Request('GET', $path, ['accept' => 'text/html', 'host' => '127.0.0.1:6123', ...$headers], $query, [], $cookies, $raw, $remote);
}

/** The token the toolbar drawn into $response reads with. */
function tokenIn(Response $response): string
{
    preg_match('#<script type="application/json" id="' . DevToolbar::ID . '-config">(.*?)</script>#s', $response->body(), $m);
    expect($m)->not->toBe([]);
    $config = json_decode($m[1], true);
    \assert(\is_array($config) && \is_string($config['token']));

    return $config['token'];
}

/** @return array{int, array<string, mixed>} */
function read(Kernel $kernel, string $path, string $token, array $cookies = ['sid' => 'session-a'], array $headers = []): array
{
    $response = $kernel->handle(local($path, $cookies, [DevToolbar::HEADER => $token, 'accept' => 'application/json', ...$headers]), respond(null));
    $body = json_decode($response->body(), true);

    return [$response->status, \is_array($body) ? $body : []];
}

// ------------------------------------------------------------- refusals

it('refuses the toolbar beside any production marker, before anything else, dev mode or not', function (string $marker) {
    $directory = run();

    expect(fn () => DevToolbar::fromEnvironment(static fn (): string => '', env: [DevToolbar::ENV => $directory, $marker => 'production']))
        ->toThrow(\RuntimeException::class, "TINVAR_DEV_TOOLBAR is refused: {$marker}=production")
        ->and(fn () => DevToolbar::fromEnvironment(static fn (): string => '', env: [DevToolbar::ENV => $directory, 'TINVAR_DEV' => '1', $marker => ' Production ']))
        ->toThrow(\RuntimeException::class, 'is refused');
})->with(['TINVAR_ENV', 'APP_ENV', 'NODE_ENV']);

it('is not there unless `tinvar dev` asked for it, in dev', function () {
    $directory = run();

    expect(DevToolbar::fromEnvironment(static fn (): string => '', env: []))->toBeNull()
        ->and(DevToolbar::fromEnvironment(static fn (): string => '', env: ['TINVAR_DEV' => '1']))->toBeNull()
        // Asked for outside dev: a serve without --dev, which keeps TINVAR_DEV out.
        ->and(DevToolbar::fromEnvironment(static fn (): string => '', env: [DevToolbar::ENV => $directory]))->toBeNull()
        ->and(DevToolbar::fromEnvironment(static fn (): string => '', env: [DevToolbar::ENV => $directory, 'TINVAR_DEV' => '1'], dev: false))->toBeNull();
});

it('refuses a Kernel not built for dev', function () {
    expect(fn () => kernel(toolbar(run()), dev: false))
        ->toThrow(\InvalidArgumentException::class, 'not built for dev');
});

it('refuses a run directory or key that is not this user\'s alone', function () {
    $open = run();
    chmod($open, 0755);
    expect(fn () => toolbar($open))->toThrow(\RuntimeException::class, 'not private to this user');

    $readable = run();
    chmod("{$readable}/" . DevToolbar::KEY_FILE, 0644);
    expect(fn () => toolbar($readable))->toThrow(\RuntimeException::class, 'not private to this user');

    $missing = run();
    unlink("{$missing}/" . DevToolbar::KEY_FILE);
    expect(fn () => toolbar($missing))->toThrow(\RuntimeException::class, 'is not one `tinvar dev` made');

    $short = run();
    file_put_contents("{$short}/" . DevToolbar::KEY_FILE, 'abc');
    expect(fn () => toolbar($short))->toThrow(\RuntimeException::class, 'is not a key `tinvar dev` wrote');

    $real = run();
    $link = sys_get_temp_dir() . '/tinvar-dev-link-' . bin2hex(random_bytes(4));
    symlink($real, $link);
    try {
        expect(fn () => toolbar($link))->toThrow(\RuntimeException::class, 'is not one `tinvar dev` made');
    } finally {
        unlink($link);
    }

    expect(fn () => toolbar('relative/dir'))->toThrow(\RuntimeException::class, 'is not one `tinvar dev` made');
});

// ------------------------------------------------------------- drawing

it('draws itself into a document under the nonce the response\'s own policy names', function () {
    $toolbar = toolbar(run());
    $response = kernel($toolbar)->handle(local('/catalog'), respond($toolbar));
    $body = $response->body();

    expect($response->status)->toBe(200)
        ->and($response->header('content-security-policy'))->toBe("script-src 'nonce-" . NONCE . "' 'strict-dynamic'; object-src 'none'")
        ->and($body)->toContain('<script type="application/json" id="' . DevToolbar::ID . '-config">')
        // At the end of the body, after the page React hydrates.
        ->and(strpos($body, DevToolbar::ID))->toBeGreaterThan(strpos($body, '<main>'))
        ->and(strpos($body, DevToolbar::ID))->toBeLessThan(strpos($body, '</body>'));

    // One script that runs, and it carries the nonce; the data block is never run.
    preg_match_all('/<script\b([^>]*)>/', $body, $tags);
    expect($tags[1])->toBe([' type="application/json" id="' . DevToolbar::ID . '-config"', ' nonce="' . NONCE . '"']);
});

it('escapes its data block so nothing in it can close the element', function () {
    $markup = DevToolbar::markup(NONCE, ['endpoint' => '/_dev/toolbar', 'header' => 'h', 'token' => '</script><script>alert(1)</script>', 'request' => 'x']);

    expect($markup)->not->toContain('</script><script>alert(1)')
        ->and(substr_count($markup, '</script>'))->toBe(2)
        ->and($markup)->toContain('"token":' . json_encode('</script><script>alert(1)</script>', JSON_HEX_TAG));
});

it('draws nothing where it could not run, or would run unvouched for', function (\Closure $change) {
    $toolbar = toolbar(run());
    [$request, $respond] = $change($toolbar);
    $body = kernel($toolbar)->handle($request, $respond)->body();

    expect($body)->not->toContain(DevToolbar::ID);
})->with([
    'a response with no policy' => [static fn (DevToolbar $t): array => [
        local('/catalog'),
        static fn (): Response => Response::make(200, '<html><body>x</body></html>', ['content-type' => 'text/html']),
    ]],
    'a client navigation\'s payload' => [static fn (DevToolbar $t): array => [local('/catalog', headers: ['rsc' => '1']), respond($t)]],
    'a request with no session' => [static fn (DevToolbar $t): array => [local('/catalog', cookies: []), respond($t)]],
    'a peer that is not loopback' => [static fn (DevToolbar $t): array => [local('/catalog', remote: '192.168.1.20'), respond($t)]],
    'a host that is not loopback' => [static fn (DevToolbar $t): array => [local('/catalog', headers: ['host' => 'rebound.example:6123']), respond($t)]],
]);

it('draws nothing outside dev: not the toolbar, and no endpoint', function () {
    $kernel = kernel(null, dev: false);
    $page = $kernel->handle(local('/catalog'), respond(null));
    $endpoint = $kernel->handle(local(DevToolbar::PATH, headers: ['accept' => 'application/json']), respond(null));

    expect($page->body())->not->toContain(DevToolbar::ID)
        ->and($endpoint->status)->toBe(404);
});

// ------------------------------------------------------------- the endpoint

it('hands back the session\'s requests: props, timings, ticks and frames, decoded', function () {
    $toolbar = toolbar(run());
    $kernel = kernel($toolbar);
    $token = tokenIn($kernel->handle(local('/catalog'), respond($toolbar)));

    [$status, $list] = read($kernel, DevToolbar::PATH, $token);
    expect($status)->toBe(200)->and($list['requests'])->toHaveCount(1);
    $summary = $list['requests'][0];
    expect($summary)->toMatchArray(['method' => 'GET', 'path' => '/catalog', 'kind' => 'document', 'status' => 200, 'renders' => 1])
        // The binding it is kept under is the store's, never the client's.
        ->and($summary)->not->toHaveKeys(['session', 'auth']);

    [$status, $one] = read($kernel, DevToolbar::PATH . '/' . $summary['id'], $token);
    expect($status)->toBe(200);
    $request = $one['request'];

    // The props the renderer was given: the render frame, whole.
    $render = $request['frames'][0];
    expect($render['dir'])->toBe('out')
        ->and($render['t'])->toBe('render')
        ->and($render['body']['data'])->toBe(['page' => ['products' => [['name' => 'Brass Lantern', 'priceCents' => 4200]]]])
        ->and($render['body']['route'])->toBe('catalog');

    expect(array_map(static fn (array $f): string => "{$f['dir']}:{$f['t']}", $request['frames']))
        ->toBe(['out:render', 'in:query', 'out:result', 'out:minted', 'in:chunk', 'in:chunk', 'in:done'])
        ->and($request['frames'][1]['body']['q'][0])->toBe(['id' => 1, 'name' => 'availability', 'args' => ['sku' => 'L-1']])
        ->and($request['frames'][4]['bytes'])->toBe(26)
        ->and($request['frames'][4]['body']['b']['preview'])->toBe('<main>Brass Lantern</main>')
        ->and($request['timings'][0])->toMatchArray([
            'totalMs' => 9.5,
            'waitingOnRendererMs' => 6.0,
            'queryMs' => 1.25,
            'streamingMs' => 0.5,
            'queryCalls' => 1,
            'queryBatches' => 1,
        ])
        ->and($request['ms'])->toBeGreaterThan(0.0);
});

it('keeps what would let someone act as the visitor out of what it records', function () {
    $toolbar = toolbar(run());
    $kernel = kernel($toolbar);
    $token = tokenIn($kernel->handle(local('/catalog?token=reset-secret&page=2'), respond($toolbar)));
    [, $list] = read($kernel, DevToolbar::PATH, $token);
    [, $one] = read($kernel, DevToolbar::PATH . '/' . $list['requests'][0]['id'], $token);
    $json = json_encode($one, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('csrf-secret-value')
        ->and($json)->not->toContain('hunter2')
        ->and($json)->not->toContain(NONCE)
        ->and($json)->not->toContain(addcslashes(NONCE, '/'))
        ->and($json)->not->toContain('minted-fragment-token')
        ->and($json)->not->toContain('reset-secret')
        ->and($one['request']['frames'][0]['body']['nonce'])->toBe(Recording::REDACTED)
        ->and($one['request']['frames'][0]['body']['csrf'])->toBe(Recording::REDACTED)
        // What the page shows anyway stays.
        ->and($one['request']['frames'][0]['body']['flash']['old']['email'])->toBe('kept@shop.test')
        ->and($one['request']['query'])->toBe(['token' => Recording::REDACTED, 'page' => '2'])
        // Written back by the renderer, and taken out of that too.
        ->and($one['request']['frames'][5]['body']['b']['preview'])->toBe(
            '<script nonce="' . Recording::REDACTED . '">x</script><input name="_token" value="' . Recording::REDACTED . '">'
            . '<div data-token="' . Recording::REDACTED . '"></div>',
        );
});

it('records a failed render\'s classification and the reference the visitor was shown', function () {
    $toolbar = toolbar(run());
    $kernel = kernel($toolbar);
    $token = tokenIn($kernel->handle(local('/catalog'), respond($toolbar)));
    $failure = RendererException::fromErrorFrame(['code' => 'browser_api_on_server', 'digest' => 'abc123']);
    $failed = $kernel->handle(local('/catalog'), respond($toolbar, $failure));

    [, $list] = read($kernel, DevToolbar::PATH, $token);
    expect($failed->status)->toBe(500)
        ->and($list['requests'][0])->toMatchArray(['status' => 500, 'failure' => 'browser_api_on_server']);
    [, $one] = read($kernel, DevToolbar::PATH . '/' . $list['requests'][0]['id'], $token);
    expect($one['request']['failure'])->toMatchArray(['code' => 'browser_api_on_server', 'title' => 'Browser API used on the server'])
        ->and($one['request']['failure']['digest'])->toBe(ExceptionHandler::digest($failure))
        ->and($failed->body())->toContain(ExceptionHandler::digest($failure))
        // The frames up to the failure are kept: what it had been sent, what it had sent back.
        ->and(array_column($one['request']['frames'], 't'))->toBe(['render', 'query', 'result', 'minted', 'chunk', 'chunk'])
        ->and($one['request']['timings'])->toBe([]);
});

it('is not there for a peer that is not loopback, or a request naming another host', function (string $remote, string $host) {
    $toolbar = toolbar(run());
    $kernel = kernel($toolbar);
    $token = tokenIn($kernel->handle(local('/catalog'), respond($toolbar)));

    $response = $kernel->handle(local(DevToolbar::PATH, headers: [DevToolbar::HEADER => $token, 'host' => $host], remote: $remote), respond(null));

    expect($response->status)->toBe(404)->and($response->body())->not->toContain('catalog');
})->with([
    'another machine' => ['10.0.0.7', '127.0.0.1:6123'],
    // DNS rebinding: a page's own name pointed at 127.0.0.1.
    'a rebound name' => ['127.0.0.1', 'attacker.example:6123'],
    'a rebound name on its own subdomain' => ['127.0.0.1', '127.0.0.1.attacker.example'],
    'no host at all' => ['127.0.0.1', ''],
]);

it('refuses a missing, forged or foreign token', function () {
    $toolbar = toolbar(run());
    $kernel = kernel($toolbar);
    $token = tokenIn($kernel->handle(local('/catalog'), respond($toolbar)));
    $otherRun = tokenIn(kernel(toolbar(run()))->handle(local('/catalog'), respond(null)));

    expect(read($kernel, DevToolbar::PATH, '')[0])->toBe(403)
        ->and(read($kernel, DevToolbar::PATH, $token . 'x')[0])->toBe(403)
        ->and(read($kernel, DevToolbar::PATH, substr_replace($token, 'A', 10, 1))[0])->toBe(403)
        // Signed by another run's key.
        ->and(read($kernel, DevToolbar::PATH, $otherRun)[0])->toBe(403)
        ->and(read($kernel, DevToolbar::PATH, $token)[0])->toBe(200);
});

// The token is bound to the session and the user with their auth epoch: one
// taken from a page reads nothing for any other.
it('reads nothing for another session, another user, or the same user at another epoch', function () {
    $toolbar = toolbar(run());
    $kernel = kernel($toolbar);
    $as = ['sid' => 'session-a', 'user' => 'u-1', 'epoch' => '1'];
    $token = tokenIn($kernel->handle(local('/catalog', $as), respond($toolbar)));
    $kernel->handle(local('/catalog', ['sid' => 'session-b']), respond($toolbar));

    [$status, $list] = read($kernel, DevToolbar::PATH, $token, $as);
    expect($status)->toBe(200)->and($list['requests'])->toHaveCount(1);
    $id = $list['requests'][0]['id'];

    expect(read($kernel, DevToolbar::PATH, $token, [...$as, 'sid' => 'session-b'])[0])->toBe(403)
        ->and(read($kernel, DevToolbar::PATH, $token, [...$as, 'user' => 'u-2'])[0])->toBe(403)
        ->and(read($kernel, DevToolbar::PATH, $token, [...$as, 'epoch' => '2'])[0])->toBe(403)
        ->and(read($kernel, DevToolbar::PATH, $token, ['sid' => 'session-a'])[0])->toBe(403)
        ->and(read($kernel, DevToolbar::PATH . "/{$id}", $token, $as)[0])->toBe(200);

    // Session b's own token lists only session b's request, and cannot open a's.
    $tokenB = tokenIn($kernel->handle(local('/catalog', ['sid' => 'session-b']), respond($toolbar)));
    [, $listB] = read($kernel, DevToolbar::PATH, $tokenB, ['sid' => 'session-b']);
    expect(array_column($listB['requests'], 'id'))->not->toContain($id)
        ->and(read($kernel, DevToolbar::PATH . "/{$id}", $tokenB, ['sid' => 'session-b'])[0])->toBe(404)
        ->and(read($kernel, DevToolbar::PATH . '/not-an-id', $tokenB, ['sid' => 'session-b'])[0])->toBe(404);
});

it('answers as JSON no cache keeps, and records none of its own requests', function () {
    $toolbar = toolbar(run());
    $kernel = kernel($toolbar);
    $token = tokenIn($kernel->handle(local('/catalog'), respond($toolbar)));
    $response = $kernel->handle(local(DevToolbar::PATH, headers: [DevToolbar::HEADER => $token]), respond(null));
    $kernel->handle(local(DevToolbar::PATH, headers: [DevToolbar::HEADER => $token]), respond(null));

    expect($response->header('content-type'))->toBe('application/json; charset=utf-8')
        ->and($response->header('cache-control'))->toBe('no-store')
        ->and($response->header('x-content-type-options'))->toBe('nosniff');
    [, $list] = read($kernel, DevToolbar::PATH, $token);
    expect(array_column($list['requests'], 'path'))->toBe(['/catalog']);
});

it('refuses a token past its expiry, or claiming a longer life than one is issued for', function () {
    $signer = new Signer(str_repeat('k', 64), ToolbarToken::PURPOSE);
    $issued = ToolbarToken::for($signer, 'session-a', null, '', 'dev', now: 1_000_000);

    expect(ToolbarToken::decode($issued->encode($signer), $signer, now: 1_000_100)->admits($issued))->toBeTrue()
        ->and(fn () => ToolbarToken::decode($issued->encode($signer), $signer, now: 1_000_000 + ToolbarToken::TTL))
        ->toThrow(TokenError::class, 'expired');

    $forever = new ToolbarToken($issued->sessionHash, $issued->authHash, 'dev', PHP_INT_MAX, 1_000_000);
    expect(fn () => ToolbarToken::decode($forever->encode($signer), $signer, now: 1_000_100))
        ->toThrow(TokenError::class, 'longer life');
});

it('keeps the newest requests of a run and lets the oldest go', function () {
    $directory = run();
    $toolbar = toolbar($directory);
    $kernel = kernel($toolbar);
    for ($i = 0; $i < 65; $i++) {
        $kernel->handle(local('/catalog'), respond($toolbar));
    }

    expect(glob("{$directory}/r-*.json"))->toHaveCount(60);
    foreach (glob("{$directory}/r-*.json") ?: [] as $file) {
        expect(fileperms($file) & 0777)->toBe(0600);
    }
});

it('notes the nonce the overlay and the toolbar share', function () {
    expect(DevOverlay::nonceOf("script-src 'nonce-" . NONCE . "'"))->toBe(NONCE);
});
