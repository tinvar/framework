<?php

declare(strict_types=1);

/**
 * The spec's panel-binding rule, for queries: "an action or query reachable
 * only from admin-panel pages with no panel binding fails the build."
 *
 * A query's reachability is the routes' query allowlists. One with no panel
 * binding that only routes in a panel may call was written for those pages,
 * and over /_query it ran behind no panel's stack at all. The Kernel is not
 * built with one now; the message names the panels to bind it to.
 */

namespace Tinvar\Tests\Panels\PanelOnlyQuery;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\BuildRules;
use Tinvar\Tests\Panels\Fixtures\Admin\AdminTotals;
use Tinvar\Tests\Panels\Fixtures\App;

require_once __DIR__ . '/Fixtures/app.php';
require_once __DIR__ . '/Fixtures/Admin/totals.php';

/** In no panel: no #[Panel], and outside every panel's namespace. */
#[Query(name: 'loose.totals'), NoAuthorization(reason: 'a fixture query')]
final class LooseTotals
{
    public function __invoke(): int
    {
        return 7;
    }
}

/** @param array<string, list<string>> $allowlists by route name */
function app(array $allowlists): App
{
    return new App(
        extraQueries: [LooseTotals::class, AdminTotals::class],
        allowedQueries: static fn (string $route): array => $allowlists[$route] ?? [],
    );
}

describe('a query with no panel binding', function () {
    // The dashboard is in the admin panel by its route group's #[Panel], and
    // reports by its namespace alone: both count as in it.
    it('is refused when every route that may call it is in a panel, naming the panel', function () {
        expect(fn () => app(['admin.dashboard' => ['loose.totals'], 'reports' => ['loose.totals']]))->toThrow(
            \InvalidArgumentException::class,
            '[' . BuildRules::PANEL_ONLY_QUERY_MUST_BE_BOUND . "] query 'loose.totals' (" . LooseTotals::class . '): has no '
            . "panel binding, and every route that may call it is in a panel: 'admin' (admin.dashboard, reports).",
        );
    });

    it('says how to bind it', function () {
        expect(fn () => app(['admin.dashboard' => ['loose.totals']]))
            ->toThrow(\InvalidArgumentException::class, "Bind it with #[Panel('admin')], or move it into that panel's namespace.");
    });

    // One query cannot be in two panels, so binding it to either would leave
    // the other's pages refused by the allowlist check.
    it('names every panel its routes are in, and says to split it when there are several', function () {
        $build = fn () => app(['admin.dashboard' => ['loose.totals'], 'reseller' => ['loose.totals']]);

        expect($build)->toThrow(
            \InvalidArgumentException::class,
            "in a panel: 'admin' (admin.dashboard), 'reseller' (reseller). Over /_query it runs behind no panel's stack",
        )->and($build)->toThrow(\InvalidArgumentException::class, 'A query is in one panel, so split it, one per panel');
    });

    it('passes when a route in no panel may call it too', function () {
        expect(app(['admin.dashboard' => ['loose.totals'], 'home' => ['loose.totals']]))->toBeInstanceOf(App::class);
    });

    // Reached over HTTP only, where its own #[Authorize] is what holds it.
    it('passes when no route may call it', function () {
        expect(app([]))->toBeInstanceOf(App::class);
    });
});

describe('a query bound to the panel', function () {
    it('passes, bound by its #[Panel]', function () {
        expect(app(['admin.dashboard' => ['admin.stats']]))->toBeInstanceOf(App::class);
    });

    it('passes, bound by its namespace', function () {
        expect(app(['admin.dashboard' => ['admin.totals'], 'reports' => ['admin.totals']]))->toBeInstanceOf(App::class);
    });
});
