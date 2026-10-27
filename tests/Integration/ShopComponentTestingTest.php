<?php

declare(strict_types=1);

/**
 * The JavaScript half of the testing layer stays out of the shop's build
 * (ADR 0061). Its mocks answer for queries and actions without running
 * them -- an action that does nothing and says it succeeded -- so no
 * environment of the build may hold the runtime, the mocks or the fakes.
 * `queryFunctions()` fails a build whose graph reaches them; this reads the
 * build that was made, every file of it.
 */

namespace Tinvar\Tests\Integration\ShopComponentTesting;

use Tinvar\Bench\Shop;

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

it('is in no build of the shop', function () {
    $dist = Shop::root() . '/dist';
    $markers = ['tinvar:testing-runtime', '@tinvar/react/testing', 'fakeActionResult', 'fakeProductCardData', 'mockQuery', 'mockAction'];
    $found = [];
    $scanned = 0;
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile() || !\in_array($file->getExtension(), ['js', 'mjs', 'html', 'json', 'map'], true)) {
            continue;
        }
        $scanned++;
        $contents = (string) file_get_contents($file->getPathname());
        foreach ($markers as $marker) {
            if (str_contains($contents, $marker)) {
                $found[] = "{$file->getPathname()}: {$marker}";
            }
        }
    }

    expect($scanned)->toBeGreaterThan(5)
        ->and(is_dir("{$dist}/client"))->toBeTrue()
        ->and(is_dir("{$dist}/rsc"))->toBeTrue()
        ->and(is_dir("{$dist}/ssr"))->toBeTrue()
        ->and($found)->toBe([]);
});

it('has a mock beside every module the build resolves @queries and @actions to', function () {
    $types = Shop::root() . '/resources/types';
    $queries = json_decode((string) file_get_contents("{$types}/queries.json"), true, 8, JSON_THROW_ON_ERROR);
    \assert(\is_array($queries) && \is_array($queries['modules']));

    $missing = [];
    foreach (array_keys($queries['modules']) as $module) {
        $file = $module === '@queries' ? 'queries.ts' : 'queries/' . substr((string) $module, \strlen('@queries/')) . '.ts';
        if (!is_file("{$types}/testing/{$file}")) {
            $missing[] = $module;
        }
    }
    foreach (glob("{$types}/actions/*.ts") ?: [] as $actions) {
        if (!is_file("{$types}/testing/actions/" . basename($actions))) {
            $missing[] = '@actions/' . basename($actions, '.ts');
        }
    }

    expect($missing)->toBe([])
        ->and(is_file("{$types}/testing/fakes.ts"))->toBeTrue();
});
