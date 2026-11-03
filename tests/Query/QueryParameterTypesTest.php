<?php

declare(strict_types=1);

/**
 * A query's arguments are "a scalar, an array or a backed enum, coerced to
 * its declared type as an action's parameter is", or a model resolved by
 * id (QueryRunner::resolveArguments()). A parameter of any other type --
 * mixed, untyped, a union, an object -- is coerced by nothing, so what the
 * renderer or an HTTP caller sent reaches the body as it came. The action
 * registry refuses such a parameter at registration (ActionException::
 * unbindable()); the query registry must too, rather than accept it and
 * let the caller's raw input, or a TypeError over it, into the body.
 */

namespace Tinvar\Tests\Query\ParameterTypes;

use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Query\QueryRegistry;

#[Query(name: 'probe.mixed'), NoAuthorization(reason: 'a probe')]
final class MixedQuery
{
    public function __invoke(mixed $x): array
    {
        return [];
    }
}

#[Query(name: 'probe.union'), NoAuthorization(reason: 'a probe')]
final class UnionQuery
{
    public function __invoke(int|string $x): array
    {
        return [];
    }
}

#[Query(name: 'probe.object'), NoAuthorization(reason: 'a probe')]
final class ObjectQuery
{
    public function __invoke(object $x): array
    {
        return [];
    }
}

#[Query(name: 'probe.untyped'), NoAuthorization(reason: 'a probe')]
final class UntypedQuery
{
    public function __invoke($x): array
    {
        return [];
    }
}

it('refuses a query parameter the runner cannot coerce, at registration', function (string $class) {
    expect(static fn () => new QueryRegistry()->register($class))->toThrow(\InvalidArgumentException::class);
})->with([MixedQuery::class, UnionQuery::class, ObjectQuery::class, UntypedQuery::class]);

#[Query(name: 'probe.batch', args: ['term' => 'string', 'limit' => 'int']), NoAuthorization(reason: 'a probe')]
final class TypedBatchQuery implements \Tinvar\Query\BatchQuery
{
    public function loadMany(array $argumentSets): array
    {
        return array_map(static fn (array $a): array => ['term' => get_debug_type($a['term']), 'limit' => get_debug_type($a['limit'])], $argumentSets);
    }
}

// #[Query(args:)] types a batch query's scalar arguments "as a scalar type
// name", and a plain query's are coerced to their declared type or refused
// as bad arguments. A batch query's must be held to the same: an array where
// it declared a string, text where it declared an int, reached loadMany() as
// sent.
it('coerces a batch query\'s declared scalar arguments as a plain query\'s, refusing what its type has no place for', function () {
    $registry = new QueryRegistry();
    $registry->register(TypedBatchQuery::class);
    $orm = new \Tinvar\Orm\Orm(new \Tinvar\Orm\PdoConnection(new \PDO('sqlite::memory:'), 'test'), new \Tinvar\Orm\IdentityMap('test'));
    $runner = new \Tinvar\Query\QueryRunner($registry, $orm, new \Tinvar\Auth\AllowAllGate());

    $results = $runner->run([
        ['id' => 1, 'name' => 'probe.batch', 'args' => ['term' => ['nested' => ['deep']], 'limit' => 'ten']],
        ['id' => 2, 'name' => 'probe.batch', 'args' => ['term' => 'rope', 'limit' => '10']],
    ], ['probe.batch']);

    expect($results[0]['error']['code'] ?? null)->toBe(\Tinvar\Query\QueryError::BAD_ARGUMENTS)
        ->and($results[1]['value'] ?? null)->toBe(['term' => 'string', 'limit' => 'int']);
});
