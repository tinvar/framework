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

namespace Tinvar\Tests\Integration\ExampleComposition;

use Shop\Database\Seeder;
use Shop\Kernel;
use Shop\Models\Product;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Signer;
use Tinvar\Support\Uuid;

function compositionKernel(): Kernel
{
    $connection = PdoConnection::sqlite(':memory:', 'shop');
    new Seeder($connection)->run(customers: 2);

    // A socket that is not there: these tests never reach the renderer.
    return new Kernel($connection, '/tmp/tinvar-composition-none.sock', str_repeat('k', 32));
}

it('registers its routes through the registry that enforces the build rules', function () {
    $kernel = compositionKernel();

    expect($kernel->routes->named('shop.product')->template)->toBe('/products/{product}')
        ->and($kernel->routes->named('orders.show')->template)->toBe('/orders/{order}');
});

/**
 * The reset contract is the Kernel's, and the example inherits it: a
 * failure before anything renders still leaves the next request on this
 * worker with an empty identity map.
 */
it('clears the identity map after a request, even a failing one', function () {
    $kernel = compositionKernel();

    // Warm the map, then make a request that cannot reach the renderer.
    $kernel->orm->all(Product::class);

    try {
        $kernel->render(Request::get('/products/' . Uuid::v7()), static fn (string $b): null => null);
    } catch (\Throwable) {
        // No such product; the teardown is what is under test.
    }

    expect($kernel->orm->identityMap()->count())->toBe(0);
});

/**
 * Going through the Kernel means a thrown error is classified rather than
 * escaping. The example keeps the cause, so a benchmark still gets a
 * diagnostic instead of a generic 500 -- which is what a real application
 * does with its error handler.
 */
it('surfaces the cause of a failure rather than a classified 500', function () {
    $kernel = compositionKernel();

    $caught = null;

    try {
        $kernel->render(Request::get('/products/' . Uuid::v7()), static fn (string $b): null => null);
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
        expect(fn () => Kernel::fromEnvironment('/tmp/unused.sock', database: ':memory:'))
            ->toThrow(\InvalidArgumentException::class, 'at least 32 bytes');
    } finally {
        putenv('TINVAR_APP_KEY');
    }
});

// A typo in the throttle setting would otherwise leave the limit where it was
// and read as configured.
it('reads throttle limits from the environment, or refuses them', function () {
    expect(Kernel::limitsFrom(''))->toBe([])
        ->and(Kernel::limitsFrom('actions=600, sign-in.address=0'))->toBe(['actions' => 600, 'sign-in.address' => 0])
        ->and(fn () => Kernel::limitsFrom('actions=lots'))->toThrow(\InvalidArgumentException::class, 'bucket=limit');
});

// Signing stops a client inventing a session id. It does NOT stop fixation:
// the server hands a valid cookie to any request without one, so an attacker
// can plant the one it was given. What bounds that is the __Host- prefix
// (nothing outside this origin can set the cookie), the expiry under the
// MAC, and rotating the id on every privilege change.
describe('the example session cookie', function () {
    $key = str_repeat('k', 32);

    it('carries an id this key signed', function () use ($key) {
        $cookie = Kernel::newSession($key);

        expect(Kernel::sessionFrom($cookie, $key))->toBe(explode('.', $cookie)[0]);
    });

    it('refuses an id the client chose, or another key signed', function (?string $cookie) use ($key) {
        expect(Kernel::sessionFrom($cookie, $key))->toBeNull();
    })->with([
        'bare id' => [str_repeat('b', 32)],
        'forged mac' => [str_repeat('b', 32) . '.AAAA'],
        'other key' => [Kernel::newSession(str_repeat('x', 32))],
        'absent' => [null],
        'trailing newline' => [Kernel::newSession(str_repeat('k', 32)) . "\n"],
        // The format before the expiry was signed into it: accepted forever.
        'id and mac only' => [(static function (): string {
            $id = str_repeat('b', 32);

            return $id . '.' . Signer::encode(new Signer(str_repeat('k', 32), 'session-cookie')->sign($id));
        })()],
    ]);

    // It used to be good forever: whoever was handed one could plant it
    // in a victim's browser at any later date.
    it('refuses a cookie once it has expired', function () use ($key) {
        $issued = time() - Kernel::SESSION_LIFETIME;
        $cookie = Kernel::newSession($key, now: $issued);

        expect(Kernel::sessionFrom($cookie, $key, now: $issued + Kernel::SESSION_LIFETIME - 1))
            ->toBe(explode('.', $cookie)[0])
            ->and(Kernel::sessionFrom($cookie, $key, now: $issued + Kernel::SESSION_LIFETIME))->toBeNull()
            ->and(Kernel::sessionFrom($cookie, $key))->toBeNull();
    });

    it('refuses a cookie issued in the future', function () use ($key) {
        $cookie = Kernel::newSession($key, now: time() + 3600);

        expect(Kernel::sessionFrom($cookie, $key))->toBeNull();
    });

    // The expiry is under the MAC, so it cannot be pushed out.
    it('refuses a cookie whose expiry was edited', function () use ($key) {
        [$id, $issued, $expires, $mac] = explode('.', Kernel::newSession($key));

        expect(Kernel::sessionFrom("{$id}.{$issued}." . ((int) $expires + 3600) . ".{$mac}", $key))->toBeNull()
            ->and(Kernel::sessionFrom("{$id}.0{$issued}.{$expires}.{$mac}", $key))->toBeNull();
    });

    // Strict base64 ignores the spare low bits of the last character, so
    // decoding and comparing bytes accepted a second spelling of the MAC --
    // two cookies for one session, which defeats anything keyed on the
    // string.
    it('accepts one spelling of the MAC only', function () use ($key) {
        $cookie = Kernel::newSession($key);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $respelled = substr($cookie, 0, -1) . $alphabet[strpos($alphabet, $cookie[-1]) ^ 1];
        // Plain base64, not Signer::decode(), which refuses the second
        // spelling itself and so cannot say it names the same bytes.
        $raw = static fn (string $cookie): string|false => base64_decode(strtr(explode('.', $cookie)[3], '-_', '+/'), true);

        expect($raw($respelled))->toBe($raw($cookie), 'the same bytes, spelled differently')
            ->and(Kernel::sessionFrom($respelled, $key))->toBeNull();
    });

    // tinvar-spec.md, "HTTP": "Session and CSRF cookies use the __Host-
    // prefix and are HttpOnly (session), Secure and SameSite=Lax."
    it('is set as the spec cookie rule requires', function () use ($key) {
        $now = time();
        $cookie = Kernel::newSession($key, now: $now);
        $line = Kernel::withSession(Response::make(), $cookie, now: $now)->cookies()[Kernel::SESSION_COOKIE];

        expect(Kernel::SESSION_COOKIE)->toStartWith('__Host-')
            ->and($line)->toBe(Kernel::SESSION_COOKIE . "={$cookie}; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=" . Kernel::SESSION_LIFETIME)
            ->and($line)->not->toContain('Domain');
    });

    // Rotation on sign-in is what makes a planted id worthless at the
    // moment it would become valuable.
    it('rotates to a new id', function () use ($key) {
        $before = Kernel::newSession($key);
        $line = Kernel::rotateSession(Response::make(), $key)->cookies()[Kernel::SESSION_COOKIE];
        $after = explode(';', substr($line, \strlen(Kernel::SESSION_COOKIE) + 1))[0];

        expect(Kernel::sessionFrom($after, $key))->not->toBeNull()
            ->and(Kernel::sessionFrom($after, $key))->not->toBe(Kernel::sessionFrom($before, $key));
    });
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
        $key = Kernel::appKey();

        expect(trim((string) file_get_contents("{$home}/.local/state/tinvar/shop.key")))->toBe($key)
            ->and(is_dir(\dirname(__DIR__, 4) . '/examples/shop/storage'))->toBeFalse();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        removeKeyHome($home);
    }
});

function removeKeyHome(string $home): void
{
    foreach (["{$home}/.local/state/tinvar/shop.key", "{$home}/.local/state/tinvar/.shop.key.lock"] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (["{$home}/.local/state/tinvar", "{$home}/.local/state", "{$home}/.local", $home] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

// A relative path resolves against the starting directory, so the server
// and the CLI would each sign with a key of their own.
describe('where the example keeps a generated key', function () {
    $with = static function (array $env, \Closure $body): mixed {
        $saved = [];
        foreach ($env as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($value === null ? $name : "{$name}={$value}");
        }
        try {
            return $body();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    };

    it('refuses a relative TINVAR_APP_KEY_FILE', function () use ($with) {
        $with(['TINVAR_APP_KEY' => null, 'TINVAR_APP_KEY_FILE' => 'storage/app.key'], function () {
            expect(fn () => Kernel::appKey())->toThrow(\RuntimeException::class, 'absolute');
        });
    });

    it('ignores a relative XDG_STATE_HOME, as the spec says to', function () use ($with) {
        $home = sys_get_temp_dir() . '/tinvar-xdg-' . bin2hex(random_bytes(4));
        $with(['TINVAR_APP_KEY' => null, 'TINVAR_APP_KEY_FILE' => null, 'XDG_STATE_HOME' => 'relative/state', 'HOME' => $home], function () use ($home) {
            Kernel::appKey();

            expect(is_file("{$home}/.local/state/tinvar/shop.key"))->toBeTrue()
                ->and(is_dir('relative'))->toBeFalse();
        });
        removeKeyHome($home);
    });
});

// Loopback is no proof of being a probe: a proxy or TLS terminator on the
// same machine connects from it, and in a pod the renderers' sidecar, a
// mesh's proxy and `kubectl port-forward` all reach /_ready and /_metrics
// from 127.0.0.1 (ADR 0071). The token is the only way in unless
// TINVAR_PROBE_TRUST_LOOPBACK=1.
describe('who the shop lets ask its probes', function () {
    $token = str_repeat('t', 40);
    $ask = static fn (array $headers = []): Request => new Request('GET', '/_ready', $headers + ['host' => '127.0.0.1:8080'], remoteAddress: '127.0.0.1');
    $with = static function (array $env, \Closure $body): mixed {
        $saved = [];
        foreach ($env as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($value === null ? $name : "{$name}={$value}");
        }
        try {
            return $body();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    };

    it('holds loopback to the token unless told to trust it', function () use ($with, $ask, $token) {
        foreach ([null, '', '0'] as $trust) {
            $with(['TINVAR_PROBE_TRUST_LOOPBACK' => $trust, 'TINVAR_PROBE_TOKEN' => $token], function () use ($ask, $token) {
                $access = Kernel::probeAccess();

                expect($access->allows($ask()))->toBeFalse()
                    ->and($access->allows($ask(['authorization' => 'Bearer wrong-' . $token])))->toBeFalse()
                    ->and($access->allows($ask(['authorization' => "Bearer {$token}"])))->toBeTrue();
            });
        }
        $with(['TINVAR_PROBE_TRUST_LOOPBACK' => '1', 'TINVAR_PROBE_TOKEN' => $token], function () use ($ask) {
            expect(Kernel::probeAccess()->allows($ask()))->toBeTrue();
        });
    });

    // Nothing set, as in dev: the token is generated, private, beside the
    // app key, and the same one every boot -- not no token, and not loopback.
    it('generates a probe token beside the app key when none is given, and keeps it', function () use ($with, $ask) {
        $state = sys_get_temp_dir() . '/tinvar-probe-' . bin2hex(random_bytes(4));
        mkdir($state, 0o700);
        try {
            [$first, $again, $file, $access] = $with(
                ['TINVAR_PROBE_TRUST_LOOPBACK' => null, 'TINVAR_PROBE_TOKEN' => null, 'TINVAR_PROBE_TOKEN_FILE' => null, 'XDG_STATE_HOME' => $state],
                fn (): array => [Kernel::probeToken(), Kernel::probeToken(), Kernel::probeTokenFile(), Kernel::probeAccess()],
            );

            expect(\strlen($first))->toBeGreaterThanOrEqual(32)
                ->and($again)->toBe($first)
                ->and($file)->toBe("{$state}/tinvar/shop.probe-token")
                ->and(fileperms($file) & 0o077)->toBe(0)
                ->and($access->allows($ask()))->toBeFalse()
                ->and($access->allows($ask(['authorization' => "Bearer {$first}"])))->toBeTrue();
        } finally {
            exec('rm -rf ' . escapeshellarg($state));
        }
    });

    it('refuses a relative TINVAR_PROBE_TOKEN_FILE', function () use ($with) {
        $with(['TINVAR_PROBE_TOKEN' => null, 'TINVAR_PROBE_TOKEN_FILE' => 'storage/probe-token'], function () {
            expect(fn () => Kernel::probeToken())->toThrow(\RuntimeException::class, 'absolute');
        });
    });

    it('refuses a value it does not know rather than guess', function () use ($with) {
        foreach (['false', 'no', 'off', ' 0'] as $value) {
            $with(['TINVAR_PROBE_TRUST_LOOPBACK' => $value], function () use ($value) {
                expect(fn () => Kernel::probeAccess())->toThrow(\InvalidArgumentException::class, 'TINVAR_PROBE_TRUST_LOOPBACK');
            });
        }
    });
});
