<?php

declare(strict_types=1);

/**
 * ADR 0183: an endpoint, and its own middleware, read the exact bytes it
 * was sent -- a `RawBody` parameter, and `RawBody::of($request)` -- so a
 * caller that signs its body (a node agent's ECDSA, a partner's HMAC) is
 * checked over what it signed, before validation and the body run.
 */

namespace Tinvar\Tests\Endpoints\RawBodies;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Post;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\Authenticatable;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Middleware\VerifyCsrfToken;
use Tinvar\Http\RawBody;
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

const SECRET = 'node-agent-shared-secret-for-tests-only';

final class Seen
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    /** @var list<string|null> what the signature middleware read, in order */
    public static array $verified = [];
}

final class AgentPolicy
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

final readonly class Reported extends Data
{
    public function __construct(public int $heightU, public string $digest) {}
}

final readonly class ReportRequest extends FormRequest
{
    public function __construct(
        #[Min(1), Max(48)]
        public int $heightU,
    ) {}
}

/**
 * What a node agent's server checks: an HMAC over the method, the path and
 * the body's SHA-256, as the agent signed them. Celerium's is ECDSA over the
 * same string; the bytes it needs are the same.
 */
final class VerifySignature implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $body = RawBody::of($request);
        Seen::$verified[] = $body?->bytes;
        $signature = $request->header('x-signature') ?? '';
        if ($body === null || !hash_equals(signature($request->method, $request->path, $body->bytes), $signature)) {
            throw new HttpException(401, 'Bad signature.', 'unauthenticated');
        }
        $this->auth->login(new User('node-1', ownedRackNames: ['R-01']));

        return $next($request);
    }
}

function signature(string $method, string $path, string $bytes): string
{
    return hash_hmac('sha256', "{$method}\n{$path}\n" . hash('sha256', $bytes), SECRET);
}

#[WithoutSession(reason: 'node agents sign every request, which VerifySignature checks')]
final class Agents
{
    #[Post('/api/agents/racks/{rack}/report', name: 'agents.report'), UseMiddleware(VerifySignature::class), Authorize('update', 'rack')]
    public function report(Rack $rack, ReportRequest $request, RawBody $body): Reported
    {
        Seen::$calls[] = ['report' => $body->bytes, 'heightU' => $request->heightU];

        return new Reported($request->heightU, $body->hash());
    }

    /** The signature checked over the bytes; the body reads only its form request. */
    #[Post('/api/agents/racks/{rack}/resize', name: 'agents.resize'), UseMiddleware(VerifySignature::class), Authorize('update', 'rack')]
    public function resize(Rack $rack, ReportRequest $request): Reported
    {
        Seen::$calls[] = ['resize' => $request->heightU];

        return new Reported($request->heightU, '');
    }

    #[Get('/api/agents/racks/{rack}', name: 'agents.show'), UseMiddleware(VerifySignature::class), Authorize('view', 'rack')]
    public function show(Rack $rack, RawBody $body): Reported
    {
        Seen::$calls[] = ['show' => $body->bytes];

        return new Reported($rack->heightU, $body->hash());
    }

    /** Bytes of no type the endpoints decode, read whole. */
    #[Post('/api/agents/blob', name: 'agents.blob'), UseMiddleware(VerifySignature::class), NoAuthorization(reason: 'test')]
    public function blob(RawBody $body): Response
    {
        Seen::$calls[] = ['blob' => $body->bytes];

        return Response::make(200, $body->hash(), ['content-type' => 'text/plain']);
    }
}

/** On a session, behind the CSRF token as every other POST is. */
final class Browser
{
    #[Post('/api/browser/echo', name: 'browser.echo'), NoAuthorization(reason: 'test')]
    public function echo(RawBody $body): Response
    {
        Seen::$calls[] = ['echo' => $body->bytes];

        return Response::make(200, (string) $body->length());
    }
}

final class SignsIn implements Middleware
{
    public function __construct(private readonly AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->cookie('sid') === 'owner') {
            $this->auth->login(new User('owner', ownedRackNames: ['R-01']));
        }

        return $next($request);
    }
}

final class TwoBodies
{
    #[Post('/api/two'), NoAuthorization(reason: 'test')]
    public function call(RawBody $one, RawBody $two): void {}
}

final class RawAction
{
    #[Action, NoAuthorization(reason: 'test')]
    public function go(RawBody $body): void {}
}

function kernel(Orm $orm, int $maxBodyBytes = 1_048_576): Kernel
{
    $auth = new AuthContext();
    $registry = new EndpointRegistry();
    $registry->registerAll([Agents::class, Browser::class]);

    return new Kernel(
        orm: $orm,
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: (new PolicyRegistry())->register(Rack::class, AgentPolicy::class),
        resolver: static fn (string $class): object => match ($class) {
            VerifySignature::class => new VerifySignature($auth),
            SignsIn::class => new SignsIn($auth),
            default => new $class(),
        },
        allowedQueries: static fn (Request $r): array => [],
        globalMiddleware: [SignsIn::class],
        throttle: new Throttle(new ArrayThrottleStore(throttleClock()), $auth, static fn (Request $r): string => $r->remoteAddress ?? ''),
        tenants: null,
        endpoints: $registry,
        maxBodyBytes: $maxBodyBytes,
    );
}

/**
 * A node agent's call: signed over exactly these bytes unless $signedOver
 * says otherwise.
 *
 * @param array<string, string> $headers
 */
function agent(Kernel $kernel, string $method, string $path, string $bytes = '', array $headers = [], ?string $signedOver = null, string $type = 'application/json'): Response
{
    $headers['x-signature'] = signature($method, $path, $signedOver ?? $bytes);
    if ($bytes !== '') {
        $headers['content-type'] ??= $type;
    }

    return $kernel->handle(
        new Request($method, $path, $headers, remoteAddress: '192.0.2.1'),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
        $bytes,
    );
}

/** @return array{Fixture, Kernel} */
function agents(int $maxBodyBytes = 1_048_576): array
{
    $fixture = new Fixture(racks: 2, unitsPerRack: 1);

    return [$fixture, kernel(new Orm($fixture->connection, new IdentityMap('test')), $maxBodyBytes)];
}

beforeEach(function () {
    Seen::$calls = [];
    Seen::$verified = [];
});

describe('a signed body', function () {
    it('reaches the middleware and the method as the exact bytes sent, with JSON input bound beside them', function () {
        [$fixture, $kernel] = agents();
        $path = "/api/agents/racks/{$fixture->ids['racks'][0]}/report";
        // Spacing and key order a decode-and-re-encode would not keep.
        $bytes = "{ \"heightU\" : 12,\n  \"note\": \"\\u00e9t\\u00e9\" }";

        $response = agent($kernel, 'POST', $path, $bytes);

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true))->toBe(['heightU' => 12, 'digest' => hash('sha256', $bytes)])
            ->and(Seen::$verified)->toBe([$bytes])
            ->and(Seen::$calls)->toBe([['report' => $bytes, 'heightU' => 12]]);
    });

    it('is refused when the body is not what was signed, before validation or the method', function () {
        [$fixture, $kernel] = agents();
        $path = "/api/agents/racks/{$fixture->ids['racks'][0]}/report";

        $tampered = agent($kernel, 'POST', $path, '{"heightU":48}', signedOver: '{"heightU":12}');
        // Invalid, and tampered: the signature is what answers.
        $invalid = agent($kernel, 'POST', $path, '{"heightU":99}', signedOver: '{"heightU":1}');

        expect($tampered->status)->toBe(401)
            ->and($invalid->status)->toBe(401)
            ->and(Seen::$calls)->toBe([]);
    });

    it('is checked by middleware on an endpoint that takes only its form request', function () {
        [$fixture, $kernel] = agents();
        $path = "/api/agents/racks/{$fixture->ids['racks'][0]}/resize";

        expect(agent($kernel, 'POST', $path, '{"heightU":7}')->status)->toBe(200)
            ->and(agent($kernel, 'POST', $path, '{"heightU":8}', signedOver: '{"heightU":7}')->status)->toBe(401)
            ->and(Seen::$calls)->toBe([['resize' => 7]]);
    });

    it('is the empty body on a GET, whose input is still its query string', function () {
        [$fixture, $kernel] = agents();
        $path = "/api/agents/racks/{$fixture->ids['racks'][0]}";

        $response = agent($kernel, 'GET', $path);

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true)['digest'])->toBe(hash('sha256', ''))
            ->and(Seen::$calls)->toBe([['show' => '']]);
    });

    it('takes a body of a type the endpoints do not decode only where the method reads the bytes', function () {
        [$fixture, $kernel] = agents();
        $bytes = "\x00\x01binary\xff";

        $read = agent($kernel, 'POST', '/api/agents/blob', $bytes, type: 'application/octet-stream');
        $decoded = agent($kernel, 'POST', "/api/agents/racks/{$fixture->ids['racks'][0]}/resize", $bytes, type: 'application/octet-stream');

        expect($read->status)->toBe(200)
            ->and($read->body())->toBe(hash('sha256', $bytes))
            // Its input is a form request's, which reads no such body.
            ->and($decoded->status)->toBe(415)
            ->and(Seen::$calls)->toBe([['blob' => $bytes]]);
    });

    it('still refuses JSON that does not parse, where the method also takes a form request', function () {
        [$fixture, $kernel] = agents();

        expect(agent($kernel, 'POST', "/api/agents/racks/{$fixture->ids['racks'][0]}/report", '{"heightU":')->status)->toBe(400)
            ->and(Seen::$calls)->toBe([]);
    });

    it('is held to the body limit before any of the endpoint\'s middleware reads it', function () {
        [$fixture, $kernel] = agents(maxBodyBytes: 64);
        $big = '{"heightU":12,"pad":"' . str_repeat('a', 64) . '"}';

        expect(agent($kernel, 'POST', "/api/agents/racks/{$fixture->ids['racks'][0]}/report", $big)->status)->toBe(413)
            ->and(agent($kernel, 'POST', '/api/agents/blob', str_repeat("\x00", 65), type: 'application/octet-stream')->status)->toBe(413)
            ->and(Seen::$verified)->toBe([])
            ->and(Seen::$calls)->toBe([])
            // At the limit, it is read.
            ->and(agent($kernel, 'POST', '/api/agents/blob', str_repeat("\x00", 64), type: 'application/octet-stream')->status)->toBe(200);
    });

    it('is nothing to read for a multipart body, whose bytes PHP consumed, so a check fails closed', function () {
        [$fixture, $kernel] = agents();

        $response = $kernel->handle(
            new Request('POST', '/api/agents/blob', [
                'content-type' => 'multipart/form-data; boundary=x',
                'x-signature' => signature('POST', '/api/agents/blob', ''),
            ], body: ['heightU' => '12'], remoteAddress: '192.0.2.1'),
            static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
            '',
        );

        expect($response->status)->toBe(401)
            ->and(Seen::$verified)->toBe([null])
            ->and(Seen::$calls)->toBe([]);
    });

    it('leaves Precognition as it was: checks and validation, no method', function () {
        [$fixture, $kernel] = agents();
        $path = "/api/agents/racks/{$fixture->ids['racks'][0]}/report";

        expect(agent($kernel, 'POST', $path, '{"heightU":99}', ['precognition' => 'true'])->status)->toBe(422)
            ->and(agent($kernel, 'POST', $path, '{"heightU":9}', ['precognition' => 'true'])->status)->toBe(204)
            ->and(agent($kernel, 'POST', $path, '{"heightU":9}', ['precognition' => 'true'], signedOver: '{}')->status)->toBe(401)
            ->and(Seen::$calls)->toBe([]);
    });
});

describe('an endpoint on a session', function () {
    it('still holds a POST that reads its bytes to the CSRF token', function () {
        [, $kernel] = agents();
        $call = static fn (bool $csrf): Response => $kernel->handle(
            new Request('POST', '/api/browser/echo', [
                'content-type' => 'application/json',
                'cookie' => 'sid=owner',
                ...($csrf ? ['x-xsrf-token' => 't'] : []),
            ], cookies: ['sid' => 'owner', ...($csrf ? [VerifyCsrfToken::COOKIE => 't'] : [])], remoteAddress: '192.0.2.1'),
            static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
            '{"a":1}',
        );

        expect($call(false)->status)->toBe(419)
            ->and(Seen::$calls)->toBe([])
            ->and($call(true)->body())->toBe('7')
            ->and(Seen::$calls)->toBe([['echo' => '{"a":1}']]);
    });
});

describe('RawBody', function () {
    it('never shows its bytes in a dump', function () {
        $body = new RawBody('secret-bytes');

        expect(print_r($body, true))->not->toContain('secret-bytes')
            ->and(print_r($body, true))->toContain('12')
            ->and($body->length())->toBe(12)
            ->and($body->hash('sha512'))->toBe(hash('sha512', 'secret-bytes'));
    });

    it('is refused twice on one endpoint, and on an action, which is the browser\'s', function () {
        expect(fn () => (new EndpointRegistry())->register(TwoBodies::class))
            ->toThrow(EndpointException::class, 'takes the RawBody twice')
            ->and(fn () => (new ActionRegistry())->register(RawAction::class))
            ->toThrow(ActionException::class, 'only an endpoint');
    });
});
