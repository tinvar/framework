<?php

declare(strict_types=1);

/**
 * The shop's build keeps the admin panel's client code to the panel: what
 * panelChunks() wrote beside the rsc entry, held to the routes' panels, and
 * the customer's download -- the client entry and every client reference
 * outside the panel -- naming none of the panel's chunks.
 */

namespace Tinvar\Tests\Integration\ShopPanelBundles;

use Shop\Kernel;
use Tinvar\Bench\Shop;
use Tinvar\Render\PanelBundles;
use Tinvar\Routing\RouteRegistry;

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** @return array<string, mixed> */
function report(): array
{
    return json_decode((string) file_get_contents(Shop::root() . '/dist/rsc/panels.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The renderer's assets manifest: what each client reference, and the
 * entry, load in the browser.
 *
 * @return array{clientEntryDeps: array{js: list<string>}, clientReferenceDeps: array<string, array{js: list<string>}>}
 */
function assets(): array
{
    $module = (string) file_get_contents(Shop::root() . '/dist/ssr/__vite_rsc_assets_manifest.js');

    return json_decode(rtrim(trim(substr($module, \strlen('export default'))), ';'), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the admin panel\'s views, as the routes put them, to chunks of the panel\'s own', function () {
    $routes = new RouteRegistry();
    $routes->registerAll(Kernel::classes()->routes);
    $report = report();

    expect($report['panels']['admin']['views'])->toBe(['admin/deliveries', 'admin/layout', 'admin/order-activity', 'admin/orders', 'admin/resources/form', 'admin/resources/index', 'admin/resources/show', 'admin/staff', 'admin/staff-activity', 'admin/transfers'])
        ->and(fn () => PanelBundles::check($routes, Kernel::panels(), Shop::root() . '/dist/rsc/panels.json'))
        ->not->toThrow(\RuntimeException::class)
        ->and(array_intersect($report['panels']['admin']['chunks'], $report['shared']))->toBe([]);
});

// The customer's download: the entry, and every client reference the
// panel does not own. None of it is the panel's.
it('ships no admin chunk to a customer\'s page', function () {
    $admin = array_map(static fn (string $file): string => "/{$file}", report()['panels']['admin']['chunks']);
    $assets = assets();
    $customer = $assets['clientEntryDeps']['js'];
    foreach ($assets['clientReferenceDeps'] as $deps) {
        if (array_intersect($deps['js'], $admin) === []) {
            $customer = [...$customer, ...$deps['js']];
        }
    }

    expect(array_intersect(array_unique($customer), $admin))->toBe([])
        ->and(array_intersect($assets['clientEntryDeps']['js'], $admin))->toBe([]);
});

// A build whose report no longer names the admin view -- the Vite config
// and the routes drifted apart -- is refused by `tinvar build:fragments`.
it('refuses, in build:fragments, a build whose panels disagree with the routes', function () {
    $root = \dirname(__DIR__, 4);
    $build = sys_get_temp_dir() . '/tinvar-shop-panels-' . bin2hex(random_bytes(4));
    mkdir("{$build}/rsc", 0o700, true);
    copy(Shop::root() . '/dist/rsc/deferred.json', "{$build}/rsc/deferred.json");
    file_put_contents("{$build}/rsc/panels.json", json_encode(['format' => 1, 'panels' => ['admin' => ['views' => [], 'references' => [], 'chunks' => []]], 'shared' => []], JSON_THROW_ON_ERROR));

    try {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/bin/tinvar") . ' build:fragments --build=' . escapeshellarg($build) . ' 2>&1', $output, $status);
        $written = is_file("{$build}/fragments.json");
    } finally {
        exec('rm -rf ' . escapeshellarg($build));
    }

    expect($status)->toBe(1)
        ->and(implode("\n", $output))->toContain("its view 'admin/orders' is in no panel's directory")
        ->and($written)->toBeFalse();
});
