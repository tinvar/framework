<?php

declare(strict_types=1);

namespace Tinvar\Tests\Auth\BareId;

use Tinvar\Actions\ActionResult;
use Tinvar\Attributes\Action;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\NoAuthorization;
use Tinvar\Attributes\Page;
use Tinvar\Attributes\Query;
use Tinvar\Auth\BuildRules;
use Tinvar\Data\Data;
use Tinvar\Tests\Orm\Fixtures\Script;
use Tinvar\Tests\Orm\Fixtures\ScriptRun;
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

final readonly class RunScriptRequest extends FormRequest
{
    public function __construct(public string $scriptId) {}
}

final readonly class ScriptFilterData extends Data
{
    /** @param list<string> $scriptIds */
    public function __construct(public array $scriptIds) {}
}

final class ScriptActions
{
    #[Action, NoAuthorization(reason: 'a fixture action')]
    public function run(RunScriptRequest $request): ActionResult
    {
        return ActionResult::ok($request->scriptId);
    }
}

#[Query(name: 'bare.runs'), NoAuthorization(reason: 'a fixture query')]
final class ScriptRuns
{
    public function __invoke(?ScriptFilterData $filter = null): int
    {
        return \count($filter->scriptIds ?? []);
    }
}

final readonly class RetryRunRequest extends FormRequest
{
    /** @param list<string> $script_run_ids */
    public function __construct(public string $script_run_id, public array $script_run_ids = []) {}
}

final class RunActions
{
    #[Action, NoAuthorization(reason: 'a fixture action')]
    public function retry(RetryRunRequest $request, string $script_run): ActionResult
    {
        return ActionResult::ok($request->script_run_id . $script_run);
    }
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

// `tinvar check` handed the rule its entry points and a list of payloads kept
// by hand, and only the list was checked: a form request nobody added to it
// carried its bare id to the action unseen. The payloads an entry point takes
// are found from its parameters now, listed or not.
it('checks every payload an entry point takes, listed or not', function () {
    expect(bareIdViolations([ScriptActions::class, ScriptRuns::class]))->toBe([
        BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . RunScriptRequest::class . '::$scriptId',
        BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . ScriptFilterData::class . '::$scriptIds',
    ]);
});

it('reports a payload both taken and listed once', function () {
    expect(bareIdViolations([ScriptActions::class], [RunScriptRequest::class]))
        ->toBe([BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . RunScriptRequest::class . '::$scriptId']);
});

it('finds the Data objects and form requests the entry points take, nullable ones included', function () {
    expect(BuildRules::payloadsTakenBy([ScriptActions::class, ScriptRuns::class, UnionIdPage::class]))
        ->toBe([RunScriptRequest::class, ScriptFilterData::class]);
});

// A model whose name is more than one word, named the way a form field
// often is: `script_run_id` names ScriptRun as surely as `scriptRunId` does.
// Only its underscores told them apart, and the snake_case spelling went
// through unflagged.
it('flags a bare id naming a model of more than one word in snake_case', function () {
    expect(array_map(
        static fn ($v): string => "{$v->rule} {$v->where}",
        (new BuildRules([Script::class, ScriptRun::class]))->check([RunActions::class]),
    ))->toBe([
        BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . RunActions::class . '::retry($script_run)',
        BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . RetryRunRequest::class . '::$script_run_id',
        BuildRules::TENANT_SCOPED_IDS_MUST_BE_TYPED . ' ' . RetryRunRequest::class . '::$script_run_ids',
    ]);
});
