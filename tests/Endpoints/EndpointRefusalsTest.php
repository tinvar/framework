<?php

declare(strict_types=1);

/**
 * #[Refusals] (ADR 0188): an endpoint's refusals -- a throttle's 429,
 * authentication's 401, a 404 from inside it -- written by a class the
 * application names, in the shape its own clients read. A 500 stays the
 * framework's.
 */

namespace Tinvar\Tests\Endpoints\Refusals;

use Tinvar\Actions\ActionException;
use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Domain;
use Tinvar\Attributes\Refusals;
use Tinvar\Attributes\Throttle as Bucket;
use Tinvar\Attributes\Get;
use Tinvar\Attributes\Middleware as UseMiddleware;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\PublicAccess;
use Tinvar\Attributes\WithoutSession;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Data\Data;
use Tinvar\Endpoints\EndpointException;
use Tinvar\Endpoints\EndpointRegistry;
use Tinvar\Http\ArrayThrottleStore;
use Tinvar\Http\Kernel;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\HttpError;
use Tinvar\Http\HttpException;
use Tinvar\Http\RefusalWriter;
use Tinvar\Http\Response;
use Tinvar\Http\Throttle;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Query\QueryRegistry;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\Domains;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Auth\Fixtures\User;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

final readonly class Answer extends Data
{
    public function __construct(public string $from) {}
}

/** Signs in whoever the bearer names, as an API's own middleware does. */
final class Bearer implements Middleware
{
    public function __construct(private AuthContext $auth) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $token = $request->header('authorization');
        if ($token !== null) {
            $this->auth->login(new User(substr($token, 7)));
        }

        return $next($request);
    }
}

/** Writes a refusal as this API's clients read one. */
final class ApiRefusals implements RefusalWriter
{
    public function write(HttpError $error, Request $request): Response
    {
        return Response::make($error->status, (string) json_encode([
            'error' => $error->status === 429 ? 'rate_limited' : $error->code,
            'message' => "Refused ({$error->status}).",
            'retry_after' => $error->retryAfter(),
            'reference' => $error->digest,
        ]), ['content-type' => 'application/json']);
    }
}

#[WithoutSession(reason: 'a script sends a bearer token'), Domain('api.{root}'), Refusals(ApiRefusals::class)]
final class Api
{
    #[Get('/v1/status', name: 'api.status'), UseMiddleware(Bearer::class), NoAuthorization(reason: 'a status'), Bucket('api.reads', perMinute: 1)]
    public function status(): Answer
    {
        return new Answer('api');
    }

    #[Get('/v1/gone', name: 'api.gone'), UseMiddleware(Bearer::class), NoAuthorization(reason: 'test')]
    public function gone(): Answer
    {
        throw new HttpException(404, 'Rack 7 of tenant acme is not there.', 'not_found');
    }

    #[Get('/v1/broken', name: 'api.broken'), UseMiddleware(Bearer::class), NoAuthorization(reason: 'test')]
    public function broken(): Answer
    {
        throw new \RuntimeException('the table rack_units has no column height');
    }
}

/** The same refusals, unwritten: the framework's own shape. */
#[WithoutSession(reason: 'a script sends a bearer token'), Domain('api.{root}')]
final class Plain
{
    #[Get('/v1/plain', name: 'plain.status'), UseMiddleware(Bearer::class), NoAuthorization(reason: 'test'), Bucket('plain', perMinute: 1)]
    public function status(): Answer
    {
        return new Answer('plain');
    }
}

final class NotAWriter {}

#[Refusals(NotAWriter::class)]
final class WrongWriter
{
    #[Get('/w', name: 'wrong.w'), PublicAccess, NoAuthorization(reason: 'test')]
    public function w(): Answer
    {
        return new Answer('w');
    }
}

#[Refusals(ApiRefusals::class)]
final class RefusingAction
{
    #[Action('refusing.save'), NoAuthorization(reason: 'test')]
    public function save(): void {}
}

function refusalKernel(): Kernel
{
    $auth = new AuthContext();
    $registry = new EndpointRegistry();
    $registry->registerAll([Api::class, Plain::class]);
    $fixture = new Fixture(racks: 1, unitsPerRack: 1);

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: new RouteRegistry(),
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: $auth,
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => $class === Bearer::class ? new Bearer($auth) : new $class(),
        allowedQueries: static fn (Request $r): array => [],
        throttle: new Throttle(new ArrayThrottleStore(throttleClock()), $auth, static fn (Request $r): string => $r->remoteAddress ?? ''),
        limits: Kernel::DEFAULT_LIMITS,
        tenants: null,
        endpoints: $registry,
        domains: new Domains(['root' => 'shop.test']),
    );
}

function call(Kernel $kernel, string $path, ?string $bearer = 'ada'): Response
{
    $headers = ['host' => 'api.shop.test', 'accept' => 'application/json'];
    if ($bearer !== null) {
        $headers['authorization'] = "Bearer {$bearer}";
    }

    return $kernel->handle(
        new Request('GET', $path, $headers, remoteAddress: '192.0.2.1'),
        static fn (LoadedRoute $l, Request $r): Response => Response::make(200, 'page'),
    );
}

/** @return array<string, mixed> */
function body(Response $response): array
{
    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
}

it('writes a throttle\'s 429 as the endpoint says, the headers kept', function () {
    $kernel = refusalKernel();
    expect(call($kernel, '/v1/status')->status)->toBe(200);

    $refused = call($kernel, '/v1/status');
    expect($refused->status)->toBe(429)
        ->and(body($refused)['error'])->toBe('rate_limited')
        ->and(body($refused)['retry_after'])->toBeInt()->toBeGreaterThan(0)
        ->and($refused->header('retry-after'))->toBe((string) body($refused)['retry_after'])
        ->and($refused->header('x-ratelimit-limit'))->toBe('1');
});

it('writes a refusal from before the endpoint ran, and one from inside it, saying none of the exception\'s message', function () {
    $kernel = refusalKernel();

    $anonymous = call($kernel, '/v1/gone', bearer: null);
    expect($anonymous->status)->toBe(401)
        ->and(body($anonymous)['error'])->toBe('unauthenticated');

    $gone = call($kernel, '/v1/gone');
    expect($gone->status)->toBe(404)
        ->and(body($gone)['error'])->toBe('not_found')
        ->and($gone->body())->not->toContain('acme')
        ->and(body($gone)['reference'])->toBeString()->not->toBe('');
});

it('leaves a failure that is not a refusal to the framework', function () {
    $broken = call(refusalKernel(), '/v1/broken');

    expect($broken->status)->toBe(500)
        ->and(body($broken))->toHaveKey('error.code', 'internal_error')
        ->and($broken->body())->not->toContain('rack_units');
});

it('leaves an endpoint that names no writer the framework\'s shape', function () {
    $kernel = refusalKernel();
    call($kernel, '/v1/plain');
    $refused = call($kernel, '/v1/plain');

    expect($refused->status)->toBe(429)
        ->and(body($refused))->toHaveKey('error.code', 'too_many_requests');
});

it('is refused when the class it names writes no refusals', function () {
    expect(fn () => (new EndpointRegistry())->register(WrongWriter::class))
        ->toThrow(EndpointException::class, 'RefusalWriter');
});

it('is refused on an action', function () {
    expect(fn () => (new ActionRegistry())->register(RefusingAction::class))
        ->toThrow(ActionException::class, 'only an endpoint reads');
});
