<?php

declare(strict_types=1);

/**
 * Support's classes by the names they had before the split (ADR 0055):
 * `Tinvar\Attributes\Dependency`, now `Tinvar\Container\Dependency`, and
 * `Tinvar\Data\Lazy`, now `Tinvar\Support\Lazy`, kept as aliases for one
 * release. The container reads the old attribute as the new one.
 */

namespace Tinvar\Tests\Container\Renamed;

use Tinvar\Container\Container;
use Tinvar\Container\Lifetime;
use Tinvar\Support\Lazy;

final class Settings {}

#[\Tinvar\Attributes\Dependency(Lifetime::Worker)]
final class SharedPool
{
    public function __construct(public Settings $settings) {}
}

final class WithInjected
{
    #[\Tinvar\Attributes\Dependency]
    public SharedPool $pool;
}

/** @param \Closure(): mixed $body */
function quietly(\Closure $body): mixed
{
    set_error_handler(static fn (int $level): bool => $level === \E_USER_DEPRECATED);

    try {
        return $body();
    } finally {
        restore_error_handler();
    }
}

it('aliases the old names to the classes that replaced them', function () {
    quietly(function (): void {
        expect((new \ReflectionClass('Tinvar\Attributes\Dependency'))->getName())->toBe('Tinvar\Container\Dependency')
            ->and(Lazy::value(1))->toBeInstanceOf('Tinvar\Data\Lazy')
            ->and(is_a('Tinvar\Data\Lazy', Lazy::class, true))->toBeTrue();
    });
});

it('reads a lifetime and an injected property declared with the old #[Dependency]', function () {
    $container = new Container();
    [$first, $second, $injected] = quietly(static fn (): array => [
        $container->get(SharedPool::class),
        $container->get(SharedPool::class),
        $container->get(WithInjected::class),
    ]);

    expect($first)->toBe($second)
        ->and($injected->pool)->toBe($first);
});
