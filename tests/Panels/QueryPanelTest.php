<?php

declare(strict_types=1);

/**
 * A query declared for a panel, called from a render.
 *
 * A query's #[Panel] was enforced only on an HTTP call to /_query; from a
 * render it ran behind its page's panel -- "the page request's middleware
 * for renders". So a page in a looser panel, or in none, whose allowlist
 * named a query declared for a stricter one ran that query past every check
 * the query declared. Refused twice now: a route allowlist the Kernel is
 * shown is held to the panels of the queries it names when the Kernel is
 * built, and each call from a render is held to the query's panel, failing
 * alone.
 */

namespace Tinvar\Tests\Panels\QueryPanel;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Query\QueryError;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Tests\Panels\Fixtures\AdminRefunds;
use Tinvar\Tests\Panels\Fixtures\App;
use Tinvar\Tests\Panels\Fixtures\HostPanel;
use Tinvar\Tests\Panels\Fixtures\SignIn;
use Tinvar\Tests\Panels\Fixtures\Trace;

require_once __DIR__ . '/Fixtures/app.php';

/** @param array<string, list<string>> $allowlists by route name */
function app(array $allowlists = [], array $global = [SignIn::class]): App
{
    return new App(
        global: $global,
        extraQueries: [AdminRefunds::class],
        allowedQueries: static fn (string $route): array => $allowlists[$route] ?? [],
    );
}

/**
 * Calls the named queries from inside a render of $path, as the render
 * channel does, through a runner held to the Kernel's panel check unless
 * told otherwise.
 *
 * @param list<string> $names
 * @param array<string, string> $headers
 * @return array<string, array<string, mixed>> each call's result, by query name
 */
function renderCalls(App $app, string $path, string $user, array $names, bool $held = true, array $headers = []): array
{
    $runner = new QueryRunner($app->queries, $app->orm, new AllowAllGate(), panels: $held ? $app->kernel->queryPanelRefusal(...) : null);
    $results = [];

    $response = $app->kernel->handle(
        new Request('GET', $path, ['host' => 'shop.test', 'x-user' => $user, ...$headers]),
        static function (LoadedRoute $loaded, Request $passed) use ($runner, $names, &$results): Response {
            $calls = array_map(static fn (int $i, string $name): array => ['id' => $i, 'name' => $name, 'args' => []], array_keys($names), $names);
            foreach ($runner->run($calls, $names) as $result) {
                $results[$names[$result['id']]] = $result;
            }

            return Response::make(200, '');
        },
    );
    expect($response->status)->toBe(200, $response->body());

    return $results;
}

describe('a route allowlist, when the Kernel is built', function () {
    it('refuses a page in no panel that may call a query in the admin panel', function () {
        expect(fn () => app(['home' => ['admin.stats']]))
            ->toThrow(\InvalidArgumentException::class, "Route 'home' may call query 'admin.stats', which is in panel 'admin'");
    });

    it('refuses a page in another panel', function () {
        expect(fn () => app(['reseller' => ['admin.stats']]))->toThrow(\InvalidArgumentException::class, "in panel 'reseller'");
    });

    // In the panel is not enough: the page has to have been held to every
    // permission the query needs.
    it('refuses an admin page not held to a permission the query needs', function () {
        expect(fn () => app(['admin.dashboard' => ['admin.refunds']]))
            ->toThrow(\InvalidArgumentException::class, "needing 'orders.refund', 'admin.access'; the route is in panel 'admin' needing 'admin.access'");
    });

    it('accepts a page held to everything its queries need', function () {
        expect(app(['admin.orders' => ['admin.stats'], 'admin.dashboard' => ['admin.stats']]))->toBeInstanceOf(App::class);
    });
});

describe('a call from a render', function () {
    it('is refused, alone and without running, when the user lacks a permission the query needs', function () {
        $app = app();

        $results = renderCalls($app, '/admin/dashboard', 'clerk', ['admin.refunds', 'admin.stats']);

        expect($results['admin.refunds']['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN)
            ->and($results['admin.stats']['value'] ?? null)->toBe(42)
            ->and(Trace::$events)->not->toContain('query:refunds');
    });

    // The page's check did not cover the query's binding, so the membership
    // is checked again, against the query's.
    it('runs for a member holding it, checked against the query\'s binding', function () {
        $app = app();

        $results = renderCalls($app, '/admin/dashboard', 'staff', ['admin.refunds']);

        expect($results['admin.refunds']['value'] ?? null)->toBe('refunds')
            ->and($app->directory->asked)->toBe([['staff', 'admin', ''], ['staff', 'admin', '']]);
    });

    it('asks nothing more when the page was held to everything the query needs', function () {
        $app = app();

        $results = renderCalls($app, '/admin/orders', 'staff', ['admin.stats']);

        expect($results['admin.stats']['value'] ?? null)->toBe(42)
            ->and($app->directory->asked)->toBe([['staff', 'admin', '']]);
    });

    // The query's stack -- guard, MFA -- never ran for a request in another
    // panel, and a membership cannot stand in for it.
    it('is refused from a page in no panel, whoever is signed in', function () {
        $results = renderCalls(app(), '/home', 'staff', ['admin.stats']);

        expect($results['admin.stats']['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN)
            ->and(Trace::$events)->not->toContain('query');
    });

    // A panel the application's own middleware chose, with no membership
    // checked for it: the query's binding is checked on the call.
    it('checks the membership when the request is in the panel without having been held to it', function () {
        $app = app(global: [SignIn::class, HostPanel::class]);

        $staff = renderCalls($app, '/home', 'staff', ['admin.stats'], headers: ['x-panel' => 'admin']);
        $customer = renderCalls($app, '/home', 'customer', ['admin.stats'], headers: ['x-panel' => 'admin']);

        expect($staff['admin.stats']['value'] ?? null)->toBe(42)
            ->and($customer['admin.stats']['error']['code'] ?? null)->toBe(QueryError::FORBIDDEN);
    });

    it('is refused by a runner with no way to hold it to the query\'s panel', function () {
        $results = renderCalls(app(), '/admin/orders', 'staff', ['admin.stats'], held: false);

        expect($results['admin.stats']['error']['code'] ?? null)->toBe(QueryError::MISCONFIGURED);
    });
});
