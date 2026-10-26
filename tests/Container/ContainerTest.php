<?php

declare(strict_types=1);

namespace Tinvar\Tests\Container;

use Tinvar\Container\Dependency;
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

final class Singleton
{
    private function __construct() {}
}


final class OptionalClock
{
    /** @var list<Config> */
    public array $extra;

    public function __construct(public ?Clock $clock = null, Config ...$extra)
    {
        $this->extra = $extra;
    }
}

final class TreeNode
{
    public function __construct(public ?self $parent = null) {}
}


final class PromotedDependency
{
    public function __construct(#[Dependency] public readonly Pool $pool) {}
}

abstract class BaseWithPrivate
{
    #[Dependency]
    private Pool $pool;

    public function pool(): Pool
    {
        return $this->pool;
    }
}

final class ChildOfBase extends BaseWithPrivate {}


#[Dependency(Lifetime::Worker)]
final class CapturesMeter
{
    public function __construct(public Meter $meter) {}
}

final class Middle
{
    public function __construct(public Meter $meter) {}
}

#[Dependency(Lifetime::Worker)]
final class CapturesThroughMiddle
{
    public function __construct(public Middle $middle) {}
}

#[Dependency(Lifetime::Worker)]
final class InjectsMeter
{
    #[Dependency]
    public Meter $meter;
}


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

    // Optional means optional: an unbound interface, or the class being
    // built, is not an error when there is a default to take instead.
    it('gives an optional dependency it cannot provide its default', function () {
        $object = (new Container())->get(OptionalClock::class);

        expect($object->clock)->toBeNull()->and($object->extra)->toBe([]);
    });

    it('provides an optional dependency it can', function () {
        $container = (new Container())->bind(Clock::class, FixedClock::class);

        expect($container->get(OptionalClock::class)->clock)->toBeInstanceOf(FixedClock::class);
    });

    it('reads self as the declaring class, and does not cycle on an optional one', function () {
        expect((new Container())->get(TreeNode::class)->parent)->toBeNull();
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

    it('says what it can provide, and not what it cannot build', function () {
        $container = (new Container())->bind(Clock::class, FixedClock::class);

        expect($container->has(Clock::class))->toBeTrue()
            ->and($container->has(Config::class))->toBeTrue()
            ->and($container->has(Shape::class))->toBeFalse()
            ->and($container->has(Singleton::class))->toBeFalse()
            ->and($container->has('No\\Such\\Thing'))->toBeFalse();
    });

    it('names a class that does not exist', function () {
        expect(fn () => (new Container())->get('No\\Such\\Thing'))->toThrow(ContainerException::class, 'No class No\\Such\\Thing.');
    });

    it('names a constructor it may not call', function () {
        expect(fn () => (new Container())->get(Singleton::class))->toThrow(ContainerException::class, 'its constructor is not public');
    });

    // Refused before it is kept: a worker binding stored the wrong object
    // and the next lookup handed it out.
    it('keeps nothing a binding built wrongly', function () {
        $container = (new Container())->bind(Clock::class, static fn (): object => new Config(), Lifetime::Worker);

        expect(fn () => $container->get(Clock::class))->toThrow(ContainerException::class, 'which is not one')
            ->and(fn () => $container->get(Clock::class))->toThrow(ContainerException::class, 'which is not one');
    });
});

describe('the container itself', function () {
    it('hands itself to something that takes the container', function () {
        $container = (new Container())->instance(Config::class, new Config('ap'));

        expect($container->get(Container::class))->toBe($container);
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

    // The way to give a class with no attribute a lifetime; it read as a
    // cycle, the class re-entering its own resolution.
    it('gives a lifetime to a class bound to itself', function () {
        $container = (new Container())->bind(Config::class, Config::class, Lifetime::Worker);

        expect($container->get(Config::class))->toBe($container->get(Config::class));
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

    // The attribute on a promoted parameter is on its property too; the
    // container wrote the readonly property a second time and crashed.
    it('leaves a property the constructor already set', function () {
        $pool = new Pool(new Config());
        $container = (new Container())->instance(Pool::class, $pool);

        expect($container->get(PromotedDependency::class)->pool)->toBe($pool);
    });

    it("fills a parent class's private dependency", function () {
        expect((new Container())->get(ChildOfBase::class)->pool())->toBeInstanceOf(Pool::class);
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


/**
 * A request-lived service held by something that outlives the request keeps
 * that request's state -- its user, its tenant, its data -- after
 * endRequest() has dropped the container's copy.
 */
describe('captive dependencies', function () {
    it('refuses a request service in a worker service', function (string $class) {
        expect(fn () => (new Container())->get($class))
            ->toThrow(ContainerException::class, Meter::class . ' lives for one request, but ');
    })->with([
        'by constructor' => [CapturesMeter::class],
        'through a transient in between' => [CapturesThroughMiddle::class],
        'by property' => [InjectsMeter::class],
    ]);

    // The normal order in a request: the request service already exists when
    // the worker service that wants it is first built.
    it('refuses a request service in a worker service when it is already built', function (string $class) {
        $container = new Container();
        $container->get(Meter::class);

        expect(fn () => $container->get($class))
            ->toThrow(ContainerException::class, Meter::class . ' lives for one request, but ');
    })->with([
        'by constructor' => [CapturesMeter::class],
        'through a transient in between' => [CapturesThroughMiddle::class],
        'by property' => [InjectsMeter::class],
    ]);

    it('refuses an already-built request service while something long-lived is assembled', function () {
        $container = new Container();
        $container->get(Meter::class);

        expect(fn () => $container->longLived('the boot', fn () => $container->get(Meter::class)))
            ->toThrow(ContainerException::class, 'but the boot outlives the request');
    });

    it('refuses a request service while something long-lived is assembled', function () {
        $container = new Container();

        expect(fn () => $container->longLived('the boot', fn () => $container->get(Meter::class)))
            ->toThrow(ContainerException::class, 'but the boot outlives the request');
    });

    it('allows worker and transient services while long-lived things are assembled', function () {
        $container = new Container();

        expect($container->longLived('the boot', fn () => $container->get(Pool::class)))->toBeInstanceOf(Pool::class)
            ->and($container->get(Meter::class))->toBeInstanceOf(Meter::class);
    });
});
