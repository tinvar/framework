<?php

declare(strict_types=1);

namespace Tinvar\Tests\Http\ActionMiddleware;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Actions\ActionResult;
use Tinvar\Actions\ActionRunner;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Middleware as MiddlewareAttribute;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Http\Endpoints\ActionEndpoint;
use Tinvar\Http\HttpException;
use Tinvar\Http\Middleware;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Validation\Validator;

/** What ran, in order. */
final class Trail
{
    /** @var list<string> */
    public static array $seen = [];
}

final class Outer implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trail::$seen[] = 'outer';

        return $next($request);
    }
}

final class Inner implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        Trail::$seen[] = 'inner';

        return $next($request);
    }
}

final class Refuse implements Middleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        throw new HttpException(403, 'Refused by middleware.', 'forbidden');
    }
}

#[MiddlewareAttribute(Outer::class)]
final class GuardedActions
{
    #[Action(name: 'guarded.run'), NoAuthorization(reason: 'test')]
    #[MiddlewareAttribute(Inner::class, Outer::class)]
    public function run(): ActionResult
    {
        Trail::$seen[] = 'action';

        return ActionResult::ok();
    }

    #[Action(name: 'guarded.refused'), NoAuthorization(reason: 'test')]
    #[MiddlewareAttribute(Refuse::class)]
    public function refused(): ActionResult
    {
        Trail::$seen[] = 'action';

        return ActionResult::ok();
    }
}

/** @param list<class-string> $global */
function endpoint(bool $resolving = true, array $global = []): ActionEndpoint
{
    $orm = new Orm(new PdoConnection(new \PDO('sqlite::memory:')), new IdentityMap('test'));
    $registry = new ActionRegistry();
    $registry->register(GuardedActions::class);

    return new ActionEndpoint(
        new ActionRunner($registry, $orm, new AllowAllGate(), new Validator($orm)),
        middlewareResolver: $resolving ? static fn (string $class): Middleware => new $class() : null,
        globalMiddleware: $global,
    );
}

function post(string $name, array $headers = []): Request
{
    return new Request('POST', ActionEndpoint::PREFIX . $name, $headers);
}

beforeEach(function () {
    Trail::$seen = [];
});

// Compiled and never run, a middleware on an action was silently ignored.
it('runs the class middleware, then the method middleware, once each, around the action', function () {
    $response = endpoint()->handle(post('guarded.run'));

    expect($response->status)->toBe(200)
        ->and(Trail::$seen)->toBe(['outer', 'inner', 'action']);
});

it('lets an action middleware refuse the request before the action runs', function () {
    expect(fn () => endpoint()->handle(post('guarded.refused')))
        ->toThrow(HttpException::class, 'Refused by middleware.')
        ->and(Trail::$seen)->toBe(['outer'], 'the class middleware ran; the action did not');
});

// Precognition resolves models and authorizes, so it is no way around them.
it('runs it for a precognitive request too', function () {
    expect(fn () => endpoint()->handle(post('guarded.refused', ['precognition' => 'true'])))
        ->toThrow(HttpException::class, 'Refused by middleware.');
});

it('does not run a middleware the global stack already ran', function () {
    endpoint(global: [Outer::class])->handle(post('guarded.run'));

    expect(Trail::$seen)->toBe(['inner', 'action']);
});

// Fail closed: a guard nobody can build is not a guard that may be skipped.
it('refuses to run an action whose middleware it has no way to build', function () {
    expect(fn () => endpoint(resolving: false)->handle(post('guarded.run')))
        ->toThrow(\LogicException::class, 'declares #[Middleware]')
        ->and(Trail::$seen)->toBe([]);
});
