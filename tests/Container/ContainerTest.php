<?php

declare(strict_types=1);

namespace Tinvar\Tests\Container;

use Tinvar\Attributes\Dependency;
use Tinvar\Container\Container;
use Tinvar\Container\ContainerException;
use Tinvar\Container\Lifetime;

interface Clock
{
    public function now(): int;
}

final class FixedClock implements Clock
{
    public function now(): int
    {
        return 42;
    }
}

final class Config
{
    public function __construct(public string $region = 'eu', public ?int $limit = null) {}
}

#[Dependency(Lifetime::Request)]
final class Meter
{
    public function __construct(public Config $config) {}
}

#[Dependency(Lifetime::Worker)]
final class Pool
{
    public function __construct(public Config $config) {}
}

final class Page
{
    public function __construct(public Meter $meter, public Pool $pool, public Config $config) {}
}

final class NeedsClock
{
    public function __construct(public Clock $clock) {}
}

final class NeedsScalar
{
    public function __construct(public string $dsn) {}
}

final class NeedsGhost
{
    public function __construct(public \Tinvar\Tests\Container\NoSuchClass $ghost) {}
}

final class Chicken
{
    public function __construct(public Egg $egg) {}
}

final class Egg
{
    public function __construct(public Chicken $chicken) {}
}

final class Injected
{
    #[Dependency]
    private Meter $meter;

    #[Dependency]
    public readonly Pool $pool;

    public ?Config $plain = null;

    public function meter(): Meter
    {
        return $this->meter;
    }
}

final class BadInjection
{
    #[Dependency]
    public string $name;
}

abstract class Shape {}

describe('autowiring', function () {
    it('builds a class from its constructor types, all the way down', function () {
        $page = (new Container())->get(Page::class);

        expect($page->meter->config)->toBeInstanceOf(Config::class)
            ->and($page->config->region)->toBe('eu');
    });

    it('uses a default, else null, for a parameter it cannot build', function () {
        $config = (new Container())->get(Config::class);

        expect($config->region)->toBe('eu')->and($config->limit)->toBeNull();
    });

    it('names a scalar it cannot invent, with the chain that needed it', function () {
        expect(fn () => (new Container())->get(NeedsScalar::class))
            ->toThrow(ContainerException::class, 'Cannot build ' . NeedsScalar::class . ': $dsn is string');
    });

    it('names a type that does not exist', function () {
        expect(fn () => (new Container())->get(NeedsGhost::class))
            ->toThrow(ContainerException::class, 'which does not exist');
    });

    it('names a cycle rather than recursing until the stack runs out', function () {
        expect(fn () => (new Container())->get(Chicken::class))
            ->toThrow(ContainerException::class, 'Circular dependency: ' . Chicken::class . ' -> ' . Egg::class . ' -> ' . Chicken::class);
    });

    it('refuses an interface or abstract class with nothing bound', function (string $class) {
        expect(fn () => (new Container())->get($class))->toThrow(ContainerException::class, 'nothing bound to it');
    })->with([Clock::class, Shape::class]);
});

describe('bindings and instances', function () {
    it('builds an interface through the class bound to it', function () {
        $container = (new Container())->bind(Clock::class, FixedClock::class);

        expect($container->get(NeedsClock::class)->clock->now())->toBe(42);
    });

    it('builds through a factory closure', function () {
        $container = (new Container())->bind(Config::class, static fn (): Config => new Config('us', 5));

        expect($container->get(Config::class)->region)->toBe('us');
    });

    it('refuses a binding that builds the wrong thing', function () {
        $container = (new Container())->bind(Clock::class, static fn (): object => new Config());

        expect(fn () => $container->get(Clock::class))->toThrow(ContainerException::class, 'which is not one');
    });

    it('hands out a registered instance as it is', function () {
        $config = new Config('ap');
        $container = (new Container())->instance(Config::class, $config);

        expect($container->get(Page::class)->config)->toBe($config);
    });

    it('says what it can provide', function () {
        $container = (new Container())->bind(Clock::class, FixedClock::class);

        expect($container->has(Clock::class))->toBeTrue()
            ->and($container->has(Config::class))->toBeTrue()
            ->and($container->has('No\\Such\\Thing'))->toBeFalse();
    });
});

describe('lifetimes', function () {
    it('builds a transient service fresh every time', function () {
        $container = new Container();

        expect($container->get(Config::class))->not->toBe($container->get(Config::class));
    });

    it('shares a request service until the request ends', function () {
        $container = new Container();
        $first = $container->get(Meter::class);

        expect($container->get(Meter::class))->toBe($first);

        $container->endRequest();
        expect($container->get(Meter::class))->not->toBe($first);
    });

    it('shares a worker service across requests', function () {
        $container = new Container();
        $pool = $container->get(Pool::class);
        $container->endRequest();

        expect($container->get(Pool::class))->toBe($pool);
    });

    it('applies a lifetime given to a binding', function () {
        $container = (new Container())->bind(Clock::class, FixedClock::class, Lifetime::Request);

        expect($container->get(Clock::class))->toBe($container->get(Clock::class));
    });
});

describe('#[Dependency] properties', function () {
    it('fills marked properties after construction, private and readonly included', function () {
        $object = (new Container())->get(Injected::class);

        expect($object->meter())->toBeInstanceOf(Meter::class)
            ->and($object->pool)->toBeInstanceOf(Pool::class)
            ->and($object->plain)->toBeNull();
    });

    it('refuses a marked property without a class type', function () {
        expect(fn () => (new Container())->get(BadInjection::class))
            ->toThrow(ContainerException::class, 'without a class type');
    });
});

describe('as the resolver closure', function () {
    it('resolves through the container', function () {
        expect((new Container())->resolver()(Config::class))->toBeInstanceOf(Config::class);
    });

    it('refuses a name that is not a class', function () {
        expect(fn () => (new Container())->resolver()('No\\Such\\Thing'))
            ->toThrow(ContainerException::class, 'No class No\\Such\\Thing.');
    });
});
