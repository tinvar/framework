<?php

declare(strict_types=1);

/**
 * The example app goes through the framework's composition root.
 *
 * It did not, and that is the single reason the phase reviews kept finding
 * unwired guarantees: the only application in the repository matched a route
 * and called LoaderRunner directly, so nothing in it exercised global
 * middleware, the reset contract, the throttle or exception classification.
 * Both sides of every seam were faked in unit tests, and the one place that
 * composed anything real went around the thing being composed.
 *
 * These assert the wiring without needing the renderer, so they run in CI
 * whether or not the app is built.
 */

use Racks\Kernel;
use Tinvar\Http\NotFoundException;
use Tinvar\Orm\PdoConnection;

function compositionKernel(): Kernel
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE sites (id TEXT PRIMARY KEY, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE racks (id TEXT PRIMARY KEY, name TEXT NOT NULL, height_u INTEGER NOT NULL, site_id TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE units (id TEXT PRIMARY KEY, position INTEGER NOT NULL, name TEXT NOT NULL, rated_watts INTEGER NOT NULL, rack_id TEXT NOT NULL)');

    // A socket that is not there: these tests never reach the renderer.
    return new Kernel(new PdoConnection($pdo, 'racks'), '/tmp/tinvar-composition-none.sock');
}

it('registers its routes through the registry that enforces the build rules', function () {
    $kernel = compositionKernel();

    expect($kernel->routes->named('racks.show')->template)->toBe('/racks/{rack}');
});

/**
 * The reset contract is the Kernel's, and the example now inherits it. It
 * used to flush the identity map by hand at the top of showRack, which is
 * the same thing done in the wrong place -- a failure before that line left
 * the previous request's map in place.
 */
it('clears the identity map after a request, even a failing one', function () {
    $kernel = compositionKernel();

    // Warm the map, then make a request that cannot reach the renderer.
    $kernel->orm->all(Racks\Models\Site::class);

    try {
        $kernel->showRack('no-such-rack', static fn (string $b): null => null);
    } catch (\Throwable) {
        // The renderer is not there; the teardown is what is under test.
    }

    expect($kernel->orm->identityMap()->count())->toBe(0);
});

/**
 * Going through the Kernel means a thrown error is classified rather than
 * escaping. The example keeps the cause by logging it, so a benchmark still
 * gets a diagnostic instead of a generic 500 -- which is what a real
 * application does with its error handler.
 */
it('surfaces the cause of a failure rather than a classified 500', function () {
    $kernel = compositionKernel();

    $caught = null;

    try {
        $kernel->showRack('no-such-rack', static fn (string $b): null => null);
    } catch (\Throwable $e) {
        $caught = $e;
    }

    // The real cause -- a model the route could not bind -- not the
    // ExceptionHandler's generic reason, and not a 500 page.
    expect($caught)->toBeInstanceOf(NotFoundException::class)
        ->and($caught->getMessage())->not->toBe('Something went wrong.');
});
