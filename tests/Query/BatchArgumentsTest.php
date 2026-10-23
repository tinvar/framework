<?php

declare(strict_types=1);

/**
 * A batch query's per-call arguments are only checked, resolved and
 * authorized when #[Query(args:)] declares them -- and only as models when
 * declared as models. Undeclared, or declared as a string, they reached
 * loadMany() as raw ids, and neither build rule noticed.
 */

namespace Tinvar\Tests\Query\BatchArguments;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Query;
use Tinvar\Auth\BuildRules;
use Tinvar\Query\BatchQuery;
use Tinvar\Query\QueryRegistry;
use Tinvar\Tests\Orm\Fixtures\Script;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/tenant.php';

#[Query(name: 'batchArgs.undeclared'), NoAuthorization(reason: 'the declaration is what is under test')]
final class Undeclared implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): string => 'raw', $calls);
    }
}

#[Query(name: 'batchArgs.bareId', args: ['scriptId' => 'string']), NoAuthorization(reason: 'the declaration is what is under test')]
final class BareId implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): string => 'raw', $calls);
    }
}

#[Query(name: 'batchArgs.typed', args: ['script' => Script::class]), Authorize('view', 'script')]
final class Typed implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): string => 'resolved', $calls);
    }
}

#[Query(name: 'batchArgs.term', args: ['term' => 'string']), NoAuthorization(reason: 'a search term names no model')]
final class Term implements BatchQuery
{
    public function loadMany(array $calls): array
    {
        return array_map(static fn (): string => 'term', $calls);
    }
}

it('refuses to register a batch query that declares no arguments', function () {
    expect(fn () => (new QueryRegistry())->register(Undeclared::class))
        ->toThrow(\InvalidArgumentException::class, 'is a BatchQuery with no #[Query(args:)]');
});

it('flags a batch argument declared as a bare id of a tenant-scoped model', function () {
    $violations = (new BuildRules([Script::class]))->check([BareId::class]);

    expect(array_map(static fn ($v): string => "{$v->rule} {$v->where}", $violations))
        ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . BareId::class . '::loadMany($scriptId)']);
});

it('passes one declared as the model, or one whose name refers to no model', function (string $class) {
    expect((new BuildRules([Script::class]))->check([$class]))->toBe([]);
})->with([Typed::class, Term::class]);
