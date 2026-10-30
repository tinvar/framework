<?php

declare(strict_types=1);

/**
 * Shared queries that reach -- or do not -- a read of the user through a
 * local variable, a chain of calls, or a callable handed on: what the
 * shared-query rule's call graph types and follows, and what it refuses.
 */

namespace Tinvar\Tests\Auth\Fixtures\CallGraphLocals;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Tests\Auth\Fixtures\CallGraph\Context;

/** An application function that reads the user. */
function secret_function(int $value = 0): int
{
    return Context::$auth?->user() === null ? $value : $value + 1;
}

/** Reads the user, by a name the query's text does not hold. */
final class Helper
{
    public int $viewer {
        get => Context::$auth?->user() === null ? 0 : 1;
    }

    public function read(int $value = 0): int
    {
        return Context::$auth?->user() === null ? $value : $value + 1;
    }
}

/** Reads nothing of the user's. */
final class Pure
{
    public function __construct(private readonly int $value = 1) {}

    public function with(int $value): static
    {
        return new static($value);
    }

    public function label(): string
    {
        return (string) $this->value;
    }
}

/** A builder an application wrote, handing back itself and a reader. */
final class Builder
{
    public function next(): static
    {
        return $this;
    }

    public function helper(): Helper
    {
        return new Helper();
    }

    public function read(): int
    {
        return new Helper()->read();
    }
}

final class Oops extends \RuntimeException
{
    public function who(): int
    {
        return new Helper()->read();
    }
}

abstract class Fixture
{
    protected function builder(): Builder
    {
        return new Builder();
    }

    /** @return list<Helper> */
    protected function helpers(): array
    {
        return [new Helper()];
    }

    protected function anything(): mixed
    {
        return new Helper();
    }

    protected function swap(?Helper &$helper): void
    {
        $helper = null;
    }
}

#[Query(name: 'locals.new', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughLocalNew extends Fixture
{
    public function __invoke(): int
    {
        $helper = new Helper();

        return $helper->read();
    }
}

#[Query(name: 'locals.reassigned', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughReassignedLocal extends Fixture
{
    public function __invoke(): int
    {
        $builder = $this->builder();
        $builder = $builder->next();

        return $builder->read();
    }
}

#[Query(name: 'locals.chain', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughReturnedObject extends Fixture
{
    public function __invoke(): int
    {
        return $this->builder()->next()->helper()->read();
    }
}

#[Query(name: 'locals.closure-param', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughTypedClosureParameter extends Fixture
{
    public function __invoke(): int
    {
        return array_sum(array_map(static fn (Helper $helper): int => $helper->read(), $this->helpers()));
    }
}

#[Query(name: 'locals.string', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughStringInLocal extends Fixture
{
    public function __invoke(): int
    {
        $read = 'Tinvar\Tests\Auth\Fixtures\CallGraphLocals\secret_function';

        return $read();
    }
}

#[Query(name: 'locals.bare-string', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughFunctionNameString extends Fixture
{
    public function __invoke(): int
    {
        return array_sum(array_map('Tinvar\Tests\Auth\Fixtures\CallGraphLocals\secret_function', [1]));
    }
}

#[Query(name: 'locals.array', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughArrayOfLocal extends Fixture
{
    public function __invoke(): int
    {
        $helper = new Helper();
        $callback = [$helper, 'read'];

        return array_sum(array_map($callback, [1]));
    }
}

#[Query(name: 'locals.catch', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughCaughtException extends Fixture
{
    public function __invoke(): int
    {
        try {
            throw new Oops();
        } catch (Oops $oops) {
            return $oops->who();
        }
    }
}

#[Query(name: 'locals.coalesce', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughCoalescedLocal extends Fixture
{
    public function __invoke(?Pure $pure = null): int
    {
        $either = $pure ?? new Helper();

        return (int) $either->read();
    }
}

#[Query(name: 'locals.hook', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughHookOnLocal extends Fixture
{
    public function __invoke(): int
    {
        $helper = new Helper();

        return $helper->viewer;
    }
}

#[Query(name: 'locals.clean', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class CleanLocals extends Fixture
{
    public function __invoke(): string
    {
        $pure = new Pure();
        $pure = $pure->with(2);
        $sum = 0;
        foreach ([1, 2] as $value) {
            $sum += $value;
        }
        $order = [];
        foreach (['name', 'price'] as $column) {
            $order[] = [$column, 'asc'];
        }
        $count = new \ArrayObject($order)->count();
        $labels = array_map(static fn (Pure $item): string => $item->label(), [$pure]);

        return $pure->label() . $sum . $count . implode('', $labels) . strlen('abc');
    }
}

#[Query(name: 'locals.foreach', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughForeachValue extends Fixture
{
    public function __invoke(): int
    {
        $total = 0;
        foreach ($this->helpers() as $helper) {
            $total += $helper->read();
        }

        return $total;
    }
}

#[Query(name: 'locals.foreach-property', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughForeachProperty extends Fixture
{
    public function __invoke(): int
    {
        $total = 0;
        foreach ($this->helpers() as $helper) {
            $total += $helper->viewer;
        }

        return $total;
    }
}

#[Query(name: 'locals.mixed', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughMixedReturn extends Fixture
{
    public function __invoke(): int
    {
        $helper = $this->anything();

        return $helper->read();
    }
}

#[Query(name: 'locals.element', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughArrayElement extends Fixture
{
    public function __invoke(): int
    {
        $helpers = $this->helpers();

        return $helpers[0]->read();
    }
}

#[Query(name: 'locals.built-string', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughBuiltString extends Fixture
{
    public function __invoke(string $name = 'secret_function'): int
    {
        $read = 'Tinvar\Tests\Auth\Fixtures\CallGraphLocals\\' . $name;

        return \call_user_func($read) + $read();
    }
}

#[Query(name: 'locals.unknown-array', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughArrayOfUnknown extends Fixture
{
    public function __invoke(): int
    {
        $total = 0;
        foreach ($this->helpers() as $helper) {
            $total += array_sum(array_map([$helper, 'read'], [1]));
        }

        return $total;
    }
}

#[Query(name: 'locals.reference', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughReferenceArgument extends Fixture
{
    public function __invoke(): int
    {
        $helper = new Helper();
        $this->swap($helper);

        return (int) $helper?->read();
    }
}

#[Query(name: 'locals.match', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughMatch extends Fixture
{
    public function __invoke(int $which = 1): int
    {
        return (match ($which) {
            1 => new Helper(),
            default => new Helper(),
        })->read();
    }
}

#[Query(name: 'locals.literal-element', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughLiteralElement extends Fixture
{
    public function __invoke(): int
    {
        return [new Helper()][0]->read();
    }
}

#[Query(name: 'locals.variable-variable', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughVariableVariable extends Fixture
{
    public function __invoke(string $name = 'helper'): int
    {
        $helper = new Pure();
        $$name = new Helper();

        return (int) $helper->label();
    }
}

#[Query(name: 'locals.untyped-closure', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughUntypedClosureParameter extends Fixture
{
    public function __invoke(): int
    {
        return array_sum(array_map(static fn ($helper): int => $helper->read(), $this->helpers()));
    }
}

#[Query(name: 'locals.anonymous', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughAnonymousClass extends Fixture
{
    public function __invoke(): int
    {
        return new class {
            public function read(): int
            {
                return 0;
            }
        }->read();
    }
}

#[Query(name: 'locals.rebind', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughRebinding extends Fixture
{
    public function __invoke(): int
    {
        $read = fn (): int => 0;

        return (int) $read->call(new Helper());
    }
}

#[Query(name: 'locals.reflection', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughReflection extends Fixture
{
    public function __invoke(): int
    {
        $method = new \ReflectionMethod(Pure::class, 'label');

        return (int) $method->invoke(new Pure());
    }
}

#[Query(name: 'locals.undefined', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughUndefinedVariable extends Fixture
{
    public function __invoke(): int
    {
        return $nothing->read();
    }
}

final class Stringy
{
    public function __toString(): string
    {
        return (string) new Helper()->read();
    }
}

#[Query(name: 'locals.implicit', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughImplicitString extends Fixture
{
    public function __invoke(): string
    {
        $value = new Stringy();

        return "value: {$value}";
    }
}

#[Query(name: 'locals.destruct', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughOwnDestructor extends Fixture
{
    public function __invoke(): int
    {
        return 0;
    }

    public function __destruct()
    {
        new Helper()->read();
    }
}

#[Query(name: 'locals.static-string', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughStaticCallOnString extends Fixture
{
    public function __invoke(string $name = 'Helper'): int
    {
        $class = 'Tinvar\Tests\Auth\Fixtures\CallGraph\Lookup';
        $built = 'Tinvar\Tests\Auth\Fixtures\CallGraphLocals\\' . $name;

        return $class::forUser() + $built::make() + $name::make();
    }
}

final class Keeper
{
    /** @param \Closure(): int $reader */
    public function __construct(private readonly \Closure $reader) {}

    public function run(): int
    {
        return ($this->reader)();
    }
}

#[Query(name: 'locals.kept', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughKeptClosure extends Fixture
{
    public function __construct(private readonly Keeper $keeper, private readonly \Closure $handed) {}

    public function __invoke(): int
    {
        return $this->keeper->run() + ($this->handed)();
    }
}

#[Query(name: 'locals.written-closure', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class CleanWrittenClosure extends Fixture
{
    public function __invoke(): int
    {
        $double = static fn (int $value): int => $value * 2;

        return $double(2) + array_sum(array_map($double, [1, 2])) + $this->apply(static fn (int $value): int => $value);
    }

    /** @param \Closure(int): int $callback */
    private function apply(\Closure $callback): int
    {
        return $callback(1);
    }
}
