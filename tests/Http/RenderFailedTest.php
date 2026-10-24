<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "Render failures: Inertia falls
 * back to client rendering, which RSC cannot do ... Fire a RenderFailed
 * event, and add throw_on_error for tests."
 */

namespace Tinvar\Tests\Http\RenderFailed;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\ProtocolException;
use Tinvar\Query\QueryRegistry;
use Tinvar\Render\RendererException;
use Tinvar\Render\RenderFailed;
use Tinvar\Render\RenderFailure;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/../Auth/Fixtures/policies.php';

#[Page('/catalog', view: 'catalog', name: 'catalog')]
final class CatalogPage {}

/** @param list<\Closure(RenderFailed): void> $listeners */
function kernel(array $listeners = [], bool $throwOnRenderError = false): Kernel
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $routes = new RouteRegistry();
    $routes->register(CatalogPage::class);

    return new Kernel(
        orm: new Orm($fixture->connection, new IdentityMap('test')),
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        renderFailed: $listeners,
        throwOnRenderError: $throwOnRenderError,
    );
}

function failingWith(\Throwable $e): \Closure
{
    return static fn (): Response => throw $e;
}

it('tells each listener, with the class and the digest the response carries', function () {
    $heard = [];
    $kernel = kernel([static function (RenderFailed $event) use (&$heard): void {
        $heard[] = $event;
    }]);

    $failure = RendererException::fromErrorFrame(['code' => 'browser_api_on_server', 'digest' => 'abc123']);
    $response = $kernel->handle(new Request('GET', '/catalog'), failingWith($failure));

    $body = json_decode($response->body(), true);
    expect($response->status)->toBe(500)
        ->and($heard)->toHaveCount(1)
        ->and($heard[0]->failure)->toBe(RenderFailure::BrowserApiOnServer)
        ->and($heard[0]->exception)->toBe($failure)
        ->and($heard[0]->request->path)->toBe('/catalog')
        ->and($heard[0]->digest)->toBe($body['error']['digest']);
});

it('counts a renderer that broke the protocol as a failed render', function () {
    $heard = [];
    $kernel = kernel([static function (RenderFailed $event) use (&$heard): void {
        $heard[] = $event->failure;
    }]);

    $kernel->handle(new Request('GET', '/catalog'), failingWith(ProtocolException::wrongDirection('render')));

    expect($heard)->toBe([RenderFailure::ProtocolViolation]);
});

it('tells no one of a failure that was not a render', function () {
    $heard = 0;
    $kernel = kernel([static function () use (&$heard): void {
        $heard++;
    }]);

    $kernel->handle(new Request('GET', '/catalog'), failingWith(new \LogicException('a bug in a loader')));
    $kernel->handle(new Request('GET', '/nowhere'), failingWith(new \LogicException('never reached')));

    expect($heard)->toBe(0);
});

// One broken metrics hook must not decide what the visitor is shown.
it('runs the rest of the listeners when one throws', function () {
    $heard = 0;
    $kernel = kernel([
        static fn (): never => throw new \RuntimeException('the metrics hook is down'),
        static function () use (&$heard): void {
            $heard++;
        },
    ]);

    $response = $kernel->handle(new Request('GET', '/catalog'), failingWith(RendererException::closedEarly()));

    expect($heard)->toBe(1)->and($response->status)->toBe(500);
});

// "Renderer errors fail the test instead of falling back to an error page."
it('lets a failed render escape as itself under throw_on_error, after the listeners', function () {
    $heard = 0;
    $kernel = kernel([static function () use (&$heard): void {
        $heard++;
    }], throwOnRenderError: true);
    $failure = RendererException::cannotConnect('/tmp/none.sock', 'no socket at that path');

    expect(fn () => $kernel->handle(new Request('GET', '/catalog'), failingWith($failure)))
        ->toThrow(RendererException::class, 'Cannot reach the renderer');
    expect($heard)->toBe(1);
});

it('still answers anything else with a response under throw_on_error', function () {
    $kernel = kernel(throwOnRenderError: true);

    expect($kernel->handle(new Request('GET', '/nowhere'), failingWith(new \LogicException()))->status)->toBe(404)
        ->and($kernel->handle(new Request('GET', '/catalog'), failingWith(new \LogicException()))->status)->toBe(500);
});

// The reset contract still runs: a test that catches the failure and goes on
// must not inherit the failed request's user.
it('resets the request scope before a failed render escapes', function () {
    $kernel = kernel(throwOnRenderError: true);
    $kernel->auth->login(new \Tinvar\Tests\Auth\Fixtures\User('u-1'));

    try {
        $kernel->handle(new Request('GET', '/catalog'), failingWith(RendererException::closedEarly()));
    } catch (RendererException) {
    }

    expect($kernel->auth->check())->toBeFalse();
});
