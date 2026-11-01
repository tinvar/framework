<?php

declare(strict_types=1);

/**
 * The shop with TINVAR_RENDERER_USER set: the renderers run as a user of
 * their own (ADR 0028), and the shop holds every render and every probe of
 * a renderer to it.
 *
 * Fail closed: a user that is no one, root or the shop's own stops the
 * Kernel being built; and a renderer of the shop's own user -- here, the
 * only kind a test without root can start -- renders nothing, and the
 * visitor gets PHP's own 500 page, the refusal logged.
 */

namespace Tinvar\Tests\Integration\ShopRendererUser;

use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Render\RendererException;
use Tinvar\Render\RendererHealth;
use Tinvar\Render\RendererUser;
use Tinvar\Render\RenderFailure;

const KEY = 'shop-renderer-user-test-key-32-bytes!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** Runs $body with TINVAR_RENDERER_USER set to $user. */
function asRendererUser(string $user, \Closure $body): mixed
{
    $saved = getenv(RendererUser::ENV);
    putenv(RendererUser::ENV . "={$user}");
    try {
        return $body();
    } finally {
        putenv($saved === false ? RendererUser::ENV : RendererUser::ENV . "={$saved}");
    }
}

it('refuses to be built when the renderers\' user is no one, root, or the shop\'s own', function (\Closure $user, string $message) {
    // Run as root -- a CI job's container -- the shop's own user is root,
    // refused as root by the case before.
    if ($message === 'the user this process runs as' && \function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('run as root, the shop\'s own user is root, which the root case covers');
    }

    expect(fn () => asRendererUser($user(), fn (): Kernel => Kernel::fromEnvironment('/tmp/tinvar-renderer-user-none', key: KEY, database: Shop::database())))
        ->toThrow(\InvalidArgumentException::class, $message);
})->with([
    'no one' => [fn (): string => 'tinvar-no-such-user', 'there is no such user here'],
    'root' => [fn (): string => 'root', 'names root'],
    'the shop\'s own' => [fn (): string => (string) posix_geteuid(), 'the user this process runs as'],
]);

it('renders nothing through a renderer of its own user, and serves its own 500 page', function () {
    $renderer = Renderer::start('msgpack', 'renderer-user');
    $logged = [];

    try {
        [$response, $served] = asRendererUser('daemon', function () use ($renderer, &$logged): array {
            $kernel = Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(), log: function (\Throwable $e) use (&$logged): void {
                $logged[] = $e;
            });
            $response = $kernel->handle(new Request('GET', '/', ['host' => 'localhost', 'accept' => 'text/html']));

            return [$response, new RendererHealth()->check($renderer->socketPath)['served']];
        });
    } finally {
        $renderer->stop();
    }

    $refusal = array_values(array_filter($logged, static fn (\Throwable $e): bool => $e instanceof RendererException))[0] ?? null;
    expect($response->status)->toBe(500)
        ->and($response->body())->not->toContain('Refusing the renderer')
        ->and($served)->toBe(0)
        ->and($refusal)->toBeInstanceOf(RendererException::class)
        ->and($refusal?->failure())->toBe(RenderFailure::RendererUntrusted);
})->skip(fn () => !\function_exists('posix_geteuid') || posix_geteuid() === 0 || posix_getpwnam('daemon') === false, 'needs a user daemon, and not root');
