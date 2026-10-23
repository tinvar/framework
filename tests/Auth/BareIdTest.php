<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\BareId;

use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Auth\BuildRules;
use Tinvar\Data\Data;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Validation\FormRequest;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/tenant.php';

final readonly class IdData extends Data
{
    public function __construct(public string $id) {}
}

#[Page('/bare/union', view: 'union'), Authorize('viewAny', Script::class)]
final class UnionIdPage
{
    public function __invoke(int|string $scriptId): IdData
    {
        return new IdData((string) $scriptId);
    }
}

#[Page('/bare/untyped', view: 'untyped'), Authorize('viewAny', Script::class)]
final class UntypedIdPage
{
    /** @param mixed $scriptId */
    public function __invoke($scriptId): IdData
    {
        return new IdData((string) $scriptId);
    }
}

#[Page('/bare/exempt', view: 'exempt'), NoAuthorization(reason: 'public listing')]
final class ExemptIdPage
{
    public function __invoke(string $scriptId): IdData
    {
        return new IdData($scriptId);
    }
}

#[Page('/bare/intersection', view: 'intersection'), Authorize('viewAny', Script::class)]
final class IntersectionIdPage
{
    public function __invoke(\Countable&\Traversable $scriptId): IdData
    {
        return new IdData((string) \count($scriptId));
    }
}

final readonly class UnionIdRequest extends FormRequest
{
    public function __construct(public int|string $scriptId) {}
}

final readonly class MixedIdData extends Data
{
    public function __construct(public mixed $scriptId) {}
}

final readonly class EitherRequest extends FormRequest
{
    public function __construct(public MixedIdData|IdData $either) {}
}

/**
 * @param list<class-string> $entries
 * @param list<class-string> $payloads
 * @return list<string>
 */
function bareIdViolations(array $entries, array $payloads = []): array
{
    return array_map(
        static fn ($v): string => "{$v->rule} {$v->where}",
        (new BuildRules([Script::class]))->check($entries, $payloads),
    );
}

// A union with a scalar in it carries a raw id just as well, and the Data
// mapper accepts unions; only a named builtin type was being checked.
it('flags a union-typed id parameter naming a tenant-scoped model', function () {
    expect(bareIdViolations([UnionIdPage::class]))
        ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . UnionIdPage::class . '::__invoke($scriptId)']);
});

it('flags an untyped id parameter', function () {
    expect(bareIdViolations([UntypedIdPage::class]))
        ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . UntypedIdPage::class . '::__invoke($scriptId)']);
});

// #[NoAuthorization] opts out of the authorization rule. It never opted out
// of tenant scoping, and the payload path already said so.
it('flags a bare id on an entry point exempt from authorization', function () {
    expect(bareIdViolations([ExemptIdPage::class]))
        ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . ExemptIdPage::class . '::__invoke($scriptId)']);
});

it('flags a union-typed or mixed id in a payload, including one reached through a union of Data classes', function () {
    expect(bareIdViolations([], [UnionIdRequest::class, EitherRequest::class]))->toBe([
        BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . UnionIdRequest::class . '::$scriptId',
        BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . EitherRequest::class . ' -> ' . MixedIdData::class . '::$scriptId',
    ]);
});

it('names the bare part of the type in the message', function () {
    $violations = (new BuildRules([Script::class]))->check([UnionIdPage::class]);

    expect($violations[0]->message)->toContain('is a bare string|int naming');
});

// An intersection is of classes, so it can never be a raw id.
it('passes an intersection-typed parameter', function () {
    expect(bareIdViolations([IntersectionIdPage::class]))->toBe([]);
});
