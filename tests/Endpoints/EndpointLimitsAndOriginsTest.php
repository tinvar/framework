<?php

declare(strict_types=1);

/**
 * What ADR 0067 left out of endpoints, built in ADR 0074: a throttle bucket
 * of an endpoint's own beside the one they all spend, CORS opted into per
 * endpoint by an origin allow-list, Precognition for their form requests,
 * and #[WithoutSession] held by the Kernel rather than assumed of the
 * application.
 */

namespace Tinvar\Tests\Endpoints\Gaps;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Cors;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Post;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\Throttle as Bucket;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Validation\FormRequest;
use Tinvar\Validation\Rules\Max;
use Tinvar\Validation\Rules\Min;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

final class Ran
{
    /** @var list<string> */
    public static array $bodies = [];

    /** @var list<array{cookies: array<string, string>, header: string|null}> */
    public static array $seenBySessionless = [];

    /** @var list<array<string, mixed>> PHP's cookie superglobals as a sessionless endpoint's middleware saw them */
    public static array $globalsSeen = [];
}

final class OwnerPolicy
{
    public function view(?Authenticatable $user, Rack $rack): bool
    {
        return $user instanceof User && \in_array($rack->name, $user->ownedRackNames, true);
    }

    public function update(?Authenticatable $user, Rack $rack): bool
    {
        return $this->view($user, $rack);
    }
}

final readonly class Height extends Data
{
    public function __construct(public int $heightU) {}
}

final readonly class ResizeRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(48)]
        public int $heightU,
        #[Min(1)]
        public int $copies = 1,
    ) {}
}

final class Racks
{
    #[Get('/api/racks/{rack}/height', name: 'racks.height'), Authorize('view', 'rack'), Bucket('heights', perMinute: 2)]
    public function height(Rack $rack): Height
    {
        Ran::$bodies[] = 'height';

        return new Height($rack->heightU);
    }

    #[Post('/api/racks/{rack}/resize', name: 'racks.resize'), Authorize('update', 'rack')]
    public function resize(Rack $rack, ResizeRequest $request): Height
    {
        Ran::$bodies[] = 'resize';

        return new Height($request->heightU);
    }

    #[Get('/api/racks/{rack}/plain', name: 'racks.plain'), Authorize('view', 'rack')]
    public function plain(Rack $rack): Height
    {
        return new Height($rack->heightU);
    }
}

final class Partner
{
    #[Get('/api/partner/status', name: 'partner.status'), PublicAccess,
        Cors(origins: ['https://partner.example', 'http://localhost:5173'])]
    public function status(): Height
    {
        return new Height(1);
    }

    #[Post('/api/partner/racks/{rack}/resize', name: 'partner.resize'), Authorize('update', 'rack'),
        Cors(origins: ['https://app.shop.example'], credentials: true)]
    public function resize(Rack $rack, ResizeRequest $request): Height
    {
        Ran::$bodies[] = 'partner resize';

        return new Height($request->heightU);
    }

    #[Get('/api/partner/racks/{rack}', name: 'partner.rack'), Authorize('view', 'rack'),
        Cors(origins: ['https://app.shop.example'], credentials: true)]
    public function rack(Rack $rack): Height
    {
        return new Height($rack->heightU);
    }
}

/** Signs in whoever a cookie names: a session, as the application's global stack opens one. */
final class CookieSession implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->cookie('sid') === 'owner') {
            $this->auth->login(new User('owner', ownedRackNames: ['R-01']));
        }
        if ($request->cookie('sid') === 'other') {
            $this->auth->login(new User('other', ownedRackNames: ['R-01']));
        }

        return $next($request);
    }
}

/**
 * A token API's own authentication -- and one written to sign in from the
 * session cookie too, as an application might by mistake.
 */
final class TokenOrCookie implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        Ran::$seenBySessionless[] = ['cookies' => $request->cookies, 'header' => $request->header('cookie')];
        Ran::$globalsSeen[] = ['cookie' => $_COOKIE, 'server' => $_SERVER['HTTP_COOKIE'] ?? null, 'request' => $_REQUEST];
        // A helper reading PHP's own copy, as a library might.
        if ($request->header('authorization') === 'Bearer scanner-token' || $request->cookie('sid') === 'owner' || ($_COOKIE['sid'] ?? null) === 'owner') {
            $this->auth->login(new User('scanner', ownedRackNames: ['R-01']));
        }

        return $next($request);
    }
}

#[WithoutSession(reason: 'the scanner sends a bearer token')]
final class Scanner
{
    #[Post('/api/scanner/racks/{rack}', name: 'scanner.scan'), UseMiddleware(TokenOrCookie::class), Authorize('update', 'rack'),
        Bucket('scanner', perMinute: 5), Cors(origins: ['https://scanner.example'], headers: ['authorization'])]
    public function scan(Rack $rack): Height
    {
        Ran::$bodies[] = 'scan';

        return new Height($rack->heightU);
    }

    /** A body produced after the stack has returned, as the response is sent. */
    #[Post('/api/scanner/racks/{rack}/stream', name: 'scanner.stream'), UseMiddleware(TokenOrCookie::class), Authorize('update', 'rack')]
    public function stream(Rack $rack): Response
    {
        return Response::stream(static function (\Closure $sink): void {
            $sink((string) json_encode(['cookie' => $_COOKIE, 'server' => $_SERVER['HTTP_COOKIE'] ?? null, 'request' => $_REQUEST]));
        }, 200, ['content-type' => 'application/json']);
    }
}

// --- refused ------------------------------------------------------------------

final class TwoSizes
{
    #[Get('/a'), NoAuthorization(reason: 'test'), Bucket('shared', perMinute: 5)]
    public function a(): void {}

    #[Get('/b'), NoAuthorization(reason: 'test'), Bucket('shared', perMinute: 6)]
    public function b(): void {}
}

#[WithoutSession(reason: 'test')]
final class CredentialedTokenApi
{
    #[Post('/api/token'), PublicAccess(reason: 'test'), NoAuthorization(reason: 'test'), Cors(origins: ['https://a.example'], credentials: true)]
    public function call(): void {}
}

#[WithoutSession(reason: 'test')]
final class OpensTheSession
{
    #[Post('/api/opens'), UseMiddleware(CookieSession::class), PublicAccess(reason: 'test'), NoAuthorization(reason: 'test')]
    public function call(): void {}
}

final class ThrottledAction
{
    #[Action, NoAuthorization(reason: 'test'), Bucket('nope', perMinute: 1)]
    public function go(): void {}
}

#[Bucket('inherited', perMinute: 3)]
abstract class ThrottledBase {}

#[Bucket('traited', perMinute: 4)]
trait ThrottledTrait {}

final class InheritsABucket extends ThrottledBase
{
    #[Get('/inherited'), NoAuthorization(reason: 'test')]
    public function show(): void {}
}

final class TakesABucketFromATrait
{
    use ThrottledTrait;

    #[Get('/traited'), NoAuthorization(reason: 'test')]
    public function show(): void {}
}

#[Bucket('nearer', perMinute: 7)]
final class NamesItsOwnOverItsBase extends ThrottledBase
{
    #[Get('/nearer'), NoAuthorization(reason: 'test')]
    public function show(): void {}
}

final class ThrottledActionByItsBase extends ThrottledBase
{
    #[Action, NoAuthorization(reason: 'test')]
    public function go(): void {}
}

final class ThrottledActionByATrait
{
    use ThrottledTrait;

    #[Action, NoAuthorization(reason: 'test')]
    public function go(): void {}
}

/**
 * @param list<class-string> $endpoints
 * @param array<string, int> $limits
 */
function gapsKernel(array $endpoints, Orm $orm, array $limits = [], bool $throttled = true): Kernel
{
    $auth = new AuthContext();
    $registry = new EndpointRegistry();
    $registry->registerAll($endpoints);

    return new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: (new PolicyRegistry())->register(Rack::class, OwnerPolicy::class),
        resolver: static fn (string $class): object => match ($class) {
            CookieSession::class => new CookieSession($auth),
            TokenOrCookie::class => new TokenOrCookie($auth),
            default => new $class(),
        },
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [CookieSession::class],
        throttle: $throttled ? new Throttle(new ArrayThrottleStore(), $auth, static fn (Request $r): string => $r->remoteAddress ?? '') : null,
        limits: [...Kernel::DEFAULT_LIMITS, ...$limits],
        tenants: null,
        endpoints: $registry,
    );
}

/**
 * @param array<string, string> $headers
 */
function send(
    Kernel $kernel,
    string $method,
    string $path,
    array $headers = [],
    string $body = '',
    ?string $session = 'owner',
    bool $csrf = true,
    string $address = '192.0.2.1',
): Response {
    $cookies = [];
    if ($session !== null) {
        $cookies['sid'] = $session;
        $headers['cookie'] = "sid={$session}";
    }
    if ($csrf) {
        $cookies[VerifyCsrfToken::COOKIE] = 't';
        $headers['x-xsrf-token'] = 't';
    }
    if ($body !== '') {
        $headers['content-type'] = 'application/json';
    }

    return $kernel->handle(
        new Request($method, $path, $headers, cookies: $cookies, remoteAddress: $address),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
        $body,
    );
}

/** @return array{Fixture, Kernel} */
function racks(array $limits = []): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);

    return [$fixture, gapsKernel([Racks::class, Partner::class, Scanner::class], new Orm($fixture->connection, new IdentityMap('test')), $limits)];
}

beforeEach(function () {
    Ran::$bodies = [];
    Ran::$seenBySessionless = [];
});

describe('an endpoint\'s own throttle bucket', function () {
    it('refuses past its own budget with a 429, and leaves other endpoints theirs', function () {
        [$fixture, $kernel] = racks();
        $rack = $fixture->ids['racks'][0];

        expect(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false)->status)->toBe(200)
            ->and(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false)->status)->toBe(200);

        $refused = send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false);
        expect($refused->status)->toBe(429)
            ->and($refused->header('retry-after'))->not->toBeNull()
            ->and(Ran::$bodies)->toBe(['height', 'height'])
            // Spent the bucket, not the global one: the next endpoint answers.
            ->and(send($kernel, 'GET', "/api/racks/{$rack}/plain", csrf: false)->status)->toBe(200);
    });

    it('still spends the global bucket, so a generous bucket buys nothing past it', function () {
        [$fixture, $kernel] = racks(['endpoints' => 1]);
        $rack = $fixture->ids['racks'][0];

        expect(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false)->status)->toBe(200)
            ->and(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false)->status)->toBe(429)
            ->and(Ran::$bodies)->toBe(['height']);
    });

    it('counts a signed-in caller by their account, whatever address they come from', function () {
        [$fixture, $kernel] = racks();
        $rack = $fixture->ids['racks'][0];

        expect(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false, address: '192.0.2.1')->status)->toBe(200)
            ->and(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false, address: '192.0.2.2')->status)->toBe(200)
            // A third address is a fresh address bucket, and the same account.
            ->and(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false, address: '192.0.2.3')->status)->toBe(429)
            // Another account at a fresh address has its own.
            ->and(send($kernel, 'GET', "/api/racks/{$rack}/height", csrf: false, session: 'other', address: '192.0.2.4')->status)->toBe(200);
    });

    it('counts a token API\'s caller by account once its own middleware signed them in, in both buckets', function () {
        [$fixture, $kernel] = racks(['endpoints' => 3]);
        $rack = $fixture->ids['racks'][0];
        $bearer = ['authorization' => 'Bearer scanner-token'];

        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $address) {
            expect(send($kernel, 'POST', "/api/scanner/racks/{$rack}", $bearer, session: null, csrf: false, address: $address)->status)->toBe(200);
        }
        // The global bucket's account pass, which the stack could not run.
        expect(send($kernel, 'POST', "/api/scanner/racks/{$rack}", $bearer, session: null, csrf: false, address: '192.0.2.9')->status)->toBe(429);
    });

    it('is refused when two endpoints give one bucket two sizes', function () {
        expect(fn () => (new EndpointRegistry())->register(TwoSizes::class))
            ->toThrow(EndpointException::class, 'One bucket has one size');
    });

    it('is refused on a Kernel with nothing to count it in', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);

        expect(fn () => gapsKernel([Racks::class], new Orm($fixture->connection, new IdentityMap('test')), throttled: false))
            ->toThrow(\InvalidArgumentException::class, 'no Throttle to count it in');
    });

    it('is refused on an action, which spends throttle:actions', function () {
        expect(fn () => (new ActionRegistry())->register(ThrottledAction::class))
            ->toThrow(ActionException::class, 'only an endpoint reads');
    });

    // PHP inherits no class attribute: a bucket on a base class or a trait
    // limited nothing, and an action holder under one registered unrefused.
    it('reads a bucket off a base class or a trait, the nearest first, and refuses an action under one', function () {
        expect(Bucket::declaredOn(InheritsABucket::class, 'show'))->toEqual(new Bucket('inherited', 3))
            ->and(Bucket::declaredOn(TakesABucketFromATrait::class, 'show'))->toEqual(new Bucket('traited', 4))
            ->and(Bucket::declaredOn(NamesItsOwnOverItsBase::class, 'show'))->toEqual(new Bucket('nearer', 7))
            ->and(fn () => (new ActionRegistry())->register(ThrottledActionByItsBase::class))
            ->toThrow(ActionException::class, 'only an endpoint reads')
            ->and(fn () => (new ActionRegistry())->register(ThrottledActionByATrait::class))
            ->toThrow(ActionException::class, 'only an endpoint reads');
    });

    it('refuses a bucket that is not one, or allows nothing', function () {
        expect(fn () => new Bucket('no spaces', 1))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new Bucket('ok', 0))->toThrow(\InvalidArgumentException::class);
    });
});

describe('CORS on an endpoint', function () {
    it('answers a preflight from an origin it names, for a method and headers it allows', function () {
        [$fixture, $kernel] = racks();
        $response = send($kernel, 'OPTIONS', "/api/scanner/racks/{$fixture->ids['racks'][0]}", [
            'origin' => 'https://scanner.example',
            'access-control-request-method' => 'POST',
            'access-control-request-headers' => 'Authorization, Content-Type',
        ], session: null, csrf: false);

        expect($response->status)->toBe(204)
            ->and($response->header('access-control-allow-origin'))->toBe('https://scanner.example')
            ->and($response->header('access-control-allow-methods'))->toBe('POST')
            ->and($response->header('access-control-allow-headers'))->toContain('authorization')
            ->and($response->header('access-control-allow-credentials'))->toBeNull()
            ->and($response->header('vary'))->toContain('Origin')
            ->and(Ran::$bodies)->toBe([])
            ->and(Ran::$seenBySessionless)->toBe([]);
    });

    it('is the 405 any OPTIONS is for anything else', function (string $path, array $headers) {
        [$fixture, $kernel] = racks();
        $path = str_replace('{rack}', $fixture->ids['racks'][0], $path);
        $response = send($kernel, 'OPTIONS', $path, $headers, session: null, csrf: false);

        expect($response->status)->toBe(405)
            ->and($response->header('access-control-allow-origin'))->toBeNull();
    })->with([
        'an origin it does not name' => ['/api/scanner/racks/{rack}', ['origin' => 'https://evil.example', 'access-control-request-method' => 'POST']],
        'a look-alike origin' => ['/api/scanner/racks/{rack}', ['origin' => 'https://scanner.example.evil.example', 'access-control-request-method' => 'POST']],
        'the origin with a trailing slash' => ['/api/scanner/racks/{rack}', ['origin' => 'https://scanner.example/', 'access-control-request-method' => 'POST']],
        'the null origin' => ['/api/scanner/racks/{rack}', ['origin' => 'null', 'access-control-request-method' => 'POST']],
        'a method it does not answer' => ['/api/scanner/racks/{rack}', ['origin' => 'https://scanner.example', 'access-control-request-method' => 'DELETE']],
        'a header it does not allow' => ['/api/scanner/racks/{rack}', ['origin' => 'https://scanner.example', 'access-control-request-method' => 'POST', 'access-control-request-headers' => 'x-xsrf-token']],
        'no method asked about' => ['/api/scanner/racks/{rack}', ['origin' => 'https://scanner.example']],
        'no origin' => ['/api/scanner/racks/{rack}', ['access-control-request-method' => 'POST']],
        'an endpoint that opted into nothing' => ['/api/racks/{rack}/plain', ['origin' => 'https://scanner.example', 'access-control-request-method' => 'GET']],
    ]);

    it('answers a preflight for HEAD where the endpoint answers GET', function () {
        [, $kernel] = racks();
        $response = send($kernel, 'OPTIONS', '/api/partner/status', [
            'origin' => 'https://partner.example',
            'access-control-request-method' => 'HEAD',
        ], session: null, csrf: false);

        expect($response->status)->toBe(204)
            ->and($response->header('access-control-allow-methods'))->toBe('GET, HEAD');
    });

    it('echoes an origin it names on the answer, and only that one, varying on Origin', function () {
        [, $kernel] = racks();

        $named = send($kernel, 'GET', '/api/partner/status', ['origin' => 'http://localhost:5173'], session: null, csrf: false);
        expect($named->status)->toBe(200)
            ->and($named->header('access-control-allow-origin'))->toBe('http://localhost:5173')
            ->and($named->header('access-control-allow-credentials'))->toBeNull()
            ->and($named->header('vary'))->toContain('Origin');

        $other = send($kernel, 'GET', '/api/partner/status', ['origin' => 'https://evil.example'], session: null, csrf: false);
        expect($other->header('access-control-allow-origin'))->toBeNull()
            ->and($other->header('vary'))->toContain('Origin');
    });

    it('carries what it tells an origin on a refusal too, so the caller can read why', function () {
        [$fixture, $kernel] = racks();
        $response = send($kernel, 'GET', "/api/partner/racks/{$fixture->ids['racks'][0]}", ['origin' => 'https://app.shop.example'], session: null, csrf: false);

        expect($response->status)->toBe(401)
            ->and($response->header('access-control-allow-origin'))->toBe('https://app.shop.example')
            ->and($response->header('access-control-allow-credentials'))->toBe('true');
    });

    it('lets a named origin read with the visitor\'s cookies, and never write without the CSRF token', function () {
        [$fixture, $kernel] = racks();
        $rack = $fixture->ids['racks'][0];
        $origin = ['origin' => 'https://app.shop.example'];

        $read = send($kernel, 'GET', "/api/partner/racks/{$rack}", $origin, csrf: false);
        expect($read->status)->toBe(200)
            ->and($read->header('access-control-allow-credentials'))->toBe('true');

        // The cookie arrives, the token cannot: another origin cannot read it.
        $write = send($kernel, 'POST', "/api/partner/racks/{$rack}/resize", $origin, body: '{"heightU":12}', csrf: false);
        expect($write->status)->toBe(419)
            ->and(Ran::$bodies)->toBe([]);
    });

    it('refuses an origin it could never match, or one anyone could answer for', function (string $origin) {
        expect(fn () => new Cors([$origin]))->toThrow(\InvalidArgumentException::class);
    })->with([
        'a wildcard' => '*',
        'the null origin' => 'null',
        'a path' => 'https://partner.example/app',
        'a trailing slash' => 'https://partner.example/',
        'upper case' => 'https://Partner.example',
        'plain http off the loopback' => 'http://partner.example',
        'the scheme\'s own port' => 'https://partner.example:443',
        'no scheme' => 'partner.example',
    ]);

    it('refuses credentials on a token API, which reads no cookie', function () {
        expect(fn () => (new EndpointRegistry())->register(CredentialedTokenApi::class))
            ->toThrow(EndpointException::class, 'reads no cookie');
    });
});

describe('Precognition on an endpoint', function () {
    it('validates without running the body', function () {
        [$fixture, $kernel] = racks();
        $path = "/api/racks/{$fixture->ids['racks'][0]}/resize";

        $bad = send($kernel, 'POST', $path, ['precognition' => 'true'], body: '{"heightU":99}');
        expect($bad->status)->toBe(422)
            ->and(json_decode($bad->body(), true)['errors'])->toHaveKey('heightU')
            ->and($bad->header('precognition'))->toBe('true');

        $good = send($kernel, 'POST', $path, ['precognition' => 'true'], body: '{"heightU":12}');
        expect($good->status)->toBe(204)
            ->and(Ran::$bodies)->toBe([]);
    });

    it('judges only the fields named', function () {
        [$fixture, $kernel] = racks();
        $path = "/api/racks/{$fixture->ids['racks'][0]}/resize";
        $only = ['precognition' => 'true', 'precognition-validate-only' => 'copies'];

        // heightU is missing and wrong; only copies was asked about.
        expect(send($kernel, 'POST', $path, $only, body: '{"copies":2}')->status)->toBe(204)
            ->and(json_decode(send($kernel, 'POST', $path, $only, body: '{"copies":0}')->body(), true)['errors'])->toHaveKeys(['copies'])
            ->and(Ran::$bodies)->toBe([]);
    });

    it('refuses a field nobody declares without quoting it, and too many fields', function () {
        [$fixture, $kernel] = racks();
        $path = "/api/racks/{$fixture->ids['racks'][0]}/resize";

        $unknown = send($kernel, 'POST', $path, ['precognition' => 'true', 'precognition-validate-only' => 'secretField'], body: '{}');
        expect($unknown->status)->toBe(422)
            ->and($unknown->body())->not->toContain('secretField');

        $many = implode(',', array_map(static fn (int $i): string => "f{$i}", range(1, 40)));
        expect(send($kernel, 'POST', $path, ['precognition' => 'true', 'precognition-validate-only' => $many], body: '{}')->status)->toBe(400);
    });

    it('runs every check first, and the CSRF token still', function () {
        [$fixture, $kernel] = racks();

        expect(send($kernel, 'POST', "/api/racks/{$fixture->ids['racks'][1]}/resize", ['precognition' => 'true'], body: '{"heightU":12}')->status)->toBe(403)
            ->and(send($kernel, 'POST', "/api/racks/{$fixture->ids['racks'][0]}/resize", ['precognition' => 'true'], body: '{"heightU":12}', csrf: false)->status)->toBe(419)
            ->and(Ran::$bodies)->toBe([]);
    });
});

describe('a #[WithoutSession] endpoint, held by the Kernel', function () {
    it('reaches its own middleware with no cookie, so nothing it runs signs anyone in from one', function () {
        [$fixture, $kernel] = racks();

        // The middleware would sign in from sid=owner; it never sees it.
        expect(send($kernel, 'POST', "/api/scanner/racks/{$fixture->ids['racks'][0]}", csrf: false)->status)->toBe(401)
            ->and(Ran::$seenBySessionless)->toBe([['cookies' => [], 'header' => null]])
            ->and(Ran::$bodies)->toBe([]);
    });

    // ADR 0125: PHP's own copies of the cookies too, which the Request
    // never carried -- and put back once the endpoint has run.
    it('empties $_COOKIE, the Cookie header in $_SERVER and $_REQUEST\'s cookies while it runs', function () {
        [$fixture, $kernel] = racks();
        $saved = [$_COOKIE, $_SERVER['HTTP_COOKIE'] ?? null, $_REQUEST, $_GET, $_POST];
        $_COOKIE = ['sid' => 'owner'];
        $_SERVER['HTTP_COOKIE'] = 'sid=owner';
        $_GET = ['q' => '1'];
        $_POST = [];
        $_REQUEST = ['q' => '1', 'sid' => 'owner'];
        Ran::$globalsSeen = [];

        try {
            $status = send($kernel, 'POST', "/api/scanner/racks/{$fixture->ids['racks'][0]}", csrf: false)->status;
            $after = [$_COOKIE, $_SERVER['HTTP_COOKIE'], $_REQUEST];
        } finally {
            [$_COOKIE, $header, $_REQUEST, $_GET, $_POST] = $saved;
            if ($header === null) {
                unset($_SERVER['HTTP_COOKIE']);
            } else {
                $_SERVER['HTTP_COOKIE'] = $header;
            }
        }

        expect($status)->toBe(401)
            ->and(Ran::$globalsSeen)->toBe([['cookie' => [], 'server' => null, 'request' => ['q' => '1']]])
            ->and($after)->toBe([['sid' => 'owner'], 'sid=owner', ['q' => '1', 'sid' => 'owner']]);
    });

    // A streamed body is produced after the stack has returned, as the
    // response is sent: it is held to the same emptied superglobals.
    it('empties them for a streamed body too, produced after the stack has returned', function () {
        [$fixture, $kernel] = racks();
        $saved = [$_COOKIE, $_SERVER['HTTP_COOKIE'] ?? null, $_REQUEST, $_GET, $_POST];
        $_COOKIE = ['sid' => 'owner'];
        $_SERVER['HTTP_COOKIE'] = 'sid=owner';
        $_GET = [];
        $_POST = [];
        $_REQUEST = ['sid' => 'owner'];

        try {
            $response = send($kernel, 'POST', "/api/scanner/racks/{$fixture->ids['racks'][0]}/stream", ['authorization' => 'Bearer scanner-token'], session: null, csrf: false);
            $body = '';
            $response->sendBody(static function (string $chunk) use (&$body): void {
                $body .= $chunk;
            });
            $after = [$_COOKIE, $_SERVER['HTTP_COOKIE'], $_REQUEST];
        } finally {
            [$_COOKIE, $header, $_REQUEST, $_GET, $_POST] = $saved;
            if ($header === null) {
                unset($_SERVER['HTTP_COOKIE']);
            } else {
                $_SERVER['HTTP_COOKIE'] = $header;
            }
        }

        expect($response->status)->toBe(200)
            ->and($response->isStreaming())->toBeTrue()
            ->and(json_decode($body, true))->toBe(['cookie' => [], 'server' => null, 'request' => []])
            // And put back once the body is sent.
            ->and($after)->toBe([['sid' => 'owner'], 'sid=owner', ['sid' => 'owner']]);
    });

    // ADR 0075: what an application's front controller asks before issuing
    // a session cookie to a request that has none.
    it('tells the application which requests open no session: its calls, and nothing else', function () {
        [$fixture, $kernel] = racks();
        $rack = $fixture->ids['racks'][0];

        expect($kernel->opensNoSession(new Request('POST', "/api/scanner/racks/{$rack}")))->toBeTrue()
            // The same path, a method it does not answer: a 405, which any
            // request may be, not a call to it.
            ->and($kernel->opensNoSession(new Request('GET', "/api/scanner/racks/{$rack}")))->toBeFalse()
            ->and($kernel->opensNoSession(new Request('GET', "/api/racks/{$rack}/height")))->toBeFalse()
            ->and($kernel->opensNoSession(new Request('GET', '/')))->toBeFalse();
    });

    // A browser preflights a cross-origin call before making it. The Kernel
    // answers the preflight with nothing of the endpoint's run, so the
    // application must not issue a session for it either.
    it('counts a preflight for one of its calls as one, and no other OPTIONS', function () {
        [$fixture, $kernel] = racks();
        $rack = $fixture->ids['racks'][0];
        $preflight = static fn (string $path, array $headers): Request => new Request('OPTIONS', $path, $headers);

        expect($kernel->opensNoSession($preflight("/api/scanner/racks/{$rack}", ['origin' => 'https://scanner.example', 'access-control-request-method' => 'POST'])))->toBeTrue()
            // From an origin it does not name: still the Kernel's 405, with
            // no session opened for it.
            ->and($kernel->opensNoSession($preflight("/api/scanner/racks/{$rack}", ['origin' => 'https://evil.example', 'access-control-request-method' => 'POST'])))->toBeTrue()
            // Asking about a method no sessionless endpoint there answers.
            ->and($kernel->opensNoSession($preflight("/api/scanner/racks/{$rack}", ['origin' => 'https://scanner.example', 'access-control-request-method' => 'GET'])))->toBeFalse()
            // An OPTIONS that is no preflight, and a preflight to an
            // endpoint that opens the session.
            ->and($kernel->opensNoSession($preflight("/api/scanner/racks/{$rack}", [])))->toBeFalse()
            ->and($kernel->opensNoSession($preflight("/api/racks/{$rack}/height", ['origin' => 'https://partner.example', 'access-control-request-method' => 'GET'])))->toBeFalse()
            ->and($kernel->opensNoSession($preflight('/', ['origin' => 'https://scanner.example', 'access-control-request-method' => 'GET'])))->toBeFalse();
    });

    it('is refused when it declares global middleware, which opens the session', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1);

        expect(fn () => gapsKernel([OpensTheSession::class], new Orm($fixture->connection, new IdentityMap('test'))))
            ->toThrow(\InvalidArgumentException::class, 'opens the session it runs without');
    });
});
