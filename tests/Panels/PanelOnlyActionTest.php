<?php

declare(strict_types=1);

/**
 * The spec's panel-binding rule, for actions (ADR 0095): "an action or query
 * reachable only from admin-panel pages with no panel binding fails the
 * build."
 *
 * Which pages call an action is the renderer build's to say, read from each
 * page's source and its client components into the fragment manifest. An
 * action with no panel binding that only pages in a panel call was written
 * for them, and over /_action it ran behind no panel's stack at all. The
 * Kernel is not built with one now.
 */

namespace Tinvar\Tests\Panels\PanelOnlyAction;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Panel;
use Tinvar\Auth\BuildRules;
use Tinvar\Render\FragmentManifest;
use Tinvar\Tests\Panels\Fixtures\App;

require_once __DIR__ . '/Fixtures/app.php';

/** In no panel: no #[Panel], and outside every panel's namespace. */
final class LooseActions
{
    #[Action(name: 'loose.export'), NoAuthorization(reason: 'a fixture action')]
    public function export(): ActionResult
    {
        return ActionResult::ok('exported');
    }

    #[Action(name: 'loose.bound'), NoAuthorization(reason: 'a fixture action'), Panel('admin')]
    public function bound(): ActionResult
    {
        return ActionResult::ok('bound');
    }
}

/** @param array<string, list<string>>|null $callers by route name; null for a manifest the build did not fill */
function app(?array $callers): App
{
    $manifest = new FragmentManifest();
    foreach ($callers ?? [] as $route => $actions) {
        $manifest->declareActions($route, $actions);
    }

    return new App(manifest: $manifest, extraActions: [LooseActions::class]);
}

describe('an action with no panel binding', function () {
    it('is refused when every page that calls it is in a panel, naming the panel and the pages', function () {
        expect(fn () => app(['admin.dashboard' => ['loose.export'], 'reports' => ['loose.export']]))->toThrow(
            \InvalidArgumentException::class,
            '[' . BuildRules::PANEL_ONLY_ACTION_MUST_BE_BOUND . "] action 'loose.export' (" . LooseActions::class . '::export()): '
            . "has no panel binding, and every page that calls it is in a panel: 'admin' (admin.dashboard, reports).",
        );
    });

    it('says how to bind it, or to split it across panels', function () {
        expect(fn () => app(['admin.dashboard' => ['loose.export']]))
            ->toThrow(\InvalidArgumentException::class, "Bind it with #[Panel('admin')]")
            ->and(fn () => app(['admin.dashboard' => ['loose.export'], 'reseller' => ['loose.export']]))
            ->toThrow(\InvalidArgumentException::class, 'An action is in one panel, so split it');
    });

    it('passes when a page in no panel calls it too', function () {
        expect(app(['admin.dashboard' => ['loose.export'], 'home' => ['loose.export']]))->toBeInstanceOf(App::class);
    });

    it('passes when no page calls it, as when the build did not say', function () {
        expect(app([]))->toBeInstanceOf(App::class)
            ->and(app(null))->toBeInstanceOf(App::class);
    });
});

describe('an action bound to the panel', function () {
    it('passes, bound by its #[Panel]', function () {
        expect(app(['admin.dashboard' => ['loose.bound']]))->toBeInstanceOf(App::class);
    });
});

describe('the manifest', function () {
    it('keeps each route\'s actions through the file, and knows when the build said none', function () {
        $path = sys_get_temp_dir() . '/tinvar-actions-' . bin2hex(random_bytes(4)) . '.json';
        try {
            new FragmentManifest()->declareActions('home', ['b', 'a', 'b'])->write($path);
            $read = FragmentManifest::fromFile($path);

            new FragmentManifest()->write($path);
            $none = FragmentManifest::fromFile($path);
        } finally {
            @unlink($path);
        }

        expect($read->actionsFor('home'))->toBe(['a', 'b'])
            ->and($read->knowsActions())->toBeTrue()
            ->and($read->actionsFor('elsewhere'))->toBe([])
            ->and($none->knowsActions())->toBeFalse();
    });
});
