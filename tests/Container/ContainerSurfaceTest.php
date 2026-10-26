<?php

declare(strict_types=1);

namespace Tinvar\Tests\Container\Surface;

use Tinvar\Container\Dependency;
use Tinvar\Container\Container;
use Tinvar\Container\ContainerException;

interface Clock {}

trait LinksToParent
{
    public function __construct(public ?self $parent = null) {}
}

trait RequiresParent
{
    public function __construct(public self $parent) {}
}

final class Branch
{
    use LinksToParent;
}

final class Loop
{
    use RequiresParent;
}

final class Unhinted
{
    /** @param mixed $anything */
    public function __construct(public $anything, public ?int $limit) {}
}

final class InjectsGhost
{
    #[Dependency]
    public \Tinvar\Tests\Container\Surface\NoSuchService $ghost;
}

it('refuses a registered instance that is not the class it was registered as', function () {
    $container = (new Container())->instance(Clock::class, new \stdClass());

    expect(fn () => $container->get(Clock::class))
        ->toThrow(ContainerException::class, 'The binding for ' . Clock::class . ' built a stdClass, which is not one.');
});

// In a trait, reflection reports self as "self", not the class using it.
describe('self in a constructor a trait provides', function () {
    it('reads it as the class using the trait, and takes an optional one as its default', function () {
        expect((new Container())->get(Branch::class)->parent)->toBeNull();
    });

    it('names a required one as a cycle on the class using the trait', function () {
        expect(fn () => (new Container())->get(Loop::class))
            ->toThrow(ContainerException::class, 'Circular dependency: ' . Loop::class . ' -> ' . Loop::class . '.');
    });
});

it('passes null to an untyped or nullable parameter with no default', function () {
    $object = (new Container())->get(Unhinted::class);

    expect($object->anything)->toBeNull()->and($object->limit)->toBeNull();
});

it('names a #[Dependency] property typed with a class that does not exist', function () {
    expect(fn () => (new Container())->get(InjectsGhost::class))->toThrow(
        ContainerException::class,
        'Cannot build ' . InjectsGhost::class . ': $ghost is typed ' . NoSuchService::class . ', which does not exist.',
    );
});
