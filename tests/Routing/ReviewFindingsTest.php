<?php

declare(strict_types=1);

/**
 * Regressions for the routing review of eab3488.
 *
 * Each case here is a defect two reviewers reproduced against the working
 * tree, not a hypothetical.
 */

use Tinvar\Auth\AllowAllGate;
use Tinvar\Http\Middleware;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Routing\LoaderRunner;
use Tinvar\Routing\Router;
use Tinvar\Routing\RouteRegistry;
use Tinvar\Routing\RoutingException;
use Tinvar\Tests\Orm\Fixtures\Fixture;
use Tinvar\Tests\Routing\Fixtures as F;

require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/models.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/schema.php';
require_once __DIR__ . '/Fixtures/routes.php';

function registryOf(array $classes): RouteRegistry
{
    $registry = new RouteRegistry();
    $registry->registerAll($classes);

    return $registry;
}

/** @return array{0: Router, 1: Fixture} */
function routerOf(array $classes, array $global = []): array
{
    F\Trace::reset();
    $fixture = new Fixture(racks: 2, unitsPerRack: 1, sites: 1);
    $orm = new Orm($fixture->connection, new IdentityMap('test'));
    $runner = new LoaderRunner($orm, static fn (string $c): object => new $c(), new AllowAllGate());

    return [new Router(registryOf($classes), $runner, static fn (string $c): Middleware => new $c(), $global), $fixture];
}

function okResponder(): Closure
{
    return static fn (LoadedRoute $l, Request $r): Response => Response::make(200, json_encode([
        'page' => $l->page === null ? null : get_object_vars($l->page),
        'layouts' => array_keys($l->layouts),
    ]));
}

// HIGH: a page spelling the shared parameter differently escaped the layout
// entirely, and with it the layout's #[Authorize]. Fails open on a typo.
// Refused at registration now, not on the first match (see
// ParameterNamesAtRegistrationTest), so the registry is never built.
it('refuses a page that renames a parameter its layout declares', function () {
    expect(fn () => registryOf([F\SiteLayout::class, F\MismatchedParameterPage::class]))
        ->toThrow(RoutingException::class, 'names the parameter at position');
});

it('still wraps a page that spells the parameter the same way', function () {
    $chain = registryOf([F\SiteLayout::class, F\RackShowPage::class])->match('/sites/abc/racks/x');

    expect(array_map(static fn ($l) => $l->class, $chain->layouts))->toBe([F\SiteLayout::class]);
});

// HIGH: two layouts whose views share a folder both keyed to it, and the outer
// layout's data vanished from LoadedRoute with no error.
it('refuses two layouts that would publish under one key', function () {
    expect(fn () => registryOf([F\SiteLayout::class, F\CollidingKeyLayout::class]))
        ->toThrow(RoutingException::class, 'publish their data under');
});

// MEDIUM: the global stack was built from the matched chain, so a miss skipped
// auth, throttling, security headers and logging entirely.
it('runs global middleware even when nothing matches', function () {
    [$router] = routerOf([F\HealthPage::class], global: [F\OuterMiddleware::class]);

    expect(fn () => $router->handle(Request::get('/does/not/exist'), okResponder()))
        ->toThrow(NotFoundException::class);

    expect(F\Trace::$events)->toContain('middleware:outer');
});

it('lets global middleware refuse before the route is even looked up', function () {
    [$router] = routerOf([F\HealthPage::class], global: [F\BlockingMiddleware::class]);

    $response = $router->handle(Request::get('/does/not/exist'), okResponder());

    expect($response->status)->toBe(503);
});

// MEDIUM: "by type" meant exact class, so a parameter typed as a base class
// did not resolve.
it('resolves a parent Data parameter by instanceof, not by exact class', function () {
    [$router] = routerOf([F\PanelLayout::class, F\PanelShowPage::class]);

    $body = json_decode($router->handle(Request::get('/panel/show'), okResponder())->body(), true);

    expect($body['page']['tenant'])->toBe('panel');
});

it('refuses two layouts returning the same Data class', function () {
    [$router] = routerOf([F\DupeOuterLayout::class, F\DupeInnerLayout::class, F\DupePage::class]);

    expect(fn () => $router->handle(Request::get('/dupe/inner/page'), okResponder()))
        ->toThrow(RoutingException::class, 'already returned');
});

// MEDIUM: precedence depended on registration order when literal counts tied.
it('ranks routes the same whichever order they register in', function () {
    $forwards = registryOf([F\AmbiguousLiteralFirstPage::class, F\AmbiguousParamFirstPage::class]);
    $backwards = registryOf([F\AmbiguousParamFirstPage::class, F\AmbiguousLiteralFirstPage::class]);

    expect($forwards->match('/amb/new/edit')->page->class)
        ->toBe($backwards->match('/amb/new/edit')->page->class)
        ->toBe(F\AmbiguousLiteralFirstPage::class, 'the leftmost literal wins');
});

// MEDIUM: an unbindable loader parameter surfaced as a TypeError on the first
// request to that route.
it('refuses an unbindable loader parameter at registration', function () {
    expect(fn () => registryOf([F\UnbindablePage::class]))
        ->toThrow(RoutingException::class, 'which the router cannot bind');
});

it('refuses a decoded control character in a path segment', function () {
    // The raw check cannot see %0a, and a decoded newline would reach logs
    // through a parameter value.
    expect(fn () => Request::normalizePath('/sites/a%0aSet-Cookie:%20x/racks/y'))
        ->toThrow(NotFoundException::class);
});

it('does not put a client-supplied id in a not-found message', function () {
    $message = NotFoundException::model('Rack', "evil\r\nFAKE LOG LINE")->getMessage();

    expect($message)->not->toContain('FAKE LOG LINE')->toContain('Rack');
});

it('binds a bool path parameter, and 404s on a value that is not one', function () {
    [$router] = routerOf([F\BoolParamPage::class]);

    expect(json_decode($router->handle(Request::get('/flag/true'), okResponder())->body(), true)['page']['tenant'])
        ->toBe('yes');

    expect(fn () => $router->handle(Request::get('/flag/maybe'), okResponder()))
        ->toThrow(NotFoundException::class);
});

it('binds a backed enum path parameter, and 404s outside the enum', function () {
    [$router] = routerOf([F\EnumParamPage::class]);

    expect(json_decode($router->handle(Request::get('/shade/dark'), okResponder())->body(), true)['page']['tenant'])
        ->toBe('dark');

    expect(fn () => $router->handle(Request::get('/shade/puce'), okResponder()))
        ->toThrow(NotFoundException::class);
});

// MEDIUM: a nullable or defaulted model parameter was treated as mandatory.
it('allows a layout to declare a model the current path does not carry', function () {
    [$router] = routerOf([F\OptionalModelLayout::class, F\OptionalListPage::class]);

    $body = json_decode($router->handle(Request::get('/optional/list'), okResponder())->body(), true);

    expect($body['page']['tenant'])->toBe('none');
});

describe('response hardening', function () {
    it('refuses a header value carrying CR or LF', function () {
        expect(fn () => Response::make()->withHeader('x-thing', "a\r\nSet-Cookie: admin=1"))
            ->toThrow(InvalidArgumentException::class, 'control character');
    });

    it('refuses a header name that is not a token', function () {
        expect(fn () => Response::make()->withHeader("x thing", 'a'))
            ->toThrow(InvalidArgumentException::class);
    });

    // Browsers treat /\host the same as //host, so checking for // alone is
    // not enough.
    it('refuses an off-origin redirect', function (string $target) {
        expect(fn () => Response::redirect($target))->toThrow(InvalidArgumentException::class);
    })->with([
        'absolute' => ['https://evil.example/'],
        'scheme-relative' => ['//evil.example/'],
        'backslash-relative' => ['/\\evil.example/'],
        'backslash anywhere' => ['/path\\evil'],
        'relative' => ['x'],
        'userinfo' => ['/\\\\evil.example'],
        // Refused by the origin check itself, not only by withHeader: a
        // guarantee that depends on which path calls it is not a guarantee.
        'CRLF' => ["/ok\r\nSet-Cookie: a=1"],
        'tab' => ["/\t//evil.example"],
        'null byte' => ["/ok\x00"],
        'embedded space' => ['/a //evil.example'],
        'traversal' => ['/..//evil.example'],
    ]);

    it('allows an ordinary same-origin path', function () {
        expect(Response::redirect('/racks/0192f3a1?tab=power')->header('location'))
            ->toBe('/racks/0192f3a1?tab=power');
    });

    it('allows an allowlisted external target through location()', function () {
        expect(Response::location('https://partner.example/sso')->header('location'))
            ->toBe('https://partner.example/sso');
    });

    it('treats Vary values case-insensitively', function () {
        expect(Response::make(200, '', ['vary' => 'rsc'])->withAddedHeader('vary', 'RSC')->header('vary'))
            ->toBe('rsc');
    });

    it('does not re-run a streaming producer on a second read', function () {
        $runs = 0;
        $response = Response::stream(function (Closure $sink) use (&$runs): void {
            $runs++;
            $sink('chunk');
        });

        expect($response->body())->toBe('chunk')
            ->and($response->body())->toBe('chunk')
            ->and($runs)->toBe(1);
    });
});
