<?php

declare(strict_types=1);

/**
 * Shared queries that reach a read of the user through a call the walk
 * once misread or passed over in silence: each must end in a violation --
 * the read found, or the call refused.
 */

namespace Tinvar\Tests\Auth\Fixtures\CallGraphGaps;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Tests\Auth\Fixtures\CallGraph\Context;

abstract class Report
{
    public function __invoke(): int
    {
        // self:: is this class, whichever class the query is.
        return self::count();
    }

    protected static function count(): int
    {
        return Context::$auth?->user() === null ? 0 : 1;
    }
}

#[Query(name: 'gaps.self', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughSelfInParent extends Report
{
    protected static function count(): int
    {
        return 0;
    }
}

class Reads
{
    public function read(): int
    {
        return Context::$auth?->user() === null ? 0 : 1;
    }
}

class Overrides extends Reads
{
    public function read(): int
    {
        return 0;
    }

    public function viaParent(): int
    {
        // parent:: is Reads, the parent of the class this is written in.
        return parent::read();
    }
}

#[Query(name: 'gaps.parent', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughParentInParent extends Overrides
{
    public function read(): int
    {
        return 0;
    }

    public function __invoke(): int
    {
        return $this->viaParent();
    }
}

final class Secret
{
    public static function who(): int
    {
        return Context::$auth?->user() === null ? 0 : 1;
    }
}

#[Query(name: 'gaps.dynamic', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughDynamicName
{
    public function __invoke(): int
    {
        $method = 'secret';

        return $this->{$method}() + $this->$method();
    }

    private function secret(): int
    {
        return Secret::who();
    }
}

#[Query(name: 'gaps.callable', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughArrayCallable
{
    public function __invoke(): int
    {
        return array_sum(array_map([$this, 'secret'], [1]));
    }

    private function secret(int $value): int
    {
        return Secret::who() + $value;
    }
}

#[Query(name: 'gaps.static-callable', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughStaticCallable
{
    public function __invoke(): int
    {
        return \call_user_func([Secret::class, 'who']);
    }
}

#[Query(name: 'gaps.string-callable', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughStringCallable
{
    public function __invoke(): int
    {
        return \call_user_func('Tinvar\Tests\Auth\Fixtures\CallGraphGaps\Secret::who');
    }
}

#[Query(name: 'gaps.computed-callable', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughComputedCallable
{
    public function __invoke(string $method = 'who'): int
    {
        return \call_user_func([Secret::class, $method]) + Secret::$method();
    }
}

enum Tier: string
{
    case Gold = 'gold';

    public function label(): string
    {
        return Secret::who() . $this->value;
    }
}

#[Query(name: 'gaps.enum', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughEnumCase
{
    public function __invoke(): string
    {
        return Tier::Gold->label();
    }
}

final class Registry
{
    public static ?Secret $secret = null;
}

#[Query(name: 'gaps.static-property', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughStaticProperty
{
    public function __invoke(): int
    {
        return Registry::$secret?->who() ?? 0;
    }
}

#[Query(name: 'gaps.relative', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughRelativeName
{
    public function __invoke(): int
    {
        return namespace\Secret::who();
    }
}

#[Query(name: 'gaps.unknown-class', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughUnknownClass
{
    public function __invoke(): int
    {
        return Missing::who();
    }
}

#[Query(name: 'gaps.unknown-function', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughUnknownFunction
{
    public function __invoke(): int
    {
        return missing_function();
    }
}
