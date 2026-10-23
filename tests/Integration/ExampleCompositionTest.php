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

// Every token is an offline oracle for its key, so a short one is refused.
it('refuses a short app key rather than signing with it', function () {
    putenv('TINVAR_APP_KEY=short');
    try {
        expect(fn () => Racks\Kernel::fromEnvironment('/tmp/unused.sock'))
            ->toThrow(InvalidArgumentException::class, 'at least 32 bytes');
    } finally {
        putenv('TINVAR_APP_KEY');
    }
});

// A client that could pick its own session id could fix a victim's session
// -- planting the cookie from a sibling subdomain -- and share its tokens.
describe('the example session cookie', function () {
    $key = str_repeat('k', 32);

    it('carries an id this key signed', function () use ($key) {
        $cookie = Racks\Kernel::newSession($key);

        expect(Racks\Kernel::sessionFrom($cookie, $key))->toBe(explode('.', $cookie)[0]);
    });

    it('refuses an id the client chose, or another key signed', function (?string $cookie) use ($key) {
        expect(Racks\Kernel::sessionFrom($cookie, $key))->toBeNull();
    })->with([
        'bare id' => [str_repeat('b', 32)],
        'forged mac' => [str_repeat('b', 32) . '.AAAA'],
        'other key' => [Racks\Kernel::newSession(str_repeat('x', 32))],
        'absent' => [null],
        'trailing newline' => [Racks\Kernel::newSession(str_repeat('k', 32)) . "\n"],
    ]);
});

// Never under the project: node_modules holds a workspace symlink to the
// example, and the renderer's sandbox checks paths as requested, so a key
// anywhere in the tree was readable through it.
it('generates the app key into the user state directory, outside the project', function () {
    $home = sys_get_temp_dir() . '/tinvar-home-' . bin2hex(random_bytes(4));
    $saved = ['TINVAR_APP_KEY_FILE' => getenv('TINVAR_APP_KEY_FILE'), 'XDG_STATE_HOME' => getenv('XDG_STATE_HOME'), 'HOME' => getenv('HOME')];
    putenv('TINVAR_APP_KEY_FILE');
    putenv('XDG_STATE_HOME');
    putenv("HOME={$home}");

    try {
        $key = Racks\Kernel::appKey();

        expect(trim((string) file_get_contents("{$home}/.local/state/tinvar/racks.key")))->toBe($key)
            ->and(is_dir(\dirname(__DIR__, 4) . '/examples/racks/storage'))->toBeFalse();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        @unlink("{$home}/.local/state/tinvar/racks.key");
        @rmdir("{$home}/.local/state/tinvar");
        @rmdir("{$home}/.local/state");
        @rmdir("{$home}/.local");
        @rmdir($home);
    }
});
