<?php

declare(strict_types=1);

/**
 * Shared queries whose own source reads nothing user-dependent, and whose
 * call graph does -- or does not -- for the shared-query rule's call graph.
 */

namespace Tinvar\Tests\Auth\Fixtures\CallGraph;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Support\Str;
use Tinvar\Tests\Auth\Fixtures\CallGraph\Lookup as Finder;

/** A function of the application's that reads the user. */
function current_customer(): string
{
    return (string) auth()?->id();
}

/** Stands in for a global helper an application might have. */
function auth(): ?\Tinvar\Auth\AuthContext
{
    return Context::$auth;
}

final class Lookup
{
    public static function forUser(): int
    {
        return self::through();
    }

    private static function through(): int
    {
        return Context::$auth?->user() === null ? 0 : 1;
    }
}

final class Context
{
    public static ?\Tinvar\Auth\AuthContext $auth = null;
}

final class Reporter
{
    public function __construct(private readonly ?\Tinvar\Orm\Orm $orm = null) {}

    public function summary(): int
    {
        return $this->detail();
    }

    private function detail(): int
    {
        return (int) $this->orm?->acrossTenants(static fn (): int => 1);
    }
}

final class Formatter
{
    public function format(int $value): string
    {
        // Named the way the spec names it, which the text check reads.
        return Panel::current() . $value;
    }
}

final class Panel
{
    public static function current(): string
    {
        return '';
    }
}

/** Pure, and calls only the framework, where the walk stops. */
final class Pure
{
    public static function label(int $value): string
    {
        return Str::queryName(self::class) . self::twice($value);
    }

    private static function twice(int $value): int
    {
        return $value === 0 ? 0 : self::twice($value - 1) + 2;
    }
}

interface Source
{
    public function fetch(): int;
}

final class Holder
{
    public function __construct(private readonly Source $source) {}

    public function read(): int
    {
        return $this->source->fetch();
    }
}

final class Loose
{
    /** @param mixed $thing */
    public function __construct(private $thing = null) {}

    public function read(): int
    {
        return $this->thing->count();
    }
}

#[Query(name: 'graph.static', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughStatic
{
    public function __invoke(): int
    {
        // Through an alias: the walk reads the file's imports.
        return Finder::forUser();
    }
}

#[Query(name: 'graph.new', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughNew
{
    public function __invoke(): int
    {
        return new Reporter()->summary();
    }
}

#[Query(name: 'graph.property', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughProperty
{
    public function __construct(private readonly Formatter $formatter) {}

    public function __invoke(): string
    {
        return $this->formatter->format(1);
    }
}

#[Query(name: 'graph.function', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughFunction
{
    public function __invoke(): string
    {
        return current_customer();
    }
}

#[Query(name: 'graph.clean', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class Clean
{
    public function __invoke(): string
    {
        return Pure::label(3);
    }
}

#[Query(name: 'graph.interface', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughInterface
{
    public function __construct(private readonly Holder $holder) {}

    public function __invoke(): int
    {
        return $this->holder->read();
    }
}

#[Query(name: 'graph.untyped', cache: '1m', shared: true), NoAuthorization(reason: 'fixture: the shared rule is what is under test')]
final class ThroughUntyped
{
    public function __invoke(): int
    {
        return new Loose()->read();
    }
}
