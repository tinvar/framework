<?php

declare(strict_types=1);

/**
 * tinvar-spec.md, "Operations and tooling": "Render failures: Inertia falls
 * back to client rendering, which RSC cannot do ... Fire a RenderFailed
 * event, and add throw_on_error for tests."
 */

namespace Tinvar\Tests\Http\RenderFailed;

use Tinvar\Actions\ActionRegistry;
use Tinvar\Attributes\Listener;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AuthContext;
use Tinvar\Auth\PolicyRegistry;
use Tinvar\Events\EventDispatcher;
use Tinvar\Events\EventFake;
use Tinvar\Events\EventRegistry;
use Tinvar\Events\Events;
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

/**
 * @param list<\Closure(RenderFailed): void> $listeners
 * @param (\Closure(Orm): Events)|null $events the bus, built on the Kernel's database
 */
function kernel(array $listeners = [], bool $throwOnRenderError = false, ?\Closure $events = null, ?\Closure $log = null): Kernel
{
    $fixture = new Fixture(racks: 0, unitsPerRack: 0, sites: 0);
    $routes = new RouteRegistry();
    $routes->register(CatalogPage::class);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    return new Kernel(
        orm: $orm,
        routes: $routes,
        queries: new QueryRegistry(),
        actions: new ActionRegistry(),
        auth: new AuthContext(),
        policies: new PolicyRegistry(),
        resolver: static fn (string $class): object => new $class(),
        allowedQueries: static fn (Request $r): array => [],
        log: $log,
        renderFailed: $listeners,
        throwOnRenderError: $throwOnRenderError,
        events: $events === null ? null : $events($orm),
    );
}

/** Hears render failures on the bus, as an application's own #[Listener] would. */
final class CountsFailures
{
    /** @var list<RenderFailure> */
    public static array $heard = [];

    #[Listener]
    public function failed(RenderFailed $event): void
    {
        self::$heard[] = $event->failure;
    }
}

final class BrokenListener
{
    #[Listener]
    public function failed(RenderFailed $event): never
    {
        throw new \RuntimeException('the pager is down');
    }
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

// tinvar-spec.md: "Fire a RenderFailed event." The application's event bus
// hears it too, so a #[Listener] for it is all an application writes.
it('dispatches each failed render on the event bus, after the closures, with the same digest', function () {
    $order = [];
    $fake = null;
    $kernel = kernel(
        [static function () use (&$order): void {
            $order[] = 'closure';
        }],
        events: static function (Orm $orm) use (&$fake): Events {
            return $fake = new EventFake($orm);
        },
    );

    $failure = RendererException::fromErrorFrame(['code' => 'component_not_found', 'digest' => 'abc']);
    $response = $kernel->handle(new Request('GET', '/catalog'), failingWith($failure));
    \assert($fake instanceof EventFake);
    $dispatched = $fake->dispatched(RenderFailed::class);

    expect($order)->toBe(['closure'])
        ->and($dispatched)->toHaveCount(1)
        ->and($dispatched[0]->failure)->toBe(RenderFailure::ComponentNotFound)
        ->and($dispatched[0]->digest)->toBe(json_decode($response->body(), true)['error']['digest']);

    // Nothing for a failure that was not a render.
    $kernel->handle(new Request('GET', '/catalog'), failingWith(new \LogicException('a loader bug')));
    expect($fake->dispatched(RenderFailed::class))->toHaveCount(1);
});

it('runs an application\'s #[Listener] for it through the real dispatcher', function () {
    CountsFailures::$heard = [];
    $kernel = kernel(events: static function (Orm $orm): Events {
        $registry = new EventRegistry();
        $registry->register(CountsFailures::class);

        return new EventDispatcher($registry, $orm, static fn (string $class): object => new $class());
    });

    $kernel->handle(new Request('GET', '/catalog'), failingWith(RendererException::closedEarly()));

    expect(CountsFailures::$heard)->toHaveCount(1)
        ->and(CountsFailures::$heard[0]->value)->toBe(RendererException::closedEarly()->failure()->value);
});

// A broken listener on the bus is reported, and the visitor still gets the
// error page the failure calls for.
it('reports a bus listener that throws, and answers as it would have', function () {
    $logged = [];
    $kernel = kernel(
        events: static function (Orm $orm): Events {
            $registry = new EventRegistry();
            $registry->register(BrokenListener::class);

            return new EventDispatcher($registry, $orm, static fn (string $class): object => new $class());
        },
        log: static function (\Throwable $e) use (&$logged): void {
            $logged[] = $e->getMessage();
        },
    );

    $response = $kernel->handle(new Request('GET', '/catalog'), failingWith(RendererException::closedEarly()));

    expect($response->status)->toBe(500)
        ->and($logged)->toContain('the pager is down');
});

it('refuses a queued listener for it at registration: it carries the exception and the request', function () {
    $registry = new EventRegistry();

    expect(fn () => $registry->register(QueuedFailureListener::class))->toThrow(\InvalidArgumentException::class, 'is not Queueable');
});

#[\Tinvar\Attributes\Queue]
final class QueuedFailureListener
{
    #[Listener]
    public function failed(RenderFailed $event): void {}
}
