<?php

declare(strict_types=1);

namespace Tinvar\Tests\Routing\Surface;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Layout;
use Tinvar\Attributes\Page;
use Tinvar\Auth\AllowAllGate;
use Tinvar\Auth\DenyGate;
use Tinvar\Auth\Gate;
use Tinvar\Data\Data;
use Tinvar\Http\ForbiddenException;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\Binder;
use Tinvar\Routing\CompiledRoute;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\RouteChain;
use Tinvar\Routing\RouteKind;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Orm\Fixtures\Rack;
use Tinvar\Tests\Routing\Fixtures\BoolParamPage;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/routes.php';

final readonly class SurfaceData extends Data
{
    public function __construct(public string $value) {}
}

abstract readonly class SurfacePanel extends Data
{
    public function __construct(public string $label) {}
}

final readonly class OuterPanel extends SurfacePanel {}

final readonly class InnerPanel extends SurfacePanel {}

/** The model instances the loaders below were handed. */
final class Seen
{
    /** @var array<string, object|null> */
    public static array $models = [];
}

#[Layout('/shared/{rack}', view: 'shared/layout'), Authorize('view', 'rack')]
final class SharedRackLayout
{
    public function __invoke(Rack $rack): SurfaceData
    {
        Seen::$models['layout'] = $rack;

        return new SurfaceData('layout');
    }
}

#[Page('/shared/{rack}/show', view: 'shared/show', name: 'surface.shared'), Authorize('view', 'rack')]
final class SharedRackPage
{
    public function __invoke(Rack $rack): SurfaceData
    {
        Seen::$models['page'] = $rack;

        return new SurfaceData($rack->name);
    }
}

/** Declares a model that is neither optional nor carried by any path under it. */
#[Layout('/needs', view: 'needs/layout'), Authorize('view', 'rack')]
final class RequiredModelLayout
{
    public function __invoke(Rack $rack): SurfaceData
    {
        return new SurfaceData($rack->name);
    }
}

#[Page('/needs/page', view: 'needs/page', name: 'surface.needs')]
final class RequiredModelPage {}

/** Nullable without a default: the binder has to supply the null itself. */
#[Layout('/maybe', view: 'maybe/layout'), Authorize('view', 'rack')]
final class NullableModelLayout
{
    public function __invoke(?Rack $rack): SurfaceData
    {
        return new SurfaceData($rack === null ? 'none' : $rack->name);
    }
}

#[Page('/maybe/list', view: 'maybe/list', name: 'surface.maybe')]
final class NullableModelPage {}

#[Page('/echo-request', view: 'echo', name: 'surface.echo')]
final class RequestPage
{
    public function __invoke(Request $request): SurfaceData
    {
        return new SurfaceData($request->path);
    }
}

#[Page('/defaulted', view: 'defaulted', name: 'surface.defaulted')]
final class DefaultedPage
{
    public function __invoke(string $mode = 'full'): SurfaceData
    {
        return new SurfaceData($mode);
    }
}

/** A string the path does not carry and nothing defaults. */
#[Page('/unbound', view: 'unbound', name: 'surface.unbound')]
final class UnboundPage
{
    public function __invoke(string $missing): SurfaceData
    {
        return new SurfaceData($missing);
    }
}

#[Page('/untyped/{value}', view: 'untyped', name: 'surface.untyped')]
final class UntypedParamPage
{
    /** @param mixed $value */
    public function __invoke($value): SurfaceData
    {
        return new SurfaceData(get_debug_type($value) . ':' . $value);
    }
}

#[Page('/ratio/{ratio}', view: 'ratio', name: 'surface.ratio')]
final class FloatParamPage
{
    public function __invoke(float $ratio): SurfaceData
    {
        return new SurfaceData(var_export($ratio, true));
    }
}

#[Page('/union/{id}', view: 'union', name: 'surface.union')]
final class UnionParamPage
{
    public function __invoke(int|string $id): SurfaceData
    {
        return new SurfaceData((string) $id);
    }
}

#[Page('/array', view: 'array', name: 'surface.array')]
final class ArrayParamPage
{
    /** @param array<mixed> $ids */
    public function __invoke(array $ids): SurfaceData
    {
        return new SurfaceData('x');
    }
}

#[Layout('/panels', view: 'panels/layout')]
final class OuterPanelLayout
{
    public function __invoke(): OuterPanel
    {
        return new OuterPanel('outer');
    }
}

#[Layout('/panels/inner', view: 'panels-inner/layout')]
final class InnerPanelLayout
{
    public function __invoke(): InnerPanel
    {
        return new InnerPanel('inner');
    }
}

/** Asks for the base class two layouts above it both satisfy. */
#[Page('/panels/inner/page', view: 'panels/page', name: 'surface.panels')]
final class AmbiguousPanelPage
{
    public function __invoke(SurfacePanel $panel): SurfaceData
    {
        return new SurfaceData($panel->label);
    }
}

/** "Loaders are optional" holds for a layout too. */
#[Layout('/bare', view: 'bare/layout')]
final class BareLayout {}

#[Page('/bare/page', view: 'bare/page', name: 'surface.bare')]
final class BarePage
{
    public function __invoke(): SurfaceData
    {
        return new SurfaceData('page');
    }
}

#[Page('/named/one', view: 'named/one', name: 'surface.dupe')]
final class FirstNamedPage {}

#[Page('/named/two', view: 'named/two', name: 'surface.dupe')]
final class SecondNamedPage {}

/**
 * @param list<class-string> $classes
 * @return array{0: Router, 1: Fixture}
 */
function surfaceRouter(array $classes, ?Gate $gate = null): array
{
    Seen::$models = [];
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));

    $registry = new RouteRegistry();
    $registry->registerAll($classes);

    $runner = new LoaderRunner($orm, static fn (string $c): object => new $c(), $gate ?? new AllowAllGate());

    return [new Router($registry, $runner, static fn (string $c): Middleware => new $c()), $fixture];
}

/** Runs one request and hands back what the loaders produced. */
function surfaceLoad(Router $router, string $path): LoadedRoute
{
    $loaded = null;
    $router->handle(Request::get($path), static function (LoadedRoute $l) use (&$loaded): Response {
        $loaded = $l;

        return Response::make();
    });

    \assert($loaded instanceof LoadedRoute);

    return $loaded;
}

function surfacePageValue(Router $router, string $path): string
{
    $page = surfaceLoad($router, $path)->page;
    \assert($page instanceof SurfaceData);

    return $page->value;
}

describe('route model binding', function () {
    it('resolves a model a layout and its page share once, as one instance', function () {
        [$router, $fixture] = surfaceRouter([SharedRackLayout::class, SharedRackPage::class]);
        $rack = $fixture->ids['racks'][0];

        $loaded = surfaceLoad($router, "/shared/{$rack}/show");

        expect(Seen::$models['layout'])->toBe(Seen::$models['page'])
            ->and($loaded->models)->toHaveKeys(['rack'])
            ->and($loaded->models['rack'])->toBe(Seen::$models['page']);
    });

    // Refused at registration now, from the layout's own template, rather
    // than on the first request under it (see UnfillableParametersTest).
    it('refuses a required model that no path under the layout carries', function () {
        expect(fn () => surfaceRouter([RequiredModelLayout::class, RequiredModelPage::class]))
            ->toThrow(RoutingException::class, "'/needs' has no {rack} segment");
    });

    it('hands a nullable model with no default null when the path does not carry it', function () {
        [$router] = surfaceRouter([NullableModelLayout::class, NullableModelPage::class]);

        $layout = surfaceLoad($router, '/maybe/list')->layouts['maybe'];

        expect($layout)->toBeInstanceOf(SurfaceData::class)
            ->and($layout->value)->toBe('none');
    });

    // The runner always resolves the chain first, so this is the binder's own
    // guard for a caller that skipped that step.
    // A route whose template carries the model, since one that does not is
    // now refused at registration and never reaches the binder.
    it('refuses to bind a required model nobody resolved', function () {
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $binder = new Binder(new Orm($fixture->connection, new IdentityMap('test')));
        $route = (new RouteRegistry())->register(SharedRackLayout::class);

        expect(fn () => $binder->argumentsFor($route, [], [], Request::get('/shared/x')))
            ->toThrow(RoutingException::class, "needs a 'rack' parameter");
    });
});

describe('binding loader arguments', function () {
    it('hands a loader the request being served', function () {
        [$router] = surfaceRouter([RequestPage::class]);

        expect(surfacePageValue($router, '/echo-request'))->toBe('/echo-request');
    });

    it('uses a parameter default when the path does not carry it', function () {
        [$router] = surfaceRouter([DefaultedPage::class]);

        expect(surfacePageValue($router, '/defaulted'))->toBe('full');
    });

    // Refused at registration now: the template has no segment for it, so
    // no request could ever fill it.
    it('refuses a parameter that is neither in the path, a model, layout data nor the request', function () {
        expect(fn () => surfaceRouter([UnboundPage::class]))
            ->toThrow(RoutingException::class, 'requires string $missing');
    });

    it('passes an untyped path parameter through as the raw string', function () {
        [$router] = surfaceRouter([UntypedParamPage::class]);

        expect(surfacePageValue($router, '/untyped/007'))->toBe('string:007');
    });

    it('casts a float path parameter', function (string $segment, string $expected) {
        [$router] = surfaceRouter([FloatParamPage::class]);

        expect(surfacePageValue($router, "/ratio/{$segment}"))->toBe($expected);
    })->with([
        'decimal' => ['1.5', '1.5'],
        'integer' => ['2', '2.0'],
        'negative' => ['-0.25', '-0.25'],
    ]);

    it('404s rather than erroring when a float parameter is not a number', function () {
        [$router] = surfaceRouter([FloatParamPage::class]);

        expect(fn () => surfaceLoad($router, '/ratio/abc'))
            ->toThrow(NotFoundException::class, 'not a number');
    });

    it('reads the spellings of a boolean path parameter, in any case', function (string $segment, string $expected) {
        [$router] = surfaceRouter([BoolParamPage::class]);

        expect(get_object_vars(surfaceLoad($router, "/flag/{$segment}")->page))->toBe(['tenant' => $expected]);
    })->with([
        ['1', 'yes'], ['true', 'yes'], ['yes', 'yes'], ['on', 'yes'], ['TRUE', 'yes'],
        ['0', 'no'], ['false', 'no'], ['no', 'no'], ['off', 'no'], ['Off', 'no'],
    ]);

    it('refuses to guess which of two matching layouts a loader means', function () {
        [$router] = surfaceRouter([OuterPanelLayout::class, InnerPanelLayout::class, AmbiguousPanelPage::class]);

        expect(fn () => surfaceLoad($router, '/panels/inner/page'))
            ->toThrow(RoutingException::class, 'which matches 2 layouts above it');
    });
});

describe('refusing an unbindable loader at registration', function () {
    it('refuses a parameter type the router could never bind', function (string $class, string $declared) {
        expect(fn () => (new RouteRegistry())->register($class))
            ->toThrow(RoutingException::class, "declares {$declared}, which the router cannot bind");
    })->with([
        'union' => [UnionParamPage::class, '$id as string|int'],
        'array' => [ArrayParamPage::class, '$ids as array'],
    ]);
});

describe('the registry', function () {
    it('refuses two routes sharing a name', function () {
        $registry = new RouteRegistry();
        $registry->register(FirstNamedPage::class);

        expect(fn () => $registry->register(SecondNamedPage::class))
            ->toThrow(
                RoutingException::class,
                "Two routes are named 'surface.dupe': " . FirstNamedPage::class . ' and ' . SecondNamedPage::class,
            );
    });

    // Request normalizes paths before they get here, but match() is public
    // and an empty segment must never bind as a parameter value.
    it('does not match an empty segment as a parameter', function () {
        $registry = new RouteRegistry();
        $registry->registerAll([SharedRackLayout::class, SharedRackPage::class]);

        expect($registry->match('/shared//show'))->toBeNull()
            ->and($registry->match('/shared/r1/show'))->not->toBeNull();
    });
});

describe('the loader runner', function () {
    it('runs a layout with no loader for its rules alone and publishes no data for it', function () {
        [$router] = surfaceRouter([BareLayout::class, BarePage::class]);

        $loaded = surfaceLoad($router, '/bare/page');

        expect($loaded->layouts)->toBe([])
            ->and(array_map(static fn (CompiledRoute $r): string => $r->class, $loaded->chain->layouts))
            ->toBe([BareLayout::class])
            ->and($loaded->page)->toBeInstanceOf(SurfaceData::class);
    });

    // Registration refuses a check with no subject, so this builds the route
    // by hand: the runner must still put such a check to the gate rather than
    // skip it.
    it('still enforces a check with no subject when registration is bypassed', function () {
        $route = new CompiledRoute(
            kind: RouteKind::Page,
            class: BarePage::class,
            template: '/bare/page',
            view: 'bare/page',
            name: 'surface.bare',
            segments: ['bare', 'page'],
            parameters: [],
            middleware: [],
            checks: [new Authorize('viewDashboard')],
        );
        $chain = new RouteChain($route, [], []);
        $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);
        $orm = new Orm($fixture->connection, new IdentityMap('test'));

        $allow = new AllowAllGate();
        (new LoaderRunner($orm, static fn (string $c): object => new $c(), $allow))->authorize($chain, []);

        expect($allow->checks)->toBe([['ability' => 'viewDashboard', 'argument' => null]])
            ->and(fn () => (new LoaderRunner($orm, static fn (string $c): object => new $c(), new DenyGate()))
                ->authorize($chain, []))
            ->toThrow(ForbiddenException::class);
    });
});
